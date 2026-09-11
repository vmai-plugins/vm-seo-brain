<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Usage & Cost Manager.
 *
 * Tracks token consumption and estimated costs across all providers.
 */
class VMSB_Usage {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_usage';
	}

	/**
	 * Record a successful AI interaction.
	 */
	public static function record( array $data ) {
		global $wpdb;

		$provider = $data['provider'] ?? 'unknown';
		$model    = $data['model'] ?? 'unknown';
		$persona  = $data['persona'] ?? 'unknown';
		$tin      = (int) ($data['tokens_in'] ?? 0);
		$tout     = (int) ($data['tokens_out'] ?? 0);

		$cost = self::estimate_cost( $provider, $model, $tin, $tout );

		$wpdb->insert( self::table(), array(
			'provider'   => $provider,
			'model'      => $model,
			'persona'    => $persona,
			'tokens_in'  => $tin,
			'tokens_out' => $tout,
			'cost'       => $cost,
			'created_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Basic cost estimator based on 2026 average market rates (per 1M tokens).
	 */
	private static function estimate_cost( $provider, $model, $tin, $tout ) {
		$rates = array(
			'openai' => array(
				'gpt-4o'      => array( 'in' => 2.5, 'out' => 10 ),
				'gpt-4o-mini' => array( 'in' => 0.15, 'out' => 0.6 ),
			),
			'gemini' => array(
				'gemini-1.5-pro'   => array( 'in' => 1.25, 'out' => 5 ),
				'gemini-1.5-flash' => array( 'in' => 0.075, 'out' => 0.3 ),
				'gemini-2.0-flash' => array( 'in' => 0.1, 'out' => 0.4 ),
			),
			'openrouter' => array(
				'anthropic/claude-3.5-sonnet' => array( 'in' => 3, 'out' => 15 ),
			)
		);

		$p_rates = $rates[ $provider ] ?? null;
		if ( ! $p_rates ) return 0;

		// Find the most specific matching model rate. Model IDs can be
		// substrings of each other (e.g. "gpt-4o" is a substring of
		// "gpt-4o-mini"), so match the longest key first or a cheaper
		// variant gets billed at its more expensive sibling's rate.
		$keys = array_keys( $p_rates );
		usort( $keys, static function ( $a, $b ) { return strlen( $b ) <=> strlen( $a ); } );

		$m_rate = null;
		foreach ( $keys as $m_key ) {
			if ( strpos( $model, $m_key ) !== false ) {
				$m_rate = $p_rates[ $m_key ];
				break;
			}
		}

		if ( ! $m_rate ) return 0;

		$cost_in  = ( $tin / 1000000 ) * $m_rate['in'];
		$cost_out = ( $tout / 1000000 ) * $m_rate['out'];

		return $cost_in + $cost_out;
	}

	/**
	 * Get summary stats for the dashboard.
	 */
	public static function get_summary( $days = 30 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "
			SELECT
				SUM(tokens_in + tokens_out) as total_tokens,
				SUM(cost) as total_cost,
				COUNT(*) as total_calls
			FROM {$table}
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
		", (int) $days ), ARRAY_A );
	}

	/**
	 * Get breakdown by provider.
	 */
	public static function get_provider_breakdown( $days = 30 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results( $wpdb->prepare( "
			SELECT
				provider,
				SUM(tokens_in + tokens_out) as tokens,
				SUM(cost) as cost,
				COUNT(*) as calls
			FROM {$table}
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY provider
			ORDER BY cost DESC
		", (int) $days ) );
	}

	/**
	 * Get daily usage for charts.
	 */
	public static function get_daily_usage( $days = 14 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results( $wpdb->prepare( "
			SELECT
				DATE(created_at) as day,
				SUM(cost) as cost,
				SUM(tokens_in + tokens_out) as tokens
			FROM {$table}
			WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
			GROUP BY day
			ORDER BY day ASC
		", (int) $days ) );
	}

	/**
	 * Prune old usage records to prevent infinite table growth.
	 */
	public static function prune( $days = 90 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table() . " WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
			(int) $days
		) );
	}
}
