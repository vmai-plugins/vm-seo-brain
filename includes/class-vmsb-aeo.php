<?php
defined( 'ABSPATH' ) || exit;

/**
 * Answer Engine Optimization.
 *
 * Traditional SEO optimizes for a blue link. AEO optimizes for being the
 * sentence an AI answer engine or featured snippet quotes back verbatim. That
 * needs a specific shape: a direct, self-contained answer in the first 40-60
 * words of a section, phrased so it stands alone without the surrounding
 * article for context.
 *
 * This does not replace the article - it audits existing posts for
 * "quotable" structure and, where the answer is missing or buried, inserts a
 * short direct-answer block near the top of the relevant section.
 */
class VMSB_AEO {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Audit a post for answer-engine readiness.
	 *
	 * @return array{score:int,issues:array,questions:array}
	 */
	public function audit( $post_id ) {
		if ( ! (int) VMSB_Settings::get( 'feature_aeo', 1 ) ) {
			return new WP_Error( 'vmsb_aeo', 'AEO feature is disabled.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_aeo', 'Post not found.' );
		}

		$text = wp_strip_all_tags( $post->post_content );
		$first_120 = wp_trim_words( $text, 60 );

		$issues = array();
		$score  = 100;

		// Does the opening actually answer something, or is it throat-clearing?
		if ( preg_match( '/\b(in today\'s|in this article|in this post|welcome to|let\'s explore|let\'s dive)\b/i', $first_120 ) ) {
			$issues[] = 'Opens with throat-clearing instead of a direct answer.';
			$score   -= 25;
		}

		// H2/H3 phrased as questions are what answer engines key on.
		$question_headings = preg_match_all( '/<h[23][^>]*>[^<]*\?[^<]*<\/h[23]>/i', $post->post_content );
		if ( $question_headings === 0 ) {
			$issues[] = 'No question-phrased headings for the model to anchor an answer to.';
			$score   -= 20;
		}

		// A list or table is disproportionately likely to be lifted whole.
		if ( ! preg_match( '/<(ul|ol|table)[\s>]/i', $post->post_content ) ) {
			$issues[] = 'No list or table - these are the structures answer engines quote most often.';
			$score   -= 15;
		}

		if ( ! get_post_meta( $post_id, '_vmsb_faq_schema', true ) ) {
			$issues[] = 'No FAQ schema.';
			$score   -= 10;
		}

		$questions = $this->extract_answerable_questions( $post, $text );

		$result = array( 'score' => max( 0, $score ), 'issues' => $issues, 'questions' => $questions );
		update_post_meta( $post_id, '_vmsb_aeo_audit', $result );
		return $result;
	}

	/**
	 * What real questions does this content already answer, worth surfacing as
	 * an explicit direct-answer block? Grounded in the actual text - the model
	 * is asked to quote from it, not invent new claims.
	 */
	private function extract_answerable_questions( $post, $text ) {
		$prompt = "Article body:\n\n" . mb_substr( $text, 0, 6000 ) . "\n\n"
			. "Identify up to 4 real questions this article already answers. For each, write a direct 40-55 word answer using ONLY facts stated in the article - "
			. "do not add information that is not there. The answer must stand alone without needing the rest of the article for context.\n\n"
			. 'Return JSON: {"questions":[{"q":"","direct_answer":""}]}';

		$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 700, 'temperature' => 0.2, 'action' => 'aeo_sweep' ) );
		if ( ! is_array( $data ) ) {
			return array();
		}
		return array_slice( (array) ( $data['questions'] ?? array() ), 0, 4 );
	}

	/**
	 * SGE Mastery: Generate advanced content features for AI Search Engines.
	 * Includes Comparison Tables, Quick Summaries, and "AI Snapshot" blocks.
	 */
	public function generate_sge_features( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) return array();

		$brain  = new VMSB_Brain();
		$prompt = "Act as an SGE Optimization Specialist (Search Generative Experience).\n"
			. "ARTICLE: \"{$post->post_title}\"\n"
			. "CONTENT: " . mb_substr( wp_strip_all_tags($post->post_content), 0, 5000 ) . "\n\n"
			. "TASK: Create high-utility features that AI search engines (Gemini/Perplexity) love to quote.\n"
			. "1. A 'Key Takeaways' summary block.\n"
			. "2. A 'Comparison Table' or 'Fact Sheet' if the content involves choices, data, or technical specs.\n"
			. "3. A 'Quick Definition' for the primary term.\n\n"
			. 'Return JSON: {"summary_html":"","table_html":"","definition_html":"","features_added":[]}';

		return $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'auditor' ) );
	}

	/**
	 * Insert a direct-answer block for the strongest question, if one is
	 * missing near the top. Routed through the rollback-style revert payload
	 * so it can be undone like any other fix.
	 */
	public function apply( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_aeo', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages or unsafe post types
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_aeo', 'Safety: Cannot modify the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_aeo', 'Safety: This post type is not in the safe list for content modification.' );
		}

		$audit = $this->audit( $post_id );
		if ( is_wp_error( $audit ) || empty( $audit['questions'] ) ) {
			return new WP_Error( 'vmsb_aeo', 'Nothing groundable to insert.' );
		}

		$post = get_post( $post_id );
		$best = $audit['questions'][0];
		if ( empty( $best['direct_answer'] ) ) {
			return new WP_Error( 'vmsb_aeo', 'No usable answer.' );
		}

		// Already has a block for this question - do not duplicate.
		if ( false !== strpos( $post->post_content, 'vmsb-direct-answer' ) ) {
			return array( 'skipped' => 'already has a direct-answer block' );
		}

		// An Elementor page stores its layout as JSON, not post_content - a
		// direct write here would be invisible and can be discarded on the
		// next Elementor save.
		if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
			return new WP_Error( 'vmsb_aeo', 'This page is built in Elementor - direct-answer insertion into post_content is skipped to avoid corrupting the layout.' );
		}

		$block = '
<div class="vmsb-aeo-block vmsb-direct-answer" style="background:rgba(201,162,39,0.05); border-left:4px solid #c9a227; padding:25px; margin:30px 0; border-radius:0 8px 8px 0;">
	<p style="margin:0 0 10px; font-size:11px; text-transform:uppercase; letter-spacing:0.1em; color:#c9a227; font-weight:700;">Direct Answer</p>
	<h3 style="margin:0 0 15px; font-size:18px; line-height:1.4; color:inherit;">' . esc_html( $best['q'] ) . '</h3>
	<p style="margin:0; font-size:16px; line-height:1.6;">' . esc_html( $best['direct_answer'] ) . '</p>
</div>';

		// Insert after the first paragraph, not at the very top - a title with
		// no lead-in reads worse than one short sentence of framing.
		$updated = preg_replace( '/(<p[^>]*>.*?<\/p>)/is', '$1' . "\n" . $block, $post->post_content, 1 );
		if ( $updated === $post->post_content ) {
			$updated = $block . "\n" . $post->post_content;
		}

		$before = $post->post_content;

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $updated ) );

		return array( 'inserted' => true, 'question' => $best['q'], 'revert' => array( 'post_id' => $post_id, 'field' => 'post_content', 'value' => $before ) );
	}

	/**
	 * Sweep posts due for an AEO pass: never audited, or audited but scoring
	 * under 70.
	 */
	public function sweep( $limit = 5 ) {
		global $wpdb;
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$types_sql  = "'" . implode( "','", array_map( 'esc_sql', $safe_types ) ) . "'";

		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_vmsb_aeo_audit'
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$types_sql}) AND m.meta_id IS NULL
			 ORDER BY p.post_date DESC LIMIT %d",
			(int) $limit
		) );

		$done = 0;
		foreach ( $ids as $id ) {
			$audit = $this->audit( $id );
			if ( ! is_wp_error( $audit ) && $audit['score'] < 70 ) {
				$this->apply( $id );
			}
			$done++;
		}
		return array( 'processed' => $done );
	}
}
