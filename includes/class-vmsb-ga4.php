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
	 * Get high-ROI landing pages (most conversions).
	 */
	public function get_top_landing_pages( $limit = 10, $days = 30 ) {
		if ( ! $this->is_connected() ) return array();

		$token = $this->google->get_access_token();
		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
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
		if ( ! $this->is_connected() ) return array();

		$token = $this->google->get_access_token();
		if ( is_wp_error($token) ) return array();

		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
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
	 * Verify if tracking is healthy.
	 */
	public function audit_integrity() {
		if ( ! $this->is_connected() ) return array( 'ok' => false, 'message' => 'GA4 not connected.' );

		$token = $this->google->get_access_token();
		$endpoint = "https://analyticsdata.googleapis.com/v1beta/properties/{$this->property_id}:runReport";

		$response = wp_remote_post( $endpoint, array(
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
