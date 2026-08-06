<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keyword research without a paid rank tracker.
 * Three sources, merged and scored:
 *   1. Search Console — what already earns impressions (the truth source).
 *   2. Google autocomplete + People Also Ask expansion — real query demand.
 *   3. AI expansion from the business profile — for a site with no history yet.
 */
class VMSB_Keywords {

	private $ai;
	private $google;
	private $brain;
	private $log;

	public function __construct() {
		$this->ai     = new VMSB_AI_Router();
		$this->google = new VMSB_Google();
		$this->brain  = new VMSB_Brain();
		$this->log    = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_keywords';
	}

	/**
	 * Identifies "Anomalies" in the niche where competitors are weak
	 * but search interest is spikey.
	 */
	private function discover_niche_anomalies() {
		$profile = $this->brain->profile();
		$prompt = "Act as a Niche Intelligence Analyst. Identify 10 'Search Anomalies' for this business: '{$profile['name']} ({$profile['type']})'.\n"
			. "Anomalies are queries that:\n"
			. "1. Have rising interest but 'Thin' search results.\n"
			. "2. Are underserved by major industry players.\n"
			. "3. Connect two unrelated but relevant sub-topics.\n\n"
			. 'Return JSON: {"anomalies":[{"keyword":"","why_opportunity":"","intent":""}]}';

		$data = $this->ai->generate_json( $prompt, array( 'persona' => 'strategist', 'complexity' => 'premium' ) );
		$n = 0;
		if ( ! empty($data['anomalies']) ) {
			foreach ( $data['anomalies'] as $a ) {
				$this->upsert( $a['keyword'], array(
					'source' => 'anomaly',
					'intent' => $a['intent'] ?: 'informational',
					'cluster' => 'Anomalies'
				) );
				$n++;
			}
		}
		return $n;
	}

	/* ---------------------------------------------------------------- research */

	/**
	 * Pull keywords identified as competitor gaps into the main keyword universe.
	 */
	public function ingest_competitor_gaps() {
		global $wpdb;
		$issues_table = $wpdb->prefix . 'vmsb_issues';
		$gaps = $wpdb->get_results( "SELECT suggested FROM {$issues_table} WHERE rule = 'competitor_gap' AND status = 'open'" );

		$n = 0;
		foreach ( $gaps as $gap ) {
			$data = json_decode( $gap->suggested, true );
			if ( ! empty($data['query']) ) {
				$this->upsert( $data['query'], array(
					'source' => 'competitor',
					'intent' => 'commercial',
					'cluster' => $data['competitor'] ?? 'Competitor Hijack'
				) );
				$n++;
			}
		}
		return $n;
	}

	public function research( $seed_limit = 40 ) {
		$found = 0;
		$found += $this->pull_search_console();
		$found += $this->expand_autocomplete( $seed_limit );
		$found += $this->expand_with_ai();

		// 2026 Strategy: Competitor Gap Hijacking
		if ( class_exists( 'VMSB_Competitor' ) ) {
			$comp_engine = new VMSB_Competitor();
			$res = $comp_engine->scan( 5 ); // Check top 5 rivals
			if ( ! is_wp_error($res) ) {
				$found += $this->ingest_competitor_gaps();
			}
		}

		// 2026 Strategy: Niche Territorial Analysis
		$found += $this->discover_niche_anomalies();

		// 2026 Strategy: Rising Trends Ingestion
		if ( class_exists( 'VMSB_Trends' ) ) {
			$trends = ( new VMSB_Trends() )->get_rising_signals( 5 );
			foreach ( $trends as $t ) {
				$this->upsert( $t, array( 'source' => 'trends', 'intent' => 'informational' ) );
				$found++;
			}
		}

		$this->score_all();
		$this->cluster();

		$this->log->info( 'keywords', "Research pass complete. {$found} keywords touched." );
		return $found;
	}

	/**
	 * Search Console is the highest-signal source: these are queries the site
	 * already appears for, including the ones nobody planned.
	 */
	public function pull_search_console() {
		// 1. Try Rank Math Integration first (High-Quality Local Cache)
		$rm = new VMSB_RankMath();
		if ( $rm->is_active() ) {
			$local_rows = $rm->get_local_metrics( array('query'), 90, 1000 );
			if ( ! empty($local_rows) ) {
				$this->process_gsc_rows( $local_rows, 'rankmath' );
				$this->log->info( 'keywords', 'Synchronized 1,000 keywords from Rank Math Analytics.' );
				// We still fall through to live API for newest data if connected
			}
		}

		if ( ! $this->google->is_connected() ) {
			return 0;
		}
		$rows = $this->google->gsc_query( array( 'query' ), 90, 1000 );
		if ( is_wp_error( $rows ) ) {
			$this->log->warn( 'keywords', 'Search Console pull failed: ' . $rows->get_error_message() );
			return 0;
		}

		return $this->process_gsc_rows( $rows, 'gsc' );
	}

	private function process_gsc_rows( $rows, $source ) {
		$titles = array();
		$count  = 0;

		foreach ( $rows as $row ) {
			$keyword = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
			if ( ! $keyword ) {
				continue;
			}
			$this->upsert(
				$keyword,
				array(
					'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
					'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
					'ctr'         => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0,
					'position'    => isset( $row['position'] ) ? (float) $row['position'] : null,
					'source'      => $source,
				)
			);
			$titles[] = $keyword;
			$count++;
		}

		$this->brain->remember( 'gsc', 'top_queries', array_slice( $titles, 0, 100 ), 0.95, $source );
		return $count;
	}

	/**
	 * Google's own suggest endpoint. Free, fast, and reflects live demand.
	 */
	public function expand_autocomplete( $limit = 40 ) {
		$seeds = $this->seeds( $limit );
		$count = 0;
		$alpha = str_split( 'abcdefghijklmnopqrstuvwxyz' );
		$mods  = array( '', 'how to ', 'best ', 'why ', 'cost of ', 'near me', 'vs' );
		$start_time = time();

		foreach ( $seeds as $seed ) {
			// Anti-timeout check - was only evaluated once per seed, but each
			// seed can fire up to 4 (mods) + 8 (A-Z sweep) = 12 sequential
			// HTTP requests before this was checked again. suggest() caches
			// for a week, but the first, uncached run through a seed list
			// could blow well past this budget before ever re-checking it.
			if ( time() - $start_time > 20 ) break;

			foreach ( array_slice( $mods, 0, 4 ) as $mod ) {
				if ( time() - $start_time > 20 ) break 2;
				$suggestions = $this->suggest( trim( $mod . ' ' . $seed ) );
				foreach ( $suggestions as $s ) {
					$this->upsert( $s, array( 'source' => 'autocomplete' ) );
					$count++;
				}
			}
			// A-Z sweep on the strongest seeds only — it's 26 requests each.
			if ( $count < 400 ) {
				foreach ( array_slice( $alpha, 0, 8 ) as $letter ) {
					if ( time() - $start_time > 20 ) break 2;
					foreach ( $this->suggest( $seed . ' ' . $letter ) as $s ) {
						$this->upsert( $s, array( 'source' => 'autocomplete' ) );
						$count++;
					}
				}
			}
		}
		return $count;
	}

	private function suggest( $term ) {
		$cache_key = 'vmsb_sg_' . md5( $term );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$url = 'https://suggestqueries.google.com/complete/search?' . http_build_query(
			array(
				'client' => 'firefox',
				'hl'     => VMSB_Settings::get( 'language' ),
				'gl'     => VMSB_Settings::get( 'country' ),
				'q'      => $term,
			)
		);

		$res = wp_remote_get( $url, array( 'timeout' => 15, 'user-agent' => 'Mozilla/5.0 (compatible; VMSEOBrain/1.0)' ) );
		if ( is_wp_error( $res ) ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		$out  = ( isset( $data[1] ) && is_array( $data[1] ) ) ? array_values( array_filter( $data[1], 'is_string' ) ) : array();

		set_transient( $cache_key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/**
	 * For brand new sites with no GSC history, the business profile is the seed.
	 */
	public function expand_with_ai() {
		$profile = $this->brain->profile();

		$prompt = "Produce a keyword universe for this business.\n"
			. "Cover the full funnel: informational, commercial investigation, transactional, and navigational.\n"
			. "Include long-tail questions real buyers type. Include location modifiers only if the business is local.\n"
			. "For each keyword, estimate the Global Search Volume (monthly) and identify likely SERP Features (e.g. Featured Snippet, People Also Ask, Video).\n"
			. "Do not include keywords the business cannot credibly rank for or serve.\n\n"
			. 'Return JSON: {"keywords":[{"keyword":"","intent":"informational|commercial|transactional|navigational","funnel":"top|middle|bottom","cluster":"","est_difficulty":0,"est_volume":0,"serp_features":[]}]}';

		$data = $this->ai->generate_json(
			$prompt,
			array(
				'system'      => $this->brain->context_prompt(),
				'max_tokens'  => 3000,
				'temperature' => 0.5,
				'cache_ttl'   => DAY_IN_SECONDS,
			)
		);

		$count       = 0;
		$semrush_on  = class_exists( 'VMSB_External_Data' ) && VMSB_External_Data::semrush_configured();

		foreach ( ( isset( $data['keywords'] ) ? $data['keywords'] : array() ) as $item ) {
			if ( empty( $item['keyword'] ) ) {
				continue;
			}

			$fields = array(
				'intent'     => isset( $item['intent'] ) ? $item['intent'] : null,
				'funnel'     => isset( $item['funnel'] ) ? $item['funnel'] : null,
				'cluster'    => isset( $item['cluster'] ) ? $item['cluster'] : null,
				'difficulty' => isset( $item['est_difficulty'] ) ? (int) $item['est_difficulty'] : null,
				'volume'     => isset( $item['est_volume'] ) ? (int) $item['est_volume'] : null,
				'serp_features' => isset( $item['serp_features'] ) ? wp_json_encode( (array) $item['serp_features'] ) : null,
				'source'     => 'ai',
			);

			// Real data beats the model's estimate when a key is configured.
			// Capped to the top few keywords per run - this is a metered API,
			// not something to spend on every row silently.
			if ( $semrush_on && $count < 10 ) {
				$real = VMSB_External_Data::semrush_keyword_overview( $item['keyword'] );
				if ( ! is_wp_error( $real ) ) {
					$fields['difficulty'] = (int) round( $real['difficulty'] );
					$fields['source']     = 'semrush';
				}
			}

			$this->upsert( $item['keyword'], $fields );
			$count++;
		}
		return $count;
	}

	private function seeds( $limit ) {
		global $wpdb;
		$seeds = $wpdb->get_col( $wpdb->prepare( "SELECT keyword FROM {$this->table()} WHERE source = 'gsc' ORDER BY impressions DESC LIMIT %d", (int) $limit ) );
		if ( $seeds ) {
			return $seeds;
		}

		$profile = $this->brain->profile();
		$raw     = array_merge(
			array_map( 'trim', explode( ',', (string) $profile['services'] ) ),
			(array) $profile['pillars']
		);
		return array_slice( array_values( array_filter( array_unique( $raw ) ) ), 0, (int) $limit );
	}

	/* ---------------------------------------------------------------- storage + scoring */

	public function upsert( $keyword, array $fields = array() ) {
		global $wpdb;
		$keyword = trim( mb_strtolower( wp_strip_all_tags( $keyword ) ) );
		if ( mb_strlen( $keyword ) < 3 || mb_strlen( $keyword ) > 180 ) {
			return false;
		}

		$data = array_filter(
			array(
				'keyword'     => $keyword,
				'cluster'     => isset( $fields['cluster'] ) ? $fields['cluster'] : null,
				'intent'      => isset( $fields['intent'] ) ? $fields['intent'] : null,
				'funnel'      => isset( $fields['funnel'] ) ? $fields['funnel'] : null,
				'difficulty'  => isset( $fields['difficulty'] ) ? $fields['difficulty'] : null,
				'position'    => isset( $fields['position'] ) ? $fields['position'] : null,
				'impressions' => isset( $fields['impressions'] ) ? $fields['impressions'] : null,
				'clicks'      => isset( $fields['clicks'] ) ? $fields['clicks'] : null,
				'ctr'         => isset( $fields['ctr'] ) ? $fields['ctr'] : null,
				'serp_features' => isset( $fields['serp_features'] ) ? $fields['serp_features'] : null,
				'source'      => isset( $fields['source'] ) ? $fields['source'] : 'ai',
				'volume'      => isset( $fields['volume'] ) ? (int) $fields['volume'] : null,
			),
			static fn( $v ) => null !== $v
		);
		$data['updated_at'] = current_time( 'mysql', true );

		$sets = array();
		foreach ( array_keys( $data ) as $col ) {
			if ( 'keyword' === $col ) {
				continue;
			}
			$sets[] = "{$col} = VALUES({$col})";
		}

		$cols   = implode( ', ', array_keys( $data ) );
		$holds  = implode( ', ', array_fill( 0, count( $data ), '%s' ) );
		$update = implode( ', ', $sets );

		return $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} ({$cols}) VALUES ({$holds}) ON DUPLICATE KEY UPDATE {$update}",
				array_values( $data )
			)
		);
	}

	/**
	 * Opportunity score: weighted toward cheap wins - striking-distance
	 * rankings, bottom-funnel intent, and low difficulty score highest.
	 * The "Quick Win" / "Content Gap" classification this used to promise
	 * as a second step here isn't stored - it's already implemented as
	 * separate filtered queries instead (striking_distance() for Quick Win,
	 * content_gaps() for Content Gap), which is what admin/views/keywords.php
	 * actually renders as its tabs.
	 */
	public function score_all() {
		global $wpdb;

		// 1. Calculate base opportunity
		$wpdb->query(
			"UPDATE {$this->table()} SET opportunity = (
				(LOG(10, GREATEST(impressions, 1)) * 12)
				+ (CASE WHEN position BETWEEN 4 AND 20 THEN 45 WHEN position BETWEEN 21 AND 50 THEN 20 ELSE 0 END)
				+ (CASE WHEN clicks = 0 AND impressions > 50 THEN 25 ELSE 0 END)
				+ (CASE WHEN funnel = 'bottom' THEN 20 WHEN funnel = 'middle' THEN 12 ELSE 5 END)
				- (COALESCE(difficulty, 40) * 0.35)
			)"
		);

		// 2. High-Quality Booster: Boost keywords in authoritative clusters
		$clusters = $this->get_cluster_stats( 50 );
		foreach ( $clusters as $c ) {
			if ( $c->cluster_health > 70 ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$this->table()} SET opportunity = opportunity * 1.25 WHERE cluster = %s",
					$c->cluster
				) );
			}
		}

		// 3. Final normalization
		$wpdb->query( "UPDATE {$this->table()} SET opportunity = ROUND(GREATEST(0, LEAST(100, opportunity)), 1)" );
	}

	/**
	 * Get Cluster health and authority stats.
	 */
	public function get_cluster_stats( $limit = 10 ) {
		global $wpdb;
		return $wpdb->get_results( "
			SELECT
				cluster,
				COUNT(*) as keywords,
				ROUND(AVG(position), 1) as avg_pos,
				SUM(impressions) as total_imp,
				SUM(clicks) as total_clicks,
				ROUND(SUM(opportunity) / COUNT(*), 1) as cluster_health
			FROM {$this->table()}
			WHERE cluster IS NOT NULL AND cluster != ''
			GROUP BY cluster
			ORDER BY total_imp DESC
			LIMIT " . (int) $limit
		);
	}

	public function cluster() {
		global $wpdb;
		$unclustered = $wpdb->get_col( "SELECT keyword FROM {$this->table()} WHERE cluster IS NULL OR cluster = '' ORDER BY opportunity DESC LIMIT 250" );
		if ( ! $unclustered ) {
			return 0;
		}

		// Every call used to invent cluster names with zero knowledge of what
		// already existed, so the same topic could come back "Core Brand &
		// Education" this run and "Core Brand and Education" next run -
		// fragmenting instead of consolidating every time research runs.
		// Feeding the existing distinct names back in and requiring a match
		// against them first is what makes clustering actually converge to a
		// stable set of silos over repeated runs instead of growing forever.
		$existing = $wpdb->get_col( "SELECT DISTINCT cluster FROM {$this->table()} WHERE cluster IS NOT NULL AND cluster != '' ORDER BY cluster ASC" );

		$data = $this->ai->generate_json(
			"Group these search queries into topic clusters. One cluster per genuine topic, not per keyword. Name each cluster the way a pillar page would be titled.\n\n"
			. ( $existing
				? "EXISTING CLUSTERS (use one of these names verbatim whenever a query belongs to one of them - do not invent a slightly different name for a topic that already has a cluster):\n- " . implode( "\n- ", $existing ) . "\n\n"
				: '' )
			. "Only invent a new cluster name for a query that genuinely doesn't fit any existing cluster above.\n\n"
			. "Reproduce each query in \"keywords\" EXACTLY character-for-character as given below - do not paraphrase, reorder words, fix spelling/grammar, or change capitalization. "
			. "A query that doesn't match what was given verbatim cannot be matched back to its record.\n\n"
			. "Queries:\n- " . implode( "\n- ", $unclustered )
			. '\n\nReturn JSON: {"clusters":[{"name":"","intent":"","keywords":[]}]}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 3000, 'temperature' => 0.3 )
		);

		// Match against the exact set sent to the model, not the whole table -
		// anything the model returns that isn't in this set didn't survive the
		// round trip verbatim and can't be safely applied to a specific row.
		$sendable = array_flip( array_map( 'mb_strtolower', $unclustered ) );

		$n         = 0;
		$unmatched = array();
		foreach ( ( isset( $data['clusters'] ) ? $data['clusters'] : array() ) as $cluster ) {
			foreach ( ( isset( $cluster['keywords'] ) ? $cluster['keywords'] : array() ) as $kw ) {
				$norm = mb_strtolower( trim( $kw ) );
				if ( ! isset( $sendable[ $norm ] ) ) {
					$unmatched[] = $kw;
					continue;
				}
				$updated = $wpdb->update(
					$this->table(),
					array( 'cluster' => $cluster['name'], 'intent' => isset( $cluster['intent'] ) ? $cluster['intent'] : null ),
					array( 'keyword' => $norm )
				);
				// Only count what actually landed - not everything the model
				// echoed back - so the caller isn't told more got clustered
				// than actually did.
				if ( $updated ) {
					$n++;
				} else {
					$unmatched[] = $kw;
				}
			}
		}

		if ( $unmatched ) {
			$this->log->warn( 'keywords', count( $unmatched ) . ' keyword(s) returned by clustering did not match a tracked query and were skipped.', array( 'sample' => array_slice( $unmatched, 0, 10 ) ) );
		}

		return $n;
	}

	/* ---------------------------------------------------------------- reads */

	public function top( $limit = 50, $status = 'new' ) {
		global $wpdb;
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY opportunity DESC LIMIT %d", $status, (int) $limit ) );
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status != 'dismissed' ORDER BY opportunity DESC LIMIT %d", (int) $limit ) );
	}

	/** Queries ranking 4-20: one strong revision usually moves these to page one. */
	public function striking_distance( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE position BETWEEN 4 AND 20 AND impressions > 20 AND status != 'dismissed' ORDER BY impressions DESC LIMIT %d", (int) $limit ) );
	}

	/** Impressions with no clicks: the page ranks but the snippet isn't earning the click. */
	public function ctr_losers( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE impressions > 100 AND ctr < 0.01 AND status != 'dismissed' ORDER BY impressions DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Remove a keyword from active consideration (Quick Wins, Content Gaps,
	 * CTR Optimization) without deleting its history - irrelevant queries
	 * from autocomplete/AI expansion had no way to be cleared before this.
	 */
	public function dismiss( $keyword ) {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table(),
			array( 'status' => 'dismissed', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'keyword' => mb_strtolower( trim( $keyword ) ) )
		);
	}

	/**
	 * Rename a cluster, or fold it into an existing one by naming the
	 * target the same as another cluster - every keyword in $from moves to
	 * $to. Guards against AI-assigned cluster names silently fragmenting
	 * ("Water Heater Repair" vs "Water Heater Repairs") with no way to
	 * consolidate them back into one silo.
	 */
	public function merge_cluster( $from, $to ) {
		global $wpdb;
		$from = trim( $from );
		$to   = trim( $to );
		if ( ! $from || ! $to || $from === $to ) {
			return 0;
		}
		return (int) $wpdb->update(
			$this->table(),
			array( 'cluster' => $to, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'cluster' => $from )
		);
	}

	/**
	 * One-time cleanup for clusters that already fragmented before
	 * cluster() started reusing existing names - "Core Brand & Education"
	 * vs "Core Brand and Education" vs "Core Brand & Services" all sitting
	 * as separate clusters, for example. Suggests merges for a human to
	 * review and apply via merge_cluster(); never merges anything itself,
	 * since collapsing two genuinely distinct clusters into one would be
	 * worse than leaving them fragmented.
	 */
	public function suggest_cluster_merges() {
		global $wpdb;
		$clusters = $wpdb->get_col( "SELECT DISTINCT cluster FROM {$this->table()} WHERE cluster IS NOT NULL AND cluster != '' ORDER BY cluster ASC" );
		if ( count( $clusters ) < 2 ) {
			return array();
		}

		$data = $this->ai->generate_json(
			"These are cluster names currently used on a site's keyword map. Some may be the same real-world topic split across "
			. "slightly different names (capitalization, 'and' vs '&', singular vs plural, a reworded title for the same subject).\n\n"
			. "Clusters:\n- " . implode( "\n- ", $clusters ) . "\n\n"
			. "Only group names you're confident describe the same topic - when in doubt, leave them separate. For each group of 2+ "
			. "duplicates, name the clearest one as the keeper and list the rest as names to fold into it.\n\n"
			. 'Return JSON: {"groups":[{"keep":"","merge":[]}]}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 1500, 'temperature' => 0.2 )
		);

		$known = array_flip( $clusters );
		$suggestions = array();
		foreach ( ( isset( $data['groups'] ) ? $data['groups'] : array() ) as $group ) {
			$keep = isset( $group['keep'] ) ? trim( $group['keep'] ) : '';
			if ( ! $keep || ! isset( $known[ $keep ] ) ) {
				continue;
			}
			foreach ( ( isset( $group['merge'] ) ? $group['merge'] : array() ) as $from ) {
				$from = trim( $from );
				// Only ever suggest folding a cluster that actually exists into
				// another that actually exists - the model can't invent a merge
				// target merge_cluster() would then apply against nothing.
				if ( $from && $from !== $keep && isset( $known[ $from ] ) ) {
					$suggestions[] = array( 'from' => $from, 'to' => $keep );
				}
			}
		}

		return $suggestions;
	}

	public function content_gaps( $limit = 40 ) {
		global $wpdb;

		// If no keyword data exists, immediately trigger AI expansion to seed the queue
		$total = $this->count();
		if ( $total < 10 ) {
			( new VMSB_Logger() )->info( 'keywords', 'Zero-data state detected. Seeding keyword universe from business DNA.' );
			$this->expand_with_ai();
		}

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE (post_id IS NULL OR post_id = 0) AND status = 'new' ORDER BY opportunity DESC LIMIT %d",
				(int) $limit
			)
		);
	}

	public function mark( $keyword, $status, $post_id = 0 ) {
		global $wpdb;
		return $wpdb->update(
			$this->table(),
			array( 'status' => $status, 'post_id' => $post_id ? (int) $post_id : null ),
			array( 'keyword' => mb_strtolower( trim( $keyword ) ) )
		);
	}

	public function count( $status = '' ) {
		global $wpdb;
		if ( $status ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table()} WHERE status = %s", $status ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}
}
