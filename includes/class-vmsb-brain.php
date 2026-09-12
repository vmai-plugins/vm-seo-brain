<?php
defined( 'ABSPATH' ) || exit;

/**
 * The central brain. It reads the site, forms a business understanding,
 * stores it as durable memory, and hands that context to every other module
 * so nothing writes generic AI slop about a business it doesn't understand.
 */
class VMSB_Brain {

	private $ai;
	private $log;
	private static $runtime_cache = array();

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_memory';
	}

	/* ---------------------------------------------------------------- memory */

	public function remember( $bucket, $key, $value, $confidence = 0.8, $source = 'brain' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (bucket, mkey, mvalue, confidence, source, created_at, updated_at)
				 VALUES (%s, %s, %s, %f, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE mvalue = VALUES(mvalue), confidence = VALUES(confidence), source = VALUES(source), updated_at = VALUES(updated_at)",
				$bucket,
				$key,
				is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				(float) $confidence,
				$source,
				$now,
				$now
			)
		);

		// Clear cache on write
		unset( self::$runtime_cache[ $bucket ] );
		wp_cache_delete( "vmsb_memory_{$bucket}_{$key}", 'vmsb' );
	}

	public function recall( $bucket, $key = '', $default = null ) {
		global $wpdb;

		$cache_key = $key ? "vmsb_memory_{$bucket}_{$key}" : "vmsb_memory_{$bucket}_all";

		// 1. Runtime cache (Fastest)
		if ( isset( self::$runtime_cache[ $cache_key ] ) ) {
			return self::$runtime_cache[ $cache_key ];
		}

		// 2. WP Object Cache (Medium)
		$cached = wp_cache_get( $cache_key, 'vmsb' );
		if ( false !== $cached ) {
			self::$runtime_cache[ $cache_key ] = $cached;
			return $cached;
		}

		if ( $key ) {
			$val = $wpdb->get_var( $wpdb->prepare( "SELECT mvalue FROM {$this->table()} WHERE bucket = %s AND mkey = %s", $bucket, $key ) );
			if ( null === $val ) {
				return $default;
			}
			$decoded = json_decode( $val, true );
			$result = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $val;
			wp_cache_set( $cache_key, $result, 'vmsb', 3600 );
			return $result;
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT mkey, mvalue FROM {$this->table()} WHERE bucket = %s ORDER BY confidence DESC", $bucket ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$decoded            = json_decode( $row['mvalue'], true );
			$out[ $row['mkey'] ] = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $row['mvalue'];
		}

		$result = $out ? $out : $default;
		wp_cache_set( $cache_key, $result, 'vmsb', 3600 );
		return $result;
	}

	public function forget( $bucket, $key = '' ) {
		global $wpdb;

		// Clear caches
		if ( $key ) {
			wp_cache_delete( "vmsb_memory_{$bucket}_{$key}", 'vmsb' );
		} else {
			wp_cache_delete( "vmsb_memory_{$bucket}_all", 'vmsb' );
		}
		unset( self::$runtime_cache[ $bucket ] );

		if ( $key ) {
			return $wpdb->delete( $this->table(), array( 'bucket' => $bucket, 'mkey' => $key ) );
		}
		return $wpdb->delete( $this->table(), array( 'bucket' => $bucket ) );
	}

	/**
	 * PORTED: Sentient Knowledge Engine.
	 * Ingests latest SEO news and updates the Master AI's logic.
	 */
	public function learn_latest_seo_trends() {
		$current_date = date('F Y');
		$goal = (int) VMSB_Settings::get('growth_target', 50000);

		$blitz_context = ($goal >= 50000) ? "CRITICAL: We are in a 'Growth Blitz'. Rules must prioritize viral velocity and aggressive newsjacking over slow-burn branding." : "Focus on long-term authority and sustainability.";

		$prompt = "Search and analyze the latest SEO updates from {$current_date}, specifically Google Core Updates, Search Engine Land reports, and high-velocity ranking trends.\n"
			. "{$blitz_context}\n\n"
			. "TASK: Summarize the 3 most critical 'Operational Rules' for our SEO Agency this week.\n"
			. "Format as a strategic brief for our AI Agents.";

		$res = $this->ai->generate( $prompt, array( 'complexity' => 'premium', 'persona' => 'strategist' ) );

		if ( ! empty($res['ok']) ) {
			$rules = $res['text'];
			$this->remember( 'intelligence', 'current_seo_policy', $rules, 1.0, 'news_agent' );
			return "AI has learned latest trends: " . wp_trim_words($rules, 20);
		}
		return false;
	}

	public function get_current_seo_policy() {
		return $this->recall( 'intelligence', 'current_seo_policy', 'Focus on high-quality E-E-A-T and helpful content.' );
	}

	/**
	 * Strategic Pivot: Evaluates 30-day performance and decides if we
	 * should change, repeat, or stop the current growth strategy.
	 */
	public function evaluate_and_replan() {
		$stats = (new VMSB_Lifecycle())->audit_all_assets();
		$outcomes = VMSB_Outcome_Ledger::counts();
		$profile = $this->profile();

		$this->log->info( 'brain', 'Intelligence Cycle: Starting Strategic Pivot evaluation...' );

		$prompt = "Act as the Master Strategy Loop. Evaluate the last 30 days of SEO performance.\n\n"
			. "BUSINESS PROFILE: {$profile['description']}\n"
			. "PERFORMANCE SNAPSHOT: " . wp_json_encode(array_map('count', $stats)) . "\n"
			. "OUTCOME VERDICTS: Wins: {$outcomes['wins']}, Losses: {$outcomes['losses']}, Net Clicks: {$outcomes['net_clicks']}\n\n"
			. "TASK: Decide the 'Strategic Pivot' for the next 30 days.\n"
			. "1. Are we over-indexed on topics that don't convert? (Pivoting focus).\n"
			. "2. Are we winning on specific clusters? (Double down).\n"
			. "3. Is our 'Sentient AI' making consistent tone mistakes? (Adjust personas).\n\n"
			. 'Return JSON: {"pivot_name":"","pivot_reason":"","new_directives":[], "focus_clusters":[], "persona_adjustments":{}}';

		$pivot = $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'persona' => 'strategist' ) );

		if ( ! empty($pivot['pivot_name']) ) {
			$this->remember( 'intelligence', 'current_strategy_pivot', $pivot, 1.0, 'master_loop' );
			$this->log->info( 'brain', "Strategic Pivot Activated: '{$pivot['pivot_name']}'. Directives: " . implode(', ', (array)$pivot['new_directives']) );

			// Log this to the universal action log
			VMSB_Actions::record( array(
				'object_type' => 'site',
				'object_id'   => 0,
				'action_type' => 'strategic_pivot',
				'before'      => $this->recall('intelligence', 'current_strategy_pivot'),
				'after'       => $pivot,
				'reason'      => "Strategic Evaluation Cycle completed."
			) );
		}

		return $pivot;
	}

	/**
	 * Get a high-level strategic briefing for the dashboard.
	 */
	public function get_strategic_briefing() {
		$verdict = (new VMSB_Growth())->verdict();
		$ops = $this->recall('intelligence', 'active_opportunities', array());
		$lifecycle = VMSB_Lifecycle::get_stats();

		$html = "<p><strong>Current State:</strong> " . esc_html($verdict['message']) . "</p>";

		if ( ! empty($ops) ) {
			$top = $ops[0];
			$html .= "<p>Brain found <strong>" . count($ops) . " new opportunities</strong>. The highest-leverage move is <strong>" . esc_html(str_replace('_', ' ', $top['type'])) . "</strong> on <em>\"" . esc_html($top['target']) . "\"</em>.</p>";
		} else {
			$html .= "<p>No new opportunities identified in the last scan. Your current authority silos are stable.</p>";
		}

		if ( ! empty($lifecycle['stats']['decaying']) ) {
			$html .= "<p>⚠️ <strong>Critical Alert:</strong> " . (int)$lifecycle['stats']['decaying'] . " high-value pages are showing signs of rapid traffic decay.</p>";
		}

		return $html;
	}

	/* ---------------------------------------------------------------- understanding */

	/**
	 * Read the site and infer what this business actually is.
	 * Runs on first activation and again whenever the operator asks for a re-read.
	 */
	public function understand_business( $force = false ) {
		if ( ! $force && VMSB_Settings::get( 'profile_locked' ) ) {
			return $this->profile();
		}

		$evidence = $this->gather_evidence();

		$system = 'You are a senior SEO strategist doing discovery on a website. You are precise and you never invent facts that the evidence does not support. If something is unknown, say "unknown".';

		$prompt = "Read this evidence from a WordPress site and describe the business behind it.\n\n"
			. $evidence
			. "\n\nReturn JSON with exactly these keys:\n"
			. '{"business_name":"","business_type":"","one_line":"","description":"","services":[],"products":[],"audience":[],"pain_points":[],"locations":[],"service_area_type":"local|national|global","competitors":[],"topical_authority_pillars":[],"commercial_pages":[],"site_custom_post_types":[],"content_gaps_hypothesis":[],"tone":"","language":"","confidence":0.0}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $system, 'max_tokens' => 2200, 'temperature' => 0.3 ) );

		if ( ! is_array( $data ) ) {
			$this->log->error( 'brain', 'Business discovery failed — the AI chain returned nothing usable: ' . ( $this->ai->get_last_error() ?: 'unknown reason' ) );
			return $this->profile();
		}

		foreach ( $data as $key => $value ) {
			$this->remember( 'business', $key, $value, isset( $data['confidence'] ) ? (float) $data['confidence'] : 0.7, 'discovery' );
		}
		$this->remember( 'business', 'discovered_at', current_time( 'mysql', true ), 1.0, 'discovery' );

		// Mirror the headline fields into settings so the operator can correct them.
		VMSB_Settings::update(
			array(
				'business_name'        => isset( $data['business_name'] ) ? $data['business_name'] : VMSB_Settings::get( 'business_name' ),
				'business_type'        => isset( $data['business_type'] ) ? $data['business_type'] : '',
				'business_description' => isset( $data['description'] ) ? $data['description'] : '',
				'services'             => isset( $data['services'] ) ? implode( ', ', (array) $data['services'] ) : '',
				'audience'             => isset( $data['audience'] ) ? implode( ', ', (array) $data['audience'] ) : '',
				'primary_locations'    => isset( $data['locations'] ) ? implode( ', ', (array) $data['locations'] ) : '',
			)
		);

		$this->log->info( 'brain', 'Business profile updated from site discovery.', array( 'type' => isset( $data['business_type'] ) ? $data['business_type'] : '' ) );

		// 2026 Scenario Calibration: Detect site age/scale
		$published = (int) wp_count_posts( 'post' )->publish;
		if ( $published > 1000 ) {
			$this->remember( 'strategy', 'scenario', 'established', 1.0, 'calibration' );
			$this->log->info( 'brain', 'Scenario Calibrated: Established Site detected. Strategic priority shifted to Gap Hijacking & Optimization.' );
		} elseif ( $published > 50 ) {
			$this->remember( 'strategy', 'scenario', 'growing', 1.0, 'calibration' );
		} else {
			$this->remember( 'strategy', 'scenario', 'fresh', 1.0, 'calibration' );
		}

		return $this->profile();
	}

	/**
	 * Everything the brain knows, in one array.
	 */
	public function profile() {
		$stored   = (array) $this->recall( 'business', '', array() );
		$settings = VMSB_Settings::all();

		// Recover CPT catalog from stored data if present
		$cpts = array();
		if ( isset($stored['site_custom_post_types']) ) {
			$cpts = $stored['site_custom_post_types'];
		}

		return array(
			'name'        => $settings['business_name'],
			'type'        => $settings['business_type'],
			'description' => $settings['business_description'],
			'services'    => $settings['services'],
			'audience'    => $settings['audience'],
			'locations'   => $settings['primary_locations'],
			'competitors' => $settings['competitors'],
			'tone'        => $settings['tone'],
			'language'    => $settings['language'],
			'country'     => $settings['country'],
			'pillars'     => isset( $stored['topical_authority_pillars'] ) ? $stored['topical_authority_pillars'] : array(),
			'pain_points' => isset( $stored['pain_points'] ) ? $stored['pain_points'] : array(),
			'commercial'  => isset( $stored['commercial_pages'] ) ? $stored['commercial_pages'] : array(),
			'cpts'        => $cpts,
			'raw'         => $stored,
		);
	}

	/**
	 * A compact system prompt every other module prepends. This is what stops
	 * the content engine writing about a generic "your business".
	 */
	public function context_prompt() {
		$p = $this->profile();
		$policy = $this->get_current_seo_policy();

		$lines = array(
			'BUSINESS CONTEXT — treat every fact below as ground truth.',
			'Name: ' . $p['name'],
			'What it is: ' . $p['type'],
			'Description: ' . $p['description'],
			'Services or products: ' . $p['services'],
			'Audience: ' . $p['audience'],
			'Serves: ' . ( $p['locations'] ? $p['locations'] : 'no specific geography' ),
			'Topical pillars: ' . implode( ' | ', (array) $p['pillars'] ),
			'Reader pain points: ' . implode( ' | ', (array) $p['pain_points'] ),
			'Voice: ' . $p['tone'],
			'Write in: ' . $p['language'],
			'CURRENT SEO POLICY: ' . $policy,
			'Rules: no invented statistics, no invented client names, no invented awards. Never claim certifications or results the business has not stated. Prices in ' . VMSB_Settings::get( 'currency' ) . '.',
		);

		return implode( "\n", array_filter( $lines ) );
	}

	/* ---------------------------------------------------------------- evidence */

	private function gather_evidence() {
		$out = array();

		$out[] = 'SITE TITLE: ' . get_bloginfo( 'name' );
		$out[] = 'TAGLINE: ' . get_bloginfo( 'description' );
		$out[] = 'HOME URL: ' . home_url();

		// Front page copy.
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id ) {
			$front = get_post( $front_id );
			if ( $front ) {
				$out[] = "HOMEPAGE COPY:\n" . mb_substr( wp_strip_all_tags( strip_shortcodes( $front->post_content ) ), 0, 3000 );
			}
		}

		// Key pages.
		$pages = get_posts( array( 'post_type' => 'page', 'posts_per_page' => 12, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
		$titles = array();
		foreach ( $pages as $page ) {
			$titles[] = $page->post_title . ' (' . get_permalink( $page ) . ')';
			if ( preg_match( '/about|service|product|pricing|contact/i', $page->post_title ) ) {
				$out[] = 'PAGE "' . $page->post_title . "\":\n" . mb_substr( wp_strip_all_tags( strip_shortcodes( $page->post_content ) ), 0, 1200 );
			}
		}
		$out[] = "PAGE LIST:\n- " . implode( "\n- ", $titles );

		// Taxonomies signal the topical map.
		foreach ( array( 'category', 'product_cat' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false, 'number' => 40 ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$out[] = strtoupper( $tax ) . ' TERMS: ' . implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}

		// Recent posts show what they already publish.
		$posts = get_posts( array( 'posts_per_page' => 25, 'post_status' => 'publish' ) );
		if ( $posts ) {
			$out[] = 'RECENT POST TITLES: ' . implode( ' | ', wp_list_pluck( $posts, 'post_title' ) );
		}

		// Custom Post Type Discovery: Deep catalog for Destinations, Events, etc.
		$cpt_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
		$cpt_catalog = array();
		foreach ( $cpt_types as $type_obj ) {
			if ( in_array($type_obj->name, array('product', 'elementor_library')) ) continue;

			$cpt_posts = get_posts( array( 'post_type' => $type_obj->name, 'posts_per_page' => 5 ) );
			$cpt_catalog[] = array(
				'slug' => $type_obj->name,
				'label' => $type_obj->label,
				'description' => $type_obj->description ?: 'Content related to ' . $type_obj->label,
				'examples' => wp_list_pluck( $cpt_posts, 'post_title' )
			);
		}
		if ( ! empty($cpt_catalog) ) {
			$out[] = "SITE CUSTOM POST TYPES (Catalog):\n" . wp_json_encode($cpt_catalog);
		}

		// WooCommerce inventory, if present.
		if ( post_type_exists( 'product' ) ) {
			$products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => 30 ) );
			if ( $products ) {
				$out[] = 'PRODUCTS: ' . implode( ' | ', wp_list_pluck( $products, 'post_title' ) );
			}
		}

		// Top Search Console queries, when connected — the strongest evidence available.
		$queries = $this->recall( 'gsc', 'top_queries', array() );
		if ( $queries ) {
			$out[] = 'TOP SEARCH CONSOLE QUERIES: ' . implode( ' | ', array_slice( (array) $queries, 0, 40 ) );
		}

		return implode( "\n\n", $out );
	}

	/* ---------------------------------------------------------------- decisions */

	/**
	 * The daily decision: given the current state, what is the highest-leverage move?
	 * Advanced 2026: Authority & Competitor Aware.
	 */
	public function decide( array $state ) {
		// World-Class Context: Authority Radar, Rivals & Rank Math
		$silo_engine  = new VMSB_Silo();
		$silos        = $silo_engine->map_for_display();
		$weak_silos   = array_filter( $silos, fn( $s ) => isset( $s['strength'] ) && $s['strength'] < 50 );
		$weakest_silo = ! empty( $weak_silos ) ? reset( $weak_silos ) : false;

		$rm = new VMSB_RankMath();
		$avg_score = $rm->get_average_site_score();

		$competitor_engine = new VMSB_Competitor();
		$competitors = $competitor_engine->list_all();

		$state['topical_authority'] = array(
			'silos_count' => count($silos),
			'healthy_count' => count(array_filter($silos, fn($s) => $s['strength'] >= 75)),
			'weakest_silo' => $weakest_silo ? $weakest_silo['name'] : 'none',
		);
		$state['avg_rankmath_score'] = $avg_score;
		$state['competitors_count'] = count($competitors);

		$prompt = "Current SEO state of the site:\n" . wp_json_encode( $state )
			. "\n\nYou are the strategist. Rank the next actions by expected traffic gain per unit of effort over the next 14 days."
			. "\nAvailable action types: fix_technical, fix_onpage, rebuild_silo, optimise_taxonomy, publish_cluster, refresh_decaying_post, build_internal_links, expand_thin_page, target_striking_distance, hijack_competitor_gap."
			. '\n\nReturn JSON: {"actions":[{"type":"","target":"","reason":"","expected_lift":"","effort":"low|medium|high","priority":0}]}';

		$data = $this->ai->generate_json(
			$prompt,
			array(
				'system'      => $this->context_prompt() . "\nYou answer as a strategist, not a cheerleader. If the data does not support an action, do not recommend it.",
				'max_tokens'  => 1800,
				'temperature' => 0.4,
			)
		);

		$actions = isset( $data['actions'] ) && is_array( $data['actions'] ) ? $data['actions'] : array();
		usort(
			$actions,
			static function ( $a, $b ) {
				return ( isset( $b['priority'] ) ? $b['priority'] : 0 ) <=> ( isset( $a['priority'] ) ? $a['priority'] : 0 );
			}
		);

		$this->remember( 'decisions', gmdate( 'Y-m-d' ), $actions, 0.7, 'decide' );
		return $actions;
	}
}
