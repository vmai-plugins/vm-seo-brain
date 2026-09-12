<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sentient Freshness Engine.
 * Injects real-time "Live Updates" into ranking content to signal QDF (Query Deserves Freshness).
 * Ported & Enhanced from VMAI Autopilot.
 */
class VMSB_Freshness {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Run the freshness scan on top-performing pages.
	 */
	public function run( $limit = 3 ) {
		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) return 0;

		$rows = $google->gsc_query( array( 'page' ), 28, 100 );
		if ( is_wp_error($rows) || empty($rows) ) return 0;

		usort( $rows, fn($a, $b) => ($b['clicks'] ?? 0) <=> ($a['clicks'] ?? 0) );

		$injected = 0;
		foreach ( array_slice($rows, 0, 10) as $row ) {
			if ( $injected >= $limit ) break;

			$post_id = url_to_postid( $row['keys'][0] );
			if ( ! $post_id ) continue;

			// Throttle: Max 1 update per 21 days per page to maintain quality
			$last_fresh = (int) get_post_meta($post_id, '_vmsb_last_freshness_injection', true);
			if ( (time() - $last_fresh) < 21 * DAY_IN_SECONDS ) continue;

			if ( ! is_wp_error( $this->inject_live_update($post_id) ) ) {
				$injected++;
			}
		}

		return $injected;
	}

	/**
	 * Scans for current news/trends and injects a small "Live Update" block.
	 */
	public function inject_live_update( $post_id ) {
		$post = get_post($post_id);
		if ( ! $post ) {
			return new WP_Error( 'vmsb_freshness', 'Post not found.' );
		}
		$keyword = ( new VMSB_RankMath() )->get_focus_keyword($post_id) ?: $post->post_title;
		$brain = new VMSB_Brain();

		$prompt = "Act as a Niche Intelligence Scout.\n"
			. "TOPIC: \"{$keyword}\"\n\n"
			. "TASK: Identify ONE high-value recent development, industry shift, or new data point related to this topic for " . date('F Y') . ".\n"
			. "Generate a 'Live Intelligence Update' block that provides immediate utility to the reader.\n"
			. "Return JSON: {\"heading\": \"\", \"content_html\": \"\"}";

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'strategist' ) );

		if ( empty($data['content_html']) ) {
			return new WP_Error( 'ai_fail', 'Could not generate freshness update.' );
		}

		$date_str = date('F j, Y');
		$block = "\n\n<!-- wp:group {\"className\":\"vmsb-freshness-block\"} -->\n"
			. "<div class=\"wp-block-group vmsb-freshness-block\" style=\"background:rgba(99,102,241,0.03); border-left:4px solid #6366f1; padding:25px; margin:35px 0; border-radius:0 12px 12px 0;\">\n"
			. "<div style=\"font-size:11px; font-weight:900; color:#6366f1; text-transform:uppercase; letter-spacing:0.1em; margin-bottom:12px;\">Live Intelligence Update: $date_str</div>\n"
			. "<h4 style=\"margin-top:0; font-weight:800; font-size:18px;\">" . esc_html($data['heading']) . "</h4>\n"
			. "<div style=\"font-size:15px; line-height:1.6; color:var(--text);\">" . wp_kses_post($data['content_html']) . "</div>\n"
			. "</div>\n"
			. "<!-- /wp:group -->\n\n";

		$content = $post->post_content;
		// Inject after the first H2
		if ( preg_match('/(<h2[^>]*>.*?<\/h2>)/is', $content, $m, PREG_OFFSET_CAPTURE) ) {
			$pos = $m[0][1] + strlen($m[0][0]);
			$content = substr_replace($content, $block, $pos, 0);
		} else {
			$content = $block . $content;
		}

		wp_update_post( array('ID' => $post_id, 'post_content' => $content) );
		update_post_meta($post_id, '_vmsb_last_freshness_injection', time());

		$this->log->info( 'freshness', "Freshness Signal: Injected live update into post #{$post_id} to signal QDF authority." );

		if ( class_exists('VMSB_Indexing') ) {
			( new VMSB_Indexing() )->submit($post_id);
		}

		return true;
	}
}
