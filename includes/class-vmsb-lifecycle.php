<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content Lifecycle Monitor (VM SEO Brain X).
 *
 * Classifies every piece of content into a lifecycle stage:
 * Growing, Stable, Declining, Decaying, Cannibalized, Underperforming.
 */
class VMSB_Lifecycle {

	private $google;
	private $log;

	public function __construct() {
		$this->google = new VMSB_Google();
		$this->log    = new VMSB_Logger();
	}

	/**
	 * Analyze and classify all active posts.
	 */
	public function audit_all_assets() {
		if ( ! $this->google->is_connected() ) return array();

		$data = $this->google->gsc_query( array( 'page' ), 28, 500 );
		if ( is_wp_error($data) ) return array();

		$report = array(
			'growing'         => array(),
			'stable'          => array(),
			'declining'       => array(),
			'decaying'        => array(),
			'underperforming' => array(),
		);

		foreach ( $data as $row ) {
			$url = $row['keys'][0];
			$post_id = url_to_postid( $url );
			if ( ! $post_id ) continue;

			$stage = $this->classify_stage( $row, $url );
			$report[$stage][] = $post_id;

			// Store stage in postmeta for quick dashboard retrieval
			update_post_meta( $post_id, '_vmsb_lifecycle_stage', $stage );
		}

		update_option( 'vmsb_lifecycle_report', array(
			'stats' => array_map('count', $report),
			'updated_at' => current_time('mysql')
		) );

		return $report;
	}

	private function classify_stage( $row, $url ) {
		$clicks = (int) $row['clicks'];
		$prev_metrics = $this->google->gsc_page_metrics( $url, 28, 29 );
		$prev_clicks = (int) ($prev_metrics['clicks'] ?? 0);

		// 1. Growing: +20% clicks
		if ( $prev_clicks > 0 && ($clicks / $prev_clicks) > 1.2 ) {
			return 'growing';
		}

		// 2. Declining: -15% clicks
		if ( $prev_clicks > 0 && ($clicks / $prev_clicks) < 0.85 && ($clicks / $prev_clicks) >= 0.7 ) {
			return 'declining';
		}

		// 3. Decaying: -30% clicks
		if ( $prev_clicks > 0 && ($clicks / $prev_clicks) < 0.7 ) {
			return 'decaying';
		}

		// 4. Underperforming: High impressions, low clicks (CTR < 1%)
		if ( (int)$row['impressions'] > 500 && ( (float)$row['ctr'] < 0.01 ) ) {
			return 'underperforming';
		}

		// 5. Stable: Within 15% variance
		return 'stable';
	}

	public static function get_stats() {
		return get_option( 'vmsb_lifecycle_report', array( 'stats' => array(), 'updated_at' => null ) );
	}
}
