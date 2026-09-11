<?php
defined( 'ABSPATH' ) || exit;

/**
 * External SEO data clients: SEMrush and Ahrefs.
 *
 * Ported & Hardened from VM AI SEO (Elite Standard).
 * Aggregates high-fidelity traffic, difficulty, and backlink metrics.
 */
class VMSB_External_Data {

	public static function semrush_configured() {
		return (bool) VMSB_Settings::get( 'semrush_key' );
	}

	public static function ahrefs_configured() {
		return (bool) VMSB_Settings::get( 'ahrefs_token' );
	}

	/* ---------------------------------------------------------------- SEMrush */

	/**
	 * SEMrush API Request Bridge.
	 */
	private static function semrush_request( $params ) {
		$key = VMSB_Settings::get( 'semrush_key' );
		if ( ! $key ) return new WP_Error( 'vmsb_external', 'SEMrush key not configured.' );

		$params['key'] = $key;
		$url = add_query_arg( $params, 'https://api.semrush.com/' );

		$res = wp_remote_get( $url, array( 'timeout' => 25 ) );
		if ( is_wp_error( $res ) ) return $res;

		$body = wp_remote_retrieve_body( $res );
		if ( ! $body || false !== stripos( $body, 'ERROR' ) ) {
			return new WP_Error( 'vmsb_external', 'SEMrush API Error: ' . $body );
		}

		return self::parse_semrush_csv( $body );
	}

	private static function parse_semrush_csv( $body ) {
		$lines = array_filter( explode( "\n", trim( $body ) ) );
		if ( empty( $lines ) ) return array();
		$headers = explode( ';', array_shift( $lines ) );
		$rows = array();
		foreach ( $lines as $line ) {
			$cols = explode( ';', $line );
			if ( count($headers) === count($cols) ) {
				$rows[] = array_combine( $headers, $cols );
			}
		}
		return $rows;
	}

	/** Keyword overview: volume, difficulty, CPC. */
	public static function semrush_keyword_overview( $keyword, $database = 'us' ) {
		$data = self::semrush_request( array(
			'type'     => 'phrase_this',
			'phrase'   => $keyword,
			'database' => $database,
			'export_columns' => 'Ph,Nq,Cp,Co,Kd',
		) );

		if ( is_wp_error($data) || empty($data[0]) ) return $data;

		$row = $data[0];
		return array(
			'keyword'     => $row['Phrase'] ?? $keyword,
			'volume'      => (int) ($row['Search Volume'] ?? 0),
			'cpc'         => (float) ($row['CPC'] ?? 0),
			'difficulty'  => (float) ($row['Keyword Difficulty'] ?? 0),
			'source'      => 'semrush',
		);
	}

	/** Organic keyword gap vs a competitor domain. */
	public static function semrush_keyword_gap( $domain, $competitor_domain, $database = 'us' ) {
		return self::semrush_request( array(
			'type'     => 'domain_domains',
			'domains'  => "Dn|{$domain}|0|Dn|{$competitor_domain}|0",
			'database' => $database,
			'export_columns' => 'Ph,P0,P1,Nq,Cp',
			'display_limit' => 200,
		) );
	}

	/** Top organic keywords currently driving traffic to the domain. */
	public static function semrush_organic_keywords( $domain, $database = 'us', $limit = 100 ) {
		return self::semrush_request( array(
			'type'     => 'domain_organic',
			'domain'   => $domain,
			'database' => $database,
			'display_limit' => $limit,
			'export_columns' => 'Ph,Po,Pp,Nq,Cp,Tr,Tc,Ur',
		) );
	}

	/**
	 * Who actually ranks for a keyword right now, and at which URL - real
	 * SERP composition from SEMrush's own index, not a live Google fetch.
	 * Used to find a specific competitor page to compare against instead
	 * of guessing what "a site like this" probably covers.
	 */
	public static function semrush_phrase_organic( $keyword, $database = 'us', $limit = 20 ) {
		return self::semrush_request( array(
			'type'     => 'phrase_organic',
			'phrase'   => $keyword,
			'database' => $database,
			'display_limit' => $limit,
			'export_columns' => 'Dn,Ur,Po',
		) );
	}

	/**
	 * Fetch a public page's readable text - a normal HTTP GET on content
	 * the page owner has already published for anyone to read, the same
	 * way a browser or any crawler would. Bounded length and a real
	 * User-Agent so this behaves like any other well-behaved fetcher.
	 *
	 * @return string|WP_Error
	 */
	public static function fetch_page_text( $url, $max_chars = 6000 ) {
		$res = wp_remote_get( $url, array(
			'timeout'    => 15,
			'redirection'=> 3,
			'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . ' (+' . home_url() . ')',
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'vmsb_external', "Fetching {$url} returned HTTP {$code}." );
		}

		$html = wp_remote_retrieve_body( $res );
		// Strip script/style blocks before tag-stripping so their contents
		// (JS/CSS, not article text) never leak into the extracted text.
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$text = wp_strip_all_tags( $html );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );

		return mb_substr( $text, 0, $max_chars );
	}

	/* ---------------------------------------------------------------- Ahrefs */

	/**
	 * Ahrefs API Request Bridge (v3).
	 */
	private static function ahrefs_request( $endpoint, $params = array() ) {
		$token = VMSB_Settings::get( 'ahrefs_token' );
		if ( ! $token ) return new WP_Error( 'vmsb_external', 'Ahrefs token not configured.' );

		$url = 'https://api.ahrefs.com/v3/' . $endpoint . '?' . http_build_query( $params );
		$res = wp_remote_get( $url, array(
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		) );

		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code >= 400 ) {
			return new WP_Error( 'vmsb_external', 'Ahrefs API error (' . $code . ').' );
		}
		return $body;
	}

	/** Domain rating + backlink stats. */
	public static function ahrefs_domain_overview( $domain ) {
		$dr = self::ahrefs_request( 'site-explorer/domain-rating', array( 'target' => $domain ) );
		if ( is_wp_error($dr) ) return $dr;

		return array(
			'domain'         => $domain,
			'domain_rating'  => (float) ($dr['domain_rating'] ?? 0),
			'source'         => 'ahrefs',
		);
	}

	/** Top pages by organic traffic. */
	public static function ahrefs_top_pages( $domain, $limit = 50 ) {
		return self::ahrefs_request( 'site-explorer/top-pages', array(
			'target' => $domain,
			'limit'  => $limit,
			'mode'   => 'domain',
		) );
	}

	/* ---------------------------------------------------------------- Enrichment */

	public static function enrich_keyword( $keyword ) {
		if ( self::semrush_configured() ) {
			$data = self::semrush_keyword_overview( $keyword );
			if ( ! is_wp_error( $data ) ) return $data;
		}
		return null;
	}

	public static function enrich_domain( $domain ) {
		if ( self::ahrefs_configured() ) {
			$data = self::ahrefs_domain_overview( $domain );
			if ( ! is_wp_error( $data ) ) return $data;
		}
		return null;
	}
}
