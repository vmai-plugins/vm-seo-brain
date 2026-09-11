<?php
defined( 'ABSPATH' ) || exit;

/**
 * The error fixer. Two speeds:
 *   God Fix  — you press it, it fixes everything open, one pass, reversible.
 *   God Mode — the same engine on a schedule, inside the scope you allow.
 *
 * Every autonomous write stores a revert payload. Nothing is destructive.
 */
class VMSB_Fixer {

	private $ai;
	private $brain;
	private $rankmath;
	private $images;
	private $log;

	public function __construct() {
		$this->ai       = new VMSB_AI_Router();
		$this->brain    = new VMSB_Brain();
		$this->rankmath = new VMSB_RankMath();
		$this->images   = new VMSB_Image_Engine();
		$this->log      = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_issues';
	}

	/* ---------------------------------------------------------------- ledger */

	public function record( $object_type, $object_id, $rule, $severity, $detail, $suggested = array(), $metrics = null ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE object_type = %s AND object_id = %d AND rule = %s AND status IN ('open', 'dismissed')",
				$object_type,
				(int) $object_id,
				$rule
			)
		);
		if ( $exists ) {
			return (int) $exists;
		}

		$impact     = $this->calculate_impact( $rule, $severity, $object_id, $metrics );
		$confidence = VMSB_Outcome_Ledger::weight_for( $this->module_for_rule( $rule ) );

		$wpdb->insert(
			$this->table(),
			array(
				'object_type' => $object_type,
				'object_id'   => (int) $object_id,
				'rule'        => $rule,
				'severity'    => $severity,
				'impact'      => $impact,
				'confidence'  => $confidence,
				'detail'      => $detail,
				'suggested'   => $suggested ? wp_json_encode( $suggested ) : null,
				'status'      => 'open',
				'detected_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * $metrics lets a caller that already pulled GSC data for this object in
	 * the same pass (scan_posts(), scan_index_coverage()) hand it over
	 * instead of triggering another live Search Console API call here - a
	 * post with five flagged issues used to mean five extra round-trips for
	 * data already fetched once, which is most of why a full scan burns its
	 * time budget auditing so few posts and can eat into GSC's daily quota.
	 */
	private function calculate_impact( $rule, $severity, $object_id, $metrics = null ) {
		$base = array( 'critical' => 80, 'high' => 50, 'medium' => 25, 'low' => 10 )[ $severity ] ?? 20;

		// Boost impact for high-traffic pages
		if ( null === $metrics && $object_id ) {
			$metrics = ( new VMSB_Google() )->gsc_page_metrics( get_permalink( $object_id ), 14 );
		}
		if ( $metrics && ! is_wp_error( $metrics ) && ( $metrics['impressions'] ?? 0 ) > 500 ) {
			$base *= 1.5;
		}

		return round( $base, 1 );
	}

	private function module_for_rule( $rule ) {
		$map = array(
			'missing_seo_title' => 'fixer',
			'thin_content'      => 'content',
			'striking_distance' => 'content',
			'low_ctr_snippet'   => 'ctr',
		);
		return $map[ $rule ] ?? 'fixer';
	}

	public function open_issues( $limit = 100, $severity = '', $post_type = '', $rule = '' ) {
		global $wpdb;
		$table = $this->table();
		$where = "i.status = 'open'";
		$join  = "";

		if ( $severity ) {
			$where .= $wpdb->prepare( " AND i.severity = %s", $severity );
		}

		if ( $rule ) {
			$where .= $wpdb->prepare( " AND i.rule = %s", $rule );
		}

		if ( $post_type ) {
			$join  = " INNER JOIN {$wpdb->posts} p ON i.object_id = p.ID";
			$where .= $wpdb->prepare( " AND i.object_type = 'post' AND p.post_type = %s", $post_type );
		}

		$query = "SELECT i.* FROM {$table} i {$join} WHERE {$where} ORDER BY i.impact DESC, FIELD(i.severity,'critical','high','medium','low'), i.id ASC LIMIT " . (int) $limit;
		return $wpdb->get_results( $query );
	}

	/**
	 * Issues whose fix was drafted but parked for review (require_review is
	 * on) instead of applied - each one has a real pending draft sitting in
	 * _vmsb_pending_revision on the target post, waiting to be approved or
	 * discarded.
	 */
	public function pending_review_issues( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE status = 'pending_review' ORDER BY id DESC LIMIT %d", (int) $limit
		) );
	}

	/**
	 * Recently auto-fixed issues that can still be undone. The god-fix
	 * confirm dialog has always promised "Revert any change later from the
	 * logs" - the logs page has never had anything to click, and neither
	 * did anywhere else. Scoped to has a revert_payload, since fixes that
	 * never captured a before-state (a handful of rule types don't) can't
	 * actually be undone even though their status is 'fixed'.
	 */
	public function fixed_issues( $limit = 25 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE status = 'fixed' AND revert_payload IS NOT NULL AND revert_payload != '' ORDER BY id DESC LIMIT %d",
			(int) $limit
		) );
	}

	public function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT severity, COUNT(*) n FROM {$this->table()} WHERE status = 'open' GROUP BY severity", ARRAY_A );
		$out  = array( 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0, 'total' => 0 );
		foreach ( $rows as $row ) {
			$out[ $row['severity'] ] = (int) $row['n'];
			$out['total']           += (int) $row['n'];
		}
		$out['fixed'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'fixed'" );
		$out['pending_review'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'pending_review'" );
		return $out;
	}

	/* ---------------------------------------------------------------- scan */

	/**
	 * Full site audit. Technical, on-page, taxonomy, silo, and index coverage.
	 */
	/**
	 * Full site scan.
	 *
	 * A scan is five separate passes and only one of them - scan_posts() - ever
	 * had a time limit. The other four walk the site with no bound at all: the
	 * taxonomy audit visits every term in every taxonomy, the silo diagnosis
	 * spends a premium AI call and then works through each pillar, and the
	 * index-coverage pass calls url_to_postid() for up to 500 Search Console
	 * rows, which is a query apiece. On a small site that is invisible. On a
	 * site with a couple of thousand posts and the tag archive that usually
	 * comes with them, the request runs for minutes and the gateway gives up
	 * first - a 524 with nothing recorded, because the passes that did finish
	 * never got to write their results.
	 *
	 * So the budget belongs to the scan as a whole, not to one pass inside it.
	 * Every phase now shares one deadline, stops cleanly when it is reached,
	 * and what did complete is saved. The next run resumes where this one
	 * stopped: scan_posts() already orders by least-recently-audited, so
	 * repeated partial scans still walk the whole site.
	 */
	public function scan( $post_limit = 200, $budget = null ) {
		$started = time();

		if ( null === $budget ) {
			// Same reasoning as the task runner: the binding limit is whatever
			// proxy holds the connection, not max_execution_time. Under cron or
			// WP-CLI nothing is waiting, so allow a real pass.
			$unattended = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || 'cli' === PHP_SAPI;
			$max_exec   = (int) ini_get( 'max_execution_time' );
			$budget     = $unattended
				? ( $max_exec > 0 ? max( 60, (int) ( $max_exec * 0.6 ) ) : 300 )
				: 45;
		}
		$deadline = $started + (int) $budget;

		$found   = 0;
		$skipped = array();

		$found += $this->scan_technical();

		$found += $this->scan_posts( $post_limit, $deadline );

		if ( time() < $deadline ) {
			$found += ( new VMSB_Taxonomy() )->audit( array( 'category', 'post_tag' ), $deadline );
		} else {
			$skipped[] = 'taxonomy';
		}

		if ( time() < $deadline ) {
			$found += ( new VMSB_Silo() )->diagnose( $deadline );
		} else {
			$skipped[] = 'silos';
		}

		if ( time() < $deadline ) {
			$found += $this->scan_index_coverage( $deadline );
		} else {
			$skipped[] = 'index coverage';
		}

		update_option( 'vmsb_last_scan', time(), false );
		delete_transient( 'vmsb_status_summary' ); // Invalidate status cache

		$elapsed = time() - $started;
		if ( $skipped ) {
			$this->log->warn( 'fixer', sprintf(
				'Scan stopped at its %ds budget after %ds with %d issues recorded. Not reached this pass: %s. The next scan resumes from the least recently audited content.',
				$budget, $elapsed, $found, implode( ', ', $skipped )
			) );
		} else {
			$this->log->info( 'fixer', "Site scan complete in {$elapsed}s. {$found} open issues." );
		}

		return $found;
	}

	private function scan_technical() {
		// Optimization: Only run technical checks once every 24 hours
		$last_tech_scan = (int) get_option( 'vmsb_last_tech_scan', 0 );
		if ( time() - $last_tech_scan < DAY_IN_SECONDS ) {
			return 0;
		}

		$n = 0;

		if ( '1' !== get_option( 'blog_public' ) ) {
			$this->record( 'site', 0, 'search_engines_discouraged', 'critical', 'Settings > Reading is blocking search engines. Nothing else matters until this is off.' );
			$n++;
		}

		$permalink = get_option( 'permalink_structure' );
		if ( ! $permalink || false !== strpos( $permalink, '%post_id%' ) && false === strpos( $permalink, '%postname%' ) ) {
			$this->record( 'site', 0, 'weak_permalinks', 'high', 'Permalinks do not contain the post name.' );
			$n++;
		}

		if ( ! is_ssl() && 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
			$this->record( 'site', 0, 'no_https', 'critical', 'The site is not served over HTTPS.' );
			$n++;
		}

		if ( ! $this->rankmath->is_active() ) {
			$this->record( 'site', 0, 'rankmath_missing', 'high', 'Rank Math is not active, so meta output is unmanaged.' );
			$n++;
		}

		// Robots.txt sanity.
		$robots = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 15 ) );
		if ( ! is_wp_error( $robots ) ) {
			$body = wp_remote_retrieve_body( $robots );
			if ( preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $body ) ) {
				$this->record( 'site', 0, 'robots_blocks_site', 'critical', 'robots.txt disallows the entire site.' );
				$n++;
			}
			if ( false === stripos( $body, 'sitemap' ) ) {
				$this->record( 'site', 0, 'robots_no_sitemap', 'low', 'robots.txt does not reference a sitemap.' );
				$n++;
			}
		}

		// Sitemap reachable.
		$sitemap = wp_remote_head( $this->rankmath->sitemap_url(), array( 'timeout' => 15 ) );
		if ( is_wp_error( $sitemap ) || 200 !== (int) wp_remote_retrieve_response_code( $sitemap ) ) {
			$this->record( 'site', 0, 'sitemap_unreachable', 'high', 'The XML sitemap did not return 200.' );
			$n++;
		}

		// World-Class Tech Pass: Global Noindex check
		global $wpdb;
		$noindex_posts = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'rank_math_robots' AND meta_value LIKE '%noindex%'" );
		if ( $noindex_posts ) {
			foreach ( array_slice($noindex_posts, 0, 5) as $pid ) {
				if ( get_post_status($pid) === 'publish' ) {
					$this->record( 'post', $pid, 'accidental_noindex', 'critical', 'This published post is set to "noindex" in Rank Math meta.' );
					$n++;
				}
			}
		}

		update_option( 'vmsb_last_tech_scan', time(), false );
		return $n;
	}

	private function scan_posts( $limit, $deadline = 0 ) {
		$types = get_post_types( array( 'public' => true ), 'names' );

		// 1. Strict Exclusion: Never audit known template or technical types
		$exclude_types = array( 'elementor_library', 'ae_global_templates', 'section', 'hub_section', 'revisions', 'attachment', 'nav_menu_item' );
		foreach ( $exclude_types as $et ) {
			if ( isset( $types[ $et ] ) ) unset( $types[ $et ] );
		}

		// 2. Identify System Pages that should NEVER be rewritten (Front Page, Blog Page)
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$system_page_ids = array_filter( array( $front_page_id, $blog_page_id ) );

		$posts = get_posts(
			array(
				'post_type'      => array_values( $types ),
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_vmsb_last_audit', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_vmsb_last_audit', 'compare' => '<', 'value' => time() - ( 3 * DAY_IN_SECONDS ) ),
				),
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_vmsb_last_audit',
				'order'          => 'ASC',
				'post__not_in'   => $system_page_ids,
			)
		);

		$n = 0;
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$start_time = time();
		$google     = new VMSB_Google();
		$gsc_ready  = $google->is_connected();

		foreach ( (array) $posts as $post ) {
			// This loop makes one live GSC call per post, so it is the most
			// likely place to run long. It now stops against the scan's shared
			// deadline rather than a private 25s window, so the passes that
			// follow it still get a chance to run within the same request.
			$out_of_time = $deadline ? ( time() >= $deadline ) : ( time() - $start_time > 25 );
			if ( $out_of_time ) {
				$this->log->warn( 'fixer', "Post audit stopped at the scan budget after recording {$n} issues." );
				break;
			}

			// World-Class Safety: Only audit post types explicitly enabled by the user
			if ( ! in_array( $post->post_type, $safe_types, true ) ) {
				continue;
			}

			update_post_meta( $post->ID, '_vmsb_last_audit', current_time( 'timestamp' ) );

			// Skip very short titles or obviously placeholder content
			if ( strlen( $post->post_title ) < 4 || stripos( $post->post_title, 'Default Kit' ) !== false ) {
				continue;
			}

			// One GSC fetch per post, reused by every record() call below via
			// $metrics instead of each one triggering its own live API call -
			// a post with five flagged issues used to mean five redundant
			// round-trips for data already fetched once.
			$metrics = $gsc_ready ? $google->gsc_page_metrics( get_permalink( $post->ID ), 30 ) : null;
			if ( is_wp_error( $metrics ) ) {
				$metrics = null;
			}

			// Meta fixes (Title, Description, Focus Keyword)
			foreach ( $this->rankmath->audit( $post->ID ) as $issue ) {
				$this->record( 'post', $post->ID, $issue['rule'], $issue['severity'], $issue['detail'], array(), $metrics );
				$n++;
			}

			// CONTENT-HEAVY AUDITS
			$text  = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$words = str_word_count( $text );

			if ( $words < 300 ) {
				$this->record( 'post', $post->ID, 'thin_content', 'high', "Only {$words} words of body copy.", array(), $metrics );
				$n++;
			}

			if ( ! has_post_thumbnail( $post->ID ) ) {
				$this->record( 'post', $post->ID, 'missing_featured_image', 'medium', 'No featured image set.', array(), $metrics );
				$n++;
			}

			// World-Class Audit: Semantic Entity Gap
			if ( $words > 500 && class_exists( 'VMSB_Entity' ) ) {
				$entities = ( new VMSB_Entity() )->get_missing_entities( $post->ID );
				if ( ! empty( $entities ) ) {
					$this->record( 'post', $post->ID, 'semantic_gap', 'medium', 'Missing key semantic entities: ' . implode( ', ', array_slice( $entities, 0, 5 ) ), array(), $metrics );
					$n++;
				}
			}

			// World-Class Audit: AEO Gap (Answer Engine Optimization)
			if ( $words > 400 && class_exists( 'VMSB_AEO' ) ) {
				$aeo = new VMSB_AEO();
				$aeo_audit = $aeo->audit( $post->ID );
				if ( ! is_wp_error( $aeo_audit ) && $aeo_audit['score'] < 75 ) {
					$this->record( 'post', $post->ID, 'aeo_gap', 'medium', 'Low Answer Engine Optimization score. Missing direct-answer structure.', array(), $metrics );
					$n++;
				}
			}

			// World-Class Audit: Readability & UX
			if ( $words > 800 ) {
				if ( ! preg_match( '/<h[23][\s>]/i', $post->post_content ) ) {
					$this->record( 'post', $post->ID, 'poor_readability', 'medium', 'Long article with no subheadings (H2/H3). High bounce risk.', array(), $metrics );
					$n++;
				}
			}

			// Headings.
			if ( preg_match_all( '/<h1[\s>]/i', $post->post_content, $m ) && count( $m[0] ) > 0 ) {
				$this->record( 'post', $post->ID, 'h1_in_body', 'medium', 'An H1 inside the body competes with the page title.', array(), $metrics );
				$n++;
			}

			// Images missing alt inside content.
			if ( preg_match_all( '/<img(?![^>]*\balt=)[^>]*>/i', $post->post_content, $m ) ) {
				$this->record( 'post', $post->ID, 'images_missing_alt', 'medium', count( $m[0] ) . ' inline images have no alt attribute.', array(), $metrics );
				$n++;
			}

			// Decay: published over a long period and never touched since.
			$staleness_days = (int) VMSB_Settings::get( 'staleness_threshold_days', 365 );
			$age_days = ( time() - get_post_time( 'U', true, $post ) ) / DAY_IN_SECONDS;
			$mod_days = ( time() - get_post_modified_time( 'U', true, $post ) ) / DAY_IN_SECONDS;

			// Detect previously overwritten or broken content (Rank Math score as signal)
			$score = $this->rankmath->get_score( $post->ID );
			if ( $score > 0 && $score < 60 ) {
				$this->record( 'post', $post->ID, 'low_rankmath_score', 'high', "Post has a critically low Rank Math score ({$score}/100). Needs deep optimization.", array(), $metrics );
				$n++;
			}

			if ( $age_days > $staleness_days && $mod_days > ( $staleness_days * 0.8 ) ) {
				$this->record( 'post', $post->ID, 'stale_content', 'medium', sprintf( 'Untouched for %d days.', (int) $mod_days ), array(), $metrics );
				$n++;
			}

			// World-Class Audit: Zombie Content Detection (2000 Post / 200 Visitor Fix).
			// $metrics is only null when GSC isn't connected or the call failed -
			// gsc_page_metrics() returns a real zero-value array (not an
			// 'available' flag, which it never actually sets) when a URL simply
			// has no rows yet, and that zero-data case is itself a valid zombie
			// signal for a page old enough to have been indexed by now.
			if ( $metrics && $metrics['clicks'] < 5 && $metrics['impressions'] < 50 && $age_days > 180 ) {
				$this->record(
					'post',
					$post->ID,
					'zombie_content',
					'high',
					"This post is 'Zombie Content'. In 6 months, it has earned near-zero traffic. It is dead weight dragging down your domain authority.",
					array(),
					$metrics
				);
				$n++;
			}
		}

		return $n;
	}

	private function scan_index_coverage( $deadline = 0 ) {
		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) {
			return 0;
		}

		$rows = $google->gsc_query( array( 'page' ), 90, 500 );
		if ( is_wp_error( $rows ) ) {
			return 0;
		}

		$n = 0;
		foreach ( $rows as $row ) {
			// url_to_postid() below parses the URL and queries for it, once per
			// row, up to 500 times. That is the single most expensive loop in
			// the scan on a large site, and it had no limit of any kind.
			if ( $deadline && time() >= $deadline ) {
				$this->log->warn( 'fixer', "Index coverage stopped at the scan budget after {$n} issues." );
				break;
			}

			$page = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
			$post_id = url_to_postid( $page );
			if ( ! $post_id ) {
				continue;
			}
			$ctr = (float) $row['ctr'];
			$imp = (int) $row['impressions'];
			$pos = (float) $row['position'];

			// This row already carries the same clicks/impressions/position/ctr
			// shape gsc_page_metrics() returns - reusing it as $metrics means
			// record() doesn't have calculate_impact() trigger its own extra
			// live GSC call per issue, up to three times over on a row that
			// happens to trip low_ctr_snippet, striking_distance, and
			// keyword_cannibalization all at once.
			$row_metrics = array( 'clicks' => (int) ( $row['clicks'] ?? 0 ), 'impressions' => $imp, 'position' => $pos, 'ctr' => $ctr );

			if ( $imp > 200 && $ctr < 0.01 ) {
				$this->record( 'post', $post_id, 'low_ctr_snippet', 'high', sprintf( '%d impressions, %.2f%% CTR at position %.1f. The snippet is not earning the click.', $imp, $ctr * 100, $pos ), array( 'position' => $pos, 'impressions' => $imp ), $row_metrics );
				$n++;
			}
			if ( $pos > 4 && $pos <= 20 && $imp > 50 ) {
				$this->record( 'post', $post_id, 'striking_distance', 'high', sprintf( 'Sitting at position %.1f — close enough that one strong revision can move it.', $pos ), array( 'position' => $pos ), $row_metrics );
				$n++;
			}

			// World-Class Audit: Keyword Cannibalization
			if ( class_exists( 'VMSB_Vector_Store' ) && $imp > 100 ) {
				$duplicates = VMSB_Vector_Store::search( $row['keys'][0], array( 'limit' => 2, 'threshold' => 0.92, 'exclude' => array( $post_id ) ) );
				if ( ! empty( $duplicates ) ) {
					$other = get_post( $duplicates[0]['object_id'] );
					if ( $other ) {
						$this->record( 'post', $post_id, 'keyword_cannibalization', 'high', sprintf( 'This page competes with "%s" for the keyword "%s". They should likely be merged.', $other->post_title, $row['keys'][0] ), array( 'duplicate_id' => $other->ID ), $row_metrics );
						$n++;
					}
				}
			}
		}
		return $n;
	}

	/* ---------------------------------------------------------------- fix */

	/**
	 * God Fix: work the open queue.
	 *
	 * @param int   $limit  How many issues to attempt.
	 * @param array $scope  Which fix families are allowed.
	 */
	public function god_fix( $limit = 25, array $scope = array() ) {
		// Daily Drip Cap Check for God Mode
		$today_key = 'vmsb_fixes_' . gmdate( 'Ymd' );
		$fixed_today = (int) get_option( $today_key, 0 );
		$daily_cap = (int) VMSB_Settings::get( 'max_god_fixes_day', 50 );

		if ( $fixed_today >= $daily_cap ) {
			return array( 'fixed' => 0, 'report' => array(), 'message' => "Daily God Mode fix cap of {$daily_cap} reached." );
		}

		$limit = min( $limit, $daily_cap - $fixed_today );

		$scope  = $scope ? $scope : (array) VMSB_Settings::get( 'god_mode_scope' );
		$issues = $this->open_issues( (int) $limit * 3 );
		$done   = 0;
		$report = array();
		$start_time = time();

		foreach ( $issues as $issue ) {
			if ( $done >= $limit ) {
				break;
			}
			// Safety: Stop if we're approaching the PHP timeout limit (30s default for many hosts)
			if ( time() - $start_time > 25 ) {
				$this->log->warn( 'fixer', "God Fix pass reached time limit. Resolved {$done} issues." );
				break;
			}
			if ( ! $this->rule_in_scope( $issue->rule, $scope ) ) {
				continue;
			}

			$result = $this->fix_issue( $issue );

			if ( is_wp_error( $result ) ) {
				$report[] = array( 'id' => $issue->id, 'rule' => $issue->rule, 'ok' => false, 'message' => $result->get_error_message() );
				$this->mark_issue( $issue->id, 'failed', null, $result->get_error_message() );
				continue;
			}
			if ( false === $result ) {
				continue; // Not automatable; leave it open for a human.
			}

			// require_review parks a drafted rewrite in postmeta instead of
			// touching the live page (VMSB_Content::improve_post()) - marking
			// this 'fixed' would claim success on a change that was never
			// actually applied, with no visible trace of the parked draft.
			if ( ! empty( $result['pending_review'] ) ) {
				$this->mark_issue( $issue->id, 'pending_review', null, 'Fix drafted - awaiting review.' );
				$report[] = array( 'id' => $issue->id, 'rule' => $issue->rule, 'ok' => true, 'object' => $issue->object_id, 'pending_review' => true );
				$done++;
				continue;
			}

			$this->mark_issue( $issue->id, 'fixed', $result );

			// File a hypothesis for post-level fixes so the ledger can tell us
			// in four weeks whether this class of fix actually helps this site.
			if ( 'post' === $issue->object_type && (int) $issue->object_id
				&& class_exists( 'VMSB_Outcome_Ledger' ) && (int) VMSB_Settings::get( 'learning_enabled' ) ) {
				VMSB_Outcome_Ledger::record( array(
					'module'     => 'fixer',
					'action'     => $issue->rule,
					'object_id'  => (int) $issue->object_id,
					'hypothesis' => 'Fixing ' . $issue->rule . ' should improve this page\'s search performance.',
				) );
			}

			$report[] = array( 'id' => $issue->id, 'rule' => $issue->rule, 'ok' => true, 'object' => $issue->object_id );
			$done++;
		}

		if ( $done > 0 ) {
			update_option( $today_key, $fixed_today + $done );
			delete_transient( 'vmsb_status_summary' ); // Invalidate status cache
		}

		$this->log->info( 'fixer', "God Fix pass: {$done} issues resolved.", array( 'scope' => $scope ) );
		return array( 'fixed' => $done, 'report' => $report );
	}

	private function rule_in_scope( $rule, array $scope ) {
		/*
		 * Every rule fix_issue() can actually repair must appear in exactly one
		 * family here, or God Mode can never reach it: this is a default-deny
		 * allowlist, so a rule missing from the map is silently unfixable and
		 * its issues pile up in the queue forever with no indication why.
		 *
		 * Four working repairs were stranded that way. The worst was
		 * 'accidental_noindex' - detected at CRITICAL severity, with a complete
		 * and safety-guarded handler (fix_accidental_noindex(), which refuses to
		 * touch the Home or Blog page) - for the single most damaging state a
		 * page can be in: telling Google not to index it at all. The fix existed
		 * and simply could not run.
		 */
		$families = array(
			'meta'           => array( 'missing_seo_title', 'title_too_long', 'title_too_short', 'missing_meta_description', 'description_too_long', 'missing_focus_keyword', 'low_ctr_snippet', 'missing_term_seo_title' ),
			'alt'            => array( 'images_missing_alt', 'missing_featured_image' ),
			'schema'         => array( 'missing_schema', 'aeo_gap' ),
			'internal_links' => array( 'orphan_from_pillar', 'orphan_page', 'not_marked_as_pillar', 'false_pillar', 'missing_pillar' ),
			'taxonomy'       => array( 'missing_term_description', 'empty_archive', 'thin_tag', 'zombie_tag', 'duplicate_term', 'missing_silo_category' ),
			'content'        => array( 'thin_content', 'stale_content', 'striking_distance', 'no_h2_structure', 'poor_readability', 'low_rankmath_score', 'content_decay', 'semantic_gap' ),
			'technical'      => array( 'robots_no_sitemap', 'weak_permalinks', 'search_engines_discouraged', 'accidental_noindex' ),
		);

		foreach ( $scope as $family ) {
			if ( isset( $families[ $family ] ) && in_array( $rule, $families[ $family ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * God-Fix 85-90+: Surgical Content & Meta Optimization.
	 *
	 * Specifically targets Rank Math's scoring criteria (EEAT, keyword density,
	 * structural subheadings, and engagement loops).
	 */
	public function god_fix_90( $post_id ) {
		// fix_issue() gates every other automated rewrite on this same toggle,
		// but the Issues page's "Target 90+" button calls this method directly
		// via its own REST route - without this check here too, turning off
		// "Auto Optimization" in Settings did nothing to stop that one button
		// from still doing a full 1,400+ word AI rewrite of a live page.
		if ( ! (int) VMSB_Settings::get( 'feature_maintenance', 1 ) ) {
			return new WP_Error( 'vmsb_maintenance', 'Automated Content Optimization is currently disabled in Settings.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'vmsb_fix', 'Post not found.' );

		// SAFETY CHECK: System Pages and Safe Post Types
		$front = (int) get_option( 'page_on_front' );
		$blog  = (int) get_option( 'page_for_posts' );
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front || $post_id === $blog ) {
			return new WP_Error( 'vmsb_fix', 'Safety: Cannot auto-rewrite system pages.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_fix', 'Safety: This post type is not enabled for deep optimization in Settings.' );
		}

		$keyword = $this->rankmath->get_focus_keyword( $post_id );
		if ( ! $keyword ) {
			$this->fix_focus_keyword( $post_id );
			$keyword = $this->rankmath->get_focus_keyword( $post_id );
		}

		$prompt = "Act as an Elite SEO Editor. Your mission is to rewrite this content to achieve a Rank Math SEO score of 85-90+.\n\n"
			// Same class of risk as the main content generator: a focus
			// keyword written in a non-English script (Hindi-script search
			// terms are common on this site) can pull the model's whole
			// rewrite into that language even though the existing CONTENT
			// below is English and there was never any intent to publish a
			// non-English article. Rewriting from real English content
			// makes this less likely than a from-scratch draft, but it is
			// not zero, so the same explicit guard applies here too.
			. "LANGUAGE: Write the rewrite in English throughout, even if the focus keyword below is in Hindi or another script. "
			. "Use the keyword itself, and other Hindi words or proper nouns, naturally within the English text where that reads authentically - "
			. "do not translate the article or write full paragraphs in Hindi.\n\n"
			. "TITLE: {$post->post_title}\n"
			. "FOCUS KEYWORD: {$keyword}\n"
			. "CONTENT:\n{$post->post_content}\n\n"
			. "RANK MATH SURGICAL REQUIREMENTS:\n"
			. "1. KEYWORD PLACEMENT: Use Focus Keyword in SEO Title, Meta Desc, and first 100 words.\n"
			. "2. DENSITY: Ensure keyword density is between 1.0% and 1.5% naturally.\n"
			. "3. STRUCTURE: Use multiple H2/H3 subheadings containing the focus keyword or synonyms.\n"
			. "4. EEAT: Include specific data points, technical expertise, and no AI fluff ('in today's world', etc.).\n"
			. "5. LENGTH: Content must be at least 1,400 words of deep value.\n"
			. "6. RICH MEDIA: Provide descriptive prompts for a featured image and 2 inline images.\n\n"
			// CONTENT above already shows real Gutenberg block comments
			// (<!-- wp:heading {"level":2} -->) and may contain links
			// (<a href="...">), which primes the model to reproduce both
			// patterns in its reply - so it needs telling explicitly that
			// content_html is itself a JSON string and every inner quote
			// from either source must be escaped for the outer JSON to parse.
			. "JSON ESCAPING: content_html is a JSON string. Escape every quote for the outer JSON, in both places it appears: "
			. "a block comment's own embedded {\"...\":...} JSON - <!-- wp:heading {\\\"level\\\":2} -->, never <!-- wp:heading {\"level\":2} --> - "
			. "and any HTML attribute value, especially links - <a href=\\\"/page/\\\">text</a>, never <a href=\"/page/\">text</a>.\n\n"
			. 'Return JSON: {"content_html":"","seo_title":"","meta_description":"","slug":"","seo_score":92}';

		$data = $this->ai->generate_json( $prompt, array(
			'system' => $this->brain->context_prompt(),
			'complexity' => 'premium',
			'persona' => 'wordsmith',
			'max_tokens' => 8000
		) );

		if ( empty( $data['content_html'] ) ) {
			return new WP_Error( 'vmsb_fix', $this->ai->get_last_error() ?: 'Intelligence chain returned empty content.' );
		}

		$before = array(
			'post_id'      => $post_id,
			'post_content' => $post->post_content,
			'rank_math'    => array(
				'rank_math_title'       => get_post_meta( $post_id, 'rank_math_title', true ),
				'rank_math_description' => get_post_meta( $post_id, 'rank_math_description', true ),
				'rank_math_seo_score'   => get_post_meta( $post_id, 'rank_math_seo_score', true ),
			),
		);

		// Update Post Content
		wp_update_post( array(
			'ID' => $post_id,
			'post_content' => VMSB_AI_Router::safe_html( $data['content_html'] ),
			'post_name' => ! empty($data['slug']) ? sanitize_title($data['slug']) : $post->post_name
		) );

		// Update Rank Math Meta
		$this->rankmath->apply( $post_id, array(
			'title'       => $data['seo_title'] ?? '',
			// seo_score removed: it defaulted to a hardcoded 88 in Rank Math's
			// own meta key, which made every repaired post look measured.
			'description' => $data['meta_description'] ?? ''
		) );

		return $before;
	}

	/**
	 * @return array|false|WP_Error  Revert payload on success, false if not automatable.
	 */
	public function fix_issue( $issue ) {
		$id  = (int) $issue->object_id;
		$sug = $issue->suggested ? json_decode( $issue->suggested, true ) : array();

		// Feature Gating: Maintenance Toggle
		if ( ! (int) VMSB_Settings::get( 'feature_maintenance', 1 ) ) {
			return new WP_Error( 'vmsb_maintenance', 'Automated Content Optimization is currently disabled in Settings.' );
		}

		switch ( $issue->rule ) {

			case 'missing_seo_title':
			case 'title_too_long':
			case 'title_too_short':
			case 'missing_meta_description':
			case 'description_too_long':
			case 'low_ctr_snippet':
				return $this->fix_meta( $id, $issue->rule );

			case 'missing_focus_keyword':
				return $this->fix_focus_keyword( $id );

			case 'accidental_noindex':
				return $this->fix_accidental_noindex( $id );

			case 'images_missing_alt':
				return $this->fix_inline_alts( $id );

			case 'missing_featured_image':
				return $this->fix_featured_image( $id );

			case 'missing_schema':
				$type = isset( $sug['type'] ) ? $sug['type'] : 'Article';
				if ( 'FAQPage' === $type ) {
					return ( new VMSB_Schema() )->generate_faq( $id );
				}
				return ( new VMSB_Schema() )->generate_graph( $id );

			case 'missing_term_description':
			case 'missing_term_seo_title':
				$tax = isset( $sug['taxonomy'] ) ? $sug['taxonomy'] : 'category';
				return ( new VMSB_Taxonomy() )->optimise_term( $id, $tax );

			case 'thin_tag':
			case 'zombie_tag':
			case 'empty_archive':
				return array( 'robots' => ( new VMSB_Taxonomy() )->noindex_term( $id ) );

			case 'duplicate_term':
				if ( empty( $sug['merge_into'] ) ) {
					return false;
				}
				$moved = ( new VMSB_Taxonomy() )->merge( $id, (int) $sug['merge_into'], isset( $sug['taxonomy'] ) ? $sug['taxonomy'] : 'post_tag' );
				return is_wp_error( $moved ) ? $moved : array( 'merged' => $moved );

			case 'orphan_from_pillar':
				if ( empty( $sug['pillar_id'] ) ) {
					return false;
				}
				$res = ( new VMSB_Silo() )->insert_internal_link( $id, (int) $sug['pillar_id'] );
				return is_wp_error( $res ) ? $res : $res;

			case 'not_marked_as_pillar':
				return $this->rankmath->apply( $id, array( 'pillar' => 'on' ) );

			case 'false_pillar':
				return $this->rankmath->apply( $id, array( 'pillar' => 'off' ) );

			case 'orphan_page':
				return $this->fix_orphan( $id );

			case 'semantic_gap':
				return $this->fix_semantic_gap( $id );

			case 'aeo_gap':
				return ( new VMSB_AEO() )->apply( $id );

			case 'roi_leak':
				// find_leaks() has always filed these into the same issues
				// table as everything else here, so they already show up in
				// the open-issues list with an Auto-Fix button - clicking it
				// just hit the switch's default case and silently did
				// nothing, since roi_leak was never one of the mapped rules.
				return ( new VMSB_ROI() )->insert_cta( $id );

			case 'low_rankmath_score':
				return $this->god_fix_90( $id );

			case 'robots_no_sitemap':
				return $this->fix_robots_sitemap();

			case 'search_engines_discouraged':
				// Reports the value actually written. It returned '0' - the
				// state being repaired, not the result - so the fix ledger
				// recorded every one of these as having switched the site
				// back to "discourage search engines", the exact opposite of
				// what happened.
				update_option( 'blog_public', '1' );
				return array( 'blog_public' => '1' );

			case 'poor_readability':
			case 'no_h2_structure':
			case 'thin_content':
			case 'stale_content':
			case 'content_decay':
			case 'striking_distance':
				// These change the article itself, so they route through the
				// content engine and respect the review setting.
				return ( new VMSB_Content() )->improve_post( $id, $issue->rule );

			case 'keyword_cannibalization':
				// Cannibalization usually requires a manual merge/redirect decision,
				// so we provide the "Suggested" path but don't auto-execute a redirect.
				return false;

			default:
				return false;
		}
	}

	/* ---------------------------------------------------------------- individual fixes */

	private function fix_meta( $post_id, $rule ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_fix', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages automatically
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_fix', 'Safety: Cannot rewrite Meta for the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_fix', 'Safety: This post type is not in the safe-rewrite list.' );
		}

		$keyword = $this->rankmath->get_focus_keyword( $post_id );
		$excerpt = mb_substr( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 0, 3000 );

		// World-Class Snippet Optimization
		$prompt = "Act as a High-Conversion SEO Copywriter. Rewrite the Meta Title and Description for this page to maximize CTR and relevance.\n\n"
			. "PAGE TITLE: {$post->post_title}\n"
			. "FOCUS KEYWORD: " . ( $keyword ? $keyword : 'not set' ) . "\n"
			. "CONTENT EXCERPT:\n{$excerpt}\n\n"
			. "STRATEGY:\n"
			. ( 'low_ctr_snippet' === $rule
				? "- This page ranks but has low CTR. Use a Curiosity Gap or a specific Benefit-Driven Headline.\n- Lead with the strongest USP or a surprising data point.\n"
				: "- Ensure the keyword is at the start of the title.\n- Create a 'Compelling Promise' in the description.\n" )
			. "- Meta Title: 50-60 characters. Must be 'Click-Worthy'.\n"
			. "- Meta Description: 140-155 characters. Include a clear Call to Action (CTA).\n"
			. "- Tone: Authoritative yet inviting.\n\n"
			. 'Return JSON: {"seo_title":"","meta_description":""}';

		$data = $this->ai->generate_json(
			$prompt,
			array(
				'system'      => $this->brain->context_prompt() . "\nYou write for humans first, algorithms second. No generic 'Learn more about...' or 'Discover the best...'",
				'max_tokens'  => 500,
				'temperature' => 0.7,
				'complexity'  => 'premium',
				'persona'     => 'wordsmith'
			)
		);

		if ( empty( $data['seo_title'] ) && empty( $data['meta_description'] ) ) {
			return new WP_Error( 'vmsb_fix', $this->ai->get_last_error() ?: 'The AI chain returned no usable meta.' );
		}

		$fields = array();
		if ( ! empty( $data['seo_title'] ) ) {
			$fields['title'] = sanitize_text_field( $data['seo_title'] );
		}
		if ( ! empty( $data['meta_description'] ) ) {
			$fields['description'] = sanitize_text_field( $data['meta_description'] );
		}

		return $this->rankmath->apply( $post_id, $fields );
	}

	private function fix_semantic_gap( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! class_exists( 'VMSB_Entity' ) ) {
			return false;
		}

		$entities = ( new VMSB_Entity() )->get_missing_entities( $post_id );
		if ( empty( $entities ) ) {
			return false;
		}

		$instruction = "This article has a 'Semantic Gap'. It misses critical entities that search engines expect for topical authority: " . implode( ', ', $entities ) . ". "
			. "Expand the article to naturally incorporate these entities. Do not just list them; integrate them into existing or new sections to provide more depth and value to the reader.";

		return ( new VMSB_Content() )->improve_post( $post_id, 'semantic_gap', $instruction );
	}

	/**
	 * A published post was flagged critical because it was accidentally set
	 * to noindex in Rank Math - strip just that flag, leave any other robots
	 * directive (nofollow, noarchive, etc.) untouched.
	 */
	private function fix_accidental_noindex( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_fix', 'Post not found.' );
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_fix', 'Safety: Cannot change robots meta for the Home or Blog page automatically.' );
		}

		$current = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots  = is_array( $current ) ? $current : array();
		$cleaned = array_values( array_diff( $robots, array( 'noindex' ) ) );

		return $this->rankmath->apply( $post_id, array( 'robots' => $cleaned ) );
	}

	private function fix_focus_keyword( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_keywords';

		// Prefer a keyword the page already ranks for.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT keyword FROM {$table} WHERE post_id = %d ORDER BY impressions DESC LIMIT 1", $post_id ) );
		$keyword = $row ? $row->keyword : '';

		if ( ! $keyword ) {
			$post = get_post( $post_id );
			$data = $this->ai->generate_json(
				"Title: {$post->post_title}\n\nBody excerpt:\n" . mb_substr( wp_strip_all_tags( $post->post_content ), 0, 1500 )
				. "\n\nGive the single primary search query this page should target. Return JSON: {\"keyword\":\"\"}",
				array( 'max_tokens' => 60, 'temperature' => 0.3, 'complexity' => 'standard', 'persona' => 'strategist' )
			);
			$keyword = isset( $data['keyword'] ) ? $data['keyword'] : '';
		}

		if ( ! $keyword ) {
			return new WP_Error( 'vmsb_fix', 'Could not determine a focus keyword.' );
		}

		return $this->rankmath->apply( $post_id, array( 'focus_keyword' => sanitize_text_field( $keyword ) ) );
	}

	private function fix_inline_alts( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_fix', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages or unsafe post types
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_fix', 'Safety: Cannot modify image ALTs on the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_fix', 'Safety: This post type is not in the safe-rewrite list.' );
		}

		$content = $post->post_content;

		// Find images that:
		// 1. Have no alt attribute
		// 2. Have an empty alt attribute (alt="" or alt='')
		if ( ! preg_match_all( '/<img(?![^>]*\balt=["\'][^"\']+["\'])([^>]*)>/i', $content, $matches ) ) {
			return false;
		}

		$images = array();
		foreach ( $matches[1] as $idx => $attrs ) {
			$name = '';
			if ( preg_match( '/src=["\']([^"\']+)["\']/i', $attrs, $src ) ) {
				$name = str_replace( array( '-', '_' ), ' ', pathinfo( $src[1], PATHINFO_FILENAME ) );
			}
			$images[] = array(
				'full'  => $matches[0][ $idx ],
				'attrs' => $attrs,
				'name'  => $name ? $name : 'image',
			);
		}

		// Batch generate ALTs to avoid synchronous AI loops and timeouts.
		$prompt = "Write concise alt attributes (under 125 characters) for these images in an article titled \"{$post->post_title}\". Describe what is likely visible. No 'image of'.\n\n";
		foreach ( $images as $i => $img ) {
			$prompt .= ( $i + 1 ) . ". Filename hint: \"{$img['name']}\"\n";
		}
		$prompt .= "\nReturn JSON: {\"alts\": []}";

		$data = $this->ai->generate_json( $prompt, array( 'max_tokens' => 800, 'temperature' => 0.4, 'persona' => 'wordsmith' ) );
		$alts = isset( $data['alts'] ) && is_array( $data['alts'] ) ? $data['alts'] : array();

		$updated = $content;
		foreach ( $images as $i => $img ) {
			$alt     = isset( $alts[ $i ] ) ? trim( wp_strip_all_tags( $alts[ $i ] ), " \"'\n" ) : ucfirst( $img['name'] );

			// If alt attribute exists but is empty, replace it. Otherwise, inject it.
			if ( preg_match( '/alt=["\']["\']/i', $img['full'] ) ) {
				$new_tag = preg_replace( '/alt=["\']["\']/i', 'alt="' . esc_attr( mb_substr( $alt, 0, 125 ) ) . '"', $img['full'] );
			} else {
				$new_tag = str_replace( '<img', '<img alt="' . esc_attr( mb_substr( $alt, 0, 125 ) ) . '"', $img['full'] );
			}

			// Use preg_quote to safely replace the exact tag once.
			$updated = preg_replace( '/' . preg_quote( $img['full'], '/' ) . '/', $new_tag, $updated, 1 );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $updated ) );
		return array( 'post_content' => $content );
	}

	private function fix_featured_image( $post_id ) {
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_fix', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages automatically
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_fix', 'Safety: Cannot set Featured Image for the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_fix', 'Safety: This post type is not in the safe-rewrite list.' );
		}

		$keyword = $this->rankmath->get_focus_keyword( $post_id );

		$subject = $this->ai->generate(
			"Describe, in one sentence, a photograph that would suit an article titled \"{$post->post_title}\". Concrete scene, no text in the image, no logos, no people's faces if avoidable. Sentence only.",
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 90, 'temperature' => 0.7 )
		);

		$result = $this->images->create(
			! empty( $subject['ok'] ) ? trim( $subject['text'] ) : $post->post_title,
			array(
				'keyword'       => $keyword ? $keyword : $post->post_title,
				'post_id'       => $post_id,
				'alt'           => $post->post_title,
				'filename_hint' => $keyword ? $keyword : $post->post_name,
			)
		);

		if ( empty( $result['ok'] ) ) {
			return new WP_Error( 'vmsb_fix', $result['error'] );
		}

		set_post_thumbnail( $post_id, $result['attachment_id'] );
		return array( 'thumbnail_removed' => true, 'attachment_id' => $result['attachment_id'] );
	}

	private function fix_orphan( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_fix', 'Post not found.' );
		}

		// Find the most topically related published post to link from.
		$terms = wp_get_post_categories( $post_id );
		$candidates = get_posts(
			array(
				'posts_per_page' => 15,
				'post__not_in'   => array( $post_id ),
				'category__in'   => $terms ? $terms : array(),
				'post_status'    => 'publish',
			)
		);
		if ( ! $candidates ) {
			$candidates = get_posts( array( 'posts_per_page' => 15, 'post__not_in' => array( $post_id ), 'post_status' => 'publish' ) );
		}
		if ( ! $candidates ) {
			return new WP_Error( 'vmsb_fix', 'No candidate page to link from.' );
		}

		$silo = new VMSB_Silo();
		foreach ( $candidates as $candidate ) {
			$res = $silo->insert_internal_link( $candidate->ID, $post_id );
			if ( ! is_wp_error( $res ) ) {
				return is_array( $res ) ? array_merge( $res, array( 'source_post' => $candidate->ID ) ) : array( 'source_post' => $candidate->ID );
			}
		}
		return new WP_Error( 'vmsb_fix', 'No natural anchor found in any candidate page.' );
	}

	private function fix_robots_sitemap() {
		add_filter(
			'robots_txt',
			static function ( $output ) {
				return $output . "\nSitemap: " . home_url( '/sitemap_index.xml' ) . "\n";
			}
		);
		update_option( 'vmsb_robots_sitemap', 1, false );
		return array( 'vmsb_robots_sitemap' => 0 );
	}

	/* ---------------------------------------------------------------- ledger writes */

	private function mark_issue( $issue_id, $status, $revert = null, $error = '' ) {
		global $wpdb;
		$wpdb->update(
			$this->table(),
			array(
				'status'         => $status,
				'fixed_by'       => 'god_fix',
				'revert_payload' => ( is_array( $revert ) && $revert ) ? wp_json_encode( $revert ) : null,
				'fixed_at'       => current_time( 'mysql', true ),
				'detail'         => $error ? $error : null,
			),
			array( 'id' => (int) $issue_id )
		);
	}

	/**
	 * Undo a fix. This is what makes autonomy safe to switch on.
	 */
	public function revert( $issue_id ) {
		global $wpdb;
		$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $issue_id ) );
		if ( ! $issue || 'fixed' !== $issue->status || ! $issue->revert_payload ) {
			return new WP_Error( 'vmsb_revert', 'Nothing stored to revert for this issue.' );
		}

		$payload = json_decode( $issue->revert_payload, true );
		$id      = (int) $issue->object_id;
		$type    = $issue->object_type;

		if ( 'post' === $type ) {
			if ( isset( $payload['post_content'] ) ) {
				// The edited post is not always the issue's own object (e.g. an
				// orphan_page fix links to the orphan from a different, candidate
				// post - that candidate is what actually changed and must be
				// restored, not the orphan itself).
				$target_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : $id;
				wp_update_post( array( 'ID' => $target_id, 'post_content' => $payload['post_content'] ) );
				if ( ! empty( $payload['rank_math'] ) && is_array( $payload['rank_math'] ) ) {
					$this->rankmath->revert( $target_id, $payload['rank_math'], 'post' );
				}
			} elseif ( isset( $payload['thumbnail_removed'] ) ) {
				delete_post_thumbnail( $id );
			} else {
				$this->rankmath->revert( $id, $payload, 'post' );
			}
		} elseif ( 'term' === $type ) {
			$taxonomy = $payload['taxonomy'] ?? 'category';
			if ( isset( $payload['description'] ) ) {
				wp_update_term( $id, $taxonomy, array( 'description' => $payload['description'] ) );
			}
			$this->rankmath->revert( $id, $payload, 'term' );
		}

		$wpdb->update( $this->table(), array( 'status' => 'reverted' ), array( 'id' => (int) $issue_id ) );
		$this->log->info( 'fixer', "Reverted issue #{$issue_id}." );
		return true;
	}

	public function get_rule_explanation( $rule ) {
		$explanations = array(
			'missing_seo_title'       => 'Your page title is missing from search results, hurting your rank.',
			'title_too_long'          => 'Title will be cut off in Google, reducing click-through rate.',
			'missing_meta_description' => 'Google is guessing your description. A custom one increases clicks.',
			'aeo_gap'                 => 'This post misses a direct-answer structure needed for AI search engines.',
			'orphan_page'             => 'No other pages link here. This page has zero authority and is hard to find.',
			'semantic_gap'            => 'You are missing keywords and topics that experts usually mention.',
			'low_rankmath_score'      => 'The technical SEO quality is far below our 85+ authority standard.',
			'images_missing_alt'      => 'Search engines cannot "see" your images without descriptive alt text.',
			'thin_content'            => 'This article is too short to be considered helpful by Google.',
			'zombie_content'          => 'This page has near-zero impressions and clicks despite being old. It is hurting your overall site quality.',

			// The scanners record 38 distinct rules; only the ten above had a
			// sentence. Every other one fell through to the generic fallback,
			// so the Issues list and the Content Healer table described a
			// decayed page, a broken link and an insecure site identically.

			// Indexing and crawl
			'accidental_noindex'      => 'This page tells Google not to index it. Unless that is deliberate, it cannot rank at all.',
			'search_engines_discouraged' => 'WordPress is set to discourage search engines site-wide. Nothing on this site can rank until that is turned off.',
			'robots_blocks_site'      => 'robots.txt is blocking crawlers from the site.',
			'robots_no_sitemap'       => 'robots.txt does not point to your sitemap, so crawlers have to discover pages the slow way.',
			'sitemap_unreachable'     => 'Your sitemap cannot be fetched, so new pages take far longer to be found.',
			'no_https'                => 'The site is served over HTTP. Browsers flag it as not secure and Google treats HTTPS as a ranking signal.',
			'rankmath_missing'        => 'Rank Math is not active, so titles, descriptions and schema are not being managed.',

			// Content decay and quality
			'content_decay'           => 'Traffic to this page has fallen materially over the last month.',
			'rapid_decay'             => 'This page lost more than half its traffic in the last week - something changed recently.',
			'stale_content'           => 'This page has not been updated in a long time and is losing ground to fresher results.',
			'semantic_stale'          => 'The topic has moved on since this was written; it no longer covers what searchers now expect.',
			'poor_readability'        => 'The writing is dense enough that readers are likely to bounce before finishing.',
			'missing_featured_image'  => 'No featured image, which weakens the listing everywhere this page is shown or shared.',

			// Click-through and SERP presentation
			'low_ctr_snippet'         => 'This page is seen often but rarely clicked. The title and description are not earning the click.',
			'low_ctr_anomaly'         => 'Click-through is far below what pages at this position normally get.',
			'striking_distance'       => 'This page sits just outside the top ten. Small improvements here move it onto page one.',
			'weak_permalinks'         => 'The URL does not describe the page, which costs both clicks and clarity for Google.',

			// Structure, links and silos
			'orphan_from_pillar'      => 'This supporting page is not linked from its pillar, so it receives none of that authority.',
			'missing_pillar'          => 'This topic cluster has no pillar page to anchor it.',
			'not_marked_as_pillar'    => 'This page acts as a pillar but is not marked as one, so the silo structure is not being recognised.',
			'false_pillar'            => 'This page is marked as a pillar but has too little supporting content to behave like one.',
			'missing_silo_category'   => 'This page is not filed under a silo, so it sits outside the site\'s topic structure.',
			'broken_links_found'      => 'This page contains links that no longer resolve.',
			'keyword_cannibalization' => 'Several pages target the same keyword, so they compete with each other instead of ranking.',
			'duel_gap'                => 'A competitor covers this ground better; a head-to-head comparison found specific gaps.',

			// Taxonomy
			'empty_archive'           => 'This archive has no posts, so it is a dead end for anyone who lands on it.',
			'thin_tag'                => 'This tag has too few posts to justify its own indexable page.',
			'zombie_tag'              => 'This tag gets no traffic and adds crawlable pages without adding value.',
			'duplicate_term'          => 'Two terms cover the same topic and split authority between them.',
			'missing_term_description' => 'This archive has no description, so Google has nothing to summarise it with.',
			'missing_term_seo_title'  => 'This archive has no SEO title and falls back to a bare term name.',

			// Recorded through the 'rule' => ... array form rather than
			// record(), which is why these were missed on the first pass.
			'missing_schema'          => 'No structured data on this page, so Google cannot show a rich result for it.',
			'h1_in_body'              => 'There is an H1 inside the body competing with the page title. A page should have exactly one.',
			'missing_focus_keyword'   => 'No focus keyword is set, so Rank Math cannot score this page and nothing is being optimised toward.',
			'description_too_long'    => 'The meta description will be truncated in results, cutting off the part that earns the click.',
			'competitor_gap'          => 'A competitor ranks for this topic and you have nothing covering it.',
			'dead_outbound_link'      => 'This page links out to a URL that no longer resolves, which frustrates readers and wastes authority.',
			'roi_leak'                => 'This page attracts traffic but gives it nowhere to go - no call to action to convert it.',
		);
		return $explanations[ $rule ] ?? 'Technical SEO mismatch found by the auditor.';
	}

	public function dismiss( $issue_id ) {
		global $wpdb;
		return $wpdb->update( $this->table(), array( 'status' => 'dismissed' ), array( 'id' => (int) $issue_id ) );
	}

	public function do_bulk( array $ids, $action ) {
		if ( ! $ids ) {
			return array( 'success' => false, 'error' => 'No issues selected.' );
		}

		global $wpdb;
		$ids   = array_map( 'intval', $ids );
		$count = 0;

		switch ( $action ) {
			case 'dismiss':
				$count = $wpdb->query( "UPDATE {$this->table()} SET status = 'dismissed' WHERE id IN (" . implode( ',', $ids ) . ") AND status = 'open'" );
				break;

			case 'fix':
				// We process fixes one by one to handle revert payloads properly
				foreach ( $ids as $id ) {
					$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d AND status = 'open'", $id ) );
					if ( ! $issue ) continue;

					$result = $this->fix_issue( $issue );
					if ( ! is_wp_error( $result ) && false !== $result ) {
						if ( ! empty( $result['pending_review'] ) ) {
							$this->mark_issue( $id, 'pending_review', null, 'Fix drafted - awaiting review.' );
						} else {
							$this->mark_issue( $id, 'fixed', $result );
						}
						$count++;
					}
				}
				break;
		}

		return array( 'success' => true, 'count' => (int) $count, 'action' => $action );
	}
}
