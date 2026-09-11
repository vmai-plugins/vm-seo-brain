<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema engine for existing posts.
 *
 * Content produced through VMSB_Content::produce() already gets FAQ schema at
 * write time. This covers what that path can't: posts imported before the
 * plugin existed, or written by hand, that have no schema at all - plus a
 * graph/entity schema type the generation path doesn't add: linking a post to
 * its silo peers as a Schema.org WebPage/Article graph, which is what lets a
 * search engine understand topical clusters rather than isolated pages.
 */
class VMSB_Schema {

	private $ai;

	public function __construct() {
		$this->ai = new VMSB_AI_Router();
	}

	/**
	 * Generate FAQ schema for a post that has none, grounded strictly in its
	 * own content - never invents facts to answer a question the article
	 * doesn't actually cover.
	 */
	public function generate_faq( $post_id ) {
		if ( ! (int) VMSB_Settings::get( 'feature_schema', 1 ) ) {
			return array( 'skipped' => 'Schema feature is disabled' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_schema', 'Post not found.' );
		}

		// Check for existing FAQ schema (Native or Rank Math)
		if ( get_post_meta( $post_id, '_vmsb_faq_schema', true ) || get_post_meta( $post_id, 'rank_math_schema_FAQPage', true ) ) {
			return array( 'skipped' => 'already has FAQ schema' );
		}

		$prompt = "Article:\n\n" . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 5000 ) . "\n\n"
			. "Generate 3-4 FAQ question/answer pairs using ONLY information actually stated in this article - do not invent facts to answer a question it doesn't cover.\n\n"
			. 'Return JSON: {"faq":[{"q":"","a":""}]}';

		$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 600, 'temperature' => 0.2, 'action' => 'schema_sweep' ) );
		if ( empty( $data['faq'] ) ) {
			return new WP_Error( 'vmsb_schema', 'No FAQ content produced.' );
		}

		$entities = array();
		foreach ( $data['faq'] as $qa ) {
			if ( empty( $qa['q'] ) || empty( $qa['a'] ) ) {
				continue;
			}
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $qa['q'] ),
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( $qa['a'] ) ),
			);
		}
		if ( ! $entities ) {
			return new WP_Error( 'vmsb_schema', 'No usable FAQ pairs.' );
		}

		// Rank Math Compatibility Upgrade
		if ( class_exists( 'RankMath' ) ) {
			update_post_meta(
				$post_id,
				'rank_math_schema_FAQPage',
				array(
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
					'metadata'   => array( 'title' => 'FAQ', 'shortcode' => '', 'isPrimary' => 0 ),
				)
			);
			return array( 'added' => count( $entities ), 'provider' => 'Rank Math' );
		}

		$schema = array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $entities );
		update_post_meta( $post_id, '_vmsb_faq_schema', $schema );
		return array( 'added' => count( $entities ), 'provider' => 'VMSB Native' );
	}

	/**
	 * Connect a post to up to 3 peers in the same silo as a mentions graph -
	 * this is what lets a crawler infer the site covers a cluster of related
	 * subjects, not just one page in isolation.
	 */
	public function generate_graph( $post_id ) {
		if ( ! (int) VMSB_Settings::get( 'feature_schema', 1 ) ) {
			return array( 'skipped' => 'Schema feature is disabled' );
		}

		$post = get_post( $post_id );
		$cats = wp_get_post_categories( $post_id );
		if ( ! $post || ! $cats ) {
			return new WP_Error( 'vmsb_schema', 'No category to build a graph from.' );
		}

		$peers = get_posts( array(
			'category'       => $cats[0],
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'exclude'        => array( $post_id ),
			'posts_per_page' => 3,
		) );
		if ( ! $peers ) {
			return array( 'skipped' => 'no peers in this category yet' );
		}

		$mentions = array_map( static fn( $p ) => array( '@type' => 'Thing', 'name' => $p->post_title, 'url' => get_permalink( $p ) ), $peers );

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'WebPage',
			'@id'        => get_permalink( $post_id ) . '#webpage',
			'mainEntity' => array( '@type' => 'Article', 'headline' => $post->post_title, 'mentions' => $mentions ),
		);
		update_post_meta( $post_id, '_vmsb_graph_schema', $schema );
		return array( 'linked_peers' => count( $peers ) );
	}

	/**
	 * Posts published without any schema, oldest first.
	 */
	public function sweep( $limit = 5 ) {
		// Check for both native and Rank Math FAQ schema.
		$ids = VMSB_Settings::posts_missing_meta( array( '_vmsb_faq_schema', 'rank_math_schema_FAQPage' ), $limit );

		$done = 0;
		foreach ( $ids as $id ) {
			$this->generate_faq( $id );
			$this->generate_graph( $id );
			$done++;
		}
		return array( 'processed' => $done );
	}

	/**
	 * Print any stored schema for the current post on the frontend. Hooked
	 * once from the bootstrap.
	 */
	public static function print_schema() {
		// Match the same post types the fixer is allowed to generate schema
		// for (class-vmsb-fixer.php uses 'safe_post_types', default includes
		// 'page') - otherwise schema generated for a page is stored, an AI
		// call spent, and never actually rendered here.
		if ( ! is_singular( (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) ) ) ) {
			return;
		}
		$post_id = get_the_ID();

		// If Rank Math is handling the FAQ, don't print our native version to avoid duplicates.
		$has_rm_faq = get_post_meta( $post_id, 'rank_math_schema_FAQPage', true );

		foreach ( array( '_vmsb_faq_schema', '_vmsb_graph_schema' ) as $key ) {
			if ( $key === '_vmsb_faq_schema' && $has_rm_faq ) {
				continue;
			}
			$schema = get_post_meta( $post_id, $key, true );
			if ( $schema ) {
				echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
			}
		}
	}
}
