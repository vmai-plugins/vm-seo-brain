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
	/**
	 * Trending topics for the configured niche and location.
	 *
	 * This is called while rendering the Content Factory, and it costs a
	 * request to Google Trends plus an AI call to filter the results. Only the
	 * fully successful path used to write the transient: an unreachable
	 * Trends endpoint, an empty body, an unparseable feed or an AI hiccup all
	 * returned early without caching anything. So on any failure - and this
	 * site currently has no cached value at all - every single view of that
	 * page repeated a 15-second-timeout HTTP request and a premium AI call
	 * before rendering a single row.
	 *
	 * Every outcome is now cached. Failures are held briefly so the page stops
	 * paying for them on each load while still recovering on its own, and the
	 * timeout is short enough that one bad fetch cannot dominate a page render.
	 */
	public function get_rising_signals( $limit = 10 ) {
		$cached = get_transient( 'vmsb_rising_trends' );
		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit );
		}

		$fail_ttl = 15 * MINUTE_IN_SECONDS;
		$country  = VMSB_Settings::get( 'country', 'IN' );
		$niche    = VMSB_Settings::get( 'business_type' );

		// 1. Google Trends RSS for the region
		$url = "https://trends.google.com/trends/trendingsearches/daily/rss?geo=" . rawurlencode( $country );

		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) ) {
			set_transient( 'vmsb_rising_trends', array(), $fail_ttl );
			return array();
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! $body ) {
			set_transient( 'vmsb_rising_trends', array(), $fail_ttl );
			return array();
		}

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
		if ( empty( $trends ) ) {
			set_transient( 'vmsb_rising_trends', array(), $fail_ttl );
			return array();
		}

		$prompt = "Act as a Niche Intelligence Agent. I have a list of trending topics in {$country}.\n"
			. "NICHE: {$niche}\n"
			. "TRENDS:\n" . wp_json_encode( $trends ) . "\n\n"
			. "TASK: Identify which trends are RELEVANT to our niche or can be 'News-Jacked' (connected logically to our business).\n"
			. "Return ONLY relevant topics as a JSON array of strings: [\"topic1\", \"topic2\"]";

		$relevant = $this->ai->generate_json( $prompt, array( 'max_tokens' => 300, 'persona' => 'strategist' ) );

		if ( is_array( $relevant ) ) {
			set_transient( 'vmsb_rising_trends', $relevant, HOUR_IN_SECONDS );
			return array_slice( $relevant, 0, $limit );
		}

		set_transient( 'vmsb_rising_trends', array(), $fail_ttl );
		return array();
	}

	/**
	 * Analyze "Velocity" of current keyword universe.
	 * Finds keywords with sharp impression growth in GSC.
	 */
	/**
	 * Keywords whose impressions are climbing sharply week over week.
	 *
	 * This previously issued one Search Console request per keyword returned
	 * by the first - up to 500 sequential calls to a rate-limited API, all
	 * while rendering the Content Factory.
	 *
	 * It also never worked. The fifth argument of gsc_query() is startRow, a
	 * pagination offset, not a date offset; passing 7 asked for one row after
	 * skipping the first seven of a single-row result, so $prev[0] was always
	 * empty and the comparison never ran. The feature has been returning an
	 * empty list since it was written.
	 *
	 * Impressions are additive across dates, so the previous week is simply
	 * the fourteen-day total minus the last seven days. Two requests total,
	 * and the comparison is now real.
	 */
	public function analyze_velocity() {
		$cached = get_transient( 'vmsb_velocity_signals' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) {
			return array();
		}

		$current = $google->gsc_query( array( 'query' ), 7, 500 );
		if ( is_wp_error( $current ) ) {
			set_transient( 'vmsb_velocity_signals', array(), 15 * MINUTE_IN_SECONDS );
			return array();
		}

		// Same end date, twice the window: -14..-2 versus -7..-2. The
		// difference between them is exactly the seven days before last.
		$fortnight = $google->gsc_query( array( 'query' ), 14, 500 );
		if ( is_wp_error( $fortnight ) ) {
			set_transient( 'vmsb_velocity_signals', array(), 15 * MINUTE_IN_SECONDS );
			return array();
		}

		$fortnight_by_kw = array();
		foreach ( (array) $fortnight as $row ) {
			if ( isset( $row['keys'][0] ) ) {
				$fortnight_by_kw[ $row['keys'][0] ] = (int) $row['impressions'];
			}
		}

		$rising = array();
		foreach ( (array) $current as $row ) {
			if ( ! isset( $row['keys'][0] ) ) {
				continue;
			}
			$kw      = $row['keys'][0];
			$imp_now = (int) $row['impressions'];
			$imp_old = isset( $fortnight_by_kw[ $kw ] ) ? $fortnight_by_kw[ $kw ] - $imp_now : 0;

			if ( $imp_old > 0 && ( $imp_now / $imp_old ) > 1.5 ) {
				$rising[] = array(
					'keyword' => $kw,
					'growth'  => round( ( ( $imp_now / $imp_old ) - 1 ) * 100 ) . '%',
				);
			}
		}

		set_transient( 'vmsb_velocity_signals', $rising, HOUR_IN_SECONDS );
		return $rising;
	}
}
