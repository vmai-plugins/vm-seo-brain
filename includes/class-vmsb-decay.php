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
	 * Advanced 2026: Dual-Window detection (Standard & Rapid).
	 */
	public function monitor( $limit = 10 ) {
		if ( ! $this->google->is_connected() ) {
			return 0;
		}

		// Pull traffic data for comparison
		$current = $this->google->gsc_query( array( 'page' ), 30, 500 );
		if ( is_wp_error( $current ) ) return 0;

		$found = 0;
		foreach ( $current as $row ) {
			if ( $found >= $limit ) break;

			$url = $row['keys'][0];
			$post_id = url_to_postid( $url );
			if ( ! $post_id ) continue;

			// Skip if already has an open issue or is a system page
			if ( $this->is_excluded( $post_id ) ) continue;

			$clicks_now = (int) $row['clicks'];
			$imp_now    = (int) $row['impressions'];

			// 1. Rapid Decay Check (Last 7 days vs previous 7 days)
			$rapid_metrics = $this->google->gsc_page_metrics( $url, 7, 0 );
			$prev_7_metrics = $this->google->gsc_page_metrics( $url, 7, 8 );

			if ( ! is_wp_error( $rapid_metrics ) && ! is_wp_error( $prev_7_metrics ) && ! empty($prev_7_metrics) && $prev_7_metrics['clicks'] > 10 ) {
				if ( ($rapid_metrics['clicks'] / $prev_7_metrics['clicks']) < 0.5 ) {
					( new VMSB_Fixer() )->record( 'post', $post_id, 'rapid_decay', 'critical', 'Critical: This page lost 50%+ of its traffic in the last 7 days.' );
					$found++;
					continue;
				}
			}

			// 2. Standard Decay Check (30 days)
			if ( $clicks_now < 30 ) continue; // Ignore very low traffic pages

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
