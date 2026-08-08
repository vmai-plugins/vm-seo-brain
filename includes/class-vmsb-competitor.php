<?php
defined( 'ABSPATH' ) || exit;

/**
 * Competitor intelligence.
 *
 * One module doing what the old plugin split into four (spy, tracker, duelist,
 * keyword-thief) because they all answer the same underlying question: what is
 * a named competitor doing that we are not, and is it working for them?
 *
 * Everything here reads from Search Console (our own visibility for shared
 * queries) plus the model's own knowledge of the competitor's site - there is
 * no paid rank-tracking API required, though SEMrush/Ahrefs (Tier 3) sharpen
 * the numbers when a key is present.
 */
class VMSB_Competitor {

	private $ai;
	private $google;
	private $log;

	public function __construct() {
		$this->ai     = new VMSB_AI_Router();
		$this->google = new VMSB_Google();
		$this->log    = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_competitors';
	}

	/* ---------------------------------------------------------------- roster */

	public function add( $domain, $label = '' ) {
		global $wpdb;
		$domain = self::clean_domain( $domain );
		if ( ! $domain ) {
			return new WP_Error( 'vmsb_competitor', 'Not a usable domain.' );
		}

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE domain = %s", $domain ) );
		if ( $existing ) {
			return (int) $existing;
		}

		$inserted = $wpdb->insert( $this->table(), array(
			'domain'     => $domain,
			'label'      => $label ?: $domain,
			'created_at' => current_time( 'mysql' ),
		) );

		if ( ! $inserted ) {
			return new WP_Error( 'vmsb_competitor', 'Could not add competitor: ' . $wpdb->last_error );
		}

		return (int) $wpdb->insert_id;
	}

	public function remove( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table(), array( 'id' => (int) $id ) );
	}

	public function list_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' ORDER BY overlap_score DESC, id ASC' );
	}

	private static function clean_domain( $raw ) {
		$raw = trim( (string) $raw );
		if ( ! $raw ) {
			return '';
		}
		$host = wp_parse_url( strpos( $raw, '://' ) === false ? 'https://' . $raw : $raw, PHP_URL_HOST );
		return $host ? strtolower( preg_replace( '/^www\./', '', $host ) ) : '';
	}

	/**
	 * Seed the roster from the business profile's competitors field, if the
	 * roster is still empty. Never overwrites a roster the user curated.
	 */
	public function seed_from_profile() {
		global $wpdb;
		$existing = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() );
		if ( $existing > 0 ) {
			return 0;
		}
		$raw   = (string) VMSB_Settings::get( 'competitors', '' );
		$added = 0;
		foreach ( array_filter( array_map( 'trim', preg_split( '/[,\n]/', $raw ) ) ) as $entry ) {
			if ( ! is_wp_error( $this->add( $entry ) ) ) {
				$added++;
			}
		}
		return $added;
	}

	/**
	 * PORTED: Strategic "Quantum Heist" (Mass Ranking Takeover).
	 * Steals high-value rankings from top competitors in a single cycle.
	 */
	public function run_quantum_heist() {
		$competitors = $this->list_all();
		if ( empty($competitors) ) {
			$this->seed_from_profile();
			$competitors = $this->list_all();
		}

		$total_stolen = 0;
		$brain = new VMSB_Brain();

		foreach ( array_slice($competitors, 0, 3) as $c ) {
			$comp_domain = $c->domain;

			// 1. Identify their "Cash Cow" keywords
			$cash_cows = array();
			if ( class_exists( 'VMSB_External_Data' ) && VMSB_External_Data::semrush_configured() ) {
				$gap_data = VMSB_External_Data::semrush_keyword_gap( wp_parse_url(home_url(), PHP_URL_HOST), $comp_domain );
				if ( ! is_wp_error($gap_data) ) $cash_cows = $gap_data;
			}

			if ( empty($cash_cows) ) {
				// Fallback: Ask AI to identify their likely top 5 queries
				$prompt = "Identify the top 5 high-volume search queries that the domain '{$comp_domain}' likely ranks #1-3 for in the niche: '{$brain->get_current_seo_policy()}'.\n"
					. 'Return JSON: {"keywords":[""]}';
				$res = $this->ai->generate_json( $prompt, array( 'max_tokens' => 200 ) );
				$cash_cows = array_map( fn($kw) => array('Ph' => $kw), (array) ($res['keywords'] ?? array()) );
			}

			// 2. Plan and Queue Takedowns
			foreach ( array_slice( $cash_cows, 0, 3 ) as $kw_row ) {
				$keyword = $kw_row['Ph'] ?? $kw_row['Phrase'] ?? $kw_row['Keyword'] ?? '';
				if ( ! $keyword ) continue;

				$strategy = $this->plan_takedown( $keyword, $comp_domain );
				if ( $strategy ) {
					( new VMSB_Content() )->plan_specific(
						$strategy['title'],
						$keyword,
						"TAKEOVER STRATEGY: Outranking {$comp_domain}. Angle: " . ($strategy['angle'] ?? ''),
						'competitor_heist',
						0,
						'approved'
					);
					$total_stolen++;
				}
			}
		}

		$this->log->info( 'competitor', "Quantum Heist complete. Identified and queued {$total_stolen} high-value takedown articles." );
		return $total_stolen;
	}

	private function plan_takedown( $keyword, $competitor ) {
		$prompt = "Act as a Content Domination Expert. We want to steal the #1 ranking from '{$competitor}' for the keyword: '{$keyword}'.\n\n"
			. "TASK: Design a 10x content piece that makes their coverage look obsolete.\n"
			. "Identify the specific gap (e.g., they lack data, their images are old, they don't answer X).\n"
			. 'Return JSON: {"title": "", "angle": ""}';

		return $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'persona' => 'thief' ) );
	}

	/**
	 * PORTED: Targeted URL Heist.
	 * Analyzes a specific competitor URL and plans a superior "Kill-Shot" article.
	 */
	public function execute_targeted_heist( $competitor_url ) {
		if ( false !== strpos($competitor_url, wp_parse_url(home_url(), PHP_URL_HOST)) ) return false;

		$this->log->info( 'competitor', "Initiating targeted heist on URL: {$competitor_url}" );

		$prompt = "Analyze this competitor URL (conceptual analysis): {$competitor_url}\n\n"
			. "TASK: Plan a superior article for our site that will outrank this specific page.\n"
			. "1. Identify the 'Authority Gap' (What did they miss?).\n"
			. "2. Create a 'Kill-Shot' Title (Better CTR).\n"
			. "3. Identify the primary SEO keyword they are likely ranking for.\n\n"
			. 'Return JSON: {"target_keyword": "", "new_title": "", "strategy": ""}';

		$plan = $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'persona' => 'thief' ) );

		if ( ! empty($plan['target_keyword']) ) {
			return ( new VMSB_Content() )->plan_specific(
				$plan['new_title'],
				$plan['target_keyword'],
				"TARGETED HEIST: Outranking specific competitor page. Strategy: " . ($plan['strategy'] ?? ''),
				'targeted_heist',
				0,
				'approved'
			);
		}

		return false;
	}

	/**
	 * PORTED: Traffic Siphon Mode (Competitor Vulture).
	 * Identifies keywords where competitors are dropping and we have an opportunity to strike.
	 */
	public function run_siphon_scan() {
		if ( ! class_exists( 'VMSB_External_Data' ) || ! VMSB_External_Data::semrush_configured() ) {
			return new WP_Error( 'vmsb_vulture', 'SEMrush API not configured for Siphon scan.' );
		}

		$competitors = $this->list_all();
		if ( empty($competitors) ) return 0;

		$history = get_option('vmsb_competitor_rank_snapshots', array());
		$total_strikes = 0;

		foreach ( array_slice($competitors, 0, 3) as $c ) {
			$domain = $c->domain;
			$this->log->info( 'competitor', "Vulture Agent scanning {$domain} for ranking decay..." );

			$current_kws = VMSB_External_Data::semrush_organic_keywords( $domain, 'us', 50 );
			if ( is_wp_error($current_kws) || empty($current_kws) ) continue;

			$prev_snapshot = $history[$domain] ?? array();
			$vulture_targets = array();

			foreach ( $current_kws as $row ) {
				$kw = strtolower($row['Ph'] ?? '');
				$pos = (int)($row['Po'] ?? 0);
				if ( ! $kw || $pos === 0 ) continue;

				if ( isset($prev_snapshot[$kw]) ) {
					$prev_pos = (int)$prev_snapshot[$kw];
					$drop_dist = $pos - $prev_pos;

					// DETECT DROP: If they were in top 3 and now > 5, or if they dropped 4+ spots
					if ( ($prev_pos <= 3 && $pos > 5) || $drop_dist >= 4 ) {
						$vulture_targets[] = array(
							'keyword' => $kw,
							'pos' => $pos,
							'prev_pos' => $prev_pos
						);
					}
				}
				$prev_snapshot[$kw] = $pos;
			}

			$history[$domain] = $prev_snapshot;

			// Initialize strikes for detected drops
			foreach ( array_slice($vulture_targets, 0, 3) as $target ) {
				$strategy = $this->plan_takedown( $target['keyword'], $domain );
				if ( $strategy ) {
					( new VMSB_Content() )->plan_specific(
						$strategy['title'],
						$target['keyword'],
						"VULTURE STRIKE: Competitor {$domain} dropped from #{$target['prev_pos']} to #{$target['pos']}. Strike now while they are weak. Strategy: " . ($strategy['angle'] ?? ''),
						'vulture_strike',
						0,
						'approved'
					);
					$total_strikes++;
				}
			}
		}

		update_option('vmsb_competitor_rank_snapshots', $history);
		$this->log->info( 'competitor', "Vulture Strike complete. Launched {$total_strikes} predatory takeovers." );
		return $total_strikes;
	}

	/* ---------------------------------------------------------------- shared-query overlap */

	/**
	 * For every competitor, find queries where we rank and estimate whether
	 * they likely outrank us, using our own GSC position as the signal (a
	 * query where we sit at position 8+ is a query worth checking).
	 */
	public function scan( $limit = 10 ) {
		$this->seed_from_profile();

		$competitors = array_slice( $this->list_all(), 0, $limit );
		if ( ! $competitors ) {
			return array( 'checked' => 0, 'gaps' => 0 );
		}

		// Track publishing velocity for each competitor
		foreach ( $competitors as $c ) {
			$this->track_velocity( $c->domain );
		}

		$rows = $this->google->gsc_query( array( 'query', 'page' ), 28, 300 );
		if ( is_wp_error( $rows ) ) {
			$this->log->warn( 'competitor', 'GSC unavailable for competitor scan.', array( 'error' => $rows->get_error_message() ) );
			$rows = array();
		}

		// Queries where we are weak (position 6+) are the ones worth checking
		// a competitor against - a query we already own is not a gap.
		$weak = array();
		foreach ( $rows as $r ) {
			$pos = (float) ( $r['position'] ?? 0 );
			if ( $pos >= 6 && ! empty( $r['keys'][0] ) ) {
				$weak[] = array( 'query' => $r['keys'][0], 'position' => $pos, 'impressions' => (int) ( $r['impressions'] ?? 0 ) );
			}
		}
		usort( $weak, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		$weak = array_slice( $weak, 0, 25 );

		$gap_count = 0;

		foreach ( $competitors as $c ) {
			$snapshot = $this->assess( $c->domain, $weak );
			if ( is_wp_error( $snapshot ) ) {
				continue;
			}

			// Real authority data beats the model's guess when Ahrefs is
			// configured - stored alongside, doesn't block anything if absent.
			if ( class_exists( 'VMSB_External_Data' ) && VMSB_External_Data::ahrefs_configured() ) {
				$dr = VMSB_External_Data::ahrefs_domain_overview( $c->domain );
				if ( ! is_wp_error( $dr ) ) {
					$snapshot['domain_rating'] = $dr['domain_rating'];
				}
			}

			global $wpdb;
			$wpdb->update( $this->table(), array(
				'shared_keywords' => count( $snapshot['gaps'] ?? array() ),
				'overlap_score'   => (float) ( $snapshot['threat_score'] ?? 0 ),
				'last_snapshot'   => wp_json_encode( $snapshot ),
				'checked_at'      => current_time( 'mysql' ),
			), array( 'id' => $c->id ) );

			$gap_count += count( $snapshot['gaps'] ?? array() );

			// File the strongest gaps as issues so they show up in the normal
			// issues queue and God Fix scope, not a separate silent list.
			foreach ( array_slice( $snapshot['gaps'] ?? array(), 0, 3 ) as $gap ) {
				$this->file_gap_issue( $c->domain, $gap );
			}
		}

		return array( 'checked' => count( $competitors ), 'gaps' => $gap_count );
	}

	/**
	 * Ask the model to reason about a competitor against our weak queries. No
	 * scraping - this is directional intelligence, not a rank tracker; Tier 3
	 * clients (SEMrush/Ahrefs) sharpen it further when configured.
	 */
	private function assess( $domain, array $weak_queries ) {
		if ( ! $weak_queries ) {
			return array( 'gaps' => array(), 'threat_score' => 0 );
		}

		$brain  = new VMSB_Brain();
		$prompt = "Competitor domain: {$domain}\n\n"
			. "We rank weakly (position 6+) for these queries, ordered by how much search volume they get:\n"
			. wp_json_encode( array_slice( $weak_queries, 0, 15 ) ) . "\n\n"
			. "Based on what you know about this domain and how a site like it typically covers these topics, which of these queries is this competitor likely to already own or contest strongly? "
			. "Only include a query if you have a real basis to expect they compete on it - do not guess to fill the list.\n\n"
			. 'Return JSON: {"threat_score":0,"gaps":[{"query":"","reason":"","angle":""}]}'
			. "\nthreat_score is 0-100, how much this competitor overlaps with our search footprint overall.";

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 900, 'temperature' => 0.3 ) );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_competitor', $this->ai->get_last_error() ?: 'No usable response.' );
		}

		return array(
			'threat_score' => (float) ( $data['threat_score'] ?? 0 ),
			'gaps'         => array_slice( (array) ( $data['gaps'] ?? array() ), 0, 10 ),
			'assessed_at'  => current_time( 'mysql' ),
		);
	}

	private function file_gap_issue( $domain, array $gap ) {
		global $wpdb;
		if ( empty( $gap['query'] ) ) {
			return;
		}
		$table = $wpdb->prefix . 'vmsb_issues';

		$dupe = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE rule = 'competitor_gap' AND status = 'open' AND detail LIKE %s",
			'%' . $wpdb->esc_like( $gap['query'] ) . '%'
		) );
		if ( $dupe ) {
			return;
		}

		$wpdb->insert( $table, array(
			'object_type' => 'site',
			'object_id'   => 0,
			'rule'        => 'competitor_gap',
			'severity'    => 'medium',
			'detail'      => sprintf( '%s likely competes for "%s". %s', $domain, $gap['query'], $gap['reason'] ?? '' ),
			'suggested'   => wp_json_encode( array( 'query' => $gap['query'], 'angle' => $gap['angle'] ?? '', 'competitor' => $domain ) ),
			'status'      => 'open',
			'detected_at' => current_time( 'mysql' ),
		) );
	}

	/* ---------------------------------------------------------------- content duel */

	/**
	 * Compare one of our posts against a competitor's coverage of the same
	 * topic and propose concrete improvements - not a rewrite, a diff.
	 * Advanced 2026: Handles 'Discovery Duels' for new gap identification.
	 */
	public function duel( $post_id, $domain ) {
		if ( ! $post_id ) {
			return $this->discovery_duel( $domain );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_competitor', 'Post not found.' );
		}

		$brain  = new VMSB_Brain();
		$prompt = "Our article, title \"{$post->post_title}\":\n\n" . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 5000 ) . "\n\n"
			. "Competitor domain: {$domain}\n\n"
			. "Based on how a site like this typically covers this topic, what does their coverage likely include that ours does not - "
			. "specific subtopics, data points, formats (comparison table, calculator, FAQ), or angles? Be concrete, not generic ('more detail').\n\n"
			. 'Return JSON: {"likely_gaps":[""],"recommended_additions":[""],"verdict":"ahead|even|behind"}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 700, 'temperature' => 0.3, 'persona' => 'thief' ) );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_competitor', $this->ai->get_last_error() ?: 'No usable response.' );
		}

		update_post_meta( $post_id, '_vmsb_competitor_duel', array( 'domain' => $domain, 'result' => $data, 'checked_at' => current_time( 'mysql' ) ) );
		return $data;
	}

	private function discovery_duel( $domain ) {
		$brain = new VMSB_Brain();

		$prompt = "Act as a Content Thief. Analyze the competitor domain: '{$domain}'.\n"
			. "Find 3 high-authority topics they rank for that this business ('{$brain->context_prompt()}') is missing.\n"
			. "For each, provide a 'Hijack Strategy' and why they are winning.\n"
			. 'Return JSON: {"gaps":[{"topic":"","hijack_angle":"","why_winning":""}]}';

		return $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'persona' => 'thief' ) );
	}

	/**
	 * Attempt to estimate competitor publishing velocity.
	 */
	public function track_velocity( $domain ) {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_competitor_velocity';

		// 1. Try to find a sitemap to count posts (lightweight HEAD request first)
		$sitemap_url = "https://{$domain}/post-sitemap.xml";
		$response = wp_remote_get( $sitemap_url, array( 'timeout' => 10 ) );
		$count = 0;

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$body = wp_remote_retrieve_body( $response );
			$count = substr_count( $body, '<url>' );
		}

		if ( $count === 0 ) {
			// Fallback: Ask AI to estimate based on their current index size
			$brain = new VMSB_Brain();
			$prompt = "Estimate the current total number of blog posts/articles on the domain: {$domain}. "
				. "Base this on what you know about their site size and publishing frequency. "
				. 'Return JSON: {"est_count":0}';
			$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 50 ) );
			$count = isset( $data['est_count'] ) ? (int) $data['est_count'] : 0;
		}

		if ( $count > 0 ) {
			$wpdb->replace( $table, array(
				'domain'        => $domain,
				'post_count'    => $count,
				'snapshot_date' => current_time( 'mysql' )
			) );
		}
	}

	public function get_velocity_report() {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_competitor_velocity';

		return $wpdb->get_results(
			"SELECT domain, post_count, snapshot_date FROM {$table}
			 ORDER BY domain, snapshot_date DESC"
		);
	}

	public function counts() {
		global $wpdb;
		return array(
			'tracked'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() ),
			'checked'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE checked_at IS NOT NULL' ),
			'open_gaps'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_issues WHERE rule = 'competitor_gap' AND status = 'open'" ),
		);
	}
}
