<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Trends & Signal Intelligence.
 *
 * Advanced 2026 Strategy: Headless trend monitoring via RSS and Velocity signals.
 */
class VMSB_Trends {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Get trending topics for the configured niche and location.
	 */
	public function get_rising_signals( $limit = 10 ) {
		$country = VMSB_Settings::get( 'country', 'IN' );
		$niche   = VMSB_Settings::get( 'business_type' );

		// 1. Google Trends RSS for the region
		$url = "https://trends.google.com/trends/trendingsearches/daily/rss?geo=" . rawurlencode( $country );

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) return array();

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) return array();

		$trends = array();
		if ( preg_match_all( '/<title>(.*?)<\/title>.*?<ht:approx_traffic>(.*?)<\/ht:approx_traffic>/is', $body, $matches ) ) {
			foreach ( $matches[1] as $idx => $title ) {
				if ( $idx === 0 ) continue; // Skip channel title
				$trends[] = array(
					'topic' => html_entity_decode( $title ),
					'traffic' => $matches[2][$idx] ?? 'N/A'
				);
			}
		}

		// 2. Filter via Brain for Niche Relevance
		if ( empty( $trends ) ) return array();

		$prompt = "Act as a Niche Intelligence Agent. I have a list of trending topics in {$country}.\n"
			. "NICHE: {$niche}\n"
			. "TRENDS:\n" . wp_json_encode( $trends ) . "\n\n"
			. "TASK: Identify which trends are RELEVANT to our niche or can be 'News-Jacked' (connected logically to our business).\n"
			. "Return ONLY relevant topics as a JSON array of strings: [\"topic1\", \"topic2\"]";

		$relevant = $this->ai->generate_json( $prompt, array( 'max_tokens' => 300 ) );

		return is_array( $relevant ) ? array_slice( $relevant, 0, $limit ) : array();
	}

	/**
	 * Analyze "Velocity" of current keyword universe.
	 * Finds keywords with sharp impression growth in GSC.
	 */
	public function analyze_velocity() {
		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) return array();

		// Compare last 7 days vs previous 7 days
		$current = $google->gsc_query( array( 'query' ), 7, 500 );
		if ( is_wp_error( $current ) ) return array();

		$rising = array();
		foreach ( $current as $row ) {
			$kw = $row['keys'][0];
			$prev = $google->gsc_query( array( 'query' ), 7, 1, array( array( 'dimension' => 'query', 'operator' => 'equals', 'expression' => $kw ) ), 7 );

			if ( ! is_wp_error( $prev ) && ! empty( $prev[0] ) ) {
				$imp_now = (int) $row['impressions'];
				$imp_old = (int) $prev[0]['impressions'];

				if ( $imp_old > 0 && ( $imp_now / $imp_old ) > 1.5 ) {
					$rising[] = array(
						'keyword' => $kw,
						'growth' => round( ( ( $imp_now / $imp_old ) - 1 ) * 100 ) . '%'
					);
				}
			}
		}

		return $rising;
	}
}
