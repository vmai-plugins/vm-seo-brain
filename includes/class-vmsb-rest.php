<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-facing REST endpoints. Every route is capability-gated and
 * nonce-checked; nothing here is public.
 */
class VMSB_REST {

	const NS = 'vmsb/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function permission() {
		return current_user_can( VMSB_CAP );
	}

	public function routes() {
		$routes = array(
			'understand'      => 'understand',
			'scan'            => 'scan',
			'god-fix'         => 'god_fix',
			'god-fix-90'      => 'god_fix_90',
			'research'        => 'research',
			'silo-map'        => 'silo_map',
			'silo-audit'      => 'silo_audit',
			'silo-push-gaps'  => 'silo_push_gaps',
			'heatmap-data'    => 'heatmap_data',
			'taxonomy-audit'  => 'taxonomy_audit',
			'taxonomy-propose'=> 'taxonomy_propose',
			'plan'            => 'plan',
			'niche-plan'      => 'niche_plan',
			'cluster-architect' => 'cluster_architect',
			'gap-discovery'     => 'gap_discovery',
			'battle-roadmap'    => 'battle_roadmap',
			'growth-scan'              => 'growth_scan',
			'growth-suggestion-approve'=> 'growth_suggestion_approve',
			'growth-suggestion-reject' => 'growth_suggestion_reject',
			'agents-status'            => 'agents_status',
			'agents-run-strategist'    => 'agents_run_strategist',
			'improve-post'         => 'improve_post',
			'keyword-dismiss'      => 'keyword_dismiss',
			'keyword-merge-cluster'=> 'keyword_merge_cluster',
			'keyword-suggest-merges' => 'keyword_suggest_merges',
			'push-sheet'      => 'push_sheet',
			'pull-sheet'      => 'pull_sheet',
			'import-topics'      => 'import_topics',
			'save-editor-note'   => 'save_editor_note',
			'pull-bulk-topics'   => 'pull_bulk_topics',
			'clear-rejected'  => 'clear_rejected',
			'replan-rejected' => 'replan_rejected',
			'approve-all'     => 'approve_all',
			'bulk-action'     => 'bulk_action',
			'bulk-issue-action' => 'bulk_issue_action',
			'produce'         => 'produce',
			'retry-critique'  => 'retry_critique',
			'revert'          => 'revert',
			'dismiss'         => 'dismiss',
			'pending-list'    => 'pending_list',
			'pending-approve' => 'pending_approve',
			'pending-reject'  => 'pending_reject',
			'alt-backfill'    => 'alt_backfill',
			'test-provider'   => 'test_provider',
			'webhook-test'    => 'webhook_test',
			'index-vectors'   => 'index_vectors',
			'rebuild-index'   => 'rebuild_index',
			'measure-outcomes'=> 'measure_outcomes',
			'competitor-add'      => 'competitor_add',
			'competitor-remove'   => 'competitor_remove',
			'competitor-scan'     => 'competitor_scan',
			'competitor-duel'     => 'competitor_duel',
			'backlink-discover'   => 'backlink_discover',
			'backlink-discover-recent' => 'backlink_discover_recent',
			'backlink-draft'      => 'backlink_draft',
			'backlink-send'       => 'backlink_send',
			'backlink-shield'     => 'backlink_shield',
			'aeo-audit'           => 'aeo_audit',
			'aeo-apply'           => 'aeo_apply',
			'entity-audit'        => 'entity_audit',
			'entity-inject'       => 'entity_inject',
			'programmatic-build'  => 'programmatic_build',
			'roi-scan'            => 'roi_scan',
			'roi-cta'             => 'roi_cta',
			'roi-forecast'        => 'roi_forecast',
			'ctr-start'           => 'ctr_start',
			'ctr-conclude'        => 'ctr_conclude',
			'news-scout'          => 'news_scout',
			'trend-scout'         => 'trend_scout',
			'traffic-forecast'    => 'traffic_forecast',
			'schema-faq'          => 'schema_faq',
			'schema-graph'        => 'schema_graph',
			'global-expand'       => 'global_expand',
			'health-check'        => 'health_check',
			'market-assess'       => 'market_assess',
			'performance-summary' => 'performance_summary',
			'post-insight'        => 'post_insight',
			'social-generate'     => 'social_generate',
			'strategist-preview'  => 'strategist_preview',
			'tasks-process'       => 'tasks_process',
			'quantum-heist'       => 'quantum_heist',
			'quantum-blast'       => 'quantum_blast',
			'vulture-strike'      => 'vulture_strike',
			'license-verify'      => 'license_verify',
			'command'             => 'command',
			'aipuffer-bots'       => 'aipuffer_bots',
			'health-reset'        => 'health_reset',
			'verify-ai'           => 'verify_ai',
			'test-image-provider' => 'test_image_provider',
			'sync-models'         => 'sync_models',
			'opportunity-scan'    => 'opportunity_scan',
			'action-rollback'     => 'action_rollback',
			'rollback-recent'     => 'rollback_recent',
			'task-detail'         => 'task_detail',
			'task-cancel'         => 'task_cancel',
			'global-search'       => 'global_search',
			'evaluate-pivot'      => 'evaluate_pivot',
			'graph-data'          => 'graph_data',
			'graph-sync'          => 'graph_sync',
			'video-to-blog'       => 'video_to_blog',
			'execute-opportunity' => 'execute_opportunity',
			'generate-report'     => 'generate_report',
			'notifications'       => 'notifications',
		);

		foreach ( $routes as $path => $callback ) {
			register_rest_route(
				self::NS,
				'/' . $path,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $callback ),
					'permission_callback' => array( $this, 'permission' ),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);
	}

	/* ---------------------------------------------------------------- handlers */

	public function status( $request ) {
		$force = (bool) $request->get_param( 'force' );
		if ( ! $force ) {
			$cached = get_transient( 'vmsb_status_summary' );
			if ( false !== $cached ) {
				return rest_ensure_response( $cached );
			}
		}

		$fixer  = new VMSB_Fixer();
		$growth = new VMSB_Growth();

		$data = array(
			'issues'    => $fixer->counts(),
			'growth'    => $growth->status(),
			'verdict'   => $growth->verdict(),
			'content'   => ( new VMSB_Content() )->stats(),
			'keywords'  => array(
				'total'   => ( new VMSB_Keywords() )->count(),
				'planned' => ( new VMSB_Keywords() )->count( 'planned' ),
			),
			'ai_calls'  => ( new VMSB_AI_Router() )->calls_today(),
			'google'    => ( new VMSB_Google() )->is_connected(),
			'rankmath'  => ( new VMSB_RankMath() )->is_active(),
			'last_scan' => (int) get_option( 'vmsb_last_scan', 0 ),
		);

		set_transient( 'vmsb_status_summary', $data, 300 ); // Cache for 5 minutes

		return rest_ensure_response( $data );
	}

	public function understand( $request ) {
		if ( get_transient( 'vmsb_understanding_lock' ) ) {
			return new WP_Error( 'vmsb_busy', 'The brain is already analyzing the business. Try again in a few minutes.', array( 'status' => 429 ) );
		}
		set_transient( 'vmsb_understanding_lock', 1, 120 ); // 2 minute lock
		$res = ( new VMSB_Brain() )->understand_business( true );
		delete_transient( 'vmsb_understanding_lock' );
		return rest_ensure_response( $res );
	}

	public function scan( $request ) {
		delete_transient( 'vmsb_status_summary' );
		$found = ( new VMSB_Fixer() )->scan( (int) $request->get_param( 'limit' ) ?: 200 );
		return rest_ensure_response( array( 'found' => $found, 'counts' => ( new VMSB_Fixer() )->counts() ) );
	}

	public function god_fix( $request ) {
		delete_transient( 'vmsb_status_summary' );
		$limit = (int) $request->get_param( 'limit' ) ?: 25;
		$scope = (array) $request->get_param( 'scope' );
		return rest_ensure_response( ( new VMSB_Fixer() )->god_fix( $limit, $scope ) );
	}

	public function god_fix_90( $request ) {
		// The Issues page's "Target 90+" button sends data-id (the same
		// convention every other per-row action button on that page uses,
		// e.g. Dismiss) rather than post_id - accept both instead of only
		// ever reading a param nothing actually sends.
		$post_id = (int) ( $request->get_param( 'post_id' ) ?: $request->get_param( 'id' ) );
		return rest_ensure_response( ( new VMSB_Fixer() )->god_fix_90( $post_id ) );
	}

	public function research( $request ) {
		return rest_ensure_response( array( 'found' => ( new VMSB_Keywords() )->research() ) );
	}

	public function silo_map( $request ) {
		$force = (bool) $request->get_param( 'force' );
		$silo = new VMSB_Silo();
		if ( $force ) {
			$silo->generate_map( true );
		}
		return rest_ensure_response( array( 'silos' => $silo->map_for_display(), 'orphans' => count( $silo->orphans() ) ) );
	}

	public function silo_audit( $request ) {
		return rest_ensure_response( array( 'found' => ( new VMSB_Silo() )->deep_linking_audit() ) );
	}

	public function silo_push_gaps( $request ) {
		return rest_ensure_response( ( new VMSB_Silo() )->push_gaps_to_plan() );
	}

	public function heatmap_data( $request ) {
		return rest_ensure_response( ( new VMSB_Heatmap() )->get_data() );
	}

	public function taxonomy_audit( $request ) {
		return rest_ensure_response( array( 'issues' => ( new VMSB_Taxonomy() )->audit() ) );
	}

	public function taxonomy_propose( $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( $id ) {
			return rest_ensure_response( ( new VMSB_Taxonomy() )->optimise_term( $id ) );
		}
		return rest_ensure_response( ( new VMSB_Taxonomy() )->propose_structure() );
	}

	public function plan( $request ) {
		$count   = (int) $request->get_param( 'count' ) ?: 20;
		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) );

		if ( $keyword ) {
			return rest_ensure_response( array( 'planned' => ( new VMSB_Content() )->plan_by_keyword( $keyword ) ) );
		}

		return rest_ensure_response( array( 'planned' => ( new VMSB_Content() )->plan( $count ) ) );
	}

	public function improve_post( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$reason  = sanitize_key( $request->get_param( 'reason' ) ) ?: 'striking_distance';
		$result  = ( new VMSB_Content() )->improve_post( $post_id, $reason );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function keyword_dismiss( $request ) {
		$keyword = sanitize_text_field( $request->get_param( 'keyword' ) );
		( new VMSB_Keywords() )->dismiss( $keyword );
		return rest_ensure_response( array( 'dismissed' => true ) );
	}

	public function keyword_merge_cluster( $request ) {
		$from = sanitize_text_field( $request->get_param( 'from' ) );
		$to   = sanitize_text_field( $request->get_param( 'to' ) );
		$n    = ( new VMSB_Keywords() )->merge_cluster( $from, $to );
		return rest_ensure_response( array( 'merged' => $n ) );
	}

	public function keyword_suggest_merges( $request ) {
		return rest_ensure_response( array( 'suggestions' => ( new VMSB_Keywords() )->suggest_cluster_merges() ) );
	}

	public function agents_status( $request ) {
		return rest_ensure_response( VMSB_Strategist::fleet_status() );
	}

	public function agents_run_strategist( $request ) {
		$plan = VMSB_Strategist::plan_and_queue( 6 );
		return rest_ensure_response( array( 'queued' => count( $plan ), 'plan' => $plan ) );
	}

	public function growth_scan( $request ) {
		return rest_ensure_response( ( new VMSB_Growth_Engine() )->scan() );
	}

	public function growth_suggestion_approve( $request ) {
		return rest_ensure_response( ( new VMSB_Growth_Engine() )->approve( (int) $request->get_param( 'id' ) ) );
	}

	public function growth_suggestion_reject( $request ) {
		return rest_ensure_response( ( new VMSB_Growth_Engine() )->reject( (int) $request->get_param( 'id' ) ) );
	}

	public function niche_plan( $request ) {
		$count   = (int) $request->get_param( 'count' ) ?: 20;
		$cluster = sanitize_text_field( $request->get_param( 'cluster' ) );
		return rest_ensure_response( ( new VMSB_Niche_Planner() )->plan_expansion( $count, $cluster ) );
	}

	public function cluster_architect( $request ) {
		$seed = sanitize_text_field( $request->get_param( 'seed' ) );
		$size = (int) $request->get_param( 'size' ) ?: 6;
		if ( ! $seed ) return new WP_Error( 'vmsb_rest', 'Seed keyword is required.' );

		$res = ( new VMSB_Cluster_Architect() )->design_cluster( $seed, $size );
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( $res );
	}

	public function gap_discovery( $request ) {
		$engine = new VMSB_Gap_Finder();
		$gaps = $engine->discover_golden_gaps( 10 );
		if ( is_wp_error($gaps) ) return $gaps;

		return rest_ensure_response( array( 'gaps' => $gaps ) );
	}

	public function battle_roadmap( $request ) {
		$target = (int) $request->get_param( 'target' ) ?: (int) VMSB_Settings::get( 'growth_target', 50000 );
		$days   = (int) $request->get_param( 'days' ) ?: (int) VMSB_Settings::get( 'growth_window', 50 );
		$res = ( new VMSB_Roadmap() )->generate_plan( $target, $days );
		return rest_ensure_response( array( 'roadmap' => $res ) );
	}

	public function push_sheet( $request ) {
		$force = (bool) $request->get_param( 'force' );
		$res = ( new VMSB_Content() )->push_to_sheet( $force );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'vmsb_push', $res->get_error_message(), array( 'status' => 422 ) );
		}
		return rest_ensure_response( array( 'pushed' => $res ) );
	}

	public function pull_sheet( $request ) {
		return rest_ensure_response( array( 'pulled' => ( new VMSB_Content() )->pull_from_sheet() ) );
	}

	public function import_topics( $request ) {
		$topics   = array_map( 'sanitize_text_field', (array) $request->get_param( 'topics' ) );
		$language = sanitize_text_field( (string) $request->get_param( 'language' ) );
		return rest_ensure_response( ( new VMSB_Content() )->import_topics( $topics, 5.0, $language ) );
	}

	public function save_editor_note( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$note = wp_kses_post( $request->get_param( 'note' ) );
		$updated = $wpdb->update( $wpdb->prefix . 'vmsb_plan', array( 'editor_note' => $note ), array( 'id' => $id ) );
		return rest_ensure_response( array( 'success' => (bool)$updated ) );
	}

	public function pull_bulk_topics( $request ) {
		$result = ( new VMSB_Content() )->pull_bulk_topics();
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function clear_rejected( $request ) {
		return rest_ensure_response( array( 'deleted' => ( new VMSB_Content() )->clear_rejected() ) );
	}

	public function replan_rejected( $request ) {
		return rest_ensure_response( array( 'updated' => ( new VMSB_Content() )->replan_rejected() ) );
	}

	public function approve_all( $request ) {
		return rest_ensure_response( array( 'updated' => ( new VMSB_Content() )->approve_all() ) );
	}

	public function bulk_action( $request ) {
		$ids    = (array) $request->get_param( 'ids' );
		$action = sanitize_key( $request->get_param( 'bulk_action' ) );
		return rest_ensure_response( ( new VMSB_Content() )->do_bulk( $ids, $action ) );
	}

	public function bulk_issue_action( $request ) {
		$ids    = (array) $request->get_param( 'ids' );
		$action = sanitize_key( $request->get_param( 'bulk_action' ) );
		return rest_ensure_response( ( new VMSB_Fixer() )->do_bulk( $ids, $action ) );
	}

	public function produce( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$res = ( new VMSB_Content() )->produce( $id );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'error' => $res->get_error_message() ), 422 );
		}
		return rest_ensure_response( array( 'post_id' => $res, 'edit_url' => get_edit_post_link( $res, 'raw' ) ) );
	}

	public function retry_critique( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$res = ( new VMSB_Content() )->retry_with_critique( $id );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'error' => $res->get_error_message() ), 422 );
		}
		return rest_ensure_response( array( 'post_id' => $res, 'edit_url' => get_edit_post_link( $res, 'raw' ) ) );
	}

	public function revert( $request ) {
		$res = ( new VMSB_Fixer() )->revert( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response( array( 'error' => $res->get_error_message() ), 422 );
		}
		return rest_ensure_response( array( 'reverted' => true ) );
	}

	public function dismiss( $request ) {
		return rest_ensure_response( array( 'dismissed' => (bool) ( new VMSB_Fixer() )->dismiss( (int) $request->get_param( 'id' ) ) ) );
	}

	public function pending_list( $request ) {
		return rest_ensure_response( ( new VMSB_Content() )->pending_reviews( 50 ) );
	}

	public function pending_approve( $request ) {
		$result = ( new VMSB_Content() )->approve_pending( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function pending_reject( $request ) {
		$result = ( new VMSB_Content() )->reject_pending( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function alt_backfill( $request ) {
		return rest_ensure_response( array( 'fixed' => ( new VMSB_Image_Engine() )->backfill_alt_text( (int) $request->get_param( 'limit' ) ?: 25 ) ) );
	}

	public function test_provider( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$res      = ( new VMSB_AI_Router() )->generate( 'Reply with exactly: OK', array( 'provider' => $provider, 'max_tokens' => 10, 'bypass_circuit' => true ) );
		return rest_ensure_response( array( 'ok' => ! empty( $res['ok'] ), 'message' => ! empty( $res['ok'] ) ? trim( $res['text'] ) : $res['error'] ) );
	}

	public function webhook_test( $request ) {
		$url = trim( (string) VMSB_Settings::get( 'webhook_url' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => 'No valid webhook URL saved yet - save Settings first.' ) );
		}

		// Deliberately blocking, unlike VMSB_Webhooks::dispatch() - this is
		// the one case where the admin explicitly wants to wait and see
		// whether the URL is actually reachable, not fire-and-forget.
		$res = wp_remote_post( $url, array(
			'timeout' => 8,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'event' => 'test', 'site' => home_url(), 'time' => current_time( 'mysql', true ) ) ),
		) );

		if ( is_wp_error( $res ) ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => $res->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $res );
		return rest_ensure_response( array(
			'ok'      => $code >= 200 && $code < 300,
			'message' => "Receiver responded with HTTP {$code}.",
		) );
	}

	public function index_vectors( $request ) {
		if ( ! class_exists( 'VMSB_Vector_Store' ) ) {
			return rest_ensure_response( array( 'error' => 'Vector store unavailable.' ) );
		}
		$limit = (int) $request->get_param( 'limit' ) ?: 25;
		return rest_ensure_response( VMSB_Vector_Store::index_batch( $limit ) );
	}

	public function rebuild_index( $request ) {
		if ( ! class_exists( 'VMSB_Vector_Store' ) ) {
			return rest_ensure_response( array( 'error' => 'Vector store unavailable.' ) );
		}
		VMSB_Vector_Store::rebuild();
		$batch = VMSB_Vector_Store::index_batch( 25 );
		return rest_ensure_response( array_merge( array( 'rebuilt' => true ), $batch ) );
	}

	public function measure_outcomes( $request ) {
		if ( ! class_exists( 'VMSB_Outcome_Ledger' ) ) {
			return rest_ensure_response( array( 'error' => 'Ledger unavailable.' ) );
		}
		return rest_ensure_response( VMSB_Outcome_Ledger::measure_due() );
	}

	/* ---------------------------------------------------------------- competitor */

	public function competitor_add( $request ) {
		$id = ( new VMSB_Competitor() )->add( (string) $request->get_param( 'domain' ), (string) $request->get_param( 'label' ) );
		if ( is_wp_error( $id ) ) {
			return new WP_REST_Response( array( 'error' => $id->get_error_message() ), 422 );
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	public function competitor_remove( $request ) {
		$ok = ( new VMSB_Competitor() )->remove( (int) $request->get_param( 'id' ) );
		return rest_ensure_response( array( 'removed' => $ok ) );
	}

	public function competitor_scan( $request ) {
		return rest_ensure_response( ( new VMSB_Competitor() )->scan( (int) $request->get_param( 'limit' ) ?: 10 ) );
	}

	public function competitor_duel( $request ) {
		$result = ( new VMSB_Competitor() )->duel( (int) $request->get_param( 'post_id' ), (string) $request->get_param( 'domain' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- backlinks */

	public function backlink_discover( $request ) {
		$result = ( new VMSB_Backlinks() )->discover( (int) $request->get_param( 'post_id' ), (int) $request->get_param( 'limit' ) ?: 8 );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function backlink_discover_recent( $request ) {
		return rest_ensure_response( ( new VMSB_Backlinks() )->discover_recent( (int) $request->get_param( 'limit' ) ?: 5 ) );
	}

	public function backlink_draft( $request ) {
		$result = ( new VMSB_Backlinks() )->draft_pitch( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function backlink_send( $request ) {
		$result = ( new VMSB_Backlinks() )->send_pitch( (int) $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function backlink_shield( $request ) {
		return rest_ensure_response( ( new VMSB_Backlinks() )->shield_scan( (int) $request->get_param( 'limit' ) ?: 30 ) );
	}

	/* ---------------------------------------------------------------- AEO */

	public function aeo_audit( $request ) {
		$result = ( new VMSB_AEO() )->audit( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function aeo_apply( $request ) {
		$result = ( new VMSB_AEO() )->apply( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- entity */

	public function entity_audit( $request ) {
		$result = ( new VMSB_Entity() )->audit( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function entity_inject( $request ) {
		$result = ( new VMSB_Entity() )->inject( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- programmatic */

	public function programmatic_build( $request ) {
		$template      = (string) $request->get_param( 'template' );
		$variable_name = sanitize_key( (string) $request->get_param( 'variable_name' ) );
		$values        = (array) $request->get_param( 'values' );
		$keyword       = (string) $request->get_param( 'base_keyword' );

		if ( ! $template || ! $variable_name || ! $values ) {
			return rest_ensure_response( array( 'error' => 'Template, variable name, and values are all required.' ) );
		}

		$values    = array_values( array_filter( array_map( 'sanitize_text_field', $values ) ) );
		$variables = array( $variable_name => $values );

		$result = ( new VMSB_Programmatic() )->build_set( $template, $variables, $keyword );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- ROI */

	public function roi_scan( $request ) {
		return rest_ensure_response( ( new VMSB_ROI() )->find_leaks( (int) $request->get_param( 'limit' ) ?: 15 ) );
	}

	public function roi_cta( $request ) {
		$result = ( new VMSB_ROI() )->insert_cta( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function roi_forecast( $request ) {
		$result = ( new VMSB_ROI() )->forecast();
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- CTR */

	public function ctr_start( $request ) {
		$result = ( new VMSB_CTR() )->start( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function ctr_conclude( $request ) {
		return rest_ensure_response( ( new VMSB_CTR() )->conclude_due() );
	}

	/* ---------------------------------------------------------------- news / forecast / schema / global */

	public function news_scout( $request ) {
		$result = ( new VMSB_News() )->scout( (int) $request->get_param( 'count' ) ?: 5 );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function trend_scout( $request ) {
		// This forces a refresh of the trending signals dashboard.
		delete_transient('vmsb_rising_trends');
		return rest_ensure_response( array( 'success' => true ) );
	}

	public function traffic_forecast( $request ) {
		return rest_ensure_response( ( new VMSB_Forecaster() )->forecast() );
	}

	public function schema_faq( $request ) {
		$result = ( new VMSB_Schema() )->generate_faq( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function schema_graph( $request ) {
		$result = ( new VMSB_Schema() )->generate_graph( (int) $request->get_param( 'post_id' ) );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function global_expand( $request ) {
		$locations = array_filter( array_map( 'trim', (array) $request->get_param( 'locations' ) ) );
		$result    = ( new VMSB_Global_Expander() )->expand( (int) $request->get_param( 'post_id' ), $locations );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	/* ---------------------------------------------------------------- health / market / performance */

	public function health_check( $request ) {
		return rest_ensure_response( ( new VMSB_Health() )->check() );
	}

	public function market_assess( $request ) {
		$result = ( new VMSB_Market() )->assess();
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
		}
		return rest_ensure_response( $result );
	}

	public function performance_summary( $request ) {
		return rest_ensure_response( ( new VMSB_Performance() )->business_summary() );
	}

	public function social_generate( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		return rest_ensure_response( ( new VMSB_Social_Recycler() )->generate_social_pack( $post_id ) );
	}

	public function post_insight( $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$post = get_post( $post_id );
		if ( ! $post ) return new WP_Error( 'not_found', 'Post not found.' );

		$report = VMSB_Quality_Gate::get_report( $post_id );
		$aeo    = get_post_meta( $post_id, '_vmsb_aeo_audit', true );
		$social = get_post_meta( $post_id, '_vmsb_social_pack', true );

		$entities = class_exists( 'VMSB_Entity' ) ? ( new VMSB_Entity() )->get_missing_entities( $post_id ) : array();

		return rest_ensure_response( array(
			'title'    => $post->post_title,
			'score'    => (int) get_post_meta( $post_id, '_vmsb_quality_score', true ),
			'report'   => $report,
			'aeo'      => $aeo,
			'social'   => $social,
			'entities' => $entities,
			'links'    => ( new VMSB_Silo() )->semantic_targets( $post_id, 3 )
		) );
	}

	/* ---------------------------------------------------------------- strategist / tasks */

	public function strategist_preview( $request ) {
		if ( ! class_exists( 'VMSB_Strategist' ) ) {
			return rest_ensure_response( array( 'error' => 'Strategist unavailable.' ) );
		}
		return rest_ensure_response( array( 'plan' => VMSB_Strategist::compute_plan() ) );
	}

	public function tasks_process( $request ) {
		if ( ! class_exists( 'VMSB_Task_Runner' ) ) {
			return rest_ensure_response( array( 'error' => 'Task runner unavailable.' ) );
		}
		return rest_ensure_response( VMSB_Task_Runner::process( (int) $request->get_param( 'limit' ) ?: 3 ) );
	}

	public function quantum_heist( $request ) {
		$res = ( new VMSB_Competitor() )->run_quantum_heist();
		return rest_ensure_response( array( 'stolen' => $res ) );
	}

	public function quantum_blast( $request ) {
		$res = ( new VMSB_Programmatic() )->run_quantum_blast();
		return rest_ensure_response( array( 'queued' => $res ) );
	}

	public function vulture_strike( $request ) {
		$res = ( new VMSB_Competitor() )->run_siphon_scan();
		if ( is_wp_error($res) ) return $res;
		return rest_ensure_response( array( 'strikes' => $res ) );
	}

	public function license_verify( $request ) {
		$key = sanitize_text_field( $request->get_param( 'license_key' ) );
		$ok  = VMSB_License::verify( $key );
		if ( $ok ) {
			return rest_ensure_response( array( 'ok' => true, 'plan' => VMSB_License::plan() ) );
		}
		return new WP_REST_Response( array( 'error' => 'Invalid license key or connection error.' ), 403 );
	}

	public function command( $request ) {
		$input     = (string) $request->get_param( 'input' );
		$commander = new VMSB_Commander();
		return rest_ensure_response( array( 'reply' => $commander->execute( $input ) ) );
	}

	public function aipuffer_bots( $request ) {
		$res = VMSB_AIPuffer::discover_bots();
		return rest_ensure_response( $res['bots'] ?? array() );
	}

	public function health_reset( $request ) {
		VMSB_Health::reset_failures();
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public function verify_ai( $request ) {
		$health = new VMSB_Health();
		return rest_ensure_response( $health->verify_ai_chain() );
	}

	public function test_image_provider( $request ) {
		$engine = new VMSB_Image_Engine();
		$res = $engine->create( 'A simple robot painting on a canvas', array( 'keyword' => 'test' ) );
		return rest_ensure_response( $res );
	}

	public function sync_models( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		if ( $provider ) {
			$models = VMSB_Model_Sync::sync_provider( $provider );
			if ( is_wp_error( $models ) ) {
				return new WP_REST_Response( array( 'error' => $models->get_error_message() ), 422 );
			}
			return rest_ensure_response( array( $provider => $models ) );
		}
		return rest_ensure_response( VMSB_Model_Sync::sync_all() );
	}

	public function opportunity_scan( $request ) {
		$engine = new VMSB_Opportunity_Engine();
		$count = $engine->discover_all();
		$ops = (new VMSB_Brain())->recall('intelligence', 'active_opportunities');
		return rest_ensure_response( array( 'found' => $count, 'opportunities' => $ops ) );
	}

	public function action_rollback( $request ) {
		$id = (int) $request->get_param( 'id' );
		$res = VMSB_Actions::rollback( $id );
		if ( is_wp_error($res) ) return $res;
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Task detail for the queue's "View" modal. The timeline is a JSON
	 * column on the task row itself (see VMSB_Task_Runner::log_event), not
	 * a separate events table.
	 */
	public function task_detail( $request ) {
		global $wpdb;
		$id  = (int) $request->get_param( 'id' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}vmsb_tasks WHERE id = %d", $id ) );
		if ( ! $row ) {
			return new WP_Error( 'vmsb_rest', 'Task not found.', array( 'status' => 404 ) );
		}

		$timeline = json_decode( (string) $row->timeline, true );
		if ( ! is_array( $timeline ) ) {
			$timeline = array();
		}
		// The modal reads every field unguarded, so never hand it a null.
		$timeline = array_map( static function ( $t ) {
			return array(
				'time'  => isset( $t['time'] ) ? (string) $t['time'] : '',
				'event' => isset( $t['event'] ) ? (string) $t['event'] : '',
				'note'  => isset( $t['note'] ) ? (string) $t['note'] : '',
			);
		}, $timeline );

		return rest_ensure_response( array(
			'id'       => (int) $row->id,
			'type'     => class_exists( 'VMSB_Strategist' ) ? VMSB_Strategist::agent_label( $row->task_type ) : (string) $row->task_type,
			'status'   => (string) $row->status,
			'score'    => (float) $row->score,
			'reason'   => (string) $row->reason,
			'error'    => isset( $row->last_error ) ? (string) $row->last_error : '',
			'timeline' => $timeline,
		) );
	}

	public function graph_data( $request ) {
		$limit = (int) $request->get_param( 'limit' ) ?: 100;
		return rest_ensure_response( VMSB_Graph::export_for_visualization( $limit ) );
	}

	public function graph_sync( $request ) {
		VMSB_Graph::build_twin();
		return rest_ensure_response( array( 'synced' => true, 'empty' => VMSB_Graph::is_empty() ) );
	}

	/**
	 * Execute one opportunity from the Growth page. The Opportunity Engine
	 * only ever proposes changes to posts that already exist, so every type
	 * here routes to an existing surgical fixer rather than writing new
	 * content - and anything without a resolvable object_id is refused
	 * rather than silently doing nothing.
	 */
	public function execute_opportunity( $request ) {
		$type      = (string) $request->get_param( 'type' );
		$object_id = (int) $request->get_param( 'object_id' );
		$target    = (string) $request->get_param( 'target' );

		if ( ! $object_id ) {
			return new WP_Error( 'vmsb_rest', 'This opportunity has no target post to act on.', array( 'status' => 400 ) );
		}
		if ( ! get_post( $object_id ) ) {
			return new WP_Error( 'vmsb_rest', "Target post #{$object_id} no longer exists.", array( 'status' => 404 ) );
		}

		switch ( strtoupper( $type ) ) {
			case 'CONVERSION_LEAK':
				$res = ( new VMSB_ROI() )->insert_cta( $object_id );
				break;
			case 'CANNIBALIZATION':
			case 'UPDATE_CONTENT':
			case 'CONTENT_DECAY':
			default:
				// god_fix_90 is the surgical single-post improver the Issues
				// screen uses; it records a revertible action either way.
				$res = ( new VMSB_Fixer() )->god_fix_90( $object_id );
				break;
		}

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		( new VMSB_Logger() )->info( 'intelligence', "Executed opportunity ({$type}) on '{$target}' (post #{$object_id})." );

		return rest_ensure_response( array( 'executed' => true, 'type' => $type, 'object_id' => $object_id, 'result' => $res ) );
	}

	/**
	 * Content Factory's Video-to-Blog Transformer. Accepts a pasted
	 * transcript, or a YouTube URL when no transcript is given - there is no
	 * transcript API wired up here, so a bare URL is answered honestly
	 * instead of quietly inventing an article about a video nobody read.
	 */
	public function video_to_blog( $request ) {
		$url        = trim( (string) $request->get_param( 'video_url' ) );
		$transcript = trim( (string) $request->get_param( 'transcript' ) );

		if ( ! $transcript ) {
			return new WP_Error(
				'vmsb_rest',
				$url
					? 'Paste the video transcript as well - this build cannot fetch captions from a URL on its own.'
					: 'Provide a transcript (and optionally the video URL).',
				array( 'status' => 400 )
			);
		}

		$brain  = new VMSB_Brain();
		$prompt = "Act as an SEO Content Strategist. Turn this video transcript into a publishable article.\n\n"
			. ( $url ? "SOURCE VIDEO: {$url}\n\n" : '' )
			. "TRANSCRIPT:\n" . mb_substr( $transcript, 0, 12000 ) . "\n\n"
			. "TASK: Write a ~1500 word SEO article that stands on its own without the video. Keep every concrete "
			. "fact, figure and example from the transcript; do not invent any that are not there. Use clear H2/H3 "
			. "structure and a natural primary keyword.\n\n"
			. 'Return JSON: {"title":"","primary_keyword":"","content":"","excerpt":""}';

		$data = ( new VMSB_AI_Router() )->generate_json( $prompt, array(
			'system'     => $brain->context_prompt(),
			'complexity' => 'premium',
			'persona'    => 'writer',
			'action'     => 'video_to_blog',
		) );

		if ( ! is_array( $data ) || empty( $data['content'] ) || empty( $data['title'] ) ) {
			return new WP_Error( 'vmsb_rest', 'The transformer could not produce an article from that transcript.', array( 'status' => 502 ) );
		}

		$post_id = wp_insert_post( array(
			'post_title'   => $data['title'],
			'post_content' => $data['content'],
			'post_excerpt' => isset( $data['excerpt'] ) ? $data['excerpt'] : '',
			'post_status'  => 'draft',
			'post_type'    => 'post',
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $url ) {
			update_post_meta( $post_id, '_vmsb_source_video', esc_url_raw( $url ) );
		}
		( new VMSB_Logger() )->info( 'content', "Video-to-Blog created draft post #{$post_id}: {$data['title']}" );

		return rest_ensure_response( array(
			'created' => true,
			'post_id' => $post_id,
			'title'   => $data['title'],
			'edit'    => get_edit_post_link( $post_id, 'raw' ),
		) );
	}

	public function rollback_recent( $request ) {
		global $wpdb;
		$count = (int) $request->get_param( 'count' ) ?: 5;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}vmsb_actions WHERE rollback_status != 'completed' ORDER BY created_at DESC LIMIT %d", $count ) );

		$reverted = 0;
		foreach ( $ids as $id ) {
			if ( ! is_wp_error( VMSB_Actions::rollback($id) ) ) $reverted++;
		}
		return rest_ensure_response( array( 'reverted' => $reverted ) );
	}

	public function evaluate_pivot( $request ) {
		$pivot = (new VMSB_Brain())->evaluate_and_replan();
		return rest_ensure_response( $pivot );
	}

	public function generate_report( $request ) {
		$res = (new VMSB_Reporting())->generate_boardroom_report();
		if ( ! $res ) return new WP_Error( 'vmsb_rest', 'Failed to generate boardroom report.' );
		return rest_ensure_response( array( 'success' => true, 'report' => $res ) );
	}

	public function notifications( $request ) {
		return rest_ensure_response( VMSB_Notifications::get_all() );
	}

	public function global_search( $request ) {
		global $wpdb;
		$q = sanitize_text_field( $request->get_param( 'q' ) );
		if ( strlen($q) < 3 ) return array();

		$results = array();

		// 1. Keywords
		$kws = $wpdb->get_results( $wpdb->prepare( "SELECT keyword, position FROM {$wpdb->prefix}vmsb_keywords WHERE keyword LIKE %s LIMIT 5", '%' . $q . '%' ) );
		foreach ( $kws as $k ) $results[] = array( 'type' => 'Keyword', 'label' => $k->keyword, 'note' => "Pos #{$k->position}", 'url' => admin_url('admin.php?page=vmsb-seo&tab=keywords') );

		// 2. Posts
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE %s AND post_status = 'publish' LIMIT 5", '%' . $q . '%' ) );
		foreach ( $posts as $p ) $results[] = array( 'type' => 'Page', 'label' => $p->post_title, 'note' => 'Published', 'url' => get_edit_post_link($p->ID) );

		// 3. Tasks
		$tasks = $wpdb->get_results( $wpdb->prepare( "SELECT id, task_type, reason FROM {$wpdb->prefix}vmsb_tasks WHERE reason LIKE %s LIMIT 3", '%' . $q . '%' ) );
		foreach ( $tasks as $t ) $results[] = array( 'type' => 'Task', 'label' => VMSB_Strategist::agent_label($t->task_type), 'note' => $t->reason, 'url' => admin_url('admin.php?page=vmsb-production') );

		// 4. Competitors
		$comps = $wpdb->get_results( $wpdb->prepare( "SELECT domain, label FROM {$wpdb->prefix}vmsb_competitors WHERE domain LIKE %s OR label LIKE %s LIMIT 3", '%' . $q . '%', '%' . $q . '%' ) );
		foreach ( $comps as $c ) $results[] = array( 'type' => 'Competitor', 'label' => $c->label ?: $c->domain, 'note' => $c->domain, 'url' => admin_url('admin.php?page=vmsb-seo&tab=competitors') );

		return rest_ensure_response( $results );
	}

	public function task_cancel( $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$wpdb->delete( "{$wpdb->prefix}vmsb_tasks", array( 'id' => $id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}
}
