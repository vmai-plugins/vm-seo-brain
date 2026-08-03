<?php
defined( 'ABSPATH' ) || exit;

/**
 * Task runner.
 *
 * A minimal queue: the strategist scores and files tasks here once a day,
 * and the hourly cron drains a small batch. This is what turns "ten AI-heavy
 * operations in one daily request" into "two or three per hour, ordered by
 * what's actually worth doing" - real robustness on shared hosting where a
 * single slow request is a timeout waiting to happen.
 *
 * Deliberately not a generic background-job system - it dispatches exactly
 * the fixed set of task types the strategist knows about.
 */
class VMSB_Task_Runner {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_tasks';
	}

	public static function queue( $task_type, array $payload = array(), $score = 0, $reason = '' ) {
		global $wpdb;

		// Don't double-queue the same task type while one is still pending -
		// the strategist re-evaluates and re-queues fresh each day anyway.
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM " . self::table() . " WHERE task_type = %s AND status = 'queued'", $task_type
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert( self::table(), array(
			'task_type' => $task_type,
			'payload'   => wp_json_encode( $payload ),
			'score'     => (float) $score,
			'reason'    => mb_substr( $reason, 0, 250 ),
			'status'    => 'queued',
			'queued_at' => current_time( 'mysql' ),
		) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Drain up to $limit queued tasks, highest score first.
	 *
	 * @return array{ran:int,results:array}
	 */
	public static function process( $limit = 3 ) {
		global $wpdb;
		$table = self::table();

		// Claim rows atomically before dispatching them: without this, two
		// overlapping cron requests (a real risk with external cron triggers,
		// or a manual "run now" overlapping the scheduled hourly hit) can both
		// SELECT the same queued rows and dispatch every one of them twice.
		$wpdb->query( 'START TRANSACTION' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'queued' ORDER BY score DESC LIMIT %d FOR UPDATE", (int) $limit
		) );
		if ( $rows ) {
			$ids          = wp_list_pluck( $rows, 'id' );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'running' WHERE id IN ({$placeholders})", $ids ) );
		}
		$wpdb->query( 'COMMIT' );

		$log     = new VMSB_Logger();
		$results = array();
		$start_time = time();
		$ran     = 0;

		$remaining_ids = wp_list_pluck( $rows, 'id' );

		foreach ( $rows as $row ) {
			// Anti-timeout safety: 20s per batch
			if ( time() - $start_time > 20 ) {
				$log->warn( 'task_runner', 'Batch processing paused to prevent timeout.' );
				break;
			}

			$payload = json_decode( (string) $row->payload, true ) ?: array();

			// A single handler throwing (or fataling, via \Error) must not take
			// down the whole cron request - every other claimed row would be
			// left permanently stuck in 'running' with nothing to ever revisit
			// it, since process() only ever selects status='queued'.
			try {
				$result = self::dispatch( $row->task_type, $payload );
			} catch ( \Throwable $e ) {
				$result = new WP_Error( 'vmsb_task_exception', $e->getMessage() );
				$log->error( 'task_runner', "{$row->task_type} threw: " . $e->getMessage() );
			}

			$wpdb->update( self::table(), array(
				'status' => is_wp_error( $result ) ? 'failed' : 'done',
				'result' => wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result ),
				'ran_at' => current_time( 'mysql' ),
			), array( 'id' => $row->id ) );

			$remaining_ids = array_diff( $remaining_ids, array( $row->id ) );

			$log->info( 'task_runner', "Ran {$row->task_type}.", array( 'reason' => $row->reason, 'ok' => ! is_wp_error( $result ) ) );
			$results[ $row->task_type ] = is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result;
			$ran++;
		}

		// Anything claimed but not reached (the time-cutoff break above) would
		// otherwise sit in 'running' forever - hand it back to the queue.
		if ( $remaining_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $remaining_ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'queued' WHERE id IN ({$placeholders})", $remaining_ids ) );
		}

		return array( 'ran' => $ran, 'results' => $results );
	}

	/**
	 * The fixed dispatch table. Every task type the strategist can propose
	 * must have an entry here, or it will queue and never run.
	 */
	private static function dispatch( $task_type, array $payload ) {
		switch ( $task_type ) {
			case 'aeo_sweep':
				return ( new VMSB_AEO() )->sweep( 3 );

			case 'entity_sweep':
				return ( new VMSB_Entity() )->sweep( 3 );

			case 'schema_sweep':
				return ( new VMSB_Schema() )->sweep( 5 );

			case 'roi_scan':
				return ( new VMSB_ROI() )->find_leaks( 15 );

			case 'roi_sweep':
				return (int) VMSB_Settings::get( 'god_mode' ) ? ( new VMSB_ROI() )->sweep( 3 ) : array( 'skipped' => 'God Mode is off' );

			case 'ctr_conclude':
				return ( new VMSB_CTR() )->conclude_due();

			case 'backlink_shield':
				return ( new VMSB_Backlinks() )->shield_scan( 20 );

			case 'weekly_roadmap':
				update_option( 'vmsb_last_roadmap', time() );
				return ( new VMSB_Commander() )->execute( '/report' );

			case 'improvement_loop':
				return ( new VMSB_Market() )->assess();

			case 'graph_sync':
				VMSB_Graph::build_twin();
				update_option( 'vmsb_last_graph_sync', time() );
				return array( 'synced' => true );

			case 'thief_scout':
				return ( new VMSB_Thief() )->steal();

			case 'competitor_blitz':
				return ( new VMSB_Thief() )->blitz();

			case 'link_rebalance':
				return ( new VMSB_Link_Flow() )->rebalance();

			case 'battle_roadmap':
				return ( new VMSB_Roadmap() )->generate_plan();

			case 'content_duel':
				// Duel a random striking distance post
				$star = ( new VMSB_Keywords() )->striking_distance( 1 );
				return $star ? ( new VMSB_Duel() )->duel( $star[0]->post_id ) : array( 'skipped' => 'no striking distance posts' );

			case 'self_heal':
				return ( new VMSB_Healer() )->heal_losses();

			case 'auto_fix_queue':
				return ( new VMSB_Fixer() )->god_fix( 10 );

			case 'silo_integrity':
				update_option( 'vmsb_last_silo_integrity', time() );
				return ( new VMSB_Silo() )->analyze_and_fix_weakest();

			case 'niche_expansion':
				update_option( 'vmsb_last_niche_expansion', time() );
				return ( new VMSB_Niche_Planner() )->plan_expansion( 15 );

			case 'monitor_decay':
				return ( new VMSB_Decay() )->monitor( 10 );

			case 'news_scout':
			case 'trend_scout':
				return ( new VMSB_News() )->scout( 5 );

			case 'social_recycle':
				return ( new VMSB_Social_Recycler() )->process_recent( 5 );

			case 'link_autopilot':
				return ( new VMSB_Internal_Link_Autopilot() )->funnel_authority( 10 );

			case 'hydrate_pipeline':
				return ( new VMSB_Content() )->hydrate_pipeline( 10 );

			case 'sheet_sync':
				return ( new VMSB_Content() )->sync_external_publications();

			default:
				return new WP_Error( 'vmsb_task', "Unknown task type: {$task_type}" );
		}
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::table() . " WHERE status = 'queued'" );
	}

	public static function recent( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', (int) $limit ) );
	}

	public static function prune( $days = 14 ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table() . " WHERE status != 'queued' AND queued_at < DATE_SUB(NOW(), INTERVAL %d DAY)", (int) $days
		) );
	}
}
