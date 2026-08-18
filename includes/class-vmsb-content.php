<?php
defined( 'ABSPATH' ) || exit;

/**
 * The content engine.
 *
 * Flow:  gap detection -> topic plan -> Google Sheet -> AI Puffer writes ->
 *        images attached -> internal links wired -> Rank Math meta -> publish.
 *
 * The Sheet is the shared workspace: your team can edit, approve, reorder or
 * kill a row there and the plugin honours it on the next sync.
 */
class VMSB_Content {

	private $ai;
	private $brain;
	private $google;
	private $images;
	private $rankmath;
	private $log;

	public function __construct() {
		$this->ai       = new VMSB_AI_Router();
		$this->brain    = new VMSB_Brain();
		$this->google   = new VMSB_Google();
		$this->images   = new VMSB_Image_Engine();
		$this->rankmath = new VMSB_RankMath();
		$this->log      = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_plan';
	}

	public static function sheet_header() {
		return array( 'UID', 'Status', 'Schedule', 'Topic', 'Keywords', 'Category', 'Pillar', 'Author', 'Post Type', 'Words', 'Links', 'Brief', 'Priority' );
	}

	/* ---------------------------------------------------------------- planning */

	public function plan( $count = 20 ) {
		$keywords = new VMSB_Keywords();
		$gaps     = $keywords->content_gaps( $count * 2 );

		if ( ! $gaps ) {
			$this->log->info( 'content', 'No content gaps left in the queue. Run keyword research first.' );
			return 0;
		}

		$rows = array();
		foreach ( $gaps as $gap ) {
			$rows[] = array(
				'keyword'     => $gap->keyword,
				'cluster'     => $gap->cluster,
				'intent'      => $gap->intent,
				'opportunity' => round( (float) $gap->opportunity, 1 ),
				'position'    => $gap->position,
				'impressions' => $gap->impressions,
			);
		}

		$silo   = ( new VMSB_Silo() )->map_for_display();
		$profile = $this->brain->profile();
		$cpt_context = ! empty($profile['cpts']) ? "\n\nSITE CONTENT TYPES (Routes):\n" . wp_json_encode($profile['cpts']) : "";

		$data = $this->ai->generate_json(
			"Build an editorial plan from these keyword gaps.\n" . wp_json_encode( $rows )
			. "\n\nExisting silo structure (link every new piece into it):\n" . wp_json_encode( $silo )
			. $cpt_context
			. "\n\nRules:\n"
			. "- One piece per genuine topic. Merge keywords that would cannibalise each other into a single stronger article.\n"
			. "- SMART ROUTING: Assign each piece to the correct 'content_type' (Standard 'post' or one of the SITE CONTENT TYPES slugs provided above). "
			. "For example, if a topic is a specific destination, route it to 'destinations'. If it is an event, route to 'events'. Default to 'post'.\n"
			. "- Titles are specific and written for a human, not a template. No 'Ultimate Guide' unless it truly is one.\n"
			. "- The brief tells the writer what to cover, what to avoid, and what the reader should be able to do afterwards.\n"
			. "- Every piece names two or three internal link targets from the existing site.\n\n"
			. 'Return JSON: {"plan":[{"title":"","primary_keyword":"","secondary_keywords":[],"cluster":"","intent":"","content_type":"post|slug","target_words":0,"brief":"","internal_links":[],"priority":0}]}',
			array(
				'system'      => $this->brain->context_prompt(),
				'max_tokens'  => 4000,
				'temperature' => 0.6,
				'action'      => 'content_planning'
			)
		);

		$items = isset( $data['plan'] ) ? $data['plan'] : array();
		if ( ! $items ) {
			$this->log->error( 'content', 'Planning returned no usable rows: ' . ( $this->ai->get_last_error() ?: 'Check your AI configuration.' ) );
			return 0;
		}

		global $wpdb;
		$per_day = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );
		$slot    = 0;
		$created = 0;

		foreach ( array_slice( $items, 0, $count ) as $i => $item ) {
			if ( empty( $item['title'] ) || empty( $item['primary_keyword'] ) ) {
				continue;
			}

			$uid = substr( md5( $item['primary_keyword'] ), 0, 24 );
			$day = (int) floor( $slot / $per_day );

			// 2026 Strategy: Cannibalization Guard (Early Detection)
			if ( class_exists('VMSB_Vector_Store') && (int) VMSB_Settings::get('vector_enabled') ) {
				$dupe = VMSB_Vector_Store::search( $item['title'] . ' ' . $item['primary_keyword'], array( 'limit' => 1, 'threshold' => 0.92 ) );
				if ( ! empty($dupe) ) {
					$this->log->info( 'content', "Skipping planning for '{$item['title']}' - semantically similar to existing post #{$dupe[0]['object_id']}." );
					$keywords->mark( $item['primary_keyword'], 'cannibalized', $dupe[0]['object_id'] );
					continue;
				}
			}

			$slot++;

			$now = current_time( 'mysql', true );
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, secondary_keywords, cluster, intent, content_type, brief, internal_links, target_words, priority, scheduled_for, status, created_at, updated_at)
					 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,%s,'planned',%s,%s)
					 ON DUPLICATE KEY UPDATE brief = VALUES(brief), priority = VALUES(priority), updated_at = VALUES(updated_at), status = IF(status = 'failed', 'planned', status)",
					$uid,
					$item['title'],
					$item['primary_keyword'],
					wp_json_encode( isset( $item['secondary_keywords'] ) ? $item['secondary_keywords'] : array() ),
					isset( $item['cluster'] ) ? $item['cluster'] : '',
					isset( $item['intent'] ) ? $item['intent'] : '',
					isset( $item['content_type'] ) ? $item['content_type'] : 'blog',
					isset( $item['brief'] ) ? $item['brief'] : '',
					wp_json_encode( isset( $item['internal_links'] ) ? $item['internal_links'] : array() ),
					isset( $item['target_words'] ) ? (int) $item['target_words'] : 1600,
					isset( $item['priority'] ) ? (float) $item['priority'] : 0,
					gmdate( 'Y-m-d H:i:s', strtotime( "+{$day} days" ) ),
					$now,
					$now
				)
			);

			$keywords->mark( $item['primary_keyword'], 'planned' );
			$created++;
		}

		$this->push_to_sheet();
		$this->log->info( 'content', "Planned {$created} pieces." );
		return $created;
	}

	/**
	 * Create a single plan item for a specific keyword.
	 */
	public function plan_by_keyword( $keyword ) {
		global $wpdb;
		$kw_table = $wpdb->prefix . 'vmsb_keywords';
		$gap = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$kw_table} WHERE keyword = %s", $keyword ) );

		if ( ! $gap ) {
			return 0;
		}

		$data = $this->ai->generate_json(
			"Create a single article plan for this keyword: \"{$keyword}\".\n"
			. "Cluster: {$gap->cluster}\n"
			. "Intent: {$gap->intent}\n\n"
			. "Return JSON: {\"title\":\"\",\"primary_keyword\":\"\",\"secondary_keywords\":[],\"intent\":\"\",\"content_type\":\"blog|comparison|guide|faq\",\"target_words\":1600,\"brief\":\"\",\"internal_links\":[]}",
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 1000, 'temperature' => 0.6 )
		);

		if ( empty($data['title']) ) {
			return 0;
		}

		$uid = substr( md5( $keyword ), 0, 24 );
		$now = current_time( 'mysql', true );

		$status = VMSB_Settings::get('posts_per_day') >= 10 ? 'approved' : 'planned';

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, secondary_keywords, cluster, intent, content_type, brief, internal_links, target_words, priority, scheduled_for, status, created_at, updated_at)
			 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,%s,%s,%s,%s)
			 ON DUPLICATE KEY UPDATE title = VALUES(title), brief = VALUES(brief), status = VALUES(status), updated_at = VALUES(updated_at)",
			$uid, $data['title'], $keyword, wp_json_encode( $data['secondary_keywords'] ?? array() ),
			$gap->cluster, $gap->intent, $data['content_type'] ?? 'blog', $data['brief'] ?? '',
			wp_json_encode( $data['internal_links'] ?? array() ), (int) ($data['target_words'] ?? 1600),
			1.0, $now, $status, $now, $now
		) );

		( new VMSB_Keywords() )->mark( $keyword, 'planned' );
		$this->push_to_sheet();

		return 1;
	}

	/* ---------------------------------------------------------------- bulk topic import */

	/**
	 * Turn a flat list of raw topic ideas (a pasted list, or rows pulled
	 * from a dedicated "Bulk Topics" sheet tab - see pull_bulk_topics())
	 * into proper pipeline rows. Unlike plan_by_keyword(), these don't
	 * need to already exist in the tracked keyword universe: each is
	 * expanded from scratch into a full plan row (primary/secondary
	 * keywords, cluster, intent, format, brief) via AI.
	 *
	 * @return array{imported:int,skipped:int,processed:int,submitted:int}
	 */
	public function import_topics( array $topics, $priority = 5.0, $language = '' ) {
		$language = sanitize_text_field( $language );
		global $wpdb;

		// 2026 Blitz Logic: Handle up to 30 topics per batch for high-velocity sites
		$topics = array_slice( array_values( array_unique( array_filter( array_map( 'trim', $topics ) ) ) ), 0, 30 );
		if ( ! $topics ) {
			return array( 'imported' => 0, 'skipped' => 0, 'processed' => 0, 'submitted' => 0 );
		}

		// Comprehensive Deduplication: Check both the Pipeline and the Keyword Universe.
		$existing_in_pipeline = (array) $wpdb->get_col( "SELECT LOWER(primary_keyword) FROM {$this->table()}" );
		$existing_in_keywords = (array) $wpdb->get_col( "SELECT LOWER(keyword) FROM {$wpdb->prefix}vmsb_keywords WHERE post_id > 0 OR status IN ('planned', 'writing')" );
		$existing_keywords = array_unique( array_merge( $existing_in_pipeline, $existing_in_keywords ) );

		$imported  = 0;
		$skipped   = 0;
		$processed = 0;
		$now       = current_time( 'mysql', true );
		$start     = time();

		foreach ( $topics as $topic ) {
			// Anti-timeout: One reasoning cycle per topic.
			// Stop if we exceed 40 seconds to prevent 504 Gateway Timeouts.
			if ( time() - $start > 40 ) {
				break;
			}
			$processed++;

			$data = $this->ai->generate_json(
				"Turn this raw topic idea into a full article plan: \"{$topic}\".\n\n"
				. "Define the specific primary keyword this should target, 2-4 realistic secondary keywords, "
				. "which existing content cluster it belongs to (or name a sensible new one), the search intent, "
				. "the best content format, and a brief telling the writer exactly what to cover, what to avoid, "
				. "and what the reader should be able to do afterwards.\n\n"
				. ( $language ? "LANGUAGE: the title, keyword, and brief must all be in {$language}, not English.\n\n" : '' )
				. 'Return JSON: {"title":"","primary_keyword":"","secondary_keywords":[],"cluster":"","intent":"informational|commercial|transactional|navigational","content_type":"blog|comparison|guide|faq","target_words":1600,"brief":""}'
				. "\nIMPORTANT: Return a SINGLE JSON object. Do NOT wrap in an array or add extra closing braces/brackets.",
				array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 700, 'temperature' => 0.5, 'persona' => 'strategist' )
			);

			if ( empty( $data['title'] ) || empty( $data['primary_keyword'] ) ) {
				$reason = $this->ai->get_last_error() ?: 'AI returned invalid plan schema.';
				$this->log->warn( 'content', "Skipping topic '{$topic}': {$reason}" );
				$skipped++;
				continue;
			}

			$suggested_kw = strtolower( trim( $data['primary_keyword'] ) );

			if ( in_array( $suggested_kw, $existing_keywords, true ) ) {
				$this->log->info( 'content', "Skipping topic '{$topic}': Keyword '{$suggested_kw}' already exists in pipeline or as a published post." );
				$skipped++;
				continue;
			}

			// World-Class Audit: Semantic Duplicate Check
			if ( class_exists('VMSB_Vector_Store') && VMSB_Settings::get('vector_enabled') ) {
				// Use the suggested title for semantic comparison - it's a better representation of intent than the raw topic string.
				$hits = VMSB_Vector_Store::search( $data['title'], array( 'limit' => 1, 'threshold' => 0.85 ) );
				if ( $hits ) {
					$skipped++;
					$this->log->info( 'content', "Skipping topic '{$topic}' - semantically similar (>{$hits[0]['score']}) to existing content: " . get_the_title($hits[0]['object_id']) );
					continue;
				}
			}

			$uid = substr( md5( $suggested_kw ), 0, 24 );
			$status = VMSB_Settings::get('posts_per_day') >= 10 ? 'approved' : 'planned';

			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, secondary_keywords, cluster, intent, content_language, content_type, brief, internal_links, target_words, priority, status, created_at, updated_at)
				 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,%s,%s,%s)
				 ON DUPLICATE KEY UPDATE title = VALUES(title), brief = VALUES(brief), updated_at = VALUES(updated_at)",
				$uid,
				sanitize_text_field( $data['title'] ),
				$suggested_kw,
				wp_json_encode( array_slice( (array) ( $data['secondary_keywords'] ?? array() ), 0, 6 ) ),
				sanitize_text_field( $data['cluster'] ?? '' ),
				sanitize_key( $data['intent'] ?? 'informational' ),
				$language ?: null,
				sanitize_key( $data['content_type'] ?? 'blog' ),
				wp_kses_post( $data['brief'] ?? '' ),
				wp_json_encode( array() ),
				(int) ( $data['target_words'] ?? 1600 ),
				(float) $priority,
				$status,
				$now,
				$now
			) );

			if ( $inserted ) {
				// Also mark/add to the keyword universe so other discovery tools don't pick it up
				$kw_engine = new VMSB_Keywords();
				$kw_engine->upsert( $suggested_kw, array(
					'intent'  => $data['intent'] ?? 'informational',
					'cluster' => $data['cluster'] ?? '',
					'source'  => 'import',
				) );
				$kw_engine->mark( $suggested_kw, 'planned' );

				$existing_keywords[] = $suggested_kw;
				$imported++;
			} else {
				$skipped++;
			}
		}

		if ( $imported > 0 ) {
			$this->push_to_sheet();
		}

		$this->log->info( 'content', "Bulk topic import: {$imported} planned, {$skipped} skipped of " . count( $topics ) . ' submitted.' );

		return array( 'imported' => $imported, 'skipped' => $skipped, 'processed' => $processed, 'submitted' => count( $topics ) );
	}

	/**
	 * Pull raw topic ideas from a dedicated "Bulk Topics" tab (column A:
	 * topic text, column B: status) in the SAME spreadsheet as the main
	 * pipeline sync tab, and turn each unprocessed row into a plan row.
	 *
	 * Deliberately a separate tab, not the main sync tab: a different
	 * automation (e.g. AI Puffer's own Sheets feature) can drop topic ideas
	 * there without ever touching the columns push_to_sheet()/
	 * pull_from_sheet() depend on - no shared schema, no collision.
	 */
	public function pull_bulk_topics() {
		if ( ! $this->google->is_connected() ) {
			return new WP_Error( 'vmsb_google', 'Google account is not connected.' );
		}
		$tab = VMSB_Settings::get( 'bulk_topics_tab', 'Bulk Topics' );
		if ( ! $tab ) {
			return new WP_Error( 'vmsb_google', 'No bulk topics tab configured.' );
		}

		// Auto-create the tab on first use instead of making the user set it
		// up by hand - mirrors what push_to_sheet() already does for the
		// main Content Plan tab, just with the 2-column Topic/Status header.
		$ensured = $this->google->ensure_sheet_tab( $tab, array( 'Topic', 'Status' ) );
		if ( is_wp_error( $ensured ) ) {
			return $ensured;
		}

		$rows = $this->google->sheet_read( $tab . '!A2:B500' );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! $rows ) {
			return array( 'imported' => 0, 'skipped' => 0, 'processed' => 0, 'submitted' => 0 );
		}

		$topics      = array();
		$row_numbers = array();
		foreach ( $rows as $i => $row ) {
			$topic  = isset( $row[0] ) ? trim( $row[0] ) : '';
			$status = isset( $row[1] ) ? trim( $row[1] ) : '';
			if ( ! $topic || $status ) {
				continue; // Blank rows and anything already marked are skipped.
			}
			$topics[]      = $topic;
			$row_numbers[] = $i + 2; // Sheet rows are 1-based and A2 is row 2.
		}

		if ( ! $topics ) {
			return array( 'imported' => 0, 'skipped' => 0, 'processed' => 0, 'submitted' => 0 );
		}

		$result = $this->import_topics( $topics );

		// Only mark the rows actually attempted this pass - anything left
		// over because of the time-guard above stays unmarked so the next
		// pull picks it up instead of it being silently lost.
		foreach ( array_slice( $row_numbers, 0, $result['processed'] ) as $row_num ) {
			$this->google->sheet_update( $tab . "!B{$row_num}", array( array( 'Imported' ) ) );
		}

		return $result;
	}

	public function push_to_sheet( $force = false ) {
		if ( ! $this->google->is_connected() ) {
			return new WP_Error( 'vmsb_google', 'Google account is not connected.' );
		}
		if ( ! VMSB_Settings::get( 'sheet_id' ) ) {
			return new WP_Error( 'vmsb_google', 'No Google Sheet ID configured in Settings.' );
		}

		$this->google->ensure_sheet_tab();

		global $wpdb;
		// If force, we look at everything not published. If not force, only items never pushed.
		$where = $force ? "status != 'published'" : "sheet_row IS NULL";
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY priority DESC" );

		if ( ! $rows ) {
			return 0;
		}

		$tab = VMSB_Settings::get( 'sheet_tab' );
		$existing_data = $this->google->sheet_read( $tab . '!A:A' );
		$existing_uids = array();
		if ( is_array( $existing_data ) ) {
			foreach ( $existing_data as $idx => $r ) {
				if ( ! empty( $r[0] ) ) {
					// Store UID => Row Index (1-based, index 0 is row 1)
					$existing_uids[ trim( $r[0] ) ] = $idx + 1;
				}
			}
		}

		$to_append = array();
		$pushed_count = 0;
		$author = wp_get_current_user()->user_login;

		foreach ( $rows as $row ) {
			$values = array(
				$row->row_uid,         // UID
				$row->status,          // Status
				$row->scheduled_for ? gmdate( 'Y-m-d H:i:s', strtotime( $row->scheduled_for ) ) : '', // Schedule
				$row->title,           // Topic
				$row->primary_keyword, // Keywords
				$row->cluster,         // Category
				$row->is_pillar ? 'Yes' : 'No', // Pillar
				$author,               // Author
				$row->content_type,    // Post Type
				$row->target_words,    // Words
				$row->internal_links,  // Links
				$row->brief,           // Brief
				$row->priority,        // Priority
			);

			if ( isset( $existing_uids[ $row->row_uid ] ) ) {
				if ( $force ) {
					// Update existing row
					$row_num = $existing_uids[ $row->row_uid ];
					$range = $tab . '!A' . $row_num . ':L' . $row_num;
					$this->google->sheet_update( $range, array( $values ) );
					$pushed_count++;
				}
				// Mark as pushed in DB if it wasn't
				if ( ! $row->sheet_row ) {
					$wpdb->update( $this->table(), array( 'sheet_row' => 1 ), array( 'id' => $row->id ) );
				}
				continue;
			}

			$to_append[] = $values;
			$wpdb->update( $this->table(), array( 'sheet_row' => 1 ), array( 'id' => $row->id ) );
			$pushed_count++;
		}

		if ( ! empty( $to_append ) ) {
			$res = $this->google->sheet_append( $to_append );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}

		$this->log->info( 'content', $pushed_count . ' rows synchronized with the plan sheet.' );
		return $pushed_count;
	}

	/**
	 * Read the Sheet back. Human edits win: status changes, retitles, kills.
	 */
	public function pull_from_sheet() {
		if ( ! $this->google->is_connected() ) {
			return 0;
		}
		$tab  = VMSB_Settings::get( 'sheet_tab' );
		$rows = $this->google->sheet_read( $tab . '!A2:M2000' );
		if ( is_wp_error( $rows ) || ! $rows ) {
			return 0;
		}

		global $wpdb;
		$n = 0;
		foreach ( $rows as $row ) {
			$uid = isset( $row[0] ) ? trim( $row[0] ) : '';
			if ( ! $uid ) {
				continue;
			}
			$updated = $wpdb->update(
				$this->table(),
				array(
					'status'          => isset( $row[1] ) ? sanitize_key( $row[1] ) : 'planned',
					'scheduled_for'   => isset( $row[2] ) && $row[2] ? gmdate( 'Y-m-d H:i:s', strtotime( $row[2] ) ) : null,
					'title'           => isset( $row[3] ) ? sanitize_text_field( $row[3] ) : '',
					'primary_keyword' => isset( $row[4] ) ? sanitize_text_field( $row[4] ) : '',
					'brief'           => isset( $row[10] ) ? wp_kses_post( $row[10] ) : '',
					'updated_at'      => current_time( 'mysql', true ),
				),
				array( 'row_uid' => $uid )
			);
			$n += (int) $updated;
		}
		return $n;
	}

	/* ---------------------------------------------------------------- writing */

	public function due_items( $limit = 5 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE status = 'approved' AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP()) ORDER BY priority DESC, scheduled_for ASC LIMIT %d",
				(int) $limit
			)
		);
	}

	/**
	 * Write and publish one planned piece.
	 */
	public function produce( $plan_id, $args = array() ) {
		if ( ! (int) VMSB_Settings::get( 'feature_production', 1 ) ) {
			return new WP_Error( 'vmsb_production', 'Automated Content Production is currently disabled in Settings.' );
		}

		// Global Drip Cap Check (Best of Autopilot).
		//
		// Only gate on this when the run can actually publish. With
		// auto_publish off or require_review on, $status below is always
		// 'draft', so nothing this call does will ever hit the site - yet
		// this check used to run regardless and the counter below used to
		// increment on drafts too. The result was that a review-first setup
		// produced posts_per_day drafts and then refused to generate
		// anything else for the rest of the day, reporting a "publish cap"
		// it had never actually published against.
		$can_publish = (int) VMSB_Settings::get( 'auto_publish' ) && ! (int) VMSB_Settings::get( 'require_review' );
		if ( $can_publish ) {
			$today_key       = 'vmsb_pub_' . gmdate( 'Ymd' );
			$published_today = (int) get_option( $today_key, 0 );
			$cap             = (int) VMSB_Settings::get( 'posts_per_day', 3 );
			if ( $published_today >= $cap ) {
				return new WP_Error( 'vmsb_drip', "Daily publish cap of {$cap} reached. Drip scheduling in effect." );
			}
		}

		global $wpdb;

		// Atomic Lock: Claim the row immediately to prevent concurrent duplicate production
		$locked = $wpdb->query( $wpdb->prepare(
			"UPDATE {$this->table()} SET status = 'writing', updated_at = %s WHERE id = %d AND status = 'approved'",
			current_time( 'mysql', true ), (int) $plan_id
		) );

		if ( ! $locked ) {
			return new WP_Error( 'vmsb_content', 'Task already claimed or not approved.' );
		}

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $plan_id ) );

		// Both columns are nullable and are NULL on every plan row that was
		// not created by the sheet importer, so this fired a deprecation on
		// each production run under PHP 8.1+ and will be a TypeError once
		// that deprecation is promoted.
		$secondary     = (array) json_decode( (string) $item->secondary_keywords, true );
		$links         = (array) json_decode( (string) $item->internal_links, true );
		$agent_context = isset( $args['agent_context'] ) ? $args['agent_context'] : '';

		$link_context = array();
		foreach ( $links as $hint ) {
			$found = get_posts( array( 's' => $hint, 'posts_per_page' => 1, 'post_status' => 'publish' ) );
			if ( $found ) {
				$link_context[] = array( 'title' => $found[0]->post_title, 'url' => get_permalink( $found[0] ) );
			}
		}

		$this->set_agent_task( $item->id, 'Researching topical authority gaps...' );

		// 2026 Strategy: Cannibalization & Semantic Guard
		if ( class_exists('VMSB_Vector_Store') && (int) VMSB_Settings::get('vector_enabled') ) {
			$dupe_check = VMSB_Vector_Store::search( $item->title . ' ' . $item->primary_keyword, array( 'limit' => 1, 'threshold' => 0.90 ) );
			if ( ! empty($dupe_check) ) {
				$other_id = $dupe_check[0]['object_id'];

				// Deep Intent Comparison: Only block if intent is ALSO identical
				$other_keyword = (new VMSB_RankMath())->get_focus_keyword($other_id);
				$other_intent = $wpdb->get_var($wpdb->prepare("SELECT intent FROM {$wpdb->prefix}vmsb_keywords WHERE keyword = %s", $other_keyword));

				if ( $other_intent === $item->intent || $dupe_check[0]['score'] > 0.96 ) {
					$reason = "Cannibalization Guard: Topic already covered in '" . get_the_title($other_id) . "' (#{$other_id}). Similarity: " . round($dupe_check[0]['score'] * 100, 1) . "%.";
					$wpdb->update( $this->table(), array( 'status' => 'rejected', 'last_error' => $reason ), array( 'id' => $item->id ) );
					$this->log->warn( 'content', $reason );
					return new WP_Error( 'vmsb_cannibal', $reason );
				} else {
					$agent_context .= "\nNOTE: A similar post exists ('" . get_the_title($other_id) . "'), but with a different intent ({$other_intent}). Ensure this new post focuses strictly on {$item->intent} intent to avoid cannibalization.";
				}
			}
		}

		// World-Class Optimization: Retrieval Augmented Guidance (RAG)
		$semantic_clues = array();
		if ( class_exists('VMSB_Vector_Store') && (int) VMSB_Settings::get('vector_enabled') ) {
			$hits = VMSB_Vector_Store::search( $item->title . ' ' . $item->primary_keyword, array( 'limit' => 3, 'threshold' => 0.7 ) );
			foreach ( $hits as $hit ) {
				$semantic_clues[] = get_the_title( $hit['object_id'] );
			}
		}

		$this->set_agent_task( $item->id, 'Drafting 90+ authority content...' );

		// 2026 Strategy: SERP Blueprinting
		$serp_agent = new VMSB_SERP();
		$blueprint  = $serp_agent->get_blueprint( $item->primary_keyword );
		$blueprint_context = "\nMARKET BLUEPRINT (Beat these benchmarks):\n"
			. "- Target Word Count: " . max($item->target_words, $blueprint['min_word_count'] ?? 1200) . "\n"
			. "- Essential Entities: " . implode(', ', (array)($blueprint['required_entities'] ?? [])) . "\n"
			. "- Tactical Gap to Exploit: " . ($blueprint['tactical_gap'] ?? 'Provide more technical depth') . "\n"
			. "- Trust Features to Include: " . implode(', ', (array)($blueprint['trust_features'] ?? []));

		// Travel Scenario CPT-Aware Prompting
		$cpt_requirements = "";
		if ( strpos( strtolower($item->title), 'destination' ) !== false || $item->content_type === 'destinations' ) {
			$cpt_requirements = "\nDESTINATION REQUIREMENTS:\n"
				. "- Detailed 'How to Reach' section (Air, Rail, Road).\n"
				. "- 'Best Time to Visit' with seasonal details.\n"
				. "- 'Top 5 Things to Do' list.\n"
				. "- Local travel tips (Clothing, Currency, Custom).";
		} elseif ( strpos( strtolower($item->title), 'event' ) !== false || $item->content_type === 'events' ) {
			$cpt_requirements = "\nEVENT REQUIREMENTS:\n"
				. "- Clear 'Dates & Timing' section.\n"
				. "- Detailed 'Venue & Location' info.\n"
				. "- 'What to Expect' (Key highlights).\n"
				. "- 'Booking/Registration' guidance.";
		}

		$prompt = "Write the article.\n\n"
			// A plan row's own content_language overrides the site-wide
			// "Write in: X" line already in the system prompt (context_prompt())
			// - this is what makes it possible to plan individual pieces in a
			// different language from the rest of the site, rather than every
			// piece being locked to one global setting.
			. ( $item->content_language ? "LANGUAGE: Write this article in {$item->content_language}, overriding any other language instruction.\n\n" : '' )
			. "TITLE: {$item->title}\n"
			. "PRIMARY KEYWORD: {$item->primary_keyword}\n"
			. 'SECONDARY KEYWORDS: ' . implode( ', ', $secondary ) . "\n"
			. "SEARCH INTENT: {$item->intent}\n"
			. "TARGET LENGTH: about {$item->target_words} words\n"
			. "BRIEF: {$item->brief}\n"
			. $cpt_requirements
			. ( $item->editor_note ? "CRITICAL EDITOR NOTE: {$item->editor_note}\n" : "" )
			. ( $semantic_clues ? "SEMANTIC CONTEXT (Build upon these existing site themes): " . implode( ', ', $semantic_clues ) . "\n" : "" )
			. ( $blueprint_context ? "SERP COMPETITIVE CONTEXT: {$blueprint_context}\n" : "" )
			. ( $agent_context ? "RESEARCH & ARCHITECTURE GUIDANCE: {$agent_context}\n" : "" )
			. "INTERNAL LINKS TO INCLUDE (use natural anchors): " . wp_json_encode( $link_context ) . "\n\n"
			. "ELITE CONTENT STANDARDS (Information Gain):\n"
			. "- Provide data points or perspectives missing from standard Google search results.\n"
			. "- Focus on 'How-to' depth and actionable expert advice.\n"
			. "- Avoid generic introductions. Hook the reader immediately.\n\n"
			. "RANK MATH 90+ SCORE REQUIREMENTS:\n"
			. "- Focus Keyword must be in the FIRST paragraph (first 50 words).\n"
			. "- Focus Keyword must be in at least one H2 and one H3 subheading.\n"
			. "- Use the focus keyword naturally throughout the content (density ~1.2%).\n"
			. "- Use short, punchy paragraphs (2-3 sentences max).\n"
			. "- Include a 'Key Takeaways' summary block after the intro.\n"
			. "- Include a highly descriptive SEO Title (under 60 chars) with a power word or number.\n"
			. "- Include a meta description (140-155 chars) with a clear CTA.\n"
			. "- Add an FAQ section with 4 specific, helpful questions.\n\n"
			. "TONE: Expert, authoritative, yet accessible. Avoid corporate jargon and 'AI-isms'.\n\n"
			. "FORMAT: content_html must use real Gutenberg block markup, not bare HTML - e.g. "
			. "<!-- wp:paragraph --><p>...</p><!-- /wp:paragraph -->, <!-- wp:heading {\"level\":2} --><h2>...</h2><!-- /wp:heading -->, "
			. "<!-- wp:list --><ul><li>...</li></ul><!-- /wp:list -->, so the published post is fully editable block-by-block, not one opaque HTML blob.\n\n"
			. "IMAGES: featured_image_prompt is one specific visual concept for the hero image (not a restatement of the title). "
			. "inline_image_prompts is 2 more specific, concrete visual concepts, each tied to a different H2 section and visually distinct from the featured image and from each other - not generic filler like 'a photo related to the topic'.\n\n"
			. "JSON ESCAPING - READ CAREFULLY: content_html is itself a JSON string value, and it contains Gutenberg block comments that have their OWN embedded JSON, "
			. "e.g. {\"level\":2}. Every double-quote inside those block attributes MUST be backslash-escaped so the OUTER JSON stays valid - "
			. "write <!-- wp:heading {\\\"level\\\":2} --> , never <!-- wp:heading {\"level\":2} -->. "
			. "This applies to every single block with attributes in the article (headings, images, groups, lists with attributes, etc.) - missing even one escape anywhere in the piece invalidates the entire response and the whole article is discarded.\n\n"
			. 'Return JSON: {"post_title":"","slug":"","content_html":"","excerpt":"","seo_title":"","meta_description":"","featured_image_prompt":"","inline_image_prompts":[],"faq":[{"q":"","a":""}],"suggested_category":"","suggested_tags":"","seo_score":92}'
			. "\nIMPORTANT: Return a SINGLE JSON object. Do NOT wrap in an array or add extra closing braces/brackets.";

		$data = $this->ai->generate_json(
			$prompt,
			array(
				'system'      => $this->brain->context_prompt(),
				'max_tokens'  => 8000,
				'temperature' => 0.7,
				'kb'          => true,
				// The single most important content call in the plugin was
				// missing both of these, silently defaulting to persona
				// 'strategist' and complexity 'standard' - every downstream
				// rewrite path (god_fix_90, improve_post) correctly sets
				// both. Without 'wordsmith' specifically, the quality-gate
				// self-critique/revision loop and the "recent mistakes"
				// memory in VMSB_AI_Router::generate() never fire at all
				// (both are gated on persona === 'wordsmith'), and without
				// 'premium' this skipped the model-tier boost every other
				// significant rewrite gets.
				'persona'     => 'wordsmith',
				'complexity'  => 'premium',
				'action'      => 'publish_post',
			)
		);

		if ( empty( $data['content_html'] ) ) {
			$reason = $this->ai->get_last_error() ?: 'No content returned by the AI chain.';
			$wpdb->update( $this->table(), array( 'status' => 'failed', 'last_error' => $reason ), array( 'id' => $item->id ) );
			if ( class_exists( 'VMSB_Webhooks' ) ) {
				VMSB_Webhooks::dispatch( 'content_failed', array( 'plan_id' => $item->id, 'title' => $item->title, 'reason' => $reason ) );
			}
			return new WP_Error( 'vmsb_content', $reason );
		}

		$review = (int) VMSB_Settings::get( 'require_review' );
		$auto   = (int) VMSB_Settings::get( 'auto_publish' );

		$this->set_agent_task( $item->id, 'Applying Senior Editor critique...' );

		// ELITE: Multi-Model Fact/Hallucination Check
		if ( VMSB_License::at_least('elite') ) {
			$this->set_agent_task( $item->id, 'Independent Fact-Verification in progress...' );
			$verify_prompt = "Act as an Independent Fact-Checker. Review this drafted article for hallucinations or internal contradictions.\n"
				. "ARTICLE: \"{$data['post_title']}\"\n"
				. "CONTENT SNIPPET: " . wp_trim_words($data['content_html'], 500) . "\n\n"
				. "TASK: Does this article invent facts not supported by common knowledge or the business profile?\n"
				. "Return ONLY 'PASS' or 'FAIL: [Reason]'.";

			$verification = $this->ai->generate( $verify_prompt, array( 'complexity' => 'standard', 'persona' => 'auditor', 'provider' => 'gemini' ) ); // Use different provider
			if ( ! empty($verification['ok']) && 0 === stripos(trim($verification['text']), 'FAIL') ) {
				$reason = "Independent Verification Failed: " . trim($verification['text']);
				$wpdb->update( $this->table(), array( 'status' => 'failed', 'last_error' => $reason ), array( 'id' => $item->id ) );
				return new WP_Error( 'vmsb_verification', $reason );
			}
		}

		// THE GATE. Nothing the brain writes reaches a live URL unchecked.
		$report = class_exists( 'VMSB_Quality_Gate' )
			? VMSB_Quality_Gate::evaluate( $data['content_html'], array(
				'title'   => $data['post_title'] ?? $item->title,
				'keyword' => $item->primary_keyword,
			) )
			: array( 'verdict' => 'pass', 'score' => 100 );

		$html = VMSB_AI_Router::safe_html( $data['content_html'] );

		if ( 'reject' === $report['verdict'] ) {
			$wpdb->update( $this->table(), array( 'status' => 'rejected', 'last_error' => $report['summary'] ), array( 'id' => $item->id ) );

			// AI Training: Record this mistake so it's not repeated
			$brain = new VMSB_Brain();
			$mistakes = (array) $brain->recall( 'ai_training', 'recent_mistakes', array() );
			$mistakes[] = $report['summary'];
			$brain->remember( 'ai_training', 'recent_mistakes', array_slice( array_unique($mistakes), -10 ) );

			( new VMSB_Logger() )->warn( 'gate', 'Draft rejected before publish.', array( 'plan' => $item->id, 'why' => $report['summary'] ) );
			if ( class_exists( 'VMSB_Webhooks' ) ) {
				VMSB_Webhooks::dispatch( 'content_failed', array( 'plan_id' => $item->id, 'title' => $item->title, 'reason' => $report['summary'] ) );
			}
			return new WP_Error( 'vmsb_gate', $report['summary'] );
		}

		// A held draft never auto-publishes, whatever the settings say.
		$held   = ( 'review' === $report['verdict'] );
		$status = ( $auto && ! $review && ! $held ) ? 'publish' : 'draft';

		// Scenario: High-Quality CPT Support (Destinations, Events, etc.)
		//
		// An explicitly recorded content_type always wins. The guesses below
		// used to run unconditionally and overwrite it, so a plain blog post
		// merely titled "How to pick a destination" was filed as a
		// 'destinations' entry, and any plan whose cluster label happened to
		// collide with a registered post type slug was rerouted out of the
		// post type it was deliberately planned as.
		$post_type = ( 'blog' === $item->content_type || empty( $item->content_type ) ) ? '' : $item->content_type;

		if ( $post_type && ! post_type_exists( $post_type ) ) {
			// Recorded type has since been unregistered - fall back rather
			// than create an orphan nothing can manage.
			$this->log->warn( 'content', "Plan #{$item->id} wanted post type '{$post_type}', which no longer exists. Falling back." );
			$post_type = '';
		}

		if ( ! $post_type ) {
			// Nothing explicit - infer, but only from a standalone word. The
			// trailing lookahead keeps compounds like "destination-style" or
			// "event-driven" out, since those describe an article about the
			// subject rather than an entry of that type.
			if ( post_type_exists( 'destinations' ) && preg_match( '/\bdestinations?\b(?!-)/i', (string) $item->title ) ) {
				$post_type = 'destinations';
			} elseif ( post_type_exists( 'events' ) && preg_match( '/\bevents?\b(?!-)/i', (string) $item->title ) ) {
				$post_type = 'events';
			} elseif ( ! empty( $item->cluster ) && post_type_exists( strtolower( $item->cluster ) ) ) {
				$post_type = strtolower( $item->cluster );
			} else {
				$post_type = 'post';
			}
		}

		// Take the publish slot before the post exists, not after. The check at
		// the top of this method ran before a ~40s model call, so it could only
		// ever report what was true before the work started; this is the last
		// moment where refusing still means nothing went live.
		$cap_note = '';
		if ( 'publish' === $status ) {
			$cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );
			if ( ! $this->claim_publish_slot( $cap ) ) {
				$status   = 'draft';
				$cap_note = "Daily publish cap of {$cap} was reached while this was being written, so it was saved as a draft instead.";
				( new VMSB_Logger() )->info( 'content', $cap_note, array( 'plan' => $item->id ) );
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( isset( $data['post_title'] ) ? $data['post_title'] : $item->title ),
				'post_name'    => isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : sanitize_title( $item->title ),
				'post_content' => $html,
				'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_text_field( $data['excerpt'] ) : '',
				'post_status'  => $status,
				'post_type'    => $post_type,
				'post_author'  => $this->author_id(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$wpdb->update( $this->table(), array( 'status' => 'failed', 'last_error' => $post_id->get_error_message() ), array( 'id' => $item->id ) );
			return $post_id;
		}

		if ( $item->content_language ) {
			update_post_meta( $post_id, '_vmsb_content_language', $item->content_language );
		}

		if ( $held && class_exists( 'VMSB_Webhooks' ) ) {
			VMSB_Webhooks::dispatch( 'content_review', array(
				'post_id' => $post_id,
				'title'   => $post_id ? get_the_title( $post_id ) : $item->title,
				'reason'  => $report['summary'] ?? '',
			) );
		}

		// Record the gate report and, for a new autonomous page, file an outcome
		// hypothesis + seed the vector index so later duplicate checks see it.
		if ( class_exists( 'VMSB_Quality_Gate' ) ) {
			VMSB_Quality_Gate::attach_report( $post_id, $report );
		}
		if ( class_exists( 'VMSB_Outcome_Ledger' ) && (int) VMSB_Settings::get( 'learning_enabled' ) ) {
			VMSB_Outcome_Ledger::record( array(
				'module'     => 'content',
				'action'     => 'publish_post',
				'object_id'  => $post_id,
				'hypothesis' => 'New page targeting "' . $item->primary_keyword . '" should earn search clicks.',
			) );
		}
		if ( class_exists( 'VMSB_Vector_Store' ) && (int) VMSB_Settings::get( 'vector_enabled' ) ) {
			$fresh = get_post( $post_id );
			if ( $fresh ) {
				VMSB_Vector_Store::upsert( VMSB_Vector_Store::TYPE_POST, $post_id, VMSB_Vector_Store::post_text( $fresh ), array( 'label' => $fresh->post_title ) );
			}
		}

		// Taxonomy.
		if ( ! empty( $data['suggested_category'] ) ) {
			$term = term_exists( $data['suggested_category'], 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $data['suggested_category'], 'category' );
			}
			if ( ! is_wp_error( $term ) ) {
				wp_set_post_categories( $post_id, array( (int) ( is_array( $term ) ? $term['term_id'] : $term ) ) );
			}
		}
		if ( ! empty( $data['suggested_tags'] ) ) {
			wp_set_post_tags( $post_id, array_slice( (array) $data['suggested_tags'], 0, 6 ) );
		}

		// Rank Math meta.
		$this->rankmath->apply(
			$post_id,
			array(
				'title'         => isset( $data['seo_title'] ) ? sanitize_text_field( $data['seo_title'] ) : '',
				'description'   => isset( $data['meta_description'] ) ? sanitize_text_field( $data['meta_description'] ) : '',
				'focus_keyword' => $item->primary_keyword,
				'pillar'        => $item->is_pillar ? 'on' : 'off',
				// No seo_score. It was the model's own claim about its output,
				// or a hardcoded 82, written straight into Rank Math's meta.
			)
		);

		// FAQ schema.
		if ( ! empty( $data['faq'] ) ) {
			$this->attach_faq_schema( $post_id, $data['faq'] );
		}

		// Featured image.
		$this->set_agent_task( $item->id, 'Generating hyper-realistic visuals...' );

		$img = $this->images->create(
			! empty( $data['featured_image_prompt'] ) ? $data['featured_image_prompt'] : $item->title,
			array(
				'keyword'       => $item->primary_keyword,
				'post_id'       => $post_id,
				'alt'           => isset( $data['post_title'] ) ? $data['post_title'] : $item->title,
				'filename_hint' => $item->primary_keyword,
			)
		);
		if ( ! empty( $img['ok'] ) ) {
			set_post_thumbnail( $post_id, $img['attachment_id'] );
		}

		// Inline images: the prompt asks the model for these every single
		// time (see the JSON schema above) but nothing ever consumed them -
		// every autonomously produced article shipped with exactly one
		// image (the featured image) regardless of length or how many
		// inline visuals the model had already planned for. Best-effort and
		// capped: a failed inline image never blocks or breaks the article.
		if ( ! empty( $data['inline_image_prompts'] ) && is_array( $data['inline_image_prompts'] ) ) {
			$with_images = $this->insert_inline_images( $html, array_slice( $data['inline_image_prompts'], 0, 2 ), $item, $post_id );
			if ( $with_images !== $html ) {
				$html = $with_images;
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $html ) );
			}
		}

		// SGE Mastery: Generate AI-friendly features (Ported from Autopilot)
		if ( class_exists('VMSB_AEO') ) {
			$aeo = new VMSB_AEO();
			$sge = $aeo->generate_sge_features( $post_id );
			if ( ! empty($sge['summary_html']) || ! empty($sge['table_html']) ) {
				$sge_block = "\n\n<!-- wp:group {\"className\":\"vmsb-sge-mastery\"} -->\n"
					. "<div class=\"wp-block-group vmsb-sge-mastery\">"
					. ( ! empty($sge['summary_html']) ? $sge['summary_html'] : '' )
					. ( ! empty($sge['table_html']) ? $sge['table_html'] : '' )
					. "</div>\n<!-- /wp:group -->\n\n";

				// Inject after the Key Takeaways or first paragraph
				$html = preg_replace( '/(<p[^>]*>.*?<\/p>)/is', '$1' . $sge_block, $html, 1 );
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $html ) );
			}
		}

		// Wire it into the silo. Only the links the plan explicitly asked for
		// happen here - those targets are already decided, so placing them is
		// one write with no model call in between.
		$silo = new VMSB_Silo();
		foreach ( $link_context as $target ) {
			$target_id = url_to_postid( $target['url'] );
			if ( $target_id ) {
				$silo->insert_internal_link( $target_id, $post_id, '', array( 'reason' => 'Planned internal link on publish' ) );
			}
		}

		// Index the new post's own links immediately, so the graph is correct
		// before anything queued below reasons about it.
		if ( class_exists( 'VMSB_Link_Index' ) ) {
			VMSB_Link_Index::scan_post( $post_id );
		}

		// Apply Persona (E-E-A-T)
		VMSB_Persona::apply_to_post( $post_id );

		// The semantic mesh and the social pack are each a model round trip
		// plus a content write, at the end of a function that has already run
		// a draft, a critique pass, a fact check and image generation - a
		// hydrator run was measured at 37 seconds before they were added here.
		// They are not needed for the post to exist, so they go to the queue.
		if ( VMSB_License::at_least( 'elite' ) ) {
			VMSB_Task_Runner::queue(
				'link_post',
				array( 'post_id' => $post_id, 'links' => 2 ),
				75,
				'Semantic linking for newly published post #' . $post_id
			);
		}

		if ( VMSB_License::at_least( 'pro' ) && class_exists( 'VMSB_Social_Recycler' ) ) {
			VMSB_Task_Runner::queue(
				'social_pack',
				array( 'post_id' => $post_id ),
				30,
				'Social distribution pack for post #' . $post_id
			);
		}

		$wpdb->update(
			$this->table(),
			// $cap_note is set only when the daily cap sent a finished article
			// to draft. Recording it here is what makes that visible on the
			// pipeline instead of the row just quietly reading "Drafted".
			array( 'status' => 'publish' === $status ? 'published' : 'drafted', 'post_id' => $post_id, 'last_error' => ( '' !== $cap_note ? $cap_note : null ), 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $item->id )
		);

		// Synchronize keyword status
		( new VMSB_Keywords() )->mark( $item->primary_keyword, 'published', $post_id );

		// Record what Rank Math's on-page tests actually say about this post,
		// under our own key. Replaces the invented rank_math_seo_score that
		// used to be written here, and gives the Issues screen something real
		// to act on.
		if ( class_exists( 'VMSB_RankMath_Score' ) ) {
			$rm_audit = VMSB_RankMath_Score::analyze( $post_id, $item->primary_keyword );
			if ( ! is_wp_error( $rm_audit ) ) {
				update_post_meta( $post_id, '_vmsb_rankmath_audit', array(
					'pass_pct'   => $rm_audit['pass_pct'],
					'passed'     => $rm_audit['passed'],
					'total'      => $rm_audit['total'],
					'failures'   => $rm_audit['failures'],
					'checked_at' => current_time( 'mysql', true ),
				) );
				if ( $rm_audit['failures'] ) {
					$this->log->info( 'content', sprintf(
						'Post #%d passes %d/%d Rank Math tests. Failing: %s',
						$post_id, $rm_audit['passed'], $rm_audit['total'], implode( ', ', $rm_audit['failures'] )
					) );
				}
			}
		}

		( new VMSB_Keywords() )->mark( $item->primary_keyword, 'published', $post_id );

		if ( 'publish' === $status ) {
			// The drip counter was already incremented by claim_publish_slot()
			// above, before the post was created - counting it here as well
			// would double-count every published post against the cap.
			$this->ping_sitemap();
			do_action( 'vmsb_post_produced', $post_id );
		}

		$this->log->info( 'content', sprintf( 'Produced "%s" (#%d, %s).', get_the_title( $post_id ), $post_id, $status ) );
		return $post_id;
	}

	/**
	 * Rewrite an existing post to fix a specific weakness.
	 */
	public function improve_post( $post_id, $reason, $custom_instruction = '' ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_content', 'Post not found.' );
		}

		// SAFETY CHECK: Never rewrite system pages or unsafe post types
		$front_page_id = (int) get_option( 'page_on_front' );
		$blog_page_id  = (int) get_option( 'page_for_posts' );
		$safe_types    = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );

		if ( $post_id === $front_page_id || $post_id === $blog_page_id ) {
			return new WP_Error( 'vmsb_content', 'Safety: Cannot rewrite the Home or Blog page automatically.' );
		}

		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_content', 'Safety: This post type is not in the safe-rewrite list.' );
		}

		// Same guard the other post_content rewrite paths (entity injection,
		// persona bios) already respect - a full-HTML rewrite would corrupt
		// an Elementor-built page's layout even when it's only parked for
		// review, since the operator approving it later has no way to know.
		if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
			return new WP_Error( 'vmsb_content', 'This page is built in Elementor - full post_content rewrite is skipped to avoid corrupting the layout.' );
		}

		$instructions = array(
			'thin_content'       => 'Expand this into a genuinely complete answer. Add the sections a reader still needs. Do not pad. Ensure E-E-A-T principles are followed.',
			'stale_content'      => 'Refresh this. Update anything time-bound, remove what no longer applies, and add what has changed since it was written.',
			'no_h2_structure'    => 'Restructure this into clear H2 sections without changing the substance or the facts.',
			'poor_readability'   => 'Improve readability by adding H2/H3 subheadings, breaking long paragraphs, and using bullet points where appropriate.',
			'striking_distance'  => 'This ranks on page two. Find what the top results cover that this does not, and close the gap. Tighten the opening so it answers the query immediately.',
			'semantic_gap'       => 'Topical authority check: This article is missing key entities that experts usually mention. Integrate them naturally to improve semantic density.',
		);

		$instruction = $custom_instruction ? $custom_instruction : ( isset( $instructions[ $reason ] ) ? $instructions[ $reason ] : 'Improve this page for search and for the reader.' );
		$keyword     = $this->rankmath->get_focus_keyword( $post_id );

		$data = $this->ai->generate_json(
			"TITLE: {$post->post_title}\nFOCUS KEYWORD: {$keyword}\n\nCURRENT CONTENT:\n{$post->post_content}\n\nTASK: {$instruction}\n\n"
			. "Keep every accurate fact and every existing internal link. Return the complete revised article, not a diff.\n\n"
			// CURRENT CONTENT above already contains real Gutenberg block
			// comments (<!-- wp:heading {"level":2} --> and similar) - the
			// model is being shown that exact pattern right before being
			// asked to return content_html as a JSON string, so it is
			// primed to reproduce it. Every quote inside those block
			// attributes must be backslash-escaped in the reply or the
			// whole JSON response is invalid.
			. "JSON ESCAPING: content_html is a JSON string, and Gutenberg block comments have their own embedded {\"...\":...} JSON. "
			. "Escape every quote inside those block attributes with a backslash for the outer JSON - <!-- wp:heading {\\\"level\\\":2} -->, never <!-- wp:heading {\"level\":2} -->.\n\n"
			. 'Return JSON: {"content_html":"","change_summary":""}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 8000, 'temperature' => 0.6, 'complexity' => 'premium', 'persona' => 'wordsmith' )
		);

		if ( empty( $data['content_html'] ) ) {
			return new WP_Error( 'vmsb_content', $this->ai->get_last_error() ?: 'The revision came back empty.' );
		}

		$before = $post->post_content;

		if ( (int) VMSB_Settings::get( 'require_review' ) ) {
			// Park the revision instead of overwriting the live page.
			wp_save_post_revision( $post_id );
			update_post_meta( $post_id, '_vmsb_pending_revision', VMSB_AI_Router::safe_html( $data['content_html'] ) );
			update_post_meta( $post_id, '_vmsb_pending_reason', isset( $data['change_summary'] ) ? $data['change_summary'] : $reason );
			return array( 'pending_review' => true, 'post_content' => $before );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => VMSB_AI_Router::safe_html( $data['content_html'] ) ) );
		return array( 'post_content' => $before );
	}

	/**
	 * Every post currently carrying a parked, unreviewed rewrite.
	 */
	public function pending_reviews( $limit = 50 ) {
		$posts = get_posts( array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'posts_per_page' => (int) $limit,
			'meta_key'       => '_vmsb_pending_revision',
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$out = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'post_id'  => $post->ID,
				'title'    => $post->post_title,
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
				'view_url' => get_permalink( $post->ID ),
				'reason'   => (string) get_post_meta( $post->ID, '_vmsb_pending_reason', true ),
				'current'  => $post->post_content,
				'proposed' => (string) get_post_meta( $post->ID, '_vmsb_pending_revision', true ),
			);
		}
		return $out;
	}

	/**
	 * Apply a parked rewrite to the live post and clear the queue entry.
	 */
	public function approve_pending( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		$pending = get_post_meta( $post_id, '_vmsb_pending_revision', true );
		if ( ! $pending ) {
			return new WP_Error( 'vmsb_content', 'No pending revision for this post.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_content', 'Post not found.' );
		}

		$before = $post->post_content;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => VMSB_AI_Router::safe_html( $pending ) ) );
		delete_post_meta( $post_id, '_vmsb_pending_revision' );
		delete_post_meta( $post_id, '_vmsb_pending_reason' );

		$wpdb->update(
			$wpdb->prefix . 'vmsb_issues',
			array(
				'status'         => 'fixed',
				'revert_payload' => wp_json_encode( array( 'post_id' => $post_id, 'post_content' => $before ) ),
				'fixed_at'       => current_time( 'mysql', true ),
			),
			array( 'object_id' => $post_id, 'status' => 'pending_review' )
		);

		return array( 'post_id' => $post_id, 'approved' => true );
	}

	/**
	 * Discard a parked rewrite without touching the live post.
	 */
	public function reject_pending( $post_id ) {
		global $wpdb;
		$post_id = (int) $post_id;
		if ( ! get_post_meta( $post_id, '_vmsb_pending_revision', true ) ) {
			return new WP_Error( 'vmsb_content', 'No pending revision for this post.' );
		}

		delete_post_meta( $post_id, '_vmsb_pending_revision' );
		delete_post_meta( $post_id, '_vmsb_pending_reason' );

		// Re-open the issue rather than leave it silently gone - the underlying
		// problem the fix was drafted for is still there.
		$wpdb->update(
			$wpdb->prefix . 'vmsb_issues',
			array( 'status' => 'open' ),
			array( 'object_id' => $post_id, 'status' => 'pending_review' )
		);

		return array( 'post_id' => $post_id, 'rejected' => true );
	}

	/**
	 * PORTED: Data-Driven Content Rescue.
	 * Rewrites an underperforming post using real search data to hit Page 1.
	 */
	public function rescue_post( $post_id, array $real_queries ) {
		$post = get_post( $post_id );
		if ( ! $post ) return false;

		$query_list = implode( ', ', $real_queries );
		$prompt = "Act as a Content Rescue Strategist. This article is ranking on Page 2+ and we want to push it to Top 3.\n"
			. "REAL SEARCH DATA: Users are finding this page via these queries: [{$query_list}].\n\n"
			. "TASK: Rewrite the article to better satisfy the intent of these SPECIFIC queries.\n"
			. "1. Improve the depth and add missing entities.\n"
			. "2. Ensure the content is 10x better than current Top 3 results.\n"
			. "3. Maintain the same title and URLs.\n\n"
			. "Return FULL HTML with Gutenberg blocks.";

		$data = $this->ai->generate( $prompt, array( 'complexity' => 'premium', 'persona' => 'creative' ) );
		if ( empty($data['ok']) ) return false;

		$new_content = $data['text'];
		wp_update_post( array(
			'ID'           => $post_id,
			'post_content' => VMSB_AI_Router::safe_html( $new_content ),
			'post_modified' => current_time( 'mysql' ),
			'post_modified_gmt' => current_time( 'mysql', 1 )
		) );

		update_post_meta( $post_id, '_vmsb_rescue_performed', current_time( 'mysql' ) );

		// Force instant re-indexing
		if ( class_exists('VMSB_Indexing') ) {
			( new VMSB_Indexing() )->submit($post_id);
		}

		return true;
	}

	/* ---------------------------------------------------------------- helpers */

	/**
	 * Weave AI-planned inline images in after H2 subheadings. Skips the
	 * article's opening section (the first H2) so it stays text-first, and
	 * only inserts into articles with enough real H2 structure to place an
	 * image safely - short or oddly-formatted content is left untouched
	 * rather than risking an image landing somewhere awkward.
	 */
	private function insert_inline_images( $html, array $prompts, $item, $post_id ) {
		$prompts = array_values( array_filter( array_map( 'trim', $prompts ) ) );
		if ( ! $prompts ) {
			return $html;
		}

		// Captures the closing "<!-- /wp:heading -->" comment too, when
		// present, so an inserted image lands as a sibling block after the
		// heading closes - not nested inside its comment pair, which the
		// block editor would flag as invalid content.
		$parts = preg_split( '/(<h2[^>]*>.*?<\/h2>\s*(?:<!--\s*\/wp:heading\s*-->)?)/is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		if ( count( $parts ) < 3 ) {
			return $html;
		}

		$h2_seen  = 0;
		$inserted = 0;
		$out      = '';

		foreach ( $parts as $part ) {
			$out .= $part;
			if ( ! preg_match( '/^<h2[\s>]/i', trim( $part ) ) ) {
				continue;
			}
			$h2_seen++;
			if ( 1 === $h2_seen || $inserted >= count( $prompts ) ) {
				continue;
			}

			$prompt_text = $prompts[ $inserted ];
			$img = $this->images->create( $prompt_text, array(
				'keyword'       => $item->primary_keyword,
				'post_id'       => $post_id,
				'alt'           => $prompt_text,
				'filename_hint' => $item->primary_keyword . '-inline-' . ( $inserted + 1 ),
			) );
			$inserted++; // Counts the attempt either way - don't retry the same slot on failure.
			if ( empty( $img['ok'] ) ) {
				continue;
			}

			$out .= "\n<!-- wp:image {\"id\":" . (int) $img['attachment_id'] . ',"sizeSlug":"large"} -->'
				. '<figure class="wp-block-image size-large"><img src="' . esc_url( $img['url'] ) . '" alt="' . esc_attr( $prompt_text ) . '" class="wp-image-' . (int) $img['attachment_id'] . '"/></figure>'
				. "<!-- /wp:image -->\n";
		}

		return $out;
	}

	private function attach_faq_schema( $post_id, array $faq ) {
		$entities = array();
		foreach ( $faq as $qa ) {
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
			return;
		}

		// Rank Math Compatibility
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
		} else {
			// Native Fallback
			update_post_meta(
				$post_id,
				'_vmsb_faq_schema',
				array(
					'@context'   => 'https://schema.org',
					'@type'      => 'FAQPage',
					'mainEntity' => $entities,
				)
			);
		}
	}

	private function author_id() {
		$configured = (int) get_option( 'vmsb_author_id' );
		if ( $configured && get_userdata( $configured ) ) {
			return $configured;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return $admins ? (int) $admins[0] : 1;
	}

	private function ping_sitemap() {
		$sitemap = rawurlencode( ( new VMSB_RankMath() )->sitemap_url() );
		wp_remote_get( "https://www.google.com/ping?sitemap={$sitemap}", array( 'timeout' => 10, 'blocking' => false ) );
		wp_remote_get( "https://www.bing.com/ping?sitemap={$sitemap}", array( 'timeout' => 10, 'blocking' => false ) );
	}

	/**
	 * $status defaults to 'approved' (ready for autonomous generation) to
	 * preserve every existing caller's behaviour - Cluster Architect, Silo
	 * gap-push, and the Gap Radar's "Push to Pipeline" button all skip
	 * straight to approved because a human already triggered them on
	 * purpose. Passing 'suggested' is what lets VMSB_Growth_Engine::scan()
	 * queue candidates for review instead of auto-approving them.
	 */
	/**
	 * $content_type defaults to 'blog' (the table's own DEFAULT) so every
	 * existing caller keeps writing plain posts exactly as before - only a
	 * caller that actually knows the target post type (a CPT slug validated
	 * with post_type_exists()) should ever pass one.
	 */
	public function plan_specific( $title, $keyword, $brief, $cluster = '', $is_pillar = 0, $status = 'approved', $content_type = '' ) {
		global $wpdb;
		$uid = substr( md5( $keyword . '|' . $title ), 0, 24 );
		$now = current_time( 'mysql', true );
		$content_type = $content_type ?: 'blog';

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, brief, cluster, is_pillar, status, content_type, created_at, updated_at, priority)
				 VALUES (%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,15)
				 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at), cluster = VALUES(cluster), brief = VALUES(brief), is_pillar = VALUES(is_pillar), content_type = VALUES(content_type)",
				$uid, $title, $keyword, $brief, $cluster, (int) $is_pillar, $status, $content_type, $now, $now
			)
		);

		// Don't sync a not-yet-approved suggestion to the external sheet -
		// it would look like a committed item to anyone else reading it there.
		if ( 'suggested' !== $status ) {
			$this->push_to_sheet();
		}
		return $wpdb->insert_id;
	}

	/**
	 * Detect posts published externally (e.g. by AI Puffer's own automation)
	 * and trigger the Victory Sequence. (Ported from VMAI SEO)
	 */
	public function sync_external_publications() {
		if ( ! $this->google->is_connected() ) return 0;

		$tab  = VMSB_Settings::get( 'sheet_tab' );
		$rows = $this->google->sheet_read( $tab . '!A2:M2000' );
		if ( is_wp_error( $rows ) || ! $rows ) return 0;

		global $wpdb;
		$confirmed = 0;

		foreach ( $rows as $row ) {
			$uid    = isset( $row[0] ) ? trim( $row[0] ) : '';
			$status = isset( $row[1] ) ? strtolower( trim( $row[1] ) ) : '';
			$title  = isset( $row[3] ) ? trim( $row[3] ) : '';

			if ( ! in_array( $status, array( 'success', 'completed', 'published' ) ) ) continue;

			// Verify if the post exists in WP but isn't marked as published in our plan
			$q_entry = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE row_uid = %s AND status != 'published'", $uid ) );
			if ( ! $q_entry ) continue;

			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_status = 'publish' LIMIT 1", $title ) );

			if ( ! $post_id && ! empty( $q_entry->primary_keyword ) ) {
				// Try finding by Rank Math focus keyword if title doesn't match
				$post_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rank_math_focus_keyword' AND meta_value = %s LIMIT 1",
					$q_entry->primary_keyword
				) );
			}

			if ( $post_id ) {
				$this->log->info( 'content', "Detected externally published post: '{$title}' (#$post_id). Triggering Victory Sequence." );

				$wpdb->update( $this->table(), array(
					'status' => 'published',
					'post_id' => $post_id,
					'updated_at' => current_time( 'mysql', true )
				), array( 'id' => $q_entry->id ) );

				// THE VICTORY SEQUENCE
				$this->trigger_victory_sequence( $post_id );
				$confirmed++;
			}
		}
		return $confirmed;
	}

	public function set_agent_task( $id, $task ) {
		global $wpdb;
		return $wpdb->update( $this->table(), array( 'agent_task' => $task ), array( 'id' => (int) $id ) );
	}

	/**
	 * Enhanced Retry: Dispatches a senior editor persona to analyze why
	 * the previous attempt failed and rewrite with corrected instructions.
	 */
	public function retry_with_critique( $id ) {
		global $wpdb;
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $id ) );
		if ( ! $item ) return new WP_Error( 'not_found', 'Pipeline item not found.' );

		$error = $item->last_error ?: 'Unknown technical failure.';

		// Inject the error into the brief for the next attempt
		$new_brief = "PREVIOUS ATTEMPT FAILED. REASON: {$error}\n\nORIGINAL BRIEF: {$item->brief}\n\nFIX INSTRUCTIONS: Ensure the content structure is perfectly aligned and avoids the previous failure triggers.";

		$wpdb->update( $this->table(), array(
			'brief' => $new_brief,
			'status' => 'approved',
			'priority' => 30 // Boost priority for retries
		), array( 'id' => $id ) );

		// Background the production to avoid Gateway Timeouts (524) on slow AI calls
		if ( class_exists('VMSB_Task_Runner') ) {
			return VMSB_Task_Runner::queue( 'produce_post', array( 'id' => $id ), 50, 'Retrying with senior editor critique' );
		}

		return $this->produce( $id );
	}

	public function trigger_victory_sequence( $post_id ) {
		// 1. Instant Indexing
		if ( class_exists('VMSB_Indexing') ) {
			( new VMSB_Indexing() )->submit( $post_id );
		}

		// 2. Internal Linking Autopilot (Link Genius Integration)
		if ( class_exists('AILG_Scanner') ) {
			AILG_Scanner::scan_post( $post_id );
			$this->log->info( 'content', "Triggered AI Link Genius Pro scan for post #{$post_id}." );
		} elseif ( class_exists('VMSB_Link_Flow') ) {
			( new VMSB_Link_Flow() )->rebalance();
		}

		// 3. Image Optimization Compatibility (VM Image AI)
		if ( class_exists('VMIA_Plugin') && function_exists('vmia') ) {
			$thumb_id = get_post_thumbnail_id( $post_id );
			if ( $thumb_id ) {
				// Let VM Image AI handle the detailed SEO writing for the image
				if ( class_exists('VMIA_SEO_Writer') ) {
					( new VMIA_SEO_Writer() )->write_for_attachment( $thumb_id, $post_id );
					$this->log->info( 'content', "Handed off image SEO to VM Image AI for attachment #{$thumb_id}." );
				}
			}
		}

		// 4. Social Media Automation - deliberately NOT triggered here. This
		// method runs when sync_external_publications() catches up on a post
		// that already went live (e.g. published by AI Puffer's own
		// automation) via Sheet-polling, which happens after the fact.
		// WordPress's publish_post hook already fired the moment that post
		// actually went live, and VM Social AI listens for it directly - so
		// by the time this catch-up sequence runs, VM Social AI has already
		// handled distribution. Generating a second pack here paid for the
		// same social copy twice for every externally-detected publish.

		// 5. Apply Persona E-E-A-T
		if ( class_exists('VMSB_Persona') ) {
			VMSB_Persona::apply_to_post( $post_id );
		}

		do_action( 'vmsb_post_produced', $post_id );
	}

	/**
	 * Atomically take one of today's publish slots.
	 *
	 * True when a slot was reserved, false when the cap is already spent.
	 *
	 * The cap used to be enforced by reading the counter at the top of
	 * produce() and incrementing it once the finished article came back from
	 * the model - about forty seconds later. Two runs overlapping anywhere in
	 * that window both read the same count, both passed the check, and both
	 * published, taking the day past its cap. Reserving the slot in a single
	 * conditional UPDATE closes that window: the database decides, once, who
	 * gets the last slot.
	 *
	 * Written through $wpdb rather than update_option() because the options
	 * API has no compare-and-set - so the cache is dropped by hand after.
	 */
	private function claim_publish_slot( $cap ) {
		global $wpdb;

		$key = 'vmsb_pub_' . gmdate( 'Ymd' );

		// A new key every day, read on demand: never autoloaded, or a year of
		// them rides along on every request. INSERT IGNORE (rather than
		// add_option) so two callers racing to create it cannot reset a
		// counter the other has already incremented.
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')",
			$key
		) );

		$claimed = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = option_value + 1
			 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
			$key,
			(int) $cap
		) );

		wp_cache_delete( $key, 'options' );

		return $claimed > 0;
	}

	public function stats() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$this->table()} GROUP BY status", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Every plan row scheduled within a given month, grouped by day-of-month.
	 * scheduled_for is stored in UTC (current_time('mysql', true) elsewhere
	 * in this class), so the range is built the same way for consistency.
	 *
	 * @return array<int,array> day-of-month (1-31) => list of row objects
	 */
	public function calendar_month( $year, $month ) {
		global $wpdb;
		$start = sprintf( '%04d-%02d-01 00:00:00', $year, $month );
		$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 month' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, status, primary_keyword, cluster, priority, post_id, scheduled_for
			 FROM {$this->table()}
			 WHERE scheduled_for >= %s AND scheduled_for < %s
			 ORDER BY scheduled_for ASC, priority DESC",
			$start, $end
		) );

		$by_day = array();
		foreach ( $rows as $row ) {
			$day = (int) gmdate( 'j', strtotime( $row->scheduled_for ) );
			$by_day[ $day ][] = $row;
		}
		return $by_day;
	}

	public function clear_rejected() {
		global $wpdb;
		return $wpdb->delete( $this->table(), array( 'status' => 'rejected' ) );
	}

	public function replan_rejected() {
		global $wpdb;
		return $wpdb->update(
			$this->table(),
			array( 'status' => 'planned', 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'status' => 'rejected' )
		);
	}

	public function approve_all() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id FROM {$this->table()} WHERE status = 'planned' ORDER BY priority DESC" );
		if ( ! $rows ) return 0;

		$velocity = (int) VMSB_Settings::get( 'posts_per_day', 3 );
		$interval = floor( 86400 / max(1, $velocity) ); // Seconds between posts
		$now = time();
		$count = 0;

		foreach ( $rows as $i => $row ) {
			$staggered_time = gmdate( 'Y-m-d H:i:s', $now + ($i * $interval) );
			$wpdb->update( $this->table(), array(
				'status' => 'approved',
				'scheduled_for' => $staggered_time,
				'updated_at' => current_time( 'mysql', true )
			), array( 'id' => $row->id ) );
			$count++;
		}

		return $count;
	}

	/**
	 * Hyper-Growth Pipeline: Auto-approves high-opportunity tasks
	 * to ensure the 'Writing' queue never runs dry.
	 */
	public function hydrate_pipeline( $limit = 20 ) {
		global $wpdb;

		// 2026 Blitz Mode: Always ensure at least 100 planned items before approving
		$planned_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'planned'" );
		if ( $planned_count < 100 ) {
			( new VMSB_Niche_Planner() )->plan_expansion( 50 );
		}
		$planned = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$this->table()} WHERE status = 'planned' ORDER BY priority DESC LIMIT %d",
				$limit
			)
		);

		if ( ! $planned ) {
			// If no planned items, trigger a niche expansion pass to find more
			( new VMSB_Niche_Planner() )->plan_expansion( $limit );
			$planned = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id FROM {$this->table()} WHERE status = 'planned' ORDER BY priority DESC LIMIT %d",
					$limit
				)
			);
		}

		$approved = 0;
		foreach ( $planned as $item ) {
			$wpdb->update( $this->table(), array( 'status' => 'approved' ), array( 'id' => $item->id ) );
			$approved++;
		}

		return $approved;
	}

	public function do_bulk( array $ids, $action ) {
		if ( ! $ids ) {
			return array( 'success' => false, 'error' => 'No items selected.' );
		}

		global $wpdb;
		$ids   = array_map( 'intval', $ids );
		$count = 0;

		switch ( $action ) {
			case 'bulk-approve':
				$now = current_time( 'mysql', true );
				$count = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'approved', scheduled_for = %s, updated_at = %s WHERE id IN (" . implode( ',', $ids ) . ") AND status = 'planned'", $now, $now ) );
				break;

			case 'bulk-delete':
				$count = $wpdb->query( "DELETE FROM {$this->table()} WHERE id IN (" . implode( ',', $ids ) . ") AND status != 'published'" );
				break;

			case 'bulk-produce':
				// Mark for immediate production by boosting priority and approving
				$now = current_time( 'mysql', true );
				$count = $wpdb->query( $wpdb->prepare( "UPDATE {$this->table()} SET status = 'approved', priority = 30, scheduled_for = %s WHERE id IN (" . implode( ',', $ids ) . ") AND status IN ('planned', 'rejected')", $now ) );
				break;
		}

		return array( 'success' => true, 'count' => (int) $count, 'action' => $action );
	}

	/**
	 * Direct command-line blogging. (Ported from VMAI SEO)
	 */
	public function produce_by_topic( $topic, $post_type = 'post' ) {
		global $wpdb;
		$data = $this->ai->generate_json(
			"Define a primary keyword and SEO title for this topic: {$topic}",
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 200 )
		);
		if ( empty( $data['primary_keyword'] ) ) {
			return new WP_Error( 'vmsb_content', $this->ai->get_last_error() ?: 'Could not define keyword for topic.' );
		}

		$uid = substr( md5( $data['primary_keyword'] . '|' . $topic ), 0, 24 );
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, content_type, status, priority, created_at, updated_at)
			 VALUES (%s, %s, %s, %s, 'approved', 10, %s, %s)
			 ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status), updated_at = VALUES(updated_at)",
			$uid, $data['seo_title'] ?? $topic, $data['primary_keyword'], $post_type, $now, $now
		) );

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE row_uid = %s", $uid ) );
		return $this->produce( (int) $id );
	}
}
