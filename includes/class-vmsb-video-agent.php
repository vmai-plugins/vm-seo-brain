<?php
defined( 'ABSPATH' ) || exit;

/**
 * Video Pipeline Agent.
 * Generates high-fidelity video scripts and AI prompts for social dominance.
 */
class VMSB_Video_Agent {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Generate a complete video production pack for a post.
	 */
	public function generate_pack( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'not_found', 'Post not found.' );

		$brain  = new VMSB_Brain();
		$prompt = "Act as a Viral Video Producer (YouTube/TikTok).\n"
			. "ARTICLE: \"{$post->post_title}\"\n"
			. "CONTENT: " . wp_trim_words( wp_strip_all_tags($post->post_content), 500 ) . "\n\n"
			. "TASK: Generate a production pack to turn this article into a high-engagement video.\n"
			. "1. YouTube Script: 3-minute educational script with hook, meat, and CTA.\n"
			. "2. TikTok/Shorts Script: 30-60 second fast-paced hook script.\n"
			. "3. Video AI Prompts: Specific visual prompts for each scene (for tools like Sora or Kling).\n\n"
			. 'Return JSON: {"yt_script":"","shorts_script":"","ai_scene_prompts":[],"video_title":"","tags":[]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'creative' ) );

		if ( ! is_array($data) || empty($data['yt_script']) ) {
			return new WP_Error( 'ai_fail', 'Could not generate video pack.' );
		}

		update_post_meta( $post_id, '_vmsb_video_pack', $data );
		$this->log->info( 'video', "Video Production Pack generated for post #{$post_id}." );

		return $data;
	}

	/**
	 * Scans for high-traffic posts that don't have a video pack yet.
	 */
	public function sweep( $limit = 3 ) {
		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) return array();

		$data = $google->gsc_query( array( 'page' ), 28, 100 );
		if ( is_wp_error($data) ) return array();

		$count = 0;
		foreach ( $data as $row ) {
			if ( $count >= $limit ) break;
			if ( (int)$row['clicks'] < 50 ) continue;

			$post_id = url_to_postid( $row['keys'][0] );
			if ( ! $post_id ) continue;

			if ( ! get_post_meta( $post_id, '_vmsb_video_pack', true ) ) {
				$this->generate_pack( $post_id );
				$count++;
			}
		}

		return array( 'processed' => $count );
	}
}
