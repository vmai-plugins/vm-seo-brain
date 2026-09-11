<?php
defined( 'ABSPATH' ) || exit;

/**
 * GA4 Intelligence Agent.
 * Fetches user behavior and conversion data to ground growth decisions.
 * Ported & Enhanced from VMAI Autopilot.
 */
class VMSB_GA4 {

	private $google;
	private $property_id;

	public function __construct() {
		$this->google = new VMSB_Google();
		$this->property_id = VMSB_Settings::get( 'ga4_property_id' );
	}

	public function is_connected() {
		return $this->google->is_connected() && ! empty($this->property_id);
	}

	/**
	 * Every read below is a live Google Analytics Data API round trip, and the
	 * ones that matter are called straight from view files during render:
	 * dashboard.php calls get_conversions_trend(), which itself fires TWO
	 * reports (current window and the one before it), so simply opening the
	 * plugin's main screen cost two blocking 30s-timeout API calls - every
	 * load, every admin, forever. analytics.php adds a third with limit:1000,
	 * from a panel that renders whether or not its tab is the active one.
	 *
	 * None of this data changes minute to minute: GA4 itself only finalises
	 * figures on a multi-hour delay. Caching per property + method + arguments
	 * keeps distinct windows from colliding.
	 */
	private function cached( $method, array $args, callable $fetch, $ttl = HOUR_IN_SECONDS ) {
		$key = 'vmsb_ga4_' . md5( $this->property_id . '|' . $method . '|' . wp_json_encode( $args ) );

		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}

		$fresh = $fetch();

		// Don't cache an empty/failed read: a timeout or an expired token would
		// otherwise pin a blank dashboard in place for the whole TTL.
		if ( null === $fresh || array() === $fresh ) {
			return $fresh;
		}

		set_transient( $key, $fresh, (int) $ttl );
		return $fresh;
	}

	/**
	 * Get high-ROI landing pages (most conversions).
	 */
	public function get_top_landing_pages( $limit = 10, $days = 30 ) {
		return $this->cached( __FUNCTION__, array( $limit, $days ), function () use ( $limit, $days ) {
			return $this->fetch_top_landing_pages( $limit, $days );
		} );
	}

	private function fetch_top_landing_pages( $limit = 10, $days = 30 ) {
		if ( ! $this->is_connected() ) return array();

		// VMSB_Google exposes access_token(), not get_access_token(). All
		// three methods in this class called the name that does not exist, so
		// every one of them was a fatal on first use - which is what took down
		// opportunity-scan. It also returns a WP_Error when Google is not
		// connected or the refresh fails, so the result has to be checked
		// before it is concatenated into an Authorization header.
		$token = $this->google->access_token();
		if ( is_wp_error( $token ) ) return array();

		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'dateRanges' => array( array( 'startDate' => "{$days}daysAgo", 'endDate' => 'today' ) ),
				'dimensions' => array( array( 'name' => 'landingPage' ) ),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'keyEvents' ),
					array( 'name' => 'engagementRate' )
				),
				'limit' => $limit
			) ),
		) );

		if ( is_wp_error($response) ) return array();
		$body = json_decode( wp_remote_retrieve_body($response), true );

		$pages = array();
		if ( ! empty($body['rows']) ) {
			foreach ( $body['rows'] as $row ) {
				$pages[] = array(
					'url'         => $row['dimensionValues'][0]['value'],
					'sessions'    => (int) $row['metricValues'][0]['value'],
					'conversions' => (int) $row['metricValues'][1]['value'],
					'engagement'  => (float) $row['metricValues'][2]['value'],
				);
			}
		}

		return $pages;
	}

	/**
	 * Get conversion data for all landing pages.
	 */
	public function get_all_landing_page_metrics( $days = 30 ) {
		return $this->cached( __FUNCTION__, array( $days ), function () use ( $days ) {
			return $this->fetch_all_landing_page_metrics( $days );
		} );
	}

	private function fetch_all_landing_page_metrics( $days = 30 ) {
		if ( ! $this->is_connected() ) return array();

		$token = $this->google->access_token();
		if ( is_wp_error($token) ) return array();

		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'dateRanges' => array( array( 'startDate' => "{$days}daysAgo", 'endDate' => 'today' ) ),
				'dimensions' => array( array( 'name' => 'landingPage' ) ),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'keyEvents' ),
					array( 'name' => 'engagementRate' ),
					array( 'name' => 'totalRevenue' )
				),
				'limit' => 1000
			) ),
		) );

		if ( is_wp_error($response) ) return array();
		$body = json_decode( wp_remote_retrieve_body($response), true );

		$metrics = array();
		if ( ! empty($body['rows']) ) {
			foreach ( $body['rows'] as $row ) {
				$path = '/' . ltrim($row['dimensionValues'][0]['value'], '/');
				$metrics[$path] = array(
					'sessions'    => (int) $row['metricValues'][0]['value'],
					'conversions' => (int) $row['metricValues'][1]['value'],
					'engagement'  => (float) $row['metricValues'][2]['value'],
					'revenue'     => (float) $row['metricValues'][3]['value'],
				);
			}
		}

		return $metrics;
	}

	/**
	 * Site-wide conversion trend: current N-day window vs. the N-day window
	 * immediately before it, so the Dashboard can show a real percentage
	 * instead of a placeholder.
	 *
	 * @return array{current:int,previous:int,pct_change:float}|null null
	 *         when GA4 isn't connected, or the previous window had zero
	 *         conversions (a percentage against zero is not meaningful).
	 */
	public function get_conversions_trend( $days = 30 ) {
		return $this->cached( __FUNCTION__, array( $days ), function () use ( $days ) {
			return $this->fetch_conversions_trend( $days );
		} );
	}

	private function fetch_conversions_trend( $days = 30 ) {
		if ( ! $this->is_connected() ) return null;

		$token = $this->google->access_token();
		if ( is_wp_error( $token ) ) return null;

		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$fetch = function ( $offset_days ) use ( $endpoint, $token, $days ) {
			$response = wp_remote_post( $endpoint, array(
				'timeout' => 30,
				'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array(
					'dateRanges' => array( array(
						'startDate' => gmdate( 'Y-m-d', strtotime( '-' . ( $days + $offset_days ) . ' days' ) ),
						'endDate'   => gmdate( 'Y-m-d', strtotime( '-' . $offset_days . ' days' ) ),
					) ),
					'metrics' => array( array( 'name' => 'keyEvents' ) ),
				) ),
			) );
			if ( is_wp_error( $response ) ) return null;
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return isset( $body['rows'][0]['metricValues'][0]['value'] ) ? (int) $body['rows'][0]['metricValues'][0]['value'] : 0;
		};

		$current  = $fetch( 0 );
		$previous = $fetch( $days );
		if ( null === $current || null === $previous || $previous <= 0 ) {
			return null;
		}

		return array(
			'current'    => $current,
			'previous'   => $previous,
			'pct_change' => round( ( ( $current - $previous ) / $previous ) * 100, 1 ),
		);
	}

	/**
	 * Verify if tracking is healthy.
	 */
	public function audit_integrity() {
		if ( ! $this->is_connected() ) return array( 'ok' => false, 'message' => 'GA4 not connected.' );

		$token = $this->google->access_token();
		if ( is_wp_error( $token ) ) return array( 'ok' => false, 'message' => $token->get_error_message() );

		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( array(
				'dateRanges' => array( array( 'startDate' => '7daysAgo', 'endDate' => 'today' ) ),
				'dimensions' => array( array( 'name' => 'eventName' ) ),
				'metrics'    => array( array( 'name' => 'eventCount' ) ),
				'limit' => 20
			) ),
		) );

		if ( is_wp_error($response) ) return array( 'ok' => false, 'message' => $response->get_error_message() );
		$body = json_decode( wp_remote_retrieve_body($response), true );

		$events = array();
		if ( ! empty($body['rows']) ) {
			foreach ( $body['rows'] as $row ) {
				$events[] = $row['dimensionValues'][0]['value'];
			}
		}

		$found = array_intersect( array('page_view', 'session_start'), $events );
		return array(
			'ok' => count($found) >= 2,
			'events_detected' => count($events)
		);
	}
}
