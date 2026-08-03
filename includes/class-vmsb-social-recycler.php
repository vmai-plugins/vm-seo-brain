<?php
defined( 'ABSPATH' ) || exit;

/**
 * Social Media Recycler.
 * Turns published blog posts into high-engagement social media content.
 */
class VMSB_Social_Recycler {

	private $ai;
	private $brain;
	private $log;

	public function __construct() {
		$this->ai    = new VMSB_AI_Router();
		$this->brain = new VMSB_Brain();
		$this->log   = new VMSB_Logger();
	}

	/**
	 * Process recently published posts and "Golden Oldies" for social distribution.
	 * Advanced 2026: Automatic authority recycling.
	 */
	public function process_recent( $limit = 3 ) {
		$done = 0;

		// 1. Fresh Content (Last 24 hours)
		$fresh = get_posts( array(
			'posts_per_page' => 2,
			'meta_query'     => array( array( 'key' => '_vmsb_social_done', 'compare' => 'NOT EXISTS' ) ),
			'post_status'    => 'publish',
			'post_type'      => 'post',
			'date_query'     => array( 'after' => '24 hours ago' )
		) );

		foreach ( $fresh as $post ) {
			if ( $done >= $limit ) break;
			$this->generate_social_pack( $post->ID );
			update_post_meta( $post->ID, '_vmsb_social_done', current_time( 'mysql' ) );
			$done++;
		}

		// 2. Golden Oldies (High traffic, older than 90 days, not shared recently)
		if ( $done < $limit ) {
			$oldies = get_posts( array(
				'posts_per_page' => 1,
				'post_status'    => 'publish',
				'post_type'      => 'post',
				'date_query'     => array( 'before' => '90 days ago' ),
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_vmsb_social_last_recycle', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_vmsb_social_last_recycle', 'value' => date( 'Y-m-d', strtotime( '-30 days' ) ), 'compare' => '<' )
				)
			) );

			foreach ( $oldies as $post ) {
				if ( $done >= $limit ) break;
				$this->generate_social_pack( $post->ID );
				update_post_meta( $post->ID, '_vmsb_social_last_recycle', current_time( 'mysql' ) );
				$this->log->info( 'social', "Recycling Golden Oldie: {$post->post_title}" );
				$done++;
			}
		}

		return $done;
	}

	/**
	 * Generate a pack of content for LinkedIn, X (Twitter), and Facebook.
	 */
	public function generate_social_pack( $post_id ) {
		$post = get_post( $post_id );
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$url  = get_permalink( $post_id );

		$prompt = "Transform this blog article into a 'Viral Social Distribution Pack'.\n\n"
			. "ARTICLE TITLE: {$post->post_title}\n"
			. "CONTENT EXCERPT: " . mb_substr( $text, 0, 5000 ) . "\n\n"
			. "TASK: Create high-engagement posts for:\n"
			. "1. LinkedIn: Use a 'Thumb-Stopping' professional hook. Tell a mini-story about why this topic matters.\n"
			. "2. X / Twitter: Create a 5-tweet thread. Tweet 1 must be a viral hook (Contrarian or Question). Keep tweets punchy.\n"
			. "3. Facebook: Friendly, community-driven approach with a clear call to action.\n\n"
			. "Include the URL at the end: {$url}\n\n"
			. 'Return JSON: {"linkedin":{"post":""}, "x":{"thread":[]}, "facebook":{"post":""}}';

		$data = $this->ai->generate_json( $prompt, array(
			'system' => $this->brain->context_prompt(),
			'complexity' => 'premium',
			'persona' => 'wordsmith'
		) );

		if ( ! empty( $data ) ) {
			update_post_meta( $post_id, '_vmsb_social_pack', $data );
			$this->log->info( 'social', "Generated social distribution pack for: {$post->post_title}" );

			// Integration: Push to VM Social AI if active
			$this->push_to_vmsai( $post_id, $data );

			return $data;
		}

		return false;
	}

	/**
	 * Bridge to VM Social AI: Injects the generated pack directly into the social queue.
	 */
	private function push_to_vmsai( $post_id, $data ) {
		if ( ! class_exists( 'VMSAI_Plugin' ) ) return;

		global $wpdb;
		$table = $wpdb->prefix . 'vmsai_queue';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

		$post = get_post( $post_id );
		$now  = current_time( 'mysql' );

		// 1. LinkedIn
		if ( ! empty( $data['linkedin']['post'] ) ) {
			$wpdb->insert( $table, array(
				'channel'      => 'linkedin',
				'format'       => 'image',
				'title'        => $post->post_title,
				'body'         => $data['linkedin']['post'],
				'link'         => get_permalink( $post_id ),
				'media_id'     => get_post_thumbnail_id( $post_id ),
				'status'       => 'pending',
				'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+1 hour' ) ),
				'created_at'   => $now,
			) );
		}

		// 2. X (Twitter) - Join thread into body or handle first tweet
		if ( ! empty( $data['x']['thread'] ) ) {
			$thread = (array) $data['x']['thread'];
			$wpdb->insert( $table, array(
				'channel'      => 'x',
				'format'       => 'text',
				'title'        => $post->post_title,
				'body'         => implode( "\n\n", $thread ),
				'link'         => get_permalink( $post_id ),
				'status'       => 'pending',
				'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+2 hours' ) ),
				'created_at'   => $now,
			) );
		}

		// 3. Facebook
		if ( ! empty( $data['facebook']['post'] ) ) {
			$wpdb->insert( $table, array(
				'channel'      => 'facebook',
				'format'       => 'image',
				'title'        => $post->post_title,
				'body'         => $data['facebook']['post'],
				'link'         => get_permalink( $post_id ),
				'media_id'     => get_post_thumbnail_id( $post_id ),
				'status'       => 'pending',
				'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+3 hours' ) ),
				'created_at'   => $now,
			) );
		}

		$this->log->info( 'social', "Bridged content pack to VM Social AI queue for: {$post->post_title}" );
	}
}
