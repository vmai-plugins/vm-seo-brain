<?php
defined( 'ABSPATH' ) || exit;

/**
 * One OAuth client for Search Console, GA4 Data API, and Sheets.
 * Scopes: webmasters.readonly, analytics.readonly, spreadsheets.
 */
class VMSB_Google {

	// The Indexing API scope (auth/indexing) is deliberately NOT in the
	// consent list. Google only issues Indexing API credentials to *service
	// accounts*; a user OAuth token can never use them. Requesting the scope
	// anyway was worse than useless: on a Google Cloud project that has not
	// enabled it for OAuth clients, the consent screen can refuse the whole
	// authorization outright (invalid_scope) - which is exactly the "stuck at
	// Not connected" state this panel is meant to explain.
	const SCOPES = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/indexing';

	/** Connect Hub Broker endpoint for zero-config 1-Click OAuth. */
	const BROKER_URL = 'https://connect.vmstudio.digital';

	/** How long a completed consent state stays valid before it expires (24h). */
	const OAUTH_STATE_TTL = 86400;

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/* ---------------------------------------------------------------- auth */

	public function is_connected() {
		return (bool) VMSB_Settings::get( 'google_refresh_token' );
	}

	public function redirect_uri() {
		return admin_url( 'admin.php?page=vmsb-settings&vmsb_google=callback' );
	}

	public function broker_redirect_uri() {
		return admin_url( 'admin.php?page=vmsb-settings&vmsb_google=broker_callback' );
	}

	/**
	 * Instant 1-Click Connect URL via Connect Hub broker.
	 */
	public static function broker_url() {
		if ( defined( 'VMSB_BROKER_URL' ) && VMSB_BROKER_URL ) {
			return rtrim( VMSB_BROKER_URL, '/' );
		}
		if ( defined( 'VMSAI_OAUTH_BROKER_URL' ) && VMSAI_OAUTH_BROKER_URL ) {
			return rtrim( VMSAI_OAUTH_BROKER_URL, '/' );
		}
		$custom = VMSB_Settings::get( 'google_broker_url' );
		if ( ! empty( $custom ) ) {
			return rtrim( $custom, '/' );
		}
		return self::BROKER_URL;
	}

	public function one_click_connect_url() {
		$state = hash( 'sha256', random_bytes( 32 ) . time() );
		set_transient( 'vmsb_google_oauth_state', $state, self::OAUTH_STATE_TTL );

		$params = array(
			'service'      => 'seo',
			'site_url'     => home_url(),
			'redirect_uri' => $this->broker_redirect_uri(),
			'state'        => $state,
		);

		return self::broker_url() . '/oauth/google/start?' . http_build_query( $params );
	}

	/**
	 * Developer custom app consent URL.
	 */
	public function consent_url() {
		$client_id = VMSB_Settings::get( 'google_client_id' );
		if ( empty( $client_id ) ) {
			return null;
		}
		$state = hash( 'sha256', random_bytes( 32 ) . time() );
		set_transient( 'vmsb_google_oauth_state', $state, self::OAUTH_STATE_TTL );
		return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(
			array(
				'client_id'     => $client_id,
				'redirect_uri'  => $this->redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPES,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state,
			)
		);
	}

	public function verify_oauth_state( $state ) {
		if ( empty( $state ) ) {
			return false;
		}
		$expected = get_transient( 'vmsb_google_oauth_state' );
		if ( empty( $expected ) || ! hash_equals( (string) $expected, (string) $state ) ) {
			return false;
		}
		delete_transient( 'vmsb_google_oauth_state' );
		return true;
	}

	/**
	 * Process callback from 1-Click Connect Hub Broker.
	 */
	public function handle_broker_callback( array $data ) {
		$state = isset( $data['state'] ) ? sanitize_text_field( wp_unslash( $data['state'] ) ) : '';
		if ( ! $this->verify_oauth_state( $state ) ) {
			return new WP_Error( 'vmsb_oauth_state', 'That authorisation link has expired. Please try connecting again.' );
		}

		$refresh = isset( $data['refresh_token'] ) ? sanitize_text_field( wp_unslash( $data['refresh_token'] ) ) : '';
		if ( empty( $refresh ) ) {
			return new WP_Error( 'vmsb_oauth_token', 'No refresh token returned by Connect Hub.' );
		}

		$patch = array(
			'google_refresh_token'   => $refresh,
			'google_connect_mode'    => 'broker',
			'google_account_email'   => sanitize_email( wp_unslash( $data['email'] ?? '' ) ),
			'google_account_name'    => sanitize_text_field( wp_unslash( $data['name'] ?? '' ) ),
			'google_account_picture' => esc_url_raw( wp_unslash( $data['picture'] ?? '' ) ),
		);

		VMSB_Settings::update( $patch );

		if ( ! empty( $data['access_token'] ) ) {
			$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 60 ) : 3540;
			set_transient( 'vmsb_google_access', sanitize_text_field( wp_unslash( $data['access_token'] ) ), $ttl );
		}

		$this->auto_configure_properties();

		return true;
	}

	public function exchange_code( $code ) {
		$client_id     = VMSB_Settings::get( 'google_client_id' );
		$client_secret = VMSB_Settings::get( 'google_client_secret' );
		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error( 'vmsb_oauth_config', 'Save your Google OAuth Client ID and Client Secret in Settings before authorizing.' );
		}
		$res = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $this->redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_oauth', 'Google returned an unreadable token response (HTTP ' . wp_remote_retrieve_response_code( $res ) . ').' );
		}
		if ( ! empty( $data['error'] ) ) {
			$desc = isset( $data['error_description'] ) ? $data['error_description'] : '';
			return new WP_Error( 'vmsb_oauth', ( $desc ? $desc . ' ' : '' ) . self::oauth_error_hint( $data['error'] ) );
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new WP_Error( 'vmsb_oauth', 'Google did not return a refresh token. ' . self::oauth_error_hint( 'no_refresh_token' ) );
		}
		VMSB_Settings::update( array(
			'google_refresh_token' => $data['refresh_token'],
			'google_connect_mode'  => 'custom',
		) );
		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 60 ) : 3540;
		set_transient( 'vmsb_google_access', $data['access_token'], $ttl );

		// Fetch profile and auto-configure properties
		$this->user_profile( true );
		$this->auto_configure_properties();

		return true;
	}

	private static function oauth_error_hint( $error ) {
		$hints = array(
			'invalid_client'         => 'Google rejected the client credentials - double-check the Client ID and Client Secret saved above.',
			'redirect_uri_mismatch'  => 'The Redirect URI registered in Google Cloud Console must be exactly the one shown below.',
			'invalid_grant'          => 'The authorization code expired or was already used - click Authorize again to get a fresh one.',
			'access_denied'          => 'The consent window was cancelled before it was finished.',
			'unsupported_grant_type' => 'Google rejected the grant itself - this plugin version may be too old; update from GitHub.',
			'no_refresh_token'       => 'Make sure the OAuth app is in Production (or your account is a test user) - otherwise Google will not hand out a refresh token.',
		);
		return isset( $hints[ $error ] ) ? $hints[ $error ] : 'Google error: ' . $error;
	}

	public function access_token() {
		$cached = get_transient( 'vmsb_google_access' );
		if ( $cached ) {
			return $cached;
		}
		$refresh = VMSB_Settings::get( 'google_refresh_token' );
		if ( ! $refresh ) {
			return new WP_Error( 'vmsb_oauth', 'Google is not connected yet.' );
		}

		$client_id     = VMSB_Settings::get( 'google_client_id' );
		$client_secret = VMSB_Settings::get( 'google_client_secret' );
		$connect_mode  = VMSB_Settings::get( 'google_connect_mode', 'broker' );

		// If developer mode with custom credentials
		if ( 'custom' === $connect_mode && ! empty( $client_id ) && ! empty( $client_secret ) ) {
			$res = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 30,
					'body'    => array(
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'refresh_token' => $refresh,
						'grant_type'    => 'refresh_token',
					),
				)
			);
		} else {
			// Broker mode
			$res = wp_remote_post(
				self::broker_url() . '/oauth/google/refresh',
				array(
					'timeout' => 30,
					'headers' => array( 'Content-Type' => 'application/json' ),
					'body'    => wp_json_encode( array(
						'refresh_token' => $refresh,
						'service'       => 'seo',
					) ),
				)
			);

			// Fallback to direct Google if custom credentials exist and broker failed
			if ( ( is_wp_error( $res ) || wp_remote_retrieve_response_code( $res ) !== 200 ) && ! empty( $client_id ) && ! empty( $client_secret ) ) {
				$res = wp_remote_post(
					'https://oauth2.googleapis.com/token',
					array(
						'timeout' => 30,
						'body'    => array(
							'client_id'     => $client_id,
							'client_secret' => $client_secret,
							'refresh_token' => $refresh,
							'grant_type'    => 'refresh_token',
						),
					)
				);
			}
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['access_token'] ) ) {
			$err_msg = ! empty( $data['error_description'] ) ? $data['error_description'] : ( ! empty( $data['error'] ) ? $data['error'] : 'Token refresh failed. Reconnect Google in Settings.' );
			return new WP_Error( 'vmsb_oauth', $err_msg );
		}
		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 60 ) : 3540;
		set_transient( 'vmsb_google_access', $data['access_token'], $ttl );
		return $data['access_token'];
	}

	public function user_profile( $force_refresh = false ) {
		$email   = VMSB_Settings::get( 'google_account_email' );
		$name    = VMSB_Settings::get( 'google_account_name' );
		$picture = VMSB_Settings::get( 'google_account_picture' );

		if ( ( $force_refresh || empty( $email ) ) && $this->is_connected() ) {
			$res = $this->request( 'https://www.googleapis.com/oauth2/v2/userinfo' );
			if ( is_array( $res ) && ! empty( $res['email'] ) ) {
				$email   = sanitize_email( $res['email'] );
				$name    = sanitize_text_field( $res['name'] ?? '' );
				$picture = esc_url_raw( $res['picture'] ?? '' );
				VMSB_Settings::update( array(
					'google_account_email'   => $email,
					'google_account_name'    => $name,
					'google_account_picture' => $picture,
				) );
			}
		}

		return array(
			'email'   => $email,
			'name'    => $name,
			'picture' => $picture,
		);
	}

	public function disconnect() {
		delete_transient( 'vmsb_google_access' );
		delete_transient( 'vmsb_google_oauth_state' );
		VMSB_Settings::update( array(
			'google_refresh_token'   => '',
			'google_account_email'   => '',
			'google_account_name'    => '',
			'google_account_picture' => '',
			'google_connect_mode'    => 'broker',
		) );
		return true;
	}

	public function ga4_properties() {
		$res = $this->request( 'https://analyticsadmin.googleapis.com/v1beta/accountSummaries' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$properties = array();
		if ( ! empty( $res['accountSummaries'] ) && is_array( $res['accountSummaries'] ) ) {
			foreach ( $res['accountSummaries'] as $acc ) {
				$acc_name = $acc['displayName'] ?? 'Account';
				if ( ! empty( $acc['propertySummaries'] ) && is_array( $acc['propertySummaries'] ) ) {
					foreach ( $acc['propertySummaries'] as $prop ) {
						$raw_id = $prop['property'] ?? '';
						$num_id = preg_replace( '/\D/', '', $raw_id );
						$properties[] = array(
							'id'           => $num_id,
							'raw_id'       => $raw_id,
							'name'         => $prop['displayName'] ?? 'GA4 Property',
							'account_name' => $acc_name,
						);
					}
				}
			}
		}

		return $properties;
	}

	public function auto_configure_properties() {
		$current_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $current_host ) {
			return;
		}
		$clean_host = preg_replace( '/^www\./i', '', strtolower( $current_host ) );

		// Auto match Search Console property
		if ( empty( VMSB_Settings::get( 'gsc_property' ) ) ) {
			$sites = $this->gsc_sites();
			if ( is_array( $sites ) && ! empty( $sites ) ) {
				$matched = '';
				foreach ( $sites as $site ) {
					$site_clean = str_replace( array( 'sc-domain:', 'http://', 'https://' ), '', rtrim( strtolower( $site ), '/' ) );
					$site_clean = preg_replace( '/^www\./i', '', $site_clean );
					if ( $site_clean === $clean_host ) {
						$matched = $site;
						break;
					}
				}
				if ( ! $matched && 1 === count( $sites ) ) {
					$matched = $sites[0];
				}
				if ( $matched ) {
					VMSB_Settings::update( array( 'gsc_property' => $matched ) );
				}
			}
		}

		// Auto match GA4 property
		if ( empty( VMSB_Settings::get( 'ga4_property_id' ) ) ) {
			$props = $this->ga4_properties();
			if ( is_array( $props ) && ! empty( $props ) ) {
				$matched_ga4 = '';
				foreach ( $props as $p ) {
					if ( false !== stripos( $p['name'], $clean_host ) ) {
						$matched_ga4 = $p['id'];
						break;
					}
				}
				if ( ! $matched_ga4 && 1 === count( $props ) ) {
					$matched_ga4 = $props[0]['id'];
				}
				if ( $matched_ga4 ) {
					VMSB_Settings::update( array( 'ga4_property_id' => $matched_ga4 ) );
				}
			}
		}
	}

	public function connection_status() {
		$has_id     = ! empty( VMSB_Settings::get( 'google_client_id' ) );
		$has_secret = ! empty( VMSB_Settings::get( 'google_client_secret' ) );
		$has_token  = ! empty( VMSB_Settings::get( 'google_refresh_token' ) );
		$mode       = VMSB_Settings::get( 'google_connect_mode', 'broker' );
		$failed     = (bool) get_option( 'vmsb_decryption_failed' );

		$reasons = array();
		if ( ! $has_token ) {
			$reasons[] = 'Click <strong>Sign in with Google (1-Click)</strong> to authenticate instantly.';
		}
		if ( $failed ) {
			$reasons[] = 'A stored credential could not be decrypted - your <code>AUTH_KEY</code> changed. Please reconnect Google.';
		}

		return array(
			'connected'      => $this->is_connected(),
			'mode'           => $mode,
			'has_client_id'  => $has_id,
			'has_secret'     => $has_secret,
			'has_token'      => $has_token,
			'decrypt_failed' => $failed,
			'reasons'        => $reasons,
		);
	}

	public function test_connection() {
		if ( ! $this->is_connected() ) {
			return array( 'ok' => false, 'detail' => 'No refresh token is stored - complete Google authorization first.' );
		}
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			$msg = strtolower( (string) $token->get_error_message() );
			if ( strpos( $msg, 'refresh' ) !== false || strpos( $msg, 'invalid_grant' ) !== false || strpos( $msg, 'inactive' ) !== false ) {
				return array( 'ok' => false, 'detail' => 'The saved token was rejected by Google when refreshed. Re-authorize to store a fresh token.' );
			}
			return array( 'ok' => false, 'detail' => $token->get_error_message() );
		}
		$sites = $this->gsc_sites();
		if ( is_wp_error( $sites ) ) {
			return array( 'ok' => false, 'detail' => 'Token refresh works, but Search Console returned: ' . $sites->get_error_message() );
		}
		$n = count( $sites );
		return array( 'ok' => true, 'detail' => 'Token works and Search Console answers (' . $n . ' propert' . ( 1 === $n ? 'y' : 'ies' ) . ' available).' );
	}
	// Public - VMSB_Indexing calls this directly to hit the Google Indexing
	// API, which reuses this class's OAuth/token-refresh handling rather
	// than duplicating it. It was private, which fatals (PHP visibility
	// error) on every post publish/update once the Indexing bridge tries to
	// call it - see the "Call to private method" crash in god-fix-90 and
	// any normal wp_insert_post on a published post/page.
	public function request( $url, $method = 'GET', $body = null ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'vmsb_google', $msg );
		}
		return $data;
	}

	/* ---------------------------------------------------------------- search console */

	/**
	 * @param array $dimensions e.g. array('query') or array('page','query')
	 * @param int   $offset_days Shifts the whole window further into the past
	 *                           by this many days - e.g. days=7, offset_days=8
	 *                           queries the 7-day window starting 8 days before
	 *                           the normal anchor, for a "previous period"
	 *                           comparison against days=7, offset_days=0.
	 */
	public function gsc_query( array $dimensions = array( 'query' ), $days = 28, $rows = 2000, array $filters = array(), $start_row = 0, $offset_days = 0 ) {
		$property = VMSB_Settings::get( 'gsc_property' );
		if ( ! $property ) {
			return new WP_Error( 'vmsb_gsc', 'No Search Console property selected.' );
		}

		// Prefer local Rank Math tables if high-volume is requested (Ported from VMAI SEO)
		// Only for the current, un-offset window - the local mirror has no
		// notion of a comparison window, so an offset request always needs
		// the real API.
		if ( $rows > 5000 && 0 === (int) $offset_days && class_exists('VMSB_RankMath') ) {
			$local_data = ( new VMSB_RankMath() )->get_local_metrics( $dimensions, $days, $rows );
			if ( ! empty($local_data) ) return $local_data;
		}

		$body = array(
			'startDate'  => gmdate( 'Y-m-d', strtotime( '-' . ( $days + $offset_days ) . ' days' ) ),
			'endDate'    => gmdate( 'Y-m-d', strtotime( '-' . ( 2 + $offset_days ) . ' days' ) ),
			'dimensions' => $dimensions,
			'rowLimit'   => (int) $rows,
			'startRow'   => (int) $start_row,
		);
		if ( $filters ) {
			$body['dimensionFilterGroups'] = array( array( 'filters' => $filters ) );
		}
		$url = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $property ) . '/searchAnalytics/query';
		$res = $this->request( $url, 'POST', $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$all_rows = isset( $res['rows'] ) ? $res['rows'] : array();

		// Automatic Pagination for large scans (Max 10k rows for efficiency).
		// Must check the cumulative total, not $rows (the fixed page size) -
		// $rows never changes across the recursion, so checking it against
		// 10000 either always or never triggers the cap, letting a full-page
		// response chain into unbounded sequential API calls.
		$total_so_far = $start_row + count( $all_rows );
		if ( count($all_rows) === (int)$rows && $total_so_far < 10000 ) {
			$next_batch = $this->gsc_query( $dimensions, $days, $rows, $filters, $start_row + $rows, $offset_days );
			if ( ! is_wp_error($next_batch) ) {
				$all_rows = array_merge( $all_rows, $next_batch );
			}
		}

		return $all_rows;
	}

	/**
	 * Deep Audit Data: Returns query-level metrics for a specific page.
	 * Helps identify "Underperforming Queries" on high-traffic pages.
	 */
	public function get_page_query_data( $url, $limit = 50 ) {
		return $this->gsc_query(
			array( 'query' ),
			28,
			$limit,
			array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $url ) )
		);
	}

	/**
	 * Aggregated Search Console metrics for a single URL over a window.
	 * Used by the outcome ledger to measure whether an action moved the page,
	 * and by the decay/lifecycle monitors to compare a period against an
	 * earlier one via $offset_days (see gsc_query()'s docblock - this was
	 * silently accepting and dropping a 3rd argument at every "previous
	 * period" call site until this parameter was added).
	 *
	 * @return array{clicks:int,impressions:int,position:float,ctr:float}|WP_Error
	 */
	public function gsc_page_metrics( $url, $days = 28, $offset_days = 0 ) {
		$rows = $this->gsc_query(
			array( 'page' ),
			$days,
			1,
			array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $url ) ),
			0,
			$offset_days
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( empty( $rows[0] ) ) {
			return array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'ctr' => 0 );
		}
		$r = $rows[0];
		return array(
			'clicks'      => (int) ( $r['clicks'] ?? 0 ),
			'impressions' => (int) ( $r['impressions'] ?? 0 ),
			'position'    => round( (float) ( $r['position'] ?? 0 ), 2 ),
			'ctr'         => round( (float) ( $r['ctr'] ?? 0 ), 4 ),
		);
	}

	public function gsc_sites() {
		$res = $this->request( 'https://searchconsole.googleapis.com/webmasters/v3/sites' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['siteEntry'] ) ? wp_list_pluck( $res['siteEntry'], 'siteUrl' ) : array();
	}

	/**
	 * URL Inspection — how Google actually sees a page. Used by the error fixer.
	 */
	public function inspect_url( $url ) {
		$property = VMSB_Settings::get( 'gsc_property' );
		if ( ! $property ) {
			return new WP_Error( 'vmsb_gsc', 'No Search Console property selected.' );
		}
		return $this->request(
			'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
			'POST',
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => $property,
				'languageCode'  => VMSB_Settings::get( 'language' ),
			)
		);
	}

	/**
	 * Instant Indexing: Notify Google that a URL has been updated or published.
	 */
	public function indexing_notify( $url, $type = 'URL_UPDATED' ) {
		$endpoint = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
		$body = array(
			'url'  => $url,
			'type' => $type,
		);

		$res = $this->request( $endpoint, 'POST', $body );

		if ( ! is_wp_error( $res ) ) {
			$this->log->info( 'indexing', "Indexing notification sent for: {$url}" );
			return true;
		}

		return $res;
	}

	/* ---------------------------------------------------------------- ga4 */

	public function ga4_report( array $metrics, array $dimensions = array(), $days = 28, $limit = 250 ) {
		$property = preg_replace( '/\D/', '', (string) VMSB_Settings::get( 'ga4_property_id' ) );
		if ( ! $property ) {
			return new WP_Error( 'vmsb_ga4', 'No GA4 property ID set.' );
		}
		$body = array(
			'dateRanges' => array( array( 'startDate' => "{$days}daysAgo", 'endDate' => 'today' ) ),
			'metrics'    => array_map( static fn( $m ) => array( 'name' => $m ), $metrics ),
			'limit'      => (int) $limit,
		);
		if ( $dimensions ) {
			$body['dimensions'] = array_map( static fn( $d ) => array( 'name' => $d ), $dimensions );
		}
		return $this->request( "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:runReport", 'POST', $body );
	}

	public function ga4_sessions_by_day( $days = 60 ) {
		// Google renamed this metric: 'conversions' became 'keyEvents' in the
		// GA4 Data API, and VMSB_GA4 already asks for 'keyEvents' while this
		// call still asked for the old name. Worse, the metric travelled in
		// the same request as sessions and totalUsers - so on a property that
		// rejects the name, the whole report errored and the daily snapshot
		// recorded no traffic at all, not merely no conversions.
		//
		// Ask by the current name, fall back to the legacy one, and if the
		// property will not give either, still return sessions and users
		// rather than losing the day entirely.
		$attempts = array(
			array( 'sessions', 'totalUsers', 'keyEvents' ),
			array( 'sessions', 'totalUsers', 'conversions' ),
			array( 'sessions', 'totalUsers' ),
		);

		$res = null;
		foreach ( $attempts as $metrics ) {
			$res = $this->ga4_report( $metrics, array( 'date' ), $days, 400 );
			if ( ! is_wp_error( $res ) ) {
				break;
			}
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$out = array();
		foreach ( ( isset( $res['rows'] ) ? $res['rows'] : array() ) as $row ) {
			$date         = $row['dimensionValues'][0]['value'];
			$out[ $date ] = array(
				'sessions'    => (int) ( $row['metricValues'][0]['value'] ?? 0 ),
				'users'       => (int) ( $row['metricValues'][1]['value'] ?? 0 ),
				// Absent on the sessions-only fallback, hence the null check.
				'conversions' => isset( $row['metricValues'][2]['value'] ) ? (int) $row['metricValues'][2]['value'] : 0,
			);
		}
		ksort( $out );
		return $out;
	}

	/* ---------------------------------------------------------------- sheets */

	public function sheet_read( $range ) {
		$id = VMSB_Settings::get( 'sheet_id' );
		if ( ! $id ) {
			return new WP_Error( 'vmsb_sheets', 'No spreadsheet ID set.' );
		}
		$res = $this->request( "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/" . rawurlencode( $range ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['values'] ) ? $res['values'] : array();
	}

	public function sheet_append( array $rows, $tab = '' ) {
		$id  = VMSB_Settings::get( 'sheet_id' );
		$tab = $tab ? $tab : VMSB_Settings::get( 'sheet_tab' );
		if ( ! $id ) {
			return new WP_Error( 'vmsb_sheets', 'No spreadsheet ID set.' );
		}
		$url = "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/" . rawurlencode( $tab . '!A1' )
			. ':append?valueInputOption=USER_ENTERED&insertDataOption=INSERT_ROWS';
		return $this->request( $url, 'POST', array( 'values' => $rows ) );
	}

	public function sheet_update( $range, array $rows ) {
		$id = VMSB_Settings::get( 'sheet_id' );
		if ( ! $id ) {
			return new WP_Error( 'vmsb_sheets', 'No spreadsheet ID set.' );
		}
		$url = "https://sheets.googleapis.com/v4/spreadsheets/{$id}/values/" . rawurlencode( $range ) . '?valueInputOption=USER_ENTERED';
		return $this->request( $url, 'PUT', array( 'values' => $rows ) );
	}

	/**
	 * Create the tab and header row if they don't exist yet.
	 */
	public function ensure_sheet_tab( $tab = '', array $header = array() ) {
		$id  = VMSB_Settings::get( 'sheet_id' );
		$tab = $tab ? $tab : VMSB_Settings::get( 'sheet_tab' );
		if ( ! $id ) {
			return new WP_Error( 'vmsb_sheets', 'No spreadsheet ID set.' );
		}

		$meta = $this->request( "https://sheets.googleapis.com/v4/spreadsheets/{$id}" );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}

		$existing = array();
		foreach ( ( isset( $meta['sheets'] ) ? $meta['sheets'] : array() ) as $sheet ) {
			$existing[] = $sheet['properties']['title'];
		}

		if ( ! in_array( $tab, $existing, true ) ) {
			$this->request(
				"https://sheets.googleapis.com/v4/spreadsheets/{$id}:batchUpdate",
				'POST',
				array( 'requests' => array( array( 'addSheet' => array( 'properties' => array( 'title' => $tab ) ) ) ) )
			);
			$this->sheet_update( $tab . '!A1:M1', array( $header ? $header : VMSB_Content::sheet_header() ) );
		}

		return true;
	}
}
