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
	/**
	 * Is this install the licence server itself?
	 *
	 * The old check was `class_exists( 'VM_Licence_Manager' )` alone, which
	 * any plugin on any site can satisfy by declaring a class with that name.
	 * The intent was "we are running on vmstudio.digital", so check that too.
	 */
	private static function is_license_host() {
		if ( ! class_exists( 'VM_Licence_Manager' ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 'vmstudio.digital' === $host || '.vmstudio.digital' === substr( $host, -18 );
	}

	/**
	 * Current plan slug, with expiry honoured.
	 *
	 * Nothing used to read the stored expiry date, so a lapsed or refunded
	 * licence stayed Elite forever - the plan was written once at activation
	/**
	 * Backward compatibility alias for plan().
	 */
	public static function tier() {
		return self::plan();
	}

	public static function plan() {
		if ( self::is_license_host() ) {
			return 'elite';
		}

		$license = get_option( self::OPTION, array( 'plan' => 'free' ) );
		$plan    = $license['plan'] ?? 'free';

		if ( 'free' === $plan ) {
			return 'free';
		}

		$expiry = isset( $license['expiry'] ) ? trim( (string) $license['expiry'] ) : '';
		if ( '' !== $expiry && 'lifetime' !== strtolower( $expiry ) ) {
			$expires = strtotime( $expiry );
			// A grace period, so a licence server that is briefly unreachable
			// on renewal day does not disable a paying customer's site.
			if ( $expires && $expires + ( 3 * DAY_IN_SECONDS ) < time() ) {
				return 'free';
			}
		}

		return $plan;
	}

	/**
	 * Re-check the stored key against the licence server. Hooked to the weekly
	 * cron; without it verification was a one-time boolean rather than a state.
	 */
	public static function revalidate() {
		$license = get_option( self::OPTION, array() );
		$key     = isset( $license['key'] ) ? (string) $license['key'] : '';

		if ( ! $key || self::is_license_host() ) {
			return false;
		}

		$ok = self::verify( $key );
		if ( ! $ok ) {
			// verify() has already written the failure; just make it visible.
			( new VMSB_Logger() )->warn( 'license', 'Scheduled licence revalidation failed - the plugin has dropped to the free tier.' );
		}

		return $ok;
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

		// Development keys. These used to run unconditionally in the shipped
		// build, before any network call - so every buyer who opened the
		// plugin file had a permanent Elite unlock sitting in plain sight.
		// They now require the site to be explicitly marked as a development
		// install, which a customer's production site never is.
		$dev_mode = ( defined( 'VMSB_DEV_LICENSE' ) && VMSB_DEV_LICENSE )
			|| ( function_exists( 'wp_get_environment_type' ) && in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) );

		if ( $dev_mode ) {
			$test_keys = array(
				'VMSB-PRO-TEST'   => 'pro',
				'VMSB-ELITE-TEST' => 'elite',
			);
			if ( isset( $test_keys[ $key ] ) ) {
				update_option(
					self::OPTION,
					array( 'plan' => $test_keys[ $key ], 'status' => 'active', 'key' => $key, 'expiry' => 'lifetime', 'checked_at' => time() )
				);
				return true;
			}
		}

		// 2026 Resilience: Check for Local License Server first (bypasses network/SSL errors)
		if ( self::is_license_host() ) {
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
			// The network failed, which says nothing about the licence. Leave
			// the stored plan alone rather than punishing a paying customer
			// for their host's DNS.
			( new VMSB_Logger() )->error( 'license', 'Verification request failed: ' . $response->get_error_message() );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['ok'] ) && ! empty( $body['plan'] ) ) {
			update_option( self::OPTION, array(
				'plan'       => sanitize_key( $body['plan'] ),
				'status'     => 'active',
				'key'        => $key,
				'expiry'     => $body['expiry'] ?? '',
				'checked_at' => time(),
			) );
			return true;
		}

		// The server answered and said no. That is a real downgrade, and the
		// stored plan has to follow it - previously the rejection was logged
		// and the site carried on with whatever tier it had been given.
		$error = $body['message'] ?? 'Unknown error';
		update_option( self::OPTION, array(
			'plan'       => 'free',
			'status'     => 'invalid',
			'key'        => $key,
			'expiry'     => '',
			'checked_at' => time(),
			'error'      => (string) $error,
		) );
		( new VMSB_Logger() )->error( 'license', 'Remote verification failed: ' . $error );
		return false;
	}
}
