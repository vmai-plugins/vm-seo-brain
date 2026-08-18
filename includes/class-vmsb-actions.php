<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Universal Action Log.
 * Ensures every autonomous update is explainable, measurable, and reversible.
 */
class VMSB_Actions {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_actions';
	}

	/**
	 * Record an action.
	 */
	public static function record( $data ) {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'task_id'         => $data['task_id'] ?? null,
				'object_type'     => $data['object_type'],
				'object_id'       => $data['object_id'],
				'action_type'     => $data['action_type'],
				'before_value'    => is_scalar($data['before']) ? $data['before'] : wp_json_encode($data['before']),
				'after_value'     => is_scalar($data['after']) ? $data['after'] : wp_json_encode($data['after']),
				'reason'          => $data['reason'] ?? '',
				'model'           => $data['model'] ?? '',
				'tokens_in'       => $data['tokens_in'] ?? 0,
				'tokens_out'      => $data['tokens_out'] ?? 0,
				'cost'            => $data['cost'] ?? 0,
				'created_at'      => current_time( 'mysql', true ),
			)
		);

		return $wpdb->insert_id;
	}

	/**
	 * Bulk Rollback by Tactical Batch.
	 */
	public static function rollback_batch( $task_id ) {
		global $wpdb;
		$actions = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM " . self::table() . " WHERE task_id = %d", $task_id ) );

		$count = 0;
		foreach ( $actions as $a ) {
			$res = self::rollback( $a->id );
			if ( ! is_wp_error($res) ) $count++;
		}
		return $count;
	}

	/**
	 * Get the strategic "Explainability" log for an action.
	 */
	public static function get_explanation( $action_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT reason, model, cost FROM " . self::table() . " WHERE id = %d", $action_id ) );
		return $row ? array(
			'why'   => $row->reason,
			'brain' => "Calculated by {$row->model} (Cost: {$row->cost})",
		) : null;
	}

	/**
	 * Undo a logged action by restoring its before_value.
	 */
	public static function rollback( $action_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id = %d", $action_id ) );

		if ( ! $row ) return new WP_Error( 'vmsb_action', 'Action not found.' );
		if ( $row->rollback_status === 'completed' ) return new WP_Error( 'vmsb_action', 'Already rolled back.' );

		$before = json_decode($row->before_value, true) ?: $row->before_value;
		$ok = false;

		switch ( $row->action_type ) {
			case 'update_content':
			case 'improve_post':
			case 'rescue_post':
			case 'semantic_link':
			case 'internal_link':
			case 'tactical_injection':
			case 'consolidate_content':
				$ok = wp_update_post( array( 'ID' => $row->object_id, 'post_content' => $before ) );
				// The link index is derived from content, so undoing a content
				// change has to undo the edges that change created.
				if ( $ok && class_exists( 'VMSB_Link_Index' ) ) {
					VMSB_Link_Index::scan_post( (int) $row->object_id );
				}
				break;

			case 'update_meta':
				$meta = is_array($before) ? $before : array();
				foreach ( $meta as $key => $val ) {
					update_post_meta( $row->object_id, $key, $val );
				}
				$ok = true;
				break;

			case 'strategic_pivot':
				$brain = new VMSB_Brain();
				$brain->remember( 'intelligence', 'current_strategy_pivot', $before, 1.0, 'rollback' );
				$ok = true;
				break;
		}

		if ( $ok ) {
			$wpdb->update( self::table(), array( 'rollback_status' => 'completed' ), array( 'id' => $action_id ) );
			return true;
		}

		return new WP_Error( 'vmsb_action', 'Rollback failed for type: ' . $row->action_type );
	}
}
