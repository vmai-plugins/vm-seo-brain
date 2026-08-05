<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outbound webhooks. Lets an external tool (Zapier, Make, a custom script)
 * react to what the brain does, instead of having to poll the REST API.
 */
class VMSB_Webhooks {

	const EVENTS = array(
		'content_published' => 'A post went live',
		'content_review'    => 'A draft was held for manual review',
		'content_failed'    => 'Content generation failed',
	);

	public function __construct() {
		// vmsb_post_produced already exists and fires exactly once, right
		// after a post is actually published - reusing it here means this
		// doesn't need its own new call site for the most common event.
		add_action( 'vmsb_post_produced', array( __CLASS__, 'on_post_produced' ) );
	}

	public static function on_post_produced( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		self::dispatch( 'content_published', array(
			'post_id' => $post_id,
			'title'   => $post->post_title,
			'url'     => get_permalink( $post_id ),
		) );
	}

	/**
	 * Fire the configured webhook for an event. Silently does nothing if
	 * webhooks are off, no URL is set, or this event isn't checked in
	 * Settings - callers don't need to check any of that themselves.
	 */
	public static function dispatch( $event, array $payload = array() ) {
		if ( ! (int) VMSB_Settings::get( 'webhook_enabled' ) ) {
			return;
		}
		$url = trim( (string) VMSB_Settings::get( 'webhook_url' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return;
		}
		$events = (array) VMSB_Settings::get( 'webhook_events' );
		if ( ! in_array( $event, $events, true ) ) {
			return;
		}

		$body = array_merge( $payload, array(
			'event' => $event,
			'site'  => home_url(),
			'time'  => current_time( 'mysql', true ),
		) );

		// Fire-and-forget: a slow or dead receiver on the other end must
		// never hold up the request that triggered it (a publish, a failed
		// generation). blocking=>false returns immediately without waiting
		// for or caring about the response.
		wp_remote_post( $url, array(
			'timeout'  => 5,
			'blocking' => false,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		) );

		if ( class_exists( 'VMSB_Logger' ) ) {
			( new VMSB_Logger() )->info( 'webhook', "Dispatched '{$event}' webhook." );
		}
	}
}
