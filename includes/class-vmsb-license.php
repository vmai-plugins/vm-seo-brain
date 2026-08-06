<?php
/**
 * License and subscription manager for VM SEO Brain.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles feature gating and subscription status.
 */
class VMSB_License {

	const OPTION = 'vmsb_license';

	/**
	 * Current plan slug.
	 *
	 * @return string free|pro|elite
	 */
	public static function plan() {
		$license = get_option( self::OPTION, array( 'plan' => 'free' ) );
		return $license['plan'] ?? 'free';
	}

	/**
	 * Check if the active plan is at least a certain level.
	 *
	 * @param string $min_plan free|pro|elite.
	 * @return bool
	 */
	public static function at_least( $min_plan ) {
		$tiers = array( 'free' => 0, 'pro' => 1, 'elite' => 2 );
		$current_weight = $tiers[ self::plan() ] ?? 0;
		$target_weight  = $tiers[ $min_plan ] ?? 0;

		return $current_weight >= $target_weight;
	}

	/**
	 * Gate a feature based on the plan.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function has_feature( $feature ) {
		$map = array(
			// PRO Features
			'trend_scout'         => 'pro',
			'competitor_hijack'   => 'pro',
			'authority_blitz'     => 'pro', // 15 posts/day
			'cluster_architect'   => 'pro',
			'silo_rebuilder'      => 'pro',
			'taxonomy_lab'        => 'pro',

			// ELITE Features
			'programmatic_seo'    => 'elite',
			'niche_dominance'     => 'elite', // 30+ posts/day
			'vector_memory'       => 'elite',
			'semantic_link_chain' => 'elite',
		);

		$required = $map[ $feature ] ?? 'free';

		return self::at_least( $required );
	}

	/**
	 * Get usage limits for the current plan.
	 */
	public static function limits() {
		$plan = self::plan();

		if ( 'elite' === $plan ) {
			return array( 'posts_per_day' => 50, 'keywords' => 5000, 'label' => 'Elite' );
		}

		if ( 'pro' === $plan ) {
			return array( 'posts_per_day' => 15, 'keywords' => 1000, 'label' => 'Pro' );
		}

		return array( 'posts_per_day' => 3, 'keywords' => 100, 'label' => 'Free' );
	}

	/**
	 * Verify a license key with the remote server.
	 */
	public static function verify( $key ) {
		$key = strtoupper( trim( $key ) );

		if ( ! $key ) {
			update_option( self::OPTION, array( 'plan' => 'free', 'status' => 'inactive' ) );
			return false;
		}

		// TEST KEYS for local development
		if ( 'VMSB-PRO-TEST' === $key ) {
			update_option( self::OPTION, array( 'plan' => 'pro', 'status' => 'active', 'key' => $key ) );
			return true;
		}

		if ( 'VMSB-ELITE-TEST' === $key ) {
			update_option( self::OPTION, array( 'plan' => 'elite', 'status' => 'active', 'key' => $key ) );
			return true;
		}

		// 2026 Resilience: Check for Local License Server first (bypasses network/SSL errors)
		if ( class_exists('VM_Licence_Manager') ) {
			$request = new WP_REST_Request( 'POST', '/vm-licence-manager/v1/verify' );
			$request->set_body_params( array(
				'key'      => $key,
				'site_url' => home_url(),
				'product'  => 'vm-seo-brain'
			) );
			$response = rest_do_request( $request );
			if ( ! is_wp_error( $response ) && ! $response->is_error() ) {
				$body = $response->get_data();
				if ( ! empty( $body['ok'] ) && ! empty( $body['plan'] ) ) {
					update_option( self::OPTION, array(
						'plan'   => sanitize_key( $body['plan'] ),
						'status' => 'active',
						'key'    => $key,
						'expiry' => $body['expiry'] ?? '',
					) );
					return true;
				}
			}
		}

		// REMOTE VERIFICATION with VM Licence Manager
		$url = 'https://vmstudio.digital/wp-json/vm-licence-manager/v1/verify';

		$response = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'key'      => $key,
				'site_url' => home_url(),
				'product'  => 'vm-seo-brain'
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			( new VMSB_Logger() )->error( 'license', 'Verification request failed: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['ok'] ) && ! empty( $body['plan'] ) ) {
			update_option( self::OPTION, array(
				'plan'   => sanitize_key( $body['plan'] ),
				'status' => 'active',
				'key'    => $key,
				'expiry' => $body['expiry'] ?? '',
			) );
			return true;
		}

		$error = $body['message'] ?? 'Unknown error';
		( new VMSB_Logger() )->error( 'license', 'Remote verification failed: ' . $error );
		return false;
	}
}
