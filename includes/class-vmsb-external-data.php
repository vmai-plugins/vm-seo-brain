<?php
defined( 'ABSPATH' ) || exit;

/**
 * External SEO data clients: SEMrush and Ahrefs.
 *
 * Both are optional. Keyword research and competitor scanning already work
 * without either - Search Console gives real numbers for what we rank for,
 * and the model estimates the rest. These sharpen that estimate with real
 * third-party volume/difficulty/backlink data when a key is present, and are
 * silently unused otherwise. Nothing else in the plugin should ever require
 * these to be configured.
 */
class VMSB_External_Data {

	public static function semrush_configured() {
		return (bool) VMSB_Settings::get( 'semrush_key' );
	}

	public static function ahrefs_configured() {
		return (bool) VMSB_Settings::get( 'ahrefs_token' );
	}

	/**
	 * SEMrush keyword overview: volume, CPC, competitive density.
	 * Standard Analytics API, key-based, units-metered - callers should batch
	 * requests and expect this to occasionally be empty (quota, bad key).
	 */
	public static function semrush_keyword_overview( $keyword, $database = 'us' ) {
		$key = VMSB_Settings::get( 'semrush_key' );
		if ( ! $key ) {
			return new WP_Error( 'vmsb_external', 'SEMrush key not configured.' );
		}

		$url = add_query_arg( array(
			'type'     => 'phrase_this',
			'key'      => $key,
			'phrase'   => $keyword,
			'database' => $database,
			'export_columns' => 'Ph,Nq,Cp,Co,Kd',
		), 'https://api.semrush.com/' );

		$res = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = wp_remote_retrieve_body( $res );
		if ( ! $body || false !== stripos( $body, 'ERROR' ) ) {
			return new WP_Error( 'vmsb_external', 'SEMrush returned no usable data (quota or invalid key).' );
		}

		$lines = explode( "\n", trim( $body ) );
		if ( count( $lines ) < 2 ) {
			return new WP_Error( 'vmsb_external', 'No data for this keyword.' );
		}
		$cols = explode( ';', $lines[1] );
		return array(
			'keyword'     => $cols[0] ?? $keyword,
			'volume'      => (int) ( $cols[1] ?? 0 ),
			'cpc'         => (float) ( $cols[2] ?? 0 ),
			'competition' => (float) ( $cols[3] ?? 0 ),
			'difficulty'  => (float) ( $cols[4] ?? 0 ),
			'source'      => 'semrush',
		);
	}

	/**
	 * Ahrefs v3 (bearer token): domain rating + backlink count for a domain -
	 * used by competitor scanning and backlink prospecting to replace the
	 * model's guess with a real number when available.
	 */
	public static function ahrefs_domain_overview( $domain ) {
		$token = VMSB_Settings::get( 'ahrefs_token' );
		if ( ! $token ) {
			return new WP_Error( 'vmsb_external', 'Ahrefs token not configured.' );
		}

		$res = wp_remote_get(
			'https://api.ahrefs.com/v3/site-explorer/domain-rating?target=' . rawurlencode( $domain ) . '&date=' . gmdate( 'Y-m-d' ),
			array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json' ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || ! isset( $data['domain_rating'] ) ) {
			return new WP_Error( 'vmsb_external', 'Ahrefs returned no usable data.' );
		}

		return array(
			'domain'         => $domain,
			'domain_rating'  => (float) $data['domain_rating'],
			'source'         => 'ahrefs',
		);
	}

	/**
	 * Best-effort enrichment: try SEMrush first, then Ahrefs where relevant,
	 * and only fall through to nothing (caller keeps its model estimate) if
	 * neither is configured or both fail.
	 */
	public static function enrich_keyword( $keyword ) {
		if ( self::semrush_configured() ) {
			$data = self::semrush_keyword_overview( $keyword );
			if ( ! is_wp_error( $data ) ) {
				return $data;
			}
		}
		return null;
	}

	public static function enrich_domain( $domain ) {
		if ( self::ahrefs_configured() ) {
			$data = self::ahrefs_domain_overview( $domain );
			if ( ! is_wp_error( $data ) ) {
				return $data;
			}
		}
		return null;
	}
}
