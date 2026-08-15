<?php
defined( 'ABSPATH' ) || exit;

/**
 * Silo architecture: build the map, then fix the site to match it.
 * A silo here means pillar page -> supporting cluster posts -> tight internal
 * linking inside the cluster, with deliberate links out to money pages.
 */
class VMSB_Silo {

	private $ai;
	private $brain;
	private $log;

	public function __construct() {
		$this->ai    = new VMSB_AI_Router();
		$this->brain = new VMSB_Brain();
		$this->log   = new VMSB_Logger();
	}

	/* ---------------------------------------------------------------- map */

	/**
	 * Generate a full silo map from the keyword universe + business profile.
	 */
	public function generate_map( $force = false ) {
		if ( ! (int) VMSB_Settings::get( 'feature_silo', 1 ) ) {
			return array( 'silos' => array() );
		}

		$existing = $this->brain->recall( 'silo', 'map' );
		if ( $existing && ! $force ) {
			return $existing;
		}

		global $wpdb;
		$table    = $wpdb->prefix . 'vmsb_keywords';
		$clusters = $wpdb->get_results( "SELECT cluster, COUNT(*) AS n, SUM(impressions) AS imp, GROUP_CONCAT(keyword ORDER BY opportunity DESC SEPARATOR ' | ') AS kws FROM {$table} WHERE cluster IS NOT NULL AND cluster != '' GROUP BY cluster ORDER BY imp DESC LIMIT 25", ARRAY_A );

		if ( empty($clusters) ) {
			$this->log->warn( 'silo', 'Cannot generate silo map: no keyword clusters found. Sync Google Search Console first.' );
			return array( 'silos' => array() );
		}

		// 2026 Strategy: CPT-Aware Inventory (Destinations, Events, etc.)
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post', 'page' ) );
		$pages = get_posts( array( 'post_type' => $safe_types, 'posts_per_page' => 300, 'post_status' => 'publish' ) );

		$inventory = array();
		$rm = new VMSB_RankMath();
		foreach ( $pages as $page ) {
			$inventory[] = array(
				'id' => $page->ID,
				'title' => $page->post_title,
				'url' => get_permalink( $page ),
				'type' => $page->post_type,
				'is_pillar' => $rm->is_pillar( $page->ID )
			);
		}

		$prompt = "Design a silo architecture for this site.\n\n"
			. "KEYWORD CLUSTERS (with their queries):\n" . wp_json_encode( $clusters )
			. "\n\nEXISTING PAGES:\n" . wp_json_encode( array_slice( $inventory, 0, 120 ) )
			. "\n\nRules:\n"
			. "- Every silo has exactly one pillar. Reuse an existing page as the pillar where one fits; only propose a new pillar when nothing fits.\n"
			. "- Supporting posts sit under the pillar and link up to it and sideways to two or three siblings.\n"
			. "- Each silo names the money page it should funnel to.\n"
			. "- Do not create silos the business cannot support with real expertise.\n\n"
			. 'Return JSON: {"silos":[{"name":"","pillar":{"existing_post_id":0,"title":"","slug":"","status":"exists|create"},"category_slug":"","money_page":"","supporting":[{"title":"","slug":"","primary_keyword":"","status":"exists|create","existing_post_id":0}],"internal_link_rules":[]}]}';

		$map = $this->ai->generate_json(
			$prompt,
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 4000, 'temperature' => 0.35, 'action' => 'silo_map' )
		);

		if ( ! is_array( $map ) || empty( $map['silos'] ) ) {
			$this->log->error( 'silo', 'Silo map generation returned nothing usable.' );
			return array( 'silos' => array() );
		}

		$this->brain->remember( 'silo', 'map', $map, 0.8, 'silo' );
		$this->log->info( 'silo', 'Silo map generated.', array( 'silos' => count( $map['silos'] ) ) );
		return $map;
	}

	/* ---------------------------------------------------------------- diagnosis */

	/**
	 * Compare the live site against the map and record every deviation as an issue.
	 */
	public function diagnose() {
		$map = $this->generate_map();
		$fixer = new VMSB_Fixer();
		$found = 0;

		foreach ( ( isset( $map['silos'] ) ? $map['silos'] : array() ) as $silo ) {
			$pillar_id = isset( $silo['pillar']['existing_post_id'] ) ? (int) $silo['pillar']['existing_post_id'] : 0;

			if ( ! $pillar_id ) {
				$fixer->record( 'silo', 0, 'missing_pillar', 'high', sprintf( 'The "%s" silo has no pillar page.', $silo['name'] ), $silo );
				$found++;
				continue;
			}

			// World-Class Audit: Pillar Status in Rank Math
			$rm = new VMSB_RankMath();
			if ( ! $rm->is_pillar( $pillar_id ) ) {
				$fixer->record(
					'post',
					$pillar_id,
					'not_marked_as_pillar',
					'medium',
					sprintf( 'This page acts as the pillar for "%s" but is not marked as "Pillar Content" in Rank Math.', $silo['name'] ),
					array( 'pillar_id' => $pillar_id, 'mark_as_pillar' => true )
				);
				$found++;
			}

			// Category exists?
			if ( ! empty( $silo['category_slug'] ) && ! get_term_by( 'slug', $silo['category_slug'], 'category' ) ) {
				$fixer->record( 'taxonomy', 0, 'missing_silo_category', 'medium', sprintf( 'Category "%s" does not exist yet.', $silo['category_slug'] ), $silo );
				$found++;
			}

			foreach ( ( isset( $silo['supporting'] ) ? $silo['supporting'] : array() ) as $child ) {
				$child_id = isset( $child['existing_post_id'] ) ? (int) $child['existing_post_id'] : 0;
				if ( ! $child_id ) {
					continue; // Handled by the content planner, not the fixer.
				}

				$content = get_post_field( 'post_content', $child_id );
				$pillar_url = get_permalink( $pillar_id );

				if ( $pillar_url && false === strpos( (string) $content, $pillar_url ) ) {
					$fixer->record(
						'post',
						$child_id,
						'orphan_from_pillar',
						'high',
						sprintf( 'This post does not link up to its pillar "%s".', get_the_title( $pillar_id ) ),
						array( 'pillar_id' => $pillar_id, 'pillar_url' => $pillar_url )
					);
					$found++;
				}
			}
		}

		// True orphans: nothing on the site links to them.
		foreach ( $this->orphans() as $orphan ) {
			$fixer->record( 'post', $orphan->ID, 'orphan_page', 'high', 'No internal link points at this page.', array() );
			$found++;
		}

		// World-Class Audit: False Pillars (marked in RM but not a pillar in any Silo Map)
		$pillar_ids = wp_list_pluck( array_filter( $map['silos'], fn($s) => ! empty($s['pillar']['existing_post_id']) ), 'pillar' );
		$pillar_ids = wp_list_pluck( $pillar_ids, 'existing_post_id' );

		global $wpdb;
		$rm_pillars = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_pillar_content' AND meta_value = 'on'" );

		foreach ( $rm_pillars as $rm_id ) {
			if ( ! in_array( (int) $rm_id, $pillar_ids, true ) ) {
				$fixer->record(
					'post',
					$rm_id,
					'false_pillar',
					'low',
					'This page is marked as "Pillar Content" in Rank Math but does not act as a pillar in the AI Silo Map.',
					array( 'unmark_pillar' => true )
				);
				$found++;
			}
		}

		return $found;
	}

	/**
	 * Pages with zero inbound internal links.
	 */
	public function orphans( $limit = 100 ) {
		global $wpdb;
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'posts_per_page' => (int) $limit, 'post_status' => 'publish' ) );
		if ( ! $posts ) {
			return array();
		}

		// Optimization: Fetch all published post content once to search for links,
		// instead of performing one separate query per orphan candidate.
		$all_content = $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post', 'page')" );
		$combined    = implode( ' ', $all_content );

		$orphans = array();
		foreach ( $posts as $post ) {
			$url  = get_permalink( $post );
			$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			if ( ! $path || '/' === $path ) {
				continue;
			}
			// Match the path as a whole path segment, not a substring - a plain
			// strpos() would treat "/post-1" as present inside "/post-10" and
			// wrongly count a genuinely orphaned page as linked.
			$pattern = '/' . preg_quote( $path, '/' ) . '(?=["\'\/\s]|$)/';
			if ( ! preg_match( $pattern, $combined ) ) {
				$orphans[] = $post;
			}
		}
		return $orphans;
	}

	/* ---------------------------------------------------------------- repair */

	/**
	 * Insert a contextual internal link into a post, using AI to pick the anchor
	 * sentence so the link reads naturally instead of being bolted to the footer.
	 */
	/**
	 * Best internal-link targets for a post, chosen by meaning rather than by
	 * shared category. Falls back to nothing (caller keeps its own logic) when
	 * the vector layer is unavailable, so this only ever improves selection.
	 *
	 * @return array<int,array{ID:int,title:string,url:string,score:float}>
	 */
	public function semantic_targets( $post_id, $limit = 5 ) {
		if ( ! (int) VMSB_Settings::get( 'vector_enabled' ) || ! class_exists( 'VMSB_Vector_Store' ) ) {
			return array();
		}

		$content = get_post_field( 'post_content', $post_id );
		$out     = array();

		foreach ( VMSB_Vector_Store::related_posts( $post_id, $limit ) as $item ) {
			// Skip anything already linked - re-linking adds nothing and reads
			// like a link farm.
			if ( false !== strpos( (string) $content, $item['url'] ) ) {
				continue;
			}
			$out[] = $item;
		}

		return $out;
	}

	public function insert_internal_link( $post_id, $target_id, $anchor_hint = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_silo', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages or unsafe post types
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_silo', 'Safety: Cannot insert links into the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_silo', 'Safety: This post type is not in the safe list for automated linking.' );
		}

		$target_url   = get_permalink( $target_id );
		$target_title = get_the_title( $target_id );

		if ( false !== strpos( $post->post_content, $target_url ) ) {
			return true; // Already linked.
		}

		$excerpt = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 4000 );

		$data = $this->ai->generate_json(
			"Here is the body of an article:\n\n{$excerpt}\n\n"
			. "I need to link naturally to a page titled \"{$target_title}\".\n"
			. "Find the single best existing sentence to carry that link, and give me the exact anchor phrase inside it. The anchor must be words that already appear in the sentence, 2-6 words long, descriptive, never 'click here' or 'read more'.\n\n"
			. 'Return JSON: {"sentence":"","anchor":"","confidence":0.0}',
			array( 'max_tokens' => 400, 'temperature' => 0.2, 'action' => 'link_autopilot' )
		);

		if ( empty( $data['sentence'] ) || empty( $data['anchor'] ) ) {
			return new WP_Error( 'vmsb_silo', 'Could not find a natural anchor.' );
		}

		$anchor  = $data['anchor'];
		$content = $post->post_content;

		if ( false === strpos( $content, $anchor ) ) {
			return new WP_Error( 'vmsb_silo', 'The proposed anchor is not present in the source content.' );
		}

		$link    = '<a href="' . esc_url( $target_url ) . '">' . esc_html( $anchor ) . '</a>';
		$updated = preg_replace( '/' . preg_quote( $anchor, '/' ) . '/', $link, $content, 1 );

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $updated ) );

		return array( 'post_id' => $post_id, 'anchor' => $anchor, 'target' => $target_url, 'post_content' => $content );
	}

	/**
	 * Semantic Mesh: Builds natural links between semantically related posts
	 * regardless of their silo structure. Uses Vector Distance for discovery.
	 */
	public function build_semantic_mesh( $limit = 5 ) {
		if ( ! (int) VMSB_Settings::get( 'vector_enabled' ) || ! class_exists( 'VMSB_Vector_Store' ) ) {
			return array( 'skipped' => 'Vector store disabled' );
		}

		$this->log->info( 'silo', 'Executing Semantic Mesh pass (High-Fidelity Mode)...' );

		global $wpdb;
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$types_sql  = "'" . implode( "','", array_map( 'esc_sql', $safe_types ) ) . "'";

		// Pick posts that need linking (prioritizing new or least-linked ones)
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_vmsb_last_mesh'
			 WHERE p.post_status = 'publish' AND p.post_type IN ({$types_sql})
			 ORDER BY m.meta_value ASC, p.post_date DESC LIMIT %d",
			(int) $limit
		) );

		if ( ! $post_ids ) return array( 'linked' => 0 );

		$linked_total = 0;
		foreach ( $post_ids as $id ) {
			// Find top 3 semantic relatives with high similarity (>0.80)
			$relatives = VMSB_Vector_Store::related_posts( $id, 3, 0.80 );
			if ( ! $relatives ) continue;

			foreach ( $relatives as $rel ) {
				// Don't link if already connected
				if ( $this->has_connection( $id, $rel['ID'] ) ) continue;

				// Decide direction: link FROM older/established post TO the target
				$source_id = $id;
				$target_id = $rel['ID'];

				$res = $this->insert_internal_link( $source_id, $target_id );
				if ( ! is_wp_error($res) && $res !== true ) {
					$linked_total++;
					VMSB_Actions::record( array(
						'object_type' => 'post',
						'object_id'   => $source_id,
						'action_type' => 'semantic_link',
						'before'      => $res['post_content'],
						'after'       => get_post_field('post_content', $source_id),
						'reason'      => "Semantic Mesh: Linking to related authority '{$rel['title']}' (Score: {$rel['score']})"
					) );
				}
			}
			update_post_meta( $id, '_vmsb_last_mesh', time() );
		}

		return array( 'linked' => $linked_total );
	}

	private function has_connection( $id1, $id2 ) {
		$c1 = get_post_field('post_content', $id1);
		$u2 = get_permalink($id2);
		if ( strpos($c1, $u2) !== false ) return true;

		$c2 = get_post_field('post_content', $id2);
		$u1 = get_permalink($id1);
		if ( strpos($c2, $u1) !== false ) return true;

		return false;
	}

	/**
	 * A visual map for the dashboard.
	 * Upgraded to provide data for the Radar Chart (Strength and Concentration).
	 */
	public function map_for_display() {
		$map  = $this->brain->recall( 'silo', 'map', array( 'silos' => array() ) );
		$out  = array();

		foreach ( ( isset( $map['silos'] ) ? $map['silos'] : array() ) as $silo ) {
			$pillar_id = isset( $silo['pillar']['existing_post_id'] ) ? (int) $silo['pillar']['existing_post_id'] : 0;
			$supporting = isset( $silo['supporting'] ) ? (array) $silo['supporting'] : array();
			$post_count = count( $supporting ) + ( $pillar_id ? 1 : 0 );

			// Calculate metrics for the Radar Chart
			$strength = $this->calculate_silo_strength( $post_count, $pillar_id ? 1 : 0 );

			// World-Class Audit: Intent Mix & Juice Flow
			$intent_mix = $this->calculate_intent_mix( $supporting );

			$out[] = array(
				'name'       => isset( $silo['name'] ) ? $silo['name'] : '',
				'pillar'     => $pillar_id ? get_the_title( $pillar_id ) : ( isset( $silo['pillar']['title'] ) ? $silo['pillar']['title'] . ' (to create)' : '' ),
				'pillar_id'  => $pillar_id,
				'money_page' => isset( $silo['money_page'] ) ? $silo['money_page'] : '',
				'children'   => $supporting,
				'assets'     => $post_count,
				'strength'   => $strength,
				'concentration' => ( $pillar_id ? 1.0 : 0.0 ),
				'intent_mix' => $intent_mix
			);
		}
		return $out;
	}

	private function calculate_intent_mix( $children ) {
		$mix = array( 'informational' => 0, 'commercial' => 0, 'transactional' => 0 );
		foreach ( $children as $child ) {
			$intent = isset( $child['intent'] ) ? $child['intent'] : 'informational';
			if ( isset( $mix[ $intent ] ) ) $mix[ $intent ]++;
		}
		$total = count( $children ) ?: 1;
		foreach ( $mix as &$count ) {
			$count = round( ( $count / $total ) * 100 );
		}
		return $mix;
	}

	private function calculate_silo_strength( $posts, $pillars ) {
		if ( $posts === 0 ) return 0;
		// Weighting: Pillars (50%), Content Volume (50%)
		$pillar_score = ( $pillars > 0 ) ? 50 : 0;
		$volume_score = min( 50, ( $posts / 10 ) * 50 ); // 10 posts = full volume score
		return round( $pillar_score + $volume_score );
	}

	/**
	 * Performs a deep scan of internal links to find orphans and missing connections.
	 * Upgraded for "Link Genius" awareness.
	 */
	public function deep_linking_audit() {
		$this->log->info( 'silo', 'Starting deep linking audit...' );
		$found = $this->diagnose();

		if ( class_exists('AILG_Core') ) {
			global $wpdb;
			$broken = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_broken_links WHERE status='broken'" );
			if ( $broken > 0 ) {
				$fixer = new VMSB_Fixer();
				$fixer->record( 'site', 0, 'broken_links_found', 'high', "AI Link Genius found {$broken} broken internal links that need repair." );
				$found++;
			}
		}

		return $found;
	}

	/**
	 * Analyze Silo Integrity and fix the weakest link. (Ported from VMAI SEO)
	 */
	public function analyze_and_fix_weakest() {
		$map = $this->map_for_display();
		if ( empty( $map ) ) {
			$map = $this->generate_map( true );
			$map = $this->map_for_display();
		}

		$prompt = "Analyze these content silos and identify the weakest one based on supporting content depth and pillar status.\n\n"
			. "SILO MAP: " . wp_json_encode( $map ) . "\n\n"
			. "TASK: \n"
			. "1. Name the weakest silo.\n"
			. "2. Suggest 3 specific supporting topics to strengthen it.\n"
			. "Return JSON: {\"weakest_silo\":\"\", \"recommendations\":[{\"title\":\"\",\"keyword\":\"\",\"brief\":\"\"}]}";

		$analysis = $this->ai->generate_json( $prompt, array( 'system' => $this->brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( ! empty( $analysis['weakest_silo'] ) && ! empty( $analysis['recommendations'] ) ) {
			$content = new VMSB_Content();
			foreach ( $analysis['recommendations'] as $rec ) {
				$this->log->info( 'silo', "Silo Architect: Strengthening '{$analysis['weakest_silo']}' with topic '{$rec['title']}'." );
				// Queue the recommendation into the content plan
				$content->plan_specific( $rec['title'], $rec['keyword'], $rec['brief'] );
			}
			return array( 'fixed' => count( $analysis['recommendations'] ), 'silo' => $analysis['weakest_silo'] );
		}

		return array( 'fixed' => 0 );
	}

	/**
	 * Push all "to create" gaps from the Silo Map into the Content Plan.
	 */
	/**
	 * @param bool $as_suggestion When true, gaps land as 'suggested' rows for
	 *        a human to approve instead of jumping straight to 'approved' -
	 *        used by VMSB_Growth_Engine::scan() so silo gaps go through the
	 *        same review queue as every other suggestion source.
	 */
	public function push_gaps_to_plan( $as_suggestion = false ) {
		$map = $this->generate_map();
		if ( empty( $map['silos'] ) ) {
			return array( 'pushed' => 0 );
		}

		$content = new VMSB_Content();
		$status  = $as_suggestion ? 'suggested' : 'approved';
		$pushed  = 0;

		foreach ( $map['silos'] as $silo ) {
			// 1. Pillar gap?
			if ( isset( $silo['pillar']['status'] ) && 'create' === $silo['pillar']['status'] ) {
				$content->plan_specific(
					$silo['pillar']['title'],
					$silo['pillar']['slug'], // Use slug as keyword if primary not explicit
					"Silo Pillar for: {$silo['name']}. This should be the authoritative hub for this topic.",
					$silo['name'],
					1,
					$status
				);
				$pushed++;
			}

			// 2. Supporting gaps?
			if ( ! empty( $silo['supporting'] ) ) {
				foreach ( $silo['supporting'] as $child ) {
					if ( isset( $child['status'] ) && 'create' === $child['status'] ) {
						$content->plan_specific(
							$child['title'],
							$child['primary_keyword'] ?: $child['slug'],
							"Supporting post for silo: {$silo['name']}. Links back to the pillar.",
							$silo['name'],
							0,
							$status
						);
						$pushed++;
					}
				}
			}
		}

		return array( 'pushed' => $pushed );
	}
}
