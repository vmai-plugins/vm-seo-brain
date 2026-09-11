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
	 * Process "Golden Oldies" for social distribution.
	 *
	 * Used to also cover freshly-published posts (last 24h), but that's
	 * exactly what VM Social AI's own publish_post hook already handles -
	 * it fires on any post publish regardless of source (VM SEO Brain,
	 * an external automation, or a human editor) and has done so since
	 * before this scheduled task next runs. Running our own AI call for
	 * the same fresh post paid for social copy twice and risked two
	 * uncoordinated posts on the same channels. Recycling old content is
	 * the part VM Social AI's one-time, publish-moment hook can never
	 * reach on its own, so that's what this is scoped to now.
	 */
	public function process_recent( $limit = 3 ) {
		$done = 0;

		// Golden Oldies (High traffic, older than 90 days, not shared recently)
		$oldies = get_posts( array(
			'posts_per_page' => $limit,
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
			$this->generate_social_pack( $post->ID );
			update_post_meta( $post->ID, '_vmsb_social_last_recycle', current_time( 'mysql' ) );
			$this->log->info( 'social', "Recycling Golden Oldie: {$post->post_title}" );
			$done++;
		}

		return $done;
	}

	/**
	 * Generate a pack of content for LinkedIn, X (Twitter), and Facebook.
	 */
	public function generate_social_pack( $post_id ) {
		// Reached from the 'social-generate' REST route with a caller-supplied
		// id. get_post() returns null for an id that does not exist (or was
		// trashed between page render and click), and the next line
		// dereferenced it unconditionally - a hard fatal, surfaced to the
		// browser as an opaque 500 rather than a usable message.
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'vmsb_social', 'That post no longer exists.' );
		}

		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$url  = get_permalink( $post_id );

		$prompt = "Transform this blog article into a 'Viral Social Distribution Pack'.\n\n"
			. "ARTICLE TITLE: {$post->post_title}\n"
			. "CONTENT EXCERPT: " . mb_substr( $text, 0, 5000 ) . "\n\n"
			. "TASK: Create high-engagement posts for:\n"
			. "1. LinkedIn: Use a 'Thumb-Stopping' professional hook. Tell a mini-story about why this topic matters.\n"
			. "2. X / Twitter: Create a 5-tweet thread. Tweet 1 must be a viral hook (Contrarian or Question). Keep tweets punchy.\n"
			. "3. Facebook: Friendly, community-driven approach with a clear call to action.\n"
			. "4. YouTube Short: a punchy, curiosity-driven title under 60 characters (this becomes the video's search query, so it "
			. "needs to work as a visual concept, not just a headline) and a short spoken-style caption/description that stands on its own without reading the article.\n\n"
			. "Include the URL at the end: {$url}\n\n"
			. 'Return JSON: {"linkedin":{"post":""}, "x":{"thread":[]}, "facebook":{"post":""}, "youtube_short":{"title":"","caption":""}}';

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
	 *
	 * VM Social AI has no REST or PHP API for external plugins to hand it
	 * already-written content - its routes (queue/list, queue/update, ...)
	 * are for its own admin UI to manage rows that already exist, not to
	 * create new ones from outside. VMSAI_Install::table() is the one part
	 * of its structure that IS a stable, public surface (a public static
	 * method, not a guessed table-name string), so that's what this uses
	 * instead of hand-rolling `$wpdb->prefix . 'vmsai_queue'`. Rows also now
	 * carry the real active campaign_id instead of being left at the
	 * schema's default 0, so they don't look orphaned to any of VM Social
	 * AI's own reporting that assumes a queue row belongs to a real
	 * campaign.
	 */
	private function push_to_vmsai( $post_id, $data ) {
		if ( ! class_exists( 'VMSAI_Install' ) ) {
			return;
		}

		global $wpdb;
		$table = VMSAI_Install::table( 'queue' );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$this->log->warn( 'social', 'VM Social AI is active but its queue table is missing - skipped the bridge.' );
			return;
		}

		$campaign    = class_exists( 'VMSAI_Planner' ) ? VMSAI_Planner::active_campaign() : null;
		$campaign_id = $campaign['id'] ?? 0;

		$post = get_post( $post_id );
		$now  = current_time( 'mysql' );

		// 1. LinkedIn
		if ( ! empty( $data['linkedin']['post'] ) ) {
			$wpdb->insert( $table, array(
				'campaign_id'  => $campaign_id,
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
				'campaign_id'  => $campaign_id,
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
				'campaign_id'  => $campaign_id,
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

		// 4. YouTube Short - VM Social AI's own YouTube channel already calls
		// its video engine internally (VMSAI_Channel_Youtube::publish() reads
		// title/body straight off the queue row to generate the clip), so
		// there's no video to generate or upload here - just a well-formed
		// row for its dispatcher to pick up.
		if ( ! empty( $data['youtube_short']['title'] ) ) {
			$wpdb->insert( $table, array(
				'campaign_id'  => $campaign_id,
				'channel'      => 'youtube',
				'format'       => 'video',
				'title'        => sanitize_text_field( $data['youtube_short']['title'] ),
				'body'         => $data['youtube_short']['caption'] ?? '',
				'link'         => get_permalink( $post_id ),
				'status'       => 'pending',
				'scheduled_at' => date( 'Y-m-d H:i:s', strtotime( '+4 hours' ) ),
				'created_at'   => $now,
			) );
		}

		$this->log->info( 'social', "Bridged content pack to VM Social AI queue for: {$post->post_title}" );
	}
}
