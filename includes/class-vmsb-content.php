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

	/**
	 * Turn the keyword gaps into a real editorial calendar.
	 */
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

		$silo = ( new VMSB_Silo() )->map_for_display();

		$data = $this->ai->generate_json(
			"Build an editorial plan from these keyword gaps.\n" . wp_json_encode( $rows )
			. "\n\nExisting silo structure (link every new piece into it):\n" . wp_json_encode( $silo )
			. "\n\nRules:\n"
			. "- One piece per genuine topic. Merge keywords that would cannibalise each other into a single stronger article.\n"
			. "- Titles are specific and written for a human, not a template. No 'Ultimate Guide' unless it truly is one.\n"
			. "- The brief tells the writer what to cover, what to avoid, and what the reader should be able to do afterwards.\n"
			. "- Bottom-funnel and striking-distance topics come first.\n"
			. "- Every piece names two or three internal link targets from the existing site.\n\n"
			. 'Return JSON: {"plan":[{"title":"","primary_keyword":"","secondary_keywords":[],"cluster":"","intent":"","content_type":"blog|comparison|guide|landing|faq","target_words":0,"brief":"","internal_links":[],"priority":0}]}',
			array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 4000, 'temperature' => 0.6 )
		);

		$items = isset( $data['plan'] ) ? $data['plan'] : array();
		if ( ! $items ) {
			$this->log->error( 'content', 'Planning returned no usable rows.' );
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

		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, secondary_keywords, cluster, intent, content_type, brief, internal_links, target_words, priority, scheduled_for, status, created_at, updated_at)
			 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,%s,'planned',%s,%s)
			 ON DUPLICATE KEY UPDATE title = VALUES(title), brief = VALUES(brief), status = 'planned', updated_at = VALUES(updated_at)",
			$uid, $data['title'], $keyword, wp_json_encode( $data['secondary_keywords'] ?? array() ),
			$gap->cluster, $gap->intent, $data['content_type'] ?? 'blog', $data['brief'] ?? '',
			wp_json_encode( $data['internal_links'] ?? array() ), (int) ($data['target_words'] ?? 1600),
			1.0, $now, $now, $now
		) );

		( new VMSB_Keywords() )->mark( $keyword, 'planned' );
		$this->push_to_sheet();

		return 1;
	}

	/* ---------------------------------------------------------------- bulk topic import */

	/**
	 * Turn a flat list of raw topic ideas (a pasted list, or rows pulled
	 * from a dedicated "Bulk Topics" sheet tab - see pull_bulk_topics())
	 * into proper Content Plan rows. Unlike plan_by_keyword(), these don't
	 * need to already exist in the tracked keyword universe: each is
	 * expanded from scratch into a full plan row (primary/secondary
	 * keywords, cluster, intent, format, brief) via AI.
	 *
	 * @return array{imported:int,skipped:int,processed:int,submitted:int}
	 */
	public function import_topics( array $topics, $priority = 5.0, $language = '' ) {
		$language = sanitize_text_field( $language );
		global $wpdb;
		// array_unique on the raw strings first - catches the literal "pasted
		// the same list twice" case before it burns an AI call on each copy.
		$topics = array_slice( array_values( array_unique( array_filter( array_map( 'trim', $topics ) ) ) ), 0, 15 );
		if ( ! $topics ) {
			return array( 'imported' => 0, 'skipped' => 0, 'processed' => 0, 'submitted' => 0 );
		}

		// Dedup on the target KEYWORD, not the title - checking here against
		// raw input topics ("signs you need a new water heater") never
		// matched existing plan TITLES (the AI's own polished output, e.g.
		// "5 Warning Signs You Need a New Water Heater ASAP"), so it never
		// actually caught anything. Checking primary_keyword after the AI
		// call is what actually prevents two plan rows from targeting the
		// same keyword under different titles - a real cannibalization risk.
		$existing_keywords = array_map( 'strtolower', (array) $wpdb->get_col( "SELECT primary_keyword FROM {$this->table()}" ) );

		$imported  = 0;
		$skipped   = 0;
		$processed = 0;
		$now       = current_time( 'mysql', true );
		$start     = time();

		foreach ( $topics as $topic ) {
			// Anti-timeout: one AI call per topic - whatever's left over
			// when this trips can simply be re-submitted next run.
			if ( time() - $start > 25 ) {
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
				. 'Return JSON: {"title":"","primary_keyword":"","secondary_keywords":[],"cluster":"","intent":"informational|commercial|transactional|navigational","content_type":"blog|comparison|guide|faq","target_words":1600,"brief":""}',
				array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 700, 'temperature' => 0.5, 'persona' => 'strategist' )
			);

			if ( empty( $data['title'] ) || empty( $data['primary_keyword'] ) ) {
				$skipped++;
				continue;
			}

			if ( in_array( strtolower( $data['primary_keyword'] ), $existing_keywords, true ) ) {
				$skipped++;
				continue;
			}

			$uid = substr( md5( $data['primary_keyword'] . '|' . $topic ), 0, 24 );

			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, secondary_keywords, cluster, intent, content_language, content_type, brief, internal_links, target_words, priority, status, created_at, updated_at)
				 VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%f,'planned',%s,%s)
				 ON DUPLICATE KEY UPDATE title = VALUES(title), brief = VALUES(brief), updated_at = VALUES(updated_at)",
				$uid,
				sanitize_text_field( $data['title'] ),
				sanitize_text_field( $data['primary_keyword'] ),
				wp_json_encode( array_slice( (array) ( $data['secondary_keywords'] ?? array() ), 0, 6 ) ),
				sanitize_text_field( $data['cluster'] ?? '' ),
				sanitize_key( $data['intent'] ?? 'informational' ),
				$language ?: null,
				sanitize_key( $data['content_type'] ?? 'blog' ),
				wp_kses_post( $data['brief'] ?? '' ),
				wp_json_encode( array() ),
				(int) ( $data['target_words'] ?? 1600 ),
				(float) $priority,
				$now,
				$now
			) );

			$existing_keywords[] = strtolower( $data['primary_keyword'] );
			$imported++;
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
	 * Content Plan sync tab, and turn each unprocessed row into a plan row.
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
				"SELECT * FROM {$this->table()} WHERE status IN ('planned','approved') AND (scheduled_for IS NULL OR scheduled_for <= UTC_TIMESTAMP()) ORDER BY priority DESC, scheduled_for ASC LIMIT %d",
				(int) $limit
			)
		);
	}

	/**
	 * Write and publish one planned piece.
	 */
	public function produce( $plan_id, $args = array() ) {
		// Global Drip Cap Check (Best of Autopilot)
		$today_key       = 'vmsb_pub_' . gmdate( 'Ymd' );
		$published_today = (int) get_option( $today_key, 0 );
		$cap             = (int) VMSB_Settings::get( 'posts_per_day', 3 );
		if ( $published_today >= $cap ) {
			return new WP_Error( 'vmsb_drip', "Daily publish cap of {$cap} reached. Drip scheduling in effect." );
		}

		global $wpdb;
		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", (int) $plan_id ) );
		if ( ! $item ) {
			return new WP_Error( 'vmsb_content', 'Plan row not found.' );
		}

		$wpdb->update( $this->table(), array( 'status' => 'writing' ), array( 'id' => $item->id ) );

		$secondary     = (array) json_decode( $item->secondary_keywords, true );
		$links         = (array) json_decode( $item->internal_links, true );
		$agent_context = isset( $args['agent_context'] ) ? $args['agent_context'] : '';

		$link_context = array();
		foreach ( $links as $hint ) {
			$found = get_posts( array( 's' => $hint, 'posts_per_page' => 1, 'post_status' => 'publish' ) );
			if ( $found ) {
				$link_context[] = array( 'title' => $found[0]->post_title, 'url' => get_permalink( $found[0] ) );
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
			. ( $semantic_clues ? "SEMANTIC CONTEXT (Build upon these existing site themes): " . implode( ', ', $semantic_clues ) . "\n" : "" )
			. ( $agent_context ? "RESEARCH & ARCHITECTURE GUIDANCE: {$agent_context}\n" : "" )
			. 'INTERNAL LINKS TO INCLUDE (use natural anchors): ' . wp_json_encode( $link_context ) . "\n\n"
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
			. 'Return JSON: {"post_title":"","slug":"","content_html":"","excerpt":"","seo_title":"","meta_description":"","featured_image_prompt":"","inline_image_prompts":[],"faq":[{"q":"","a":""}],"suggested_category":"","suggested_tags":"","seo_score":92}';

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
		// THE GATE. Nothing the brain writes reaches a live URL unchecked.
		$html   = wp_kses_post( $data['content_html'] );
		$report = class_exists( 'VMSB_Quality_Gate' )
			? VMSB_Quality_Gate::evaluate( $html, array(
				'title'   => $data['post_title'] ?? $item->title,
				'keyword' => $item->primary_keyword,
			) )
			: array( 'verdict' => 'pass', 'score' => 100 );

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
		$post_type = $item->content_type;
		if ( 'blog' === $post_type || empty($post_type) ) {
			$post_type = 'post';
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
				'seo_score'     => isset( $data['seo_score'] ) ? (int) $data['seo_score'] : 82,
			)
		);

		// FAQ schema.
		if ( ! empty( $data['faq'] ) ) {
			$this->attach_faq_schema( $post_id, $data['faq'] );
		}

		// Featured image.
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

		// Wire it into the silo.
		$silo = new VMSB_Silo();
		foreach ( $link_context as $target ) {
			$target_id = url_to_postid( $target['url'] );
			if ( $target_id ) {
				$silo->insert_internal_link( $target_id, $post_id );
			}
		}

		// Apply Persona (E-E-A-T)
		VMSB_Persona::apply_to_post( $post_id );

		$wpdb->update(
			$this->table(),
			array( 'status' => 'publish' === $status ? 'published' : 'drafted', 'post_id' => $post_id, 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => $item->id )
		);

		( new VMSB_Keywords() )->mark( $item->primary_keyword, 'published', $post_id );

		// Increment drip counter
		$today_key = 'vmsb_pub_' . gmdate( 'Ymd' );
		update_option( $today_key, (int) get_option( $today_key, 0 ) + 1 );

		if ( 'publish' === $status ) {
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
			update_post_meta( $post_id, '_vmsb_pending_revision', wp_kses_post( $data['content_html'] ) );
			update_post_meta( $post_id, '_vmsb_pending_reason', isset( $data['change_summary'] ) ? $data['change_summary'] : $reason );
			return array( 'pending_review' => true, 'post_content' => $before );
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $data['content_html'] ) ) );
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
		wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_kses_post( $pending ) ) );
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

	public function plan_specific( $title, $keyword, $brief, $cluster = '', $is_pillar = 0 ) {
		global $wpdb;
		$uid = substr( md5( $keyword . '|' . $title ), 0, 24 );
		$now = current_time( 'mysql', true );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, brief, cluster, is_pillar, status, created_at, updated_at, priority)
				 VALUES (%s,%s,%s,%s,%s,%d,'approved',%s,%s,15)
				 ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at), cluster = VALUES(cluster), brief = VALUES(brief), is_pillar = VALUES(is_pillar)",
				$uid, $title, $keyword, $brief, $cluster, (int) $is_pillar, $now, $now
			)
		);

		$this->push_to_sheet();
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
				$count = $wpdb->query( "UPDATE {$this->table()} SET status = 'approved', updated_at = '" . current_time( 'mysql', true ) . "' WHERE id IN (" . implode( ',', $ids ) . ") AND status = 'planned'" );
				break;

			case 'bulk-delete':
				$count = $wpdb->query( "DELETE FROM {$this->table()} WHERE id IN (" . implode( ',', $ids ) . ") AND status != 'published'" );
				break;

			case 'bulk-produce':
				// Mark for immediate production by boosting priority and approving
				$count = $wpdb->query( "UPDATE {$this->table()} SET status = 'approved', priority = 30 WHERE id IN (" . implode( ',', $ids ) . ") AND status IN ('planned', 'rejected')" );
				break;
		}

		return array( 'success' => true, 'count' => (int) $count, 'action' => $action );
	}

	/**
	 * Direct command-line blogging. (Ported from VMAI SEO)
	 */
	public function produce_by_topic( $topic ) {
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
			"INSERT INTO {$this->table()} (row_uid, title, primary_keyword, status, priority, created_at, updated_at)
			 VALUES (%s, %s, %s, 'approved', 10, %s, %s)
			 ON DUPLICATE KEY UPDATE title = VALUES(title), updated_at = VALUES(updated_at)",
			$uid, $data['seo_title'] ?? $topic, $data['primary_keyword'], $now, $now
		) );

		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE row_uid = %s", $uid ) );
		return $this->produce( (int) $id );
	}
}
