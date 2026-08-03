<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO Knowledge Graph (Digital Twin).
 *
 * Represents the site as a web of relationships (triples) rather than just pages.
 * Format: [Subject] --(Relationship)--> [Object]
 * e.g. [Post 123] --(Covers Entity)--> [Sustainable SEO]
 *      [Category A] --(Contains Pillar)--> [Post 456]
 */
class VMSB_Graph {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_graph';
	}

	public static function add_edge( $subject_type, $subject_id, $predicate, $object_type, $object_id, $weight = 1.0 ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO " . self::table() . "
			(subject_type, subject_id, predicate, object_type, object_id, weight, updated_at)
			VALUES (%s, %d, %s, %s, %s, %f, %s)
			ON DUPLICATE KEY UPDATE weight = %f, updated_at = %s",
			$subject_type, $subject_id, $predicate, $object_type, $object_id, $weight, current_time('mysql'),
			$weight, current_time('mysql')
		));
	}

	/**
	 * Map the site's semantic structure.
	 * Advanced 2026: Batched build to prevent VPS timeouts on large sites.
	 */
	public static function build_twin( $limit = 100, $offset = 0 ) {
		$posts = get_posts( array(
			'post_type' => array('post', 'page'),
			'posts_per_page' => (int) $limit,
			'offset' => (int) $offset,
			'post_status' => 'publish'
		) );

		if ( ! $posts ) return 0;

		foreach ( $posts as $post ) {
			// 1. Structural Edges
			$categories = wp_get_post_categories( $post->ID );
			foreach ( $categories as $cat_id ) {
				self::add_edge( 'post', $post->ID, 'belongs_to', 'category', $cat_id );
			}

			// 2. Semantic Edges
			$entities = get_post_meta( $post->ID, '_vmsb_entities', true );
			if ( is_array( $entities ) ) {
				foreach ( $entities as $entity ) {
					self::add_edge( 'post', $post->ID, 'covers_entity', 'entity', $entity );
				}
			}

			// 3. Link Edges (Parse content for internal links)
			if ( preg_match_all('/href="([^"]+)"/i', $post->post_content, $matches) ) {
				$home = home_url();
				foreach ( $matches[1] as $url ) {
					if ( strpos($url, $home) !== false ) {
						$target_id = url_to_postid($url);
						if ( $target_id ) {
							self::add_edge( 'post', $post->ID, 'links_to', 'post', $target_id );
						}
					}
				}
			}
		}
		return count($posts);
	}

	public static function is_empty() {
		global $wpdb;
		return ! (bool) $wpdb->get_var( "SELECT id FROM " . self::table() . " LIMIT 1" );
	}

	public static function get_related_entities( $post_id ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare(
			"SELECT object_id FROM " . self::table() . " WHERE subject_id = %d AND predicate = 'covers_entity'",
			$post_id
		));
	}

	public static function get_topical_competitors( $post_id ) {
		// Logic to find other posts covering the same entities
		global $wpdb;
		$entities = self::get_related_entities( $post_id );
		if ( ! $entities ) return array();

		$placeholders = implode( ',', array_fill( 0, count( $entities ), '%s' ) );
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT subject_id, COUNT(*) as overlap
			 FROM " . self::table() . "
			 WHERE object_id IN ($placeholders) AND subject_id != %d AND subject_type = 'post'
			 GROUP BY subject_id ORDER BY overlap DESC",
			array_merge( $entities, array( $post_id ) )
		));
	}

	/**
	 * Data provider for the Internal Link Heatmap.
	 * Calculates 'Link Juice' flow based on inbound edges.
	 */
	public static function get_link_density_map() {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT object_id as post_id, COUNT(*) as inbound_links, SUM(weight) as total_weight
			 FROM " . self::table() . "
			 WHERE predicate IN ('belongs_to', 'links_to', 'references')
			 AND object_type = 'post'
			 GROUP BY object_id
			 ORDER BY inbound_links DESC"
		);
	}
}
