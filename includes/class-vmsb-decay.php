<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content Decay Monitor.
 * Uses GSC data to find pages losing traffic and auto-queues them for refresh.
 */
class VMSB_Decay {

	private $google;
	private $log;

	public function __construct() {
		$this->google = new VMSB_Google();
		$this->log    = new VMSB_Logger();
	}

	/**
	 * Scan for decaying content.
	 * Advanced 2026: Multi-Signal detection including Predictive Velocity and Semantic Staleness.
	 */
	public function monitor( $limit = 10, $budget = 40 ) {
		if ( ! $this->google->is_connected() ) {
			return 0;
		}

		// Pull traffic data for comparison (last 28 days)
		$current = $this->google->gsc_query( array( 'page', 'query' ), 28, 500 );
		if ( is_wp_error( $current ) ) return 0;

		$found = 0;
		$processed_urls = array();

		// $found only increments for a page that turns out to actually be
		// decaying, so on a healthy site (or one where a signal was already
		// silenced upstream) this loop ran to completion regardless of
		// $limit - up to 2-3 sequential wp_remote_* calls per row, on up to
		// 500 rows, with nothing bounding total wall-clock time.
		$deadline = time() + (int) $budget;

		foreach ( $current as $row ) {
			if ( $found >= $limit ) break;
			if ( time() >= $deadline ) {
				$this->log->warn( 'decay', "Decay monitor stopped at its {$budget}s budget after finding {$found}. Resumes from wherever the next scheduled run starts." );
				break;
			}

			$url = $row['keys'][0];
			if ( in_array($url, $processed_urls) ) continue;
			$processed_urls[] = $url;

			$post_id = url_to_postid( $url );
			if ( ! $post_id ) continue;

			// Skip if already has an open issue or is a system page
			if ( $this->is_excluded( $post_id ) ) continue;

			$clicks_now = (int) $row['clicks'];
			$imp_now    = (int) $row['impressions'];
			$pos_now    = (float) $row['position'];

			// 1. Semantic Staleness Check: Year-based outdating (e.g. "Best of 2024")
			$post = get_post($post_id);
			$prev_year = (int)date('Y') - 1;
			if ( stripos($post->post_title, (string)$prev_year) !== false ) {
				( new VMSB_Fixer() )->record( 'post', $post_id, 'semantic_stale', 'high', "Semantic Staleness: Title uses an outdated year ({$prev_year})." );
				$found++;
				continue;
			}

			// 2. CTR Anomaly: High reach, low engagement
			if ( $imp_now > 1000 && ($clicks_now / $imp_now) < 0.01 ) {
				( new VMSB_Fixer() )->record( 'post', $post_id, 'low_ctr_anomaly', 'medium', "CTR Anomaly: Page has high impressions ({$imp_now}) but <1% CTR. Snippet needs re-optimization." );
				$found++;
				continue;
			}

			// 3. Rapid Decay Check (Last 7 days vs previous 7 days)
			$rapid_metrics = $this->google->gsc_page_metrics( $url, 7, 0 );
			$prev_7_metrics = $this->google->gsc_page_metrics( $url, 7, 8 );

			if ( ! is_wp_error( $rapid_metrics ) && ! is_wp_error( $prev_7_metrics ) && ! empty($prev_7_metrics) && $prev_7_metrics['clicks'] > 10 ) {
				if ( ($rapid_metrics['clicks'] / $prev_7_metrics['clicks']) < 0.5 ) {
					( new VMSB_Fixer() )->record( 'post', $post_id, 'rapid_decay', 'critical', 'Critical: This page lost 50%+ of its traffic in the last 7 days.' );
					$found++;
					continue;
				}
			}

			// 4. Standard Decay Check (30 days)
			if ( $clicks_now < 30 ) continue;

			$prev_metrics = $this->google->gsc_page_metrics( $url, 30, 31 );
			if ( empty( $prev_metrics ) || is_wp_error( $prev_metrics ) || (int)$prev_metrics['clicks'] === 0 ) continue;

			$clicks_prev = (int) $prev_metrics['clicks'];

			if ( ( $clicks_now / $clicks_prev ) < 0.8 ) {
				$drop_pct = round( ( 1 - ( $clicks_now / $clicks_prev ) ) * 100 );
				( new VMSB_Fixer() )->record( 'post', $post_id, 'content_decay', 'high', "Traffic decay detected: -{$drop_pct}% clicks in the last 30 days." );
				$found++;
			}
		}

		return $found;
	}

	private function is_excluded( $post_id ) {
		global $wpdb;
		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );
		if ( $post_id === $front || $post_id === $blog ) return true;

		$table = $wpdb->prefix . 'vmsb_issues';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_id = %d AND rule = 'content_decay' AND status = 'open'", $post_id ) );
		return (bool) $exists;
	}
}
