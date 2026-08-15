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
			'queued_at'    => current_time( 'mysql' ),
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

		foreach ( $rows as $row ) {
			// SAFETY: Timeout protection (batch cap 25s)
			if ( time() - $start_time > 25 ) {
				$wpdb->update( $table, array( 'status' => 'queued' ), array( 'id' => $row->id ) );
				self::log_event( $row->id, 'Batch timeout', 'Task returned to queue for next pass.' );
				continue;
			}

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
						'ran_at'   => current_time( 'mysql' ),
					), array( 'id' => $row->id ) );
					$log->info( 'task_runner', "Task Success: {$row->task_type} (#{$row->id})." );
					self::log_event( $row->id, 'Task completed', 'Operation successful.' );
				}
			} catch ( \Throwable $e ) {
				self::handle_failure( $row, $e->getMessage() );
				$log->error( 'task_runner', "Task Exception in {$row->task_type}: " . $e->getMessage() );
				self::log_event( $row->id, 'Execution exception', $e->getMessage() );
			}

			$ran++;
		}

		return array( 'ran' => $ran, 'results' => $results );
	}

	/**
	 * Handle task failure with Exponential Backoff logic.
	 */
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
}
