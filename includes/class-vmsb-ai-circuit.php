<?php
defined( 'ABSPATH' ) || exit;

/**
 * Granular Circuit Breaker for AI Providers.
 *
 * Prevents retrying dead providers for a cooling-off period.
 * Self-healing: automatically resets after the TTL.
 */
class VMSB_AI_Circuit {

	const OPTION_KEY = 'vmsb_ai_circuit_stats';
	const FAILURE_THRESHOLD = 3;
	const COOLDOWN_SECONDS  = 1800; // 30 minutes

	/**
	 * Record a failure for a specific provider.
	 */
	public static function failure( $provider, $error = '' ) {
		$stats = get_option( self::OPTION_KEY, array() );

		if ( ! isset( $stats[ $provider ] ) ) {
			$stats[ $provider ] = array( 'fails' => 0, 'last_fail' => 0 );
		}

		$stats[ $provider ]['fails']++;
		$stats[ $provider ]['last_fail'] = time();
		$stats[ $provider ]['last_error'] = mb_substr( $error, 0, 200 );

		update_option( self::OPTION_KEY, $stats, false );
	}

	/**
	 * Record a success, resetting the counter.
	 */
	public static function success( $provider ) {
		$stats = get_option( self::OPTION_KEY, array() );
		if ( isset( $stats[ $provider ] ) ) {
			unset( $stats[ $provider ] );
			update_option( self::OPTION_KEY, $stats, false );
		}
	}

	/**
	 * Check if a provider is allowed to run.
	 */
	public static function is_available( $provider ) {
		$stats = get_option( self::OPTION_KEY, array() );

		if ( ! isset( $stats[ $provider ] ) ) {
			return true;
		}

		// If threshold reached and still in cooldown
		if ( $stats[ $provider ]['fails'] >= self::FAILURE_THRESHOLD ) {
			$elapsed = time() - $stats[ $provider ]['last_fail'];
			if ( $elapsed < self::COOLDOWN_SECONDS ) {
				return false; // Circuit is OPEN (Provider blocked)
			} else {
				// Cooldown expired, half-open state: allow a retry
				return true;
			}
		}

		return true;
	}

	public static function get_stats() {
		return get_option( self::OPTION_KEY, array() );
	}

	public static function reset_all() {
		delete_option( self::OPTION_KEY );
	}
}
