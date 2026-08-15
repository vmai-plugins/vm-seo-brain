<?php
defined( 'ABSPATH' ) || exit;

/**
 * Google Indexing API Bridge.
 * Notifies Google instantly when content is published or updated.
 */
class VMSB_Indexing {

	public function __construct() {
		add_action( 'wp_insert_post', array( $this, 'on_post_update' ), 10, 3 );
		add_action( 'vmsb_post_produced', array( $this, 'submit' ) );
	}

	public function on_post_update( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		// Rate limit manual updates to once per hour per post.
		$last_submit = get_post_meta( $post_id, '_vmsb_indexed_at', true );
		if ( $last_submit && ( time() - (int) $last_submit ) < HOUR_IN_SECONDS ) {
			return;
		}

		$this->submit( $post_id );
	}

	public function submit( $post_id, $type = 'URL_UPDATED' ) {
		$google = new VMSB_Google();
		$url    = get_permalink( $post_id );

		// The Indexing API requires a specific scope not in the default Brain list.
		// We use the existing OAuth token but it must have been granted with the indexing scope.
		$res = $google->request(
			'https://indexing.googleapis.com/v3/urlNotifications:publish',
			'POST',
			array(
				'url'  => $url,
				'type' => $type,
			)
		);

		if ( ! is_wp_error( $res ) ) {
			update_post_meta( $post_id, '_vmsb_indexed_at', time() );
			( new VMSB_Logger() )->info( 'indexing', "Indexing API: Submitted {$url}" );
		}

		return $res;
	}
}
