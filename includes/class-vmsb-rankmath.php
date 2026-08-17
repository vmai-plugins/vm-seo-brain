<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pro-Level Rank Math Bridge.
 *
 * Deeply integrates with Rank Math's internal state, meta fields,
 * and high-fidelity analytics tables.
 */
class VMSB_RankMath {

	/**
	 * Check if Rank Math is active.
	 */
	public function is_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/* ---------------------------------------------------------------- meta accessors */

	public function get_title( $post_id ) {
		return get_post_meta( $post_id, 'rank_math_title', true );
	}

	public function get_description( $post_id ) {
		return get_post_meta( $post_id, 'rank_math_description', true );
	}

	public function get_focus_keyword( $post_id ) {
		return get_post_meta( $post_id, 'rank_math_focus_keyword', true );
	}

	public function get_score( $post_id ) {
		return (int) get_post_meta( $post_id, 'rank_math_seo_score', true );
	}

	public function get_robots( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		return is_array( $robots ) ? $robots : array();
	}

	public function get_canonical( $post_id ) {
		return get_post_meta( $post_id, 'rank_math_canonical_url', true );
	}

	public function is_pillar( $post_id ) {
		return get_post_meta( $post_id, 'rank_math_pillar_content', true ) === 'on';
	}

	public function set_pillar( $post_id, $status = true ) {
		return update_post_meta( $post_id, 'rank_math_pillar_content', $status ? 'on' : 'off' );
	}

	/* ---------------------------------------------------------------- actions */

	/**
	 * Write meta fields to Rank Math.
	 *
	 * @param int   $post_id
	 * @param array $fields  title, description, focus_keyword, robots, canonical, pillar, etc.
	 * @return array The values before they were changed (for revert).
	 */
	public function apply( $post_id, array $fields ) {
		$map = array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
			'canonical'     => 'rank_math_canonical_url',
			'robots'        => 'rank_math_robots',
			'og_title'      => 'rank_math_facebook_title',
			'og_description'=> 'rank_math_facebook_description',
			'twitter_title' => 'rank_math_twitter_title',
			'schema'        => 'rank_math_schema_Article',
			'pillar'        => 'rank_math_pillar_content',
		);

		$before = array();
		foreach ( $fields as $key => $value ) {
			if ( ! isset( $map[ $key ] ) ) continue;
			$meta_key = $map[ $key ];
			$before[ $meta_key ] = get_post_meta( $post_id, $meta_key, true );
			update_post_meta( $post_id, $meta_key, $value );
		}

		// rank_math_seo_score is deliberately NOT written here.
		//
		// It used to be, from a caller that passed either a number the writing
		// model claimed for its own output or, failing that, a hardcoded 82
		// (88 from the fixer). Rank Math scores posts in editor JavaScript and
		// nothing server-side can produce that number, so those values were
		// invented - and because they land in Rank Math's own meta key they
		// then appear in its Posts column and its site average as though Rank
		// Math had measured them. Five posts on this site carried an identical
		// 92 while genuinely having no internal links, no external links and
		// no table of contents.
		//
		// VMSB now records what it can actually verify, under its own key, via
		// VMSB_RankMath_Score. Rank Math's field is left to Rank Math.
		if ( isset( $fields['seo_score'] ) ) {
			( new VMSB_Logger() )->warn(
				'rankmath',
				"Ignored a supplied seo_score for post #{$post_id}; Rank Math's score is measured by its own editor, not set by us."
			);
		}

		return $before;
	}

	public function revert( $object_id, array $before, $object_type = 'post' ) {
		foreach ( $before as $meta_key => $value ) {
			if ( 'description' === $meta_key || 'taxonomy' === $meta_key ) continue; // Skip non-meta keys

			if ( 'post' === $object_type ) {
				if ( '' === $value || null === $value ) {
					delete_post_meta( $object_id, $meta_key );
				} else {
					update_post_meta( $object_id, $meta_key, $value );
				}
			} else {
				if ( '' === $value || null === $value ) {
					delete_term_meta( $object_id, $meta_key );
				} else {
					update_term_meta( $object_id, $meta_key, $value );
				}
			}
		}
	}

	/* ---------------------------------------------------------------- analytics */

	/**
	 * HIGH-QUALITY ANALYTICS: Direct database bridge.
	 *
	 * Bypasses API limits by reading Rank Math's localized GSC cache.
	 */
	public function get_local_metrics( $dimensions, $days = 90, $limit = 1000 ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$dim = $dimensions[0] ?? 'query';

		if ( $dim === 'page' ) {
			$table = "{$p}rank_math_analytics_objects";
			$col   = 'page'; // Path without domain
		} else {
			// Rank Math stores high-quality keyword reporting here
			$table = "{$p}rank_math_analytics_keywords";
			$col   = 'query';

			if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table ) {
				$table = "{$p}rank_math_analytics_gsc";
			}
		}

		if ( $wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table ) return array();

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM $table ORDER BY impressions DESC LIMIT %d",
			(int) $limit
		), ARRAY_A );

		$rows = array();
		foreach ( $results as $r ) {
			$rows[] = array(
				'keys'        => array( $r[ $col ] ),
				'clicks'      => (int)$r['clicks'],
				'impressions' => (int)$r['impressions'],
				'ctr'         => (float)($r['ctr'] ?? 0),
				'position'    => (float)$r['position']
			);
		}
		return $rows;
	}

	/**
	 * Pull Average Site Score.
	 */
	public function get_average_site_score() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT AVG(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = 'rank_math_seo_score'" );
	}

	/* ---------------------------------------------------------------- audit */

	/**
	 * Intelligent Audit: Detects Rank Math checklist gaps.
	 */
	public function audit( $post_id ) {
		$post = get_post( $post_id );
		$issues = array();
		if ( ! $post ) return $issues;

		$title    = $this->get_title( $post_id ) ?: $post->post_title;
		$desc     = $this->get_description( $post_id );
		$keyword  = $this->get_focus_keyword( $post_id );
		$score    = $this->get_score( $post_id );

		// 1. Missing Core Meta
		if ( ! $this->get_title( $post_id ) ) {
			$issues[] = array( 'rule' => 'missing_seo_title', 'severity' => 'high', 'detail' => 'No Rank Math title set; using raw post title.' );
		}
		if ( ! $desc ) {
			$issues[] = array( 'rule' => 'missing_meta_description', 'severity' => 'high', 'detail' => 'No meta description set.' );
		}
		if ( ! $keyword ) {
			$issues[] = array( 'rule' => 'missing_focus_keyword', 'severity' => 'medium', 'detail' => 'No focus keyword assigned.' );
		}

		// 2. Score Threshold
		if ( $score > 0 && $score < 85 ) {
			$issues[] = array( 'rule' => 'low_rankmath_score', 'severity' => 'medium', 'detail' => "Current score is {$score}/100. Target is 85-90+." );
		}

		// 3. Length Audits
		if ( mb_strlen($title) > 60 ) {
			$issues[] = array( 'rule' => 'title_too_long', 'severity' => 'low', 'detail' => 'Title exceeds 60 characters.' );
		}
		if ( $desc && mb_strlen($desc) > 160 ) {
			$issues[] = array( 'rule' => 'description_too_long', 'severity' => 'low', 'detail' => 'Description exceeds 160 characters.' );
		}

		// 4. Schema Verification
		$has_schema = get_post_meta( $post_id, 'rank_math_schema_Article', true ) || get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		if ( ! $has_schema ) {
			$issues[] = array( 'rule' => 'missing_schema', 'severity' => 'medium', 'detail' => 'No structured data schema detected.' );
		}

		return $issues;
	}

	public function sitemap_url() {
		return home_url( '/sitemap_index.xml' );
	}
}
