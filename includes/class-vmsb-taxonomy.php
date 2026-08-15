<?php
defined( 'ABSPATH' ) || exit;

/**
 * Category and tag optimiser.
 *
 * Most WordPress sites leak crawl budget through taxonomy sprawl: dozens of
 * one-post tags, categories named for the author's convenience rather than a
 * search topic, empty archives that thin out the whole domain. This module
 * measures that, proposes a merged structure, and rewrites the archives so they
 * rank in their own right.
 */
class VMSB_Taxonomy {

	private $ai;
	private $brain;
	private $google;
	private $rankmath;
	private $log;

	public function __construct() {
		$this->ai       = new VMSB_AI_Router();
		$this->brain    = new VMSB_Brain();
		$this->google   = new VMSB_Google();
		$this->rankmath = new VMSB_RankMath();
		$this->log      = new VMSB_Logger();
	}

	/* ---------------------------------------------------------------- audit */

	/**
	 * Detect "Zombie Tags": Tags that have posts but have earned 0 impressions
	 * in Search Console over the last 90 days.
	 */
	public function find_zombie_tags( $limit = 50 ) {
		if ( ! $this->google->is_connected() ) return array();

		$tags = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true, 'number' => $limit ) );
		if ( is_wp_error($tags) || empty($tags) ) return array();

		// Fetch all page traffic for the last 90 days
		$rows = $this->google->gsc_query( array( 'page' ), 90, 1000 );
		if ( is_wp_error($rows) || empty($rows) ) return array();

		$trafficked_urls = wp_list_pluck( $rows, 'keys' );
		$trafficked_paths = array_filter( array_map( function($k) {
			return isset($k[0]) ? untrailingslashit( (string) wp_parse_url($k[0], PHP_URL_PATH) ) : null;
		}, $trafficked_urls ) );

		$zombies = array();
		foreach ( $tags as $tag ) {
			$link = get_term_link( $tag );
			if ( is_wp_error($link) ) continue;

			$path = untrailingslashit( (string) wp_parse_url( $link, PHP_URL_PATH ) );
			if ( ! in_array($path, $trafficked_paths, true) ) {
				$zombies[] = $tag;
			}
		}

		return $zombies;
	}

	public function audit( array $taxonomies = array( 'category', 'post_tag' ) ) {
		if ( ! (int) VMSB_Settings::get( 'feature_taxonomy', 1 ) ) {
			return 0;
		}

		$fixer  = new VMSB_Fixer();
		$issues = 0;

		foreach ( $taxonomies as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				// Empty archive — a soft 404 waiting to happen.
				if ( 0 === $term->count ) {
					$fixer->record( 'term', $term->term_id, 'empty_archive', 'medium', sprintf( '%s "%s" has no posts.', $tax, $term->name ), array( 'taxonomy' => $tax ) );
					$issues++;
					continue;
				}

				// Thin archive — one or two posts rarely justifies its own indexable page.
				if ( $term->count <= 2 && 'post_tag' === $tax ) {
					$fixer->record( 'term', $term->term_id, 'thin_tag', 'medium', sprintf( 'Tag "%s" holds only %d posts.', $term->name, $term->count ), array( 'taxonomy' => $tax, 'count' => $term->count ) );
					$issues++;
				}

				// No description — the archive has nothing to rank on.
				if ( '' === trim( (string) $term->description ) ) {
					$fixer->record( 'term', $term->term_id, 'missing_term_description', 'medium', sprintf( '%s "%s" has no description.', $tax, $term->name ), array( 'taxonomy' => $tax ) );
					$issues++;
				}

				// No Rank Math title on the archive.
				$meta = get_term_meta( $term->term_id, 'rank_math_title', true );
				if ( ! $meta ) {
					$fixer->record( 'term', $term->term_id, 'missing_term_seo_title', 'low', sprintf( '%s "%s" has no SEO title.', $tax, $term->name ), array( 'taxonomy' => $tax ) );
					$issues++;
				}
			}

			// 2026 Strategy: Zombie Tag Detection (Tags with 0 impressions)
			if ( 'post_tag' === $tax ) {
				$zombies = $this->find_zombie_tags( 30 );
				foreach ( $zombies as $z ) {
					$fixer->record( 'term', $z->term_id, 'zombie_tag', 'medium', "Tag '{$z->name}' has earned 0 impressions in 90 days. It is unnecessary 'Crawl Waste'.", array( 'taxonomy' => 'post_tag' ) );
					$issues++;
				}
			}

			// Near-duplicate terms.
			foreach ( $this->find_duplicates( $terms ) as $pair ) {
				$fixer->record( 'term', $pair['a']->term_id, 'duplicate_term', 'high', sprintf( '"%s" and "%s" cover the same topic.', $pair['a']->name, $pair['b']->name ), array( 'taxonomy' => $tax, 'merge_into' => $pair['b']->term_id ) );
				$issues++;
			}
		}

		$this->log->info( 'taxonomy', "Taxonomy audit found {$issues} issues." );
		return $issues;
	}

	private function find_duplicates( $terms ) {
		$pairs = array();
		$seen  = array();

		foreach ( $terms as $a ) {
			foreach ( $terms as $b ) {
				if ( $a->term_id >= $b->term_id ) {
					continue;
				}
				$key = $a->term_id . ':' . $b->term_id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				similar_text( mb_strtolower( $a->name ), mb_strtolower( $b->name ), $percent );
				$singular_a = rtrim( mb_strtolower( $a->name ), 's' );
				$singular_b = rtrim( mb_strtolower( $b->name ), 's' );

				if ( $percent > 85 || $singular_a === $singular_b ) {
					// Keep the one with more posts.
					$keep  = $a->count >= $b->count ? $a : $b;
					$merge = $a->count >= $b->count ? $b : $a;
					$pairs[] = array( 'a' => $merge, 'b' => $keep );
				}
			}
		}
		return $pairs;
	}

	/* ---------------------------------------------------------------- optimise */

	/**
	 * Write a real archive: intro copy, SEO title, description, and internal
	 * links to the strongest posts inside the term.
	 */
	public function optimise_term( $term_id, $taxonomy = 'category' ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return new WP_Error( 'vmsb_tax', 'Term not found.' );
		}

		$posts = get_posts(
			array(
				'posts_per_page' => 12,
				'tax_query'      => array( array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $term_id ) ),
			)
		);
		$titles = wp_list_pluck( $posts, 'post_title' );

		$data = $this->ai->generate_json(
			"Write the archive copy for a WordPress {$taxonomy} named \"{$term->name}\".\n"
			. 'Posts inside it: ' . implode( ' | ', $titles ) . "\n\n"
			. "The description is a high-impact introduction for the archive page, between 60 and 90 words. Focus on utility and topical depth. No fluff.\n"
			. "The SEO title is under 60 characters. The meta description is under 155 characters and gives a reason to click.\n\n"
			. 'Return JSON: {"seo_title":"","meta_description":"","archive_intro":"","focus_keyword":""}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 900, 'temperature' => 0.5 )
		);

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_tax', 'The AI chain returned nothing usable.' );
		}

		$before = array(
			'description'      => $term->description,
			'rank_math_title'  => get_term_meta( $term_id, 'rank_math_title', true ),
			'rank_math_description' => get_term_meta( $term_id, 'rank_math_description', true ),
		);

		if ( ! empty( $data['archive_intro'] ) ) {
			wp_update_term( $term_id, $taxonomy, array( 'description' => wp_kses_post( $data['archive_intro'] ) ) );
		}
		if ( ! empty( $data['seo_title'] ) ) {
			update_term_meta( $term_id, 'rank_math_title', sanitize_text_field( $data['seo_title'] ) );
		}
		if ( ! empty( $data['meta_description'] ) ) {
			update_term_meta( $term_id, 'rank_math_description', sanitize_text_field( $data['meta_description'] ) );
		}
		if ( ! empty( $data['focus_keyword'] ) ) {
			update_term_meta( $term_id, 'rank_math_focus_keyword', sanitize_text_field( $data['focus_keyword'] ) );
		}

		return $before;
	}

	/**
	 * Merge one term into another, moving posts and leaving a redirect behind.
	 */
	public function merge( $from_id, $into_id, $taxonomy = 'post_tag' ) {
		$from = get_term( $from_id, $taxonomy );
		$into = get_term( $into_id, $taxonomy );
		if ( ! $from || ! $into || is_wp_error( $from ) || is_wp_error( $into ) ) {
			return new WP_Error( 'vmsb_tax', 'One of the terms does not exist.' );
		}

		$posts = get_posts(
			array(
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => $from_id ) ),
			)
		);

		foreach ( $posts as $post_id ) {
			wp_set_object_terms( $post_id, array( (int) $into_id ), $taxonomy, true );
			wp_remove_object_terms( $post_id, array( (int) $from_id ), $taxonomy );
		}

		$old_url = get_term_link( $from, $taxonomy );
		$new_url = get_term_link( $into, $taxonomy );
		wp_delete_term( $from_id, $taxonomy );

		if ( ! is_wp_error( $old_url ) && ! is_wp_error( $new_url ) ) {
			$this->add_redirect( $old_url, $new_url );
		}

		$this->log->info( 'taxonomy', sprintf( 'Merged "%s" into "%s" (%d posts moved).', $from->name, $into->name, count( $posts ) ) );
		return count( $posts );
	}

	/**
	 * Deindex a thin archive rather than deleting it, so nothing breaks.
	 */
	public function noindex_term( $term_id ) {
		$before = get_term_meta( $term_id, 'rank_math_robots', true );
		update_term_meta( $term_id, 'rank_math_robots', array( 'noindex', 'follow' ) );
		return $before;
	}

	/**
	 * Rank Math redirections table when available; otherwise our own store,
	 * served on template_redirect.
	 */
	public function add_redirect( $from, $to, $type = 301 ) {
		if ( class_exists( 'RankMath\Redirections\DB' ) ) {
			$added = \RankMath\Redirections\DB::add(
				array(
					'sources'     => array( array( 'pattern' => wp_parse_url( $from, PHP_URL_PATH ), 'comparison' => 'exact' ) ),
					'url_to'      => $to,
					'header_code' => $type,
					'status'      => 'active',
				)
			);
			// If the RankMath insert genuinely failed, fall through to our own
			// store rather than reporting success on a redirect that doesn't
			// exist - leaving the merged/removed URL a silent 404.
			if ( $added ) {
				return true;
			}
		}

		$brain = new VMSB_Brain();
		$map   = (array) $brain->recall( 'redirects', 'map', array() );
		$map[ untrailingslashit( wp_parse_url( $from, PHP_URL_PATH ) ) ] = $to;
		$brain->remember( 'redirects', 'map', $map, 1.0, 'taxonomy' );
		return true;
	}

	/**
	 * Suggest which categories the whole site should actually have, based on
	 * the keyword clusters rather than the history of what got typed in.
	 */
	public function propose_structure() {
		global $wpdb;
		$table    = $wpdb->prefix . 'vmsb_keywords';
		$clusters = $wpdb->get_results( "SELECT cluster, COUNT(*) n, SUM(impressions) imp FROM {$table} WHERE cluster IS NOT NULL AND cluster != '' GROUP BY cluster ORDER BY imp DESC LIMIT 30", ARRAY_A );

		$current = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
		$current_list = is_wp_error( $current ) ? array() : array_map( static fn( $t ) => array( 'id' => $t->term_id, 'name' => $t->name, 'count' => $t->count ), $current );

		return $this->ai->generate_json(
			"Current WordPress categories:\n" . wp_json_encode( $current_list )
			. "\n\nKeyword clusters with demand:\n" . wp_json_encode( $clusters )
			. "\n\nPropose the category structure this site should have. Fewer, stronger categories beat many weak ones. For each current category say keep, rename, merge, or deindex, and why.\n\n"
			. 'Return JSON: {"target_categories":[{"name":"","slug":"","covers":[]}],"actions":[{"term_id":0,"action":"keep|rename|merge|deindex","new_name":"","merge_into":"","reason":""}]}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 2500, 'temperature' => 0.3 )
		);
	}
}
