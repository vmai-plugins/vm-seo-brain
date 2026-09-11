<?php
defined( 'ABSPATH' ) || exit;

/**
 * Task Runner (VM SEO Brain X).
 *
 * industrial-grade background processor.
 * Orchestrates intelligence and production tasks with:
 * - Atomic row locking (FOR UPDATE)
 * - Exponential backoff on failure
 * - Timeout protection & Interruption recovery
 * - Blitz Mode: Increased throughput for high-velocity sites.
 */
class VMSB_Task_Runner {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_tasks';
	}

	public static function queue( $task_type, array $payload = array(), $score = 0, $reason = '', $max_attempts = 3 ) {
		global $wpdb;

		// Don't double-queue the same task type if already pending
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " WHERE task_type = %s AND status IN ('queued', 'running', 'retrying') AND payload = %s",
			$task_type, wp_json_encode($payload)
		) );

		if ( $existing ) return (int) $existing;

		$timeline = array(
			array( 'time' => current_time( 'mysql' ), 'event' => 'Task discovered & queued', 'note' => $reason )
		);

		$wpdb->insert( self::table(), array(
			'task_type'    => $task_type,
			'payload'      => wp_json_encode( $payload ),
			'score'        => (float) $score,
			'reason'       => mb_substr( $reason, 0, 250 ),
			'status'       => 'queued',
			'attempts'     => 0,
			'max_attempts' => (int) $max_attempts,
			'timeline'     => wp_json_encode( $timeline ),
			// UTC, like every other timestamp this table is compared against.
			'queued_at'    => current_time( 'mysql', true ),
		) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Log a milestone to a task's timeline.
	 */
	public static function log_event( $task_id, $event, $note = '' ) {
		global $wpdb;
		$table = self::table();

		$current_json = $wpdb->get_var( $wpdb->prepare( "SELECT timeline FROM {$table} WHERE id = %d", $task_id ) );
		$timeline = json_decode( (string) $current_json, true ) ?: array();

		$timeline[] = array(
			'time'  => current_time( 'mysql' ),
			'event' => $event,
			'note'  => $note
		);

		$wpdb->update( $table, array( 'timeline' => wp_json_encode( $timeline ) ), array( 'id' => $task_id ) );
	}

	/**
	 * Process a batch of tasks, one batch at a time across the whole site.
	 *
	 * Cron, the "Run Production Batch" button and any other trigger can fire
	 * at the same moment. Each batch is internally serial and neither can
	 * steal the other's rows - the claim in run_batch() is transactional - but
	 * two batches still stack their AI calls on top of each other against the
	 * same provider rate limits, and a throttled or truncated reply is exactly
	 * what surfaces later as an unparseable model response. So: overlapping
	 * runs are refused rather than queued, and whatever this pass skips is
	 * still sitting in the queue for the next one.
	 *
	 * GET_LOCK is released when the connection closes, so a fatal or a timeout
	 * mid-batch cannot strand the lock the way a flag row would.
	 */
	public static function process( $limit = 3 ) {
		global $wpdb;

		$lock = substr( $wpdb->prefix . 'vmsb_batch', 0, 64 );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) );

		// NULL means the lock could not be evaluated at all - no permission,
		// or a server that does not offer GET_LOCK. That is a reason to run
		// unlocked, not a reason to stop processing work entirely.
		if ( null === $got ) {
			return self::run_batch( $limit );
		}

		if ( '1' !== (string) $got ) {
			( new VMSB_Logger() )->info( 'task_runner', 'A batch is already running; this pass was skipped.' );
			return array( 'ran' => 0, 'results' => array(), 'skipped' => 'A batch is already running. Its work stays queued.' );
		}

		try {
			return self::run_batch( $limit );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function run_batch( $limit = 3 ) {
		global $wpdb;
		$table = self::table();

		// A task is claimed by flipping it to 'running' before it executes. If
		// the request then dies mid-task - PHP timeout, fatal, or the gateway
		// hanging up on a long AI call - nothing ever set that row back, and
		// nothing here looked for it. The row stayed 'running' forever: never
		// picked up again (only queued/retrying are eligible), counted as a
		// busy agent by fleet_status() indefinitely, and worst of all treated
		// as an active duplicate by queue(), so the Strategist could never
		// queue that task type again. A single timeout permanently disabled
		// one agent. Reclaim anything that has been 'running' far longer than
		// a request could possibly last.
		self::reclaim_stalled();

		// Let a task finish even if the browser or gateway gives up waiting.
		// Without this the connection dropping can take PHP down mid-task and
		// strand the row exactly as described above.
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		// ELITE BLITZ: If velocity is high, significantly increase processing capacity
		$velocity = (int) VMSB_Settings::get( 'posts_per_day', 3 );
		if ( $velocity >= 15 ) {
			$limit = max( $limit, 10 );
		}

		$wpdb->query( 'START TRANSACTION' );

		// Fetch eligible tasks: queued OR retrying after their delay has passed
		$now = current_time( 'mysql', true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE (status = 'queued' OR (status = 'retrying' AND retry_after <= %s))
			 ORDER BY score DESC LIMIT %d FOR UPDATE",
			$now, (int) $limit
		) );

		if ( $rows ) {
			$ids = wp_list_pluck( $rows, 'id' );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// prepare() only spreads an array into individual placeholder
			// values when that array is the SOLE argument after the query -
			// mixing a direct scalar ($now) with an array ($ids) here meant
			// $ids was passed as one positional value instead of one per id,
			// so the placeholder count (1 + count($ids)) never matched the
			// argument count (2) for any batch with more than one row.
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', ran_at = %s WHERE id IN ({$placeholders})", array_merge( array( $now ), $ids ) ) );
		}

		$wpdb->query( 'COMMIT' );

		if ( ! $rows ) return array( 'ran' => 0, 'results' => array() );

		$log = new VMSB_Logger();
		$ran = 0;
		// Populated below with one array( id, task, ok, message ) entry per
		// task actually attempted - this used to be declared, returned as
		// 'results', and never once written to, so every caller reading it
		// (the WP-CLI `wp vmsb run` command in particular) got an
		// eternally-empty array. Its own foreach then iterated the WRONG
		// level entirely - over process()'s wrapper array( 'ran' =>...,
		// 'results' =>... ) itself rather than this list - producing
		// "Undefined array key" warnings on every run (int/array values
		// have no 'ok'/'task' keys) and, whenever the batch-lock's
		// 'skipped' string got iterated too, a fatal "Cannot access offset
		// of type string on string". 1,480+ warnings and 16 fatals logged
		// in production from this single bug.
		$results = array();
		$start_time = time();

		// Budget for the whole batch. This can only stop the runner starting
		// ANOTHER task - it cannot interrupt one already executing - and a
		// single AI-heavy task is slow on its own: a Pipeline Hydrator run
		// measured 37 seconds here, so the old default of three per request
		// was ~110s and five was ~185s.
		//
		// The binding limit is NOT max_execution_time (1200s on this stack),
		// it is whatever proxy is holding the HTTP connection open - which is
		// what returned 524 after about two minutes. So cap hard when a
		// browser is waiting, and only spend a long budget under cron/CLI
		// where nothing is going to hang up. Whatever does not fit stays
		// queued and runs on the next pass.
		$unattended = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || 'cli' === PHP_SAPI;
		$max_exec   = (int) ini_get( 'max_execution_time' );
		if ( $unattended ) {
			$budget = $max_exec > 0 ? max( 60, (int) ( $max_exec * 0.6 ) ) : 300;
		} else {
			// Comfortably under the common 60s proxy read timeout, and far
			// under Cloudflare's 100s, so the request always returns.
			$budget = $max_exec > 0 ? min( 45, max( 15, (int) ( $max_exec * 0.6 ) ) ) : 45;
		}

		// Longest task seen so far in this batch, used as the estimate for how
		// long the next one might take.
		$longest = 0;

		foreach ( $rows as $row ) {
			$elapsed = time() - $start_time;

			// Require room for another task of the worst length seen so far,
			// not merely for the budget to be unspent. Checking only "elapsed
			// > budget" let a 37s task start at 44s and run the request to
			// 81s - past the very proxy timeout this is meant to stay under.
			if ( $elapsed + $longest > $budget || $elapsed > $budget ) {
				$wpdb->update( $table, array( 'status' => 'queued' ), array( 'id' => $row->id ) );
				self::log_event( $row->id, 'Batch budget reached', "Returned to the queue after {$elapsed}s of a {$budget}s budget; will run on the next pass." );
				continue;
			}

			$task_started = time();

			self::log_event( $row->id, 'Execution started', 'Agent claimed task.' );

			$payload = json_decode( (string) $row->payload, true ) ?: array();
			$attempts = (int) $row->attempts + 1;

			try {
				$res = self::dispatch( $row->task_type, $payload, $row->id );

				if ( is_wp_error($res) ) {
					self::handle_failure( $row, $res->get_error_message() );
					self::log_event( $row->id, 'Execution failed', $res->get_error_message() );
					$results[] = array( 'id' => $row->id, 'task' => $row->task_type, 'ok' => false, 'message' => $res->get_error_message() );
				} else {
					$wpdb->update( $table, array(
						'status'   => 'done',
						'attempts' => $attempts,
						'result'   => wp_json_encode( $res ),
						// Was site-local here while the claim above wrote UTC into
						// the same column - 5.5 hours apart on an IST site, which
						// made every ran_at window query wrong in one direction
						// or the other depending on which write landed last.
						'ran_at'   => current_time( 'mysql', true ),
					), array( 'id' => $row->id ) );
					$log->info( 'task_runner', "Task Success: {$row->task_type} (#{$row->id})." );
					self::log_event( $row->id, 'Task completed', 'Operation successful.' );
					$results[] = array( 'id' => $row->id, 'task' => $row->task_type, 'ok' => true, 'message' => 'Operation successful.' );
				}
			} catch ( \Throwable $e ) {
				self::handle_failure( $row, $e->getMessage() );
				$log->error( 'task_runner', "Task Exception in {$row->task_type}: " . $e->getMessage() );
				self::log_event( $row->id, 'Execution exception', $e->getMessage() );
				$results[] = array( 'id' => $row->id, 'task' => $row->task_type, 'ok' => false, 'message' => $e->getMessage() );
			}

			$longest = max( $longest, time() - $task_started );
			$ran++;
		}

		return array( 'ran' => $ran, 'results' => $results );
	}

	/**
	 * Handle task failure with Exponential Backoff logic.
	 */
	/**
	 * Return tasks that have been 'running' longer than any single request
	 * could legitimately take. Uses the row's own attempt count, so a task
	 * that repeatedly strands the request eventually lands in 'failed'
	 * instead of cycling forever.
	 */
	private static function reclaim_stalled( $stale_minutes = 30 ) {
		global $wpdb;
		$table = self::table();

		$stalled = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, attempts, max_attempts FROM {$table}
			 WHERE status = 'running' AND ran_at IS NOT NULL
			   AND ran_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
			(int) $stale_minutes
		) );
		if ( ! $stalled ) {
			return 0;
		}

		foreach ( $stalled as $row ) {
			$attempts = (int) $row->attempts + 1;
			$max      = (int) $row->max_attempts ?: 3;
			$give_up  = $attempts >= $max;

			$wpdb->update(
				$table,
				array(
					'status'     => $give_up ? 'failed' : 'queued',
					'attempts'   => $attempts,
					'last_error' => 'Stalled while running - the request died before the task reported back.',
				),
				array( 'id' => (int) $row->id, 'status' => 'running' )
			);
			self::log_event(
				(int) $row->id,
				$give_up ? 'Abandoned after stalling' : 'Reclaimed after stalling',
				"No result recorded within {$stale_minutes} minutes; attempt {$attempts} of {$max}."
			);
		}

		( new VMSB_Logger() )->warn( 'task_runner', 'Reclaimed ' . count( $stalled ) . ' stalled task(s).' );
		return count( $stalled );
	}

	private static function handle_failure( $row, $error_msg ) {
		global $wpdb;
		$attempts = (int) $row->attempts + 1;
		$max = (int) $row->max_attempts;

		if ( $attempts < $max ) {
			// Backoff: 10 mins, 1 hour, 4 hours
			$backoff_intervals = array( 600, 3600, 14400 );
			$delay = $backoff_intervals[$attempts - 1] ?? 86400;
			$retry_after = gmdate( 'Y-m-d H:i:s', time() + $delay );

			$wpdb->update( self::table(), array(
				'status'      => 'retrying',
				'attempts'    => $attempts,
				'retry_after' => $retry_after,
				'last_error'  => $error_msg,
			), array( 'id' => $row->id ) );
		} else {
			$wpdb->update( self::table(), array(
				'status'     => 'failed',
				'attempts'   => $attempts,
				'last_error' => $error_msg,
			), array( 'id' => $row->id ) );
		}
	}

	private static function dispatch( $task_type, array $payload, $task_id ) {
		// All handlers should eventually use the task_id for logging/rollback association
		switch ( $task_type ) {
			/* -------------------------------------------------- scheduled work
			 * The daily and weekly crons used to run all of this inline, in one
			 * request, with no time check. They queue it now, so each of these
			 * needs a case here or it fails with "Unknown task type".
			 */
			case 'link_index':
				if ( ! class_exists( 'VMSB_Link_Index' ) ) {
					return new WP_Error( 'vmsb_task', 'Link index unavailable.' );
				}

				$size    = max( 10, (int) ( $payload['limit'] ?? 60 ) );
				$pass    = (int) ( $payload['pass'] ?? 1 );
				$started = time();
				$total   = 0;
				$res     = array( 'remaining' => 0 );

				// Keep reading while there is both work left and time to do it
				// in. The runner's own budget only decides whether to *start* a
				// task, so a task that could run for an hour has to bound
				// itself. 20 seconds leaves room inside every budget the
				// runner sets, attended or not.
				do {
					$res     = VMSB_Link_Index::scan_batch( $size );
					$total  += (int) $res['scanned'];
				} while ( ! empty( $res['remaining'] ) && ( time() - $started ) < 20 && $res['scanned'] > 0 );

				// Hand the remainder to a fresh task. The pass counter matters:
				// queue() de-duplicates on task_type plus payload, and this row
				// is still 'running' with this exact payload, so an identical
				// re-queue would match itself and quietly do nothing - the
				// index would then stall until the next daily cron.
				if ( ! empty( $res['remaining'] ) ) {
					self::queue(
						'link_index',
						array( 'limit' => $size, 'pass' => $pass + 1 ),
						94,
						"Indexing internal links ({$res['remaining']} posts remaining)."
					);
				}

				return array( 'scanned' => $total, 'remaining' => (int) $res['remaining'], 'pass' => $pass );

			case 'vector_index':
				if ( ! class_exists( 'VMSB_Vector_Store' ) || ! (int) VMSB_Settings::get( 'vector_enabled' ) ) {
					return array( 'skipped' => 'Vector store disabled.' );
				}
				return VMSB_Vector_Store::index_batch( (int) ( $payload['limit'] ?? 40 ) );

			case 'measure_outcomes':
				if ( ! class_exists( 'VMSB_Outcome_Ledger' ) ) {
					return new WP_Error( 'vmsb_task', 'Outcome ledger unavailable.' );
				}
				return VMSB_Outcome_Ledger::measure_due();

			case 'issue_scan':
				return array( 'found' => ( new VMSB_Fixer() )->scan( (int) ( $payload['limit'] ?? 100 ) ) );

			case 'traffic_forecast':
				return ( new VMSB_Forecaster() )->forecast();

			case 'sheet_push':
				$res = ( new VMSB_Content() )->push_to_sheet();
				return is_wp_error( $res ) ? $res : array( 'pushed' => $res );

			case 'keyword_research':
				return array( 'found' => ( new VMSB_Keywords() )->research( (int) ( $payload['count'] ?? 40 ) ) );

			case 'silo_rebuild':
				$map = ( new VMSB_Silo() )->generate_map( true );
				return array( 'silos' => count( $map['silos'] ?? array() ) );

			case 'content_plan':
				return array( 'planned' => ( new VMSB_Content() )->plan( (int) ( $payload['count'] ?? 20 ) ) );

			case 'competitor_scan':
				return ( new VMSB_Competitor() )->scan( (int) ( $payload['limit'] ?? 10 ) );

			case 'brain_decide':
				$keywords = new VMSB_Keywords();
				return ( new VMSB_Brain() )->decide(
					array(
						'issues'   => ( new VMSB_Fixer() )->counts(),
						'growth'   => ( new VMSB_Growth() )->status(),
						'keywords' => array( 'total' => $keywords->count(), 'new' => $keywords->count( 'new' ) ),
						'content'  => ( new VMSB_Content() )->stats(),
					)
				);

			case 'social_pack':
				$post_id = (int) ( $payload['post_id'] ?? 0 );
				if ( ! $post_id ) {
					return new WP_Error( 'vmsb_task', 'No post id supplied.' );
				}
				return ( new VMSB_Social_Recycler() )->generate_social_pack( $post_id );

			case 'link_post':
				// Post-publish linking, moved out of produce(). Doing it there
				// meant two extra model round trips and two content writes at
				// the end of the slowest function in the plugin.
				$post_id = (int) ( $payload['post_id'] ?? 0 );
				if ( ! $post_id || ! get_post( $post_id ) ) {
					return new WP_Error( 'vmsb_task', 'The post to link no longer exists.' );
				}
				$silo    = new VMSB_Silo();
				$written = 0;
				foreach ( $silo->semantic_targets( $post_id, (int) ( $payload['links'] ?? 2 ) ) as $target ) {
					$res = VMSB_Link_Inserter::insert( $post_id, (int) $target['ID'], array( 'reason' => 'Post-publish semantic mesh', 'task_id' => $task_id ) );
					if ( ! is_wp_error( $res ) ) {
						$written++;
					}
				}
				return array( 'linked' => $written );

			case 'aeo_sweep':        return ( new VMSB_AEO() )->sweep( 3 );
			case 'entity_sweep':     return ( new VMSB_Entity() )->sweep( 3 );
			case 'schema_sweep':
				// FAQ/Article backfill, then entity types (place, event,
				// how-to) for anything the first pass cannot describe.
				$faq    = ( new VMSB_Schema() )->sweep( 5 );
				$entity = ( new VMSB_Schema_Entity() )->sweep( 10 );
				return array( 'schema' => $faq, 'entity' => $entity );
			case 'roi_scan':         return ( new VMSB_ROI() )->find_leaks( 15 );
			case 'roi_sweep':        return ( new VMSB_ROI() )->sweep( 3 );
			case 'ctr_conclude':     return ( new VMSB_CTR() )->conclude_due();
			case 'backlink_shield':  return ( new VMSB_Backlinks() )->shield_scan( 20 );
			case 'weekly_roadmap':   return ( new VMSB_Commander() )->execute( '/report' );
			case 'market_assess':    return ( new VMSB_Market() )->assess();
			case 'improvement_loop': return ( new VMSB_Healer() )->heal_losses();
			case 'graph_sync':       VMSB_Graph::build_twin(); return array( 'synced' => true );
			case 'thief_scout':      return ( new VMSB_Thief() )->steal();
			case 'link_rebalance':   return ( new VMSB_Link_Flow() )->rebalance();
			case 'content_duel':     return ( new VMSB_Duel() )->duel_random();
			case 'self_heal':        return ( new VMSB_Healer() )->heal_losses();
			case 'auto_fix_queue':
				// Re-check the kill switch at execution time, not just when the
				// scheduler queued this at 03:00. God Mode can be turned off
				// between those two moments - by hand, or by the health circuit
				// breaker, which disables it precisely because the AI chain has
				// started failing. Without this the breaker was advisory only:
				// the already-queued pass still ran, letting an unreliable AI
				// make unattended edits to live posts, which is the exact thing
				// the breaker exists to stop. god_fix() itself is deliberately
				// left ungated - the REST route behind the manual "Run God Fix"
				// button is an explicit human action and must still work with
				// God Mode off.
				if ( ! (int) VMSB_Settings::get( 'god_mode' ) ) {
					return array( 'fixed' => 0, 'skipped' => true, 'message' => 'God Mode is off - skipping the queued fix pass.' );
				}
				return ( new VMSB_Fixer() )->god_fix( 10 );
			case 'monitor_decay':    return ( new VMSB_Decay() )->monitor( 10 );
			case 'news_scout':       return ( new VMSB_News() )->scout( 5 );
			case 'social_recycle':   return ( new VMSB_Social_Recycler() )->process_recent( 5 );
			case 'link_autopilot':   return ( new VMSB_Internal_Link_Autopilot() )->funnel_authority( 10 );
			case 'hydrate_pipeline': return ( new VMSB_Content() )->hydrate_pipeline( 10 );
			case 'sheet_sync':       return ( new VMSB_Content() )->sync_external_publications();
			case 'content_defense':  return ( new VMSB_Healer() )->rescue_dropping_assets( 2 );
			case 'semantic_mesh':    return ( new VMSB_Silo() )->build_semantic_mesh( 5 );
			case 'video_pipeline':   return ( new VMSB_Video_Agent() )->sweep( 2 );
			case 'freshness_boost':  return ( new VMSB_Freshness() )->run( 2 );
			case 'opportunity_scan': return ( new VMSB_Opportunity_Engine() )->discover_all();
			// growth_scan is in the Strategist's catalogue, has its own
			// auto_growth_mode toggle, label, explanation and scheduling
			// rule - but never had a case here, so every run it queued
			// failed with "Unknown task type: growth_scan".
			case 'growth_scan':      return ( new VMSB_Growth_Engine() )->scan( 15 );

			// The five below sat in the Strategist's catalogue - each with a
			// score, a toggle, a label and a description - but had no case
			// here, so every cycle that queued one produced a task that could
			// only fail with "Unknown task type". Same defect growth_scan had.
			//
			// Two of them also stamp an option the Strategist reads to decide
			// when to run again. Nothing wrote those options, so the throttles
			// never engaged and the agents were eligible on every pass.

			case 'trend_scout':
				// trend_scout and news_scout are one agent under two names: the
				// catalogue calls it trend_scout and gates it on news_enabled,
				// while the only implementation was registered as news_scout
				// and never appeared in the catalogue at all - so neither name
				// could actually run a scheduled scan. Both now reach it.
			case 'news_scout':
				$res = ( new VMSB_News() )->scout( 5 );
				update_option( 'vmsb_last_trend_scout', time(), false );
				return $res;

			case 'battle_roadmap':
				// Drawn against the *active phase*, not the site-wide setting,
				// so phase 2's roadmap aims at phase 2's number.
				$goal_phase = ( new VMSB_Goal() )->current();
				return ( new VMSB_Roadmap() )->generate_plan(
					$goal_phase ? (int) $goal_phase['target'] : (int) VMSB_Settings::get( 'growth_target', 50000 ),
					$goal_phase ? (int) $goal_phase['window'] : (int) VMSB_Settings::get( 'growth_window', 50 )
				);

			case 'roadmap_execute':
				return ( new VMSB_Roadmap() )->execute_today();

			case 'goal_review':
				$goal      = new VMSB_Goal();
				$advance   = $goal->review();
				$directive = $goal->directive();
				$applied   = $goal->apply( $directive );

				return array(
					'phase'     => $directive['phase'] ?? null,
					'posture'   => $directive['posture'] ?? 'none',
					'advanced'  => (bool) $advance['advanced'],
					'reason'    => $advance['reason'],
					'pace'      => $applied,
					'note'      => $directive['note'] ?? '',
				);

			case 'silo_integrity':
				// Suggestions, not approved rows. push_gaps_to_plan() defaults
				// to writing 'approved', which would let an autonomous agent
				// queue work straight past the review gate this site runs on.
				$res = ( new VMSB_Silo() )->push_gaps_to_plan( true );
				update_option( 'vmsb_last_silo_integrity', time(), false );
				return $res;

			case 'niche_expansion':
				$res = ( new VMSB_Niche_Planner() )->plan_expansion( 20 );
				update_option( 'vmsb_last_niche_expansion', time(), false );
				return $res;

			case 'competitor_blitz':
				$res = ( new VMSB_Thief() )->blitz( 3 );
				update_option( 'vmsb_last_competitor_blitz', time(), false );
				return $res;

			case 'produce_post':
				$id = (int) ( $payload['id'] ?? 0 );
				if ( ! $id ) return new WP_Error( 'vmsb_task', 'No plan id supplied.' );
				return ( new VMSB_Content() )->produce( $id );

			default:
				return new WP_Error( 'vmsb_task', "Unknown task type: {$task_type}" );
		}
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE status IN ('queued', 'retrying')" );
	}

	public static function recent( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ) );
	}

	public static function prune( $days = 14 ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			// UTC_TIMESTAMP(), not NOW(): queued_at is stored in UTC, while
			// NOW() follows the MySQL server clock.
			"DELETE FROM " . self::table() . " WHERE status != 'queued' AND queued_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)", (int) $days
		) );
	}
}
