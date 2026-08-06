<?php
defined( 'ABSPATH' ) || exit;

/**
 * Trend Scout 2026: The High-Velocity Growth Engine.
 *
 * Unlike traditional SEO, Trend Scout targets "Viral Gaps" in Google Discover
 * and Search by monitoring live news RSS feeds and analyzing them against
 * your site's Topical Authority.
 */
class VMSB_News {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	public function is_enabled() {
		return (int) VMSB_Settings::get( 'news_enabled' ) || (int) VMSB_Settings::get( 'god_mode' );
	}

	/**
	 * Main Scout Entry Point.
	 * Fetches actual news signals, filters for relevance, and builds briefs.
	 */
	public function scout( $count = 5 ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'vmsb_news', 'Trend Scout is not active. Enable it in Settings > God Mode.' );
		}

		$brain   = new VMSB_Brain();
		$profile = $brain->profile();

		if ( empty($profile['type']) ) {
			return new WP_Error( 'vmsb_news', 'Business DNA missing. Re-calibrate DNA on Dashboard first.' );
		}

		// 1. Fetch live signals from Google News RSS for the niche
		$signals = $this->fetch_live_signals( $profile['type'] . ' ' . $profile['name'] );

		if ( empty( $signals ) ) {
			return array( 'proposed' => 0, 'message' => 'No fresh signals detected in your niche today.' );
		}

		// 2. Ask AI to analyze signals and select the "Rising Stars"
		$prompt = "Act as a Content Velocity Strategist. I have collected live news signals for our niche.\n\n"
			. "SIGNALS:\n" . implode( "\n", array_slice( $signals, 0, 15 ) ) . "\n\n"
			. "TASK: Select the top {$count} signals that are most likely to trend in Google Discover for our business.\n"
			. "For each, create a high-velocity article plan.\n\n"
			. "Rules:\n"
			. "- Focus on 'Breaking' or 'Contrarian' angles.\n"
			. "- Do not repeat topics we already cover.\n"
			. "- Prioritize topics with high emotional or practical utility.\n\n"
			. 'Return JSON: {"proposals":[{"title":"","source_url":"","why_trending":"","brief":"","target_words":1400,"priority":25}]}';

		$data = $this->ai->generate_json( $prompt, array(
			'system' => $brain->context_prompt(),
			'complexity' => 'premium',
			'persona' => 'strategist'
		) );

		if ( ! is_array( $data ) || empty( $data['proposals'] ) ) {
			return array( 'proposed' => 0 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_plan';
		$queued = 0;

		foreach ( $data['proposals'] as $p ) {
			if ( empty( $p['title'] ) ) continue;

			$uid = substr( md5( $p['title'] . '|trend' ), 0, 24 );

			// Check if already exists
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE row_uid = %s", $uid ) );
			if ( $exists ) continue;

			$wpdb->insert( $table, array(
				'row_uid'         => $uid,
				'title'           => "[TREND] " . $p['title'],
				'primary_keyword' => $p['title'], // Trends use long-tail headlines
				'cluster'         => 'trending',
				'intent'          => 'informational',
				'status'          => 'planned',
				'priority'        => (float) $p['priority'],
				'brief'           => "TREND ANALYSIS: " . ( $p['why_trending'] ?? '' ) . "\n\nBRIEF: " . $p['brief'],
				'target_words'    => (int) $p['target_words'],
				'created_at'      => current_time( 'mysql' ),
			) );
			$queued++;
		}

		$this->log->info( 'news', "Trend Scout identified {$queued} viral opportunities." );
		return array( 'proposed' => $queued );
	}

	/**
	 * Fetches actual news headlines from Google News RSS.
	 * Advanced 2026 WordPress Dev Ops style: no heavy dependencies.
	 */
	private function fetch_live_signals( $query ) {
		$country = (string) VMSB_Settings::get( 'country', 'IN' );
		$url = "https://news.google.com/rss/search?q=" . urlencode( $query )
			. "&hl=" . rawurlencode( 'en-' . $country ) . "&gl=" . rawurlencode( $country ) . "&ceid=" . rawurlencode( $country . ':en' );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) return array();

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) return array();

		// Parse the RSS feed (Simple XML parser)
		$signals = array();
		if ( preg_match_all( '/<item>.*?<title>(.*?)<\/title>.*?<link>(.*?)<\/link>.*?<\/item>/is', $body, $matches ) ) {
			foreach ( $matches[1] as $idx => $title ) {
				$signals[] = "HEADLINE: " . html_entity_decode( $title ) . " (URL: " . $matches[2][$idx] . ")";
			}
		}

		return $signals;
	}
}
