<?php
defined( 'ABSPATH' ) || exit;

/**
 * Entity and semantic authority.
 *
 * Search engines increasingly rank topical authority, not just keyword match -
 * do the pages on this site collectively cover the entities (people, tools,
 * concepts, related terms) a real expert on the subject would mention? A page
 * that only ever says "SEO" and never "Search Console", "crawl budget", or
 * "canonical tag" reads thinner to an entity-aware ranker than one that does,
 * even at identical length.
 *
 * This audits entity coverage against the vector-indexed corpus (what the
 * site already covers) and against what the model knows a comprehensive
 * article on the topic would include, then injects only entities that are
 * genuinely missing - it does not pad content with buzzwords.
 */
class VMSB_Entity {

	private $ai;

	public function __construct() {
		$this->ai = new VMSB_AI_Router();
	}

	/**
	 * @return array{score:int,present:array,missing:array}
	 */
	public function audit( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_entity', 'Post not found.' );
		}

		$text   = wp_strip_all_tags( $post->post_content );
		$brain  = new VMSB_Brain();
		$prompt = "Topic: \"{$post->post_title}\"\n\nArticle body (first 5000 chars):\n" . mb_substr( $text, 0, 5000 ) . "\n\n"
			. "List the specific entities (named tools, concepts, standards, roles, related terms - not generic words) a genuinely expert article on this "
			. "topic would mention. Then say which of those are actually present in the article and which are missing.\n\n"
			. 'Return JSON: {"expected_entities":[""],"present":[""],"missing":[""]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 600, 'temperature' => 0.2, 'action' => 'entity_sweep' ) );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_entity', $this->ai->get_last_error() ?: 'No usable response.' );
		}

		$expected = max( 1, count( (array) ( $data['expected_entities'] ?? array() ) ) );
		$present  = count( (array) ( $data['present'] ?? array() ) );
		$score    = (int) round( min( 100, $present / $expected * 100 ) );

		$result = array( 'score' => $score, 'present' => (array) ( $data['present'] ?? array() ), 'missing' => (array) ( $data['missing'] ?? array() ) );
		update_post_meta( $post_id, '_vmsb_entity_audit', $result );
		return $result;
	}

	/**
	 * Weave the strongest missing entities into the existing text naturally -
	 * not a bolted-on list, edits inside real sentences where they fit. Caps
	 * how many are added in one pass so a single edit stays reviewable.
	 */
	public function inject( $post_id, $max_entities = 4 ) {
		$audit = get_post_meta( $post_id, '_vmsb_entity_audit', true );
		if ( ! $audit ) {
			$audit = $this->audit( $post_id );
		}
		if ( is_wp_error( $audit ) || empty( $audit['missing'] ) ) {
			return array( 'skipped' => 'nothing missing' );
		}

		if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
			return new WP_Error( 'vmsb_entity', 'This page is built in Elementor - full post_content rewrite is skipped to avoid corrupting the layout.' );
		}

		$post    = get_post( $post_id );
		$targets = array_slice( $audit['missing'], 0, $max_entities );

		$prompt = "Article HTML:\n\n" . $post->post_content . "\n\n"
			. 'These specific entities are missing from the article and should each be woven into an existing, relevant sentence (not a new list, not a new section): '
			. wp_json_encode( $targets ) . "\n\n"
			. "Rules: edit sentences that already discuss the related idea; every entity must appear in a factually accurate context; do not fabricate a claim about it; "
			. "keep the edits minimal - return the full HTML with only these small insertions, everything else unchanged.\n\n"
			. 'Return JSON: {"content_html":"","entities_added":[""]}';

		$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 6000, 'temperature' => 0.3 ) );
		if ( empty( $data['content_html'] ) ) {
			return new WP_Error( 'vmsb_entity', $this->ai->get_last_error() ?: 'Injection failed.' );
		}

		$before = $post->post_content;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $data['content_html'] ) ) );

		// Re-index immediately so the next duplicate/related check reflects the
		// richer version, not the stale one.
		if ( class_exists( 'VMSB_Vector_Store' ) && (int) VMSB_Settings::get( 'vector_enabled' ) ) {
			$fresh = get_post( $post_id );
			VMSB_Vector_Store::upsert( VMSB_Vector_Store::TYPE_POST, $post_id, VMSB_Vector_Store::post_text( $fresh ), array( 'label' => $fresh->post_title ) );
		}

		return array( 'added' => $data['entities_added'] ?? $targets, 'revert' => array( 'post_id' => $post_id, 'field' => 'post_content', 'value' => $before ) );
	}

	/**
	 * Posts never entity-audited, or audited under 60, oldest first.
	 */
	public function sweep( $limit = 5 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_vmsb_entity_audit'
			 WHERE p.post_status = 'publish' AND p.post_type = 'post' AND m.meta_id IS NULL
			 ORDER BY p.post_date DESC LIMIT %d",
			(int) $limit
		) );

		$done = 0;
		foreach ( $ids as $id ) {
			$audit = $this->audit( $id );
			if ( ! is_wp_error( $audit ) && $audit['score'] < 60 ) {
				$this->inject( $id );
			}
			$done++;
		}
		return array( 'processed' => $done );
	}

	public function get_missing_entities( $post_id ) {
		$audit = get_post_meta( $post_id, '_vmsb_entity_audit', true );
		if ( ! $audit ) {
			$audit = $this->audit( $post_id );
		}
		return is_wp_error( $audit ) ? array() : (array) ( $audit['missing'] ?? array() );
	}

	/**
	 * Weave a single, specifically named entity into the existing text -
	 * used by surgical fixers (e.g. the Duel) that already know exactly
	 * what is missing, without re-running a full audit.
	 */
	public function inject_specific( $post_id, $entity ) {
		if ( ! $entity ) {
			return new WP_Error( 'vmsb_entity', 'No entity specified.' );
		}

		if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
			return new WP_Error( 'vmsb_entity', 'This page is built in Elementor - full post_content rewrite is skipped to avoid corrupting the layout.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_entity', 'Post not found.' );
		}

		$prompt = "Article HTML:\n\n" . $post->post_content . "\n\n"
			. 'This specific entity is missing from the article and should be woven into an existing, relevant sentence (not a new list, not a new section): '
			. wp_json_encode( $entity ) . "\n\n"
			. "Rules: edit a sentence that already discusses the related idea; it must appear in a factually accurate context; do not fabricate a claim about it; "
			. "keep the edit minimal - return the full HTML with only this small insertion, everything else unchanged.\n\n"
			. 'Return JSON: {"content_html":""}';

		$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 6000, 'temperature' => 0.3 ) );
		if ( empty( $data['content_html'] ) ) {
			return new WP_Error( 'vmsb_entity', $this->ai->get_last_error() ?: 'Injection failed.' );
		}

		$before = $post->post_content;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $data['content_html'] ) ) );

		if ( class_exists( 'VMSB_Vector_Store' ) && (int) VMSB_Settings::get( 'vector_enabled' ) ) {
			$fresh = get_post( $post_id );
			VMSB_Vector_Store::upsert( VMSB_Vector_Store::TYPE_POST, $post_id, VMSB_Vector_Store::post_text( $fresh ), array( 'label' => $fresh->post_title ) );
		}

		return array( 'added' => array( $entity ), 'revert' => array( 'post_id' => $post_id, 'field' => 'post_content', 'value' => $before ) );
	}
}
