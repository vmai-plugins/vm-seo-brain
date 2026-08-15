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
	 * Process a batch of tasks.
	 */
	public static function process( $limit = 3 ) {
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
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running', ran_at = %s WHERE id IN ({$placeholders})", $now, $ids ) );
		}

		$wpdb->query( 'COMMIT' );

		if ( ! $rows ) return array( 'ran' => 0, 'results' => array() );

		$log = new VMSB_Logger();
		$ran = 0;
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
				}
			} catch ( \Throwable $e ) {
				self::handle_failure( $row, $e->getMessage() );
				$log->error( 'task_runner', "Task Exception in {$row->task_type}: " . $e->getMessage() );
				self::log_event( $row->id, 'Execution exception', $e->getMessage() );
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
			case 'aeo_sweep':        return ( new VMSB_AEO() )->sweep( 3 );
			case 'entity_sweep':     return ( new VMSB_Entity() )->sweep( 3 );
			case 'schema_sweep':     return ( new VMSB_Schema() )->sweep( 5 );
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
			case 'auto_fix_queue':   return ( new VMSB_Fixer() )->god_fix( 10 );
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
