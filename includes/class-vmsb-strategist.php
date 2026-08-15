<?php
defined( 'ABSPATH' ) || exit;

/**
 * The strategist.
 *
 * Before this, the daily cron ran every enabled optional module in the same
 * fixed order every day - AEO sweep, then entity sweep, then ROI scan, and so
 * on - regardless of whether any of them had real work to do, and all inside
 * one synchronous request. Two problems: a site with nothing for the ROI
 * scanner to find still paid for the AI call, and a site with everything
 * enabled risked a PHP timeout running ten operations in one request.
 *
 * This scores each optional task as:
 *
 *     priority = expected_impact x module_confidence / effort
 *
 * expected_impact comes from the live state of this site (are there actually
 * open leaks, is the vector index current enough for entity work to make
 * sense). module_confidence comes from the outcome ledger - what has actually
 * produced a measurable win on this site. Tasks scoring zero are dropped
 * entirely. The result is queued via VMSB_Task_Runner and drained a few at a
 * time on the hourly cron instead of run synchronously.
 */
class VMSB_Strategist {

	/**
	 * task_type => [ module_for_confidence, effort_weight, base_impact ]
	 */
	private static function catalogue() {
		return array(
			'aeo_sweep'        => array( 'aeo',        1.5, 40 ),
			'entity_sweep'     => array( 'entity',     1.5, 40 ),
			'schema_sweep'     => array( 'schema',      1.0, 30 ),
			'roi_scan'         => array( 'roi',         1.0, 45 ),
			'roi_sweep'        => array( 'roi',         1.5, 40 ),
			'ctr_conclude'     => array( 'ctr',         0.5, 35 ),
			'backlink_shield'  => array( 'backlinks',   1.0, 20 ),
			'weekly_roadmap'   => array( 'brain',       2.0, 60 ),
			'market_assess'    => array( 'brain',       1.5, 40 ),
			'improvement_loop' => array( 'brain',       1.0, 50 ),
			'graph_sync'       => array( 'graph',       1.0, 30 ),
			'thief_scout'      => array( 'thief',       1.5, 45 ),
			'trend_scout'      => array( 'brain',       1.5, 50 ),
			'link_rebalance'   => array( 'silo',        1.0, 50 ),
			'battle_roadmap'   => array( 'brain',       2.0, 70 ),
			'content_duel'     => array( 'entity',      1.5, 55 ),
			'self_heal'        => array( 'brain',       1.0, 60 ),
			'auto_fix_queue'   => array( 'fixer',       1.5, 65 ),
			'silo_integrity'   => array( 'silo',        1.5, 50 ),
			'niche_expansion'  => array( 'niche',       2.5, 75 ),
			'monitor_decay'    => array( 'brain',       1.0, 55 ),
			'social_recycle'   => array( 'content',     1.0, 60 ),
			'link_autopilot'   => array( 'silo',        1.0, 50 ),
			'competitor_blitz' => array( 'thief',       2.0, 80 ),
			'hydrate_pipeline' => array( 'content',     0.5, 40 ),
			'sheet_sync'       => array( 'content',     0.5, 30 ),
			'growth_scan'      => array( 'growth',      1.0, 55 ),
			'content_defense'  => array( 'brain',       1.5, 75 ),
			'semantic_mesh'    => array( 'silo',        1.0, 50 ),
			'video_pipeline'   => array( 'content',     2.0, 60 ),
			'freshness_boost'  => array( 'brain',       1.0, 55 ),
			'opportunity_scan' => array( 'brain',       1.0, 50 ),
		);
	}

	/**
	 * @return array<int,array{task:string,score:float,reason:string}>
	 */
	/**
	 * Each task's underlying feature toggle - a task never gets scored, let
	 * alone queued, if its feature is switched off. The strategist decides
	 * *when* to run something that's enabled; it never overrides the toggle
	 * that decides *whether* to run it at all.
	 */
	private static function toggle_for( $task ) {
		return array(
			'aeo_sweep'       => 'aeo_enabled',
			'entity_sweep'    => 'entity_enabled',
			'schema_sweep'    => null, // schema backfill has no separate toggle - always safe
			'roi_scan'        => 'roi_enabled',
			'roi_sweep'       => 'roi_enabled',
			'ctr_conclude'    => 'ctr_test_enabled',
			'backlink_shield' => null, // read-only risk detection, safe regardless of outreach toggle
			'weekly_roadmap'  => 'god_mode',
			'market_assess'   => 'god_mode',
			'improvement_loop'=> 'god_mode',
			'graph_sync'      => 'vector_enabled',
			'thief_scout'     => 'competitor_enabled',
			'trend_scout'     => 'news_enabled',
			'link_rebalance'  => 'god_mode',
			'battle_roadmap'  => 'god_mode',
			'content_duel'    => 'entity_enabled',
			'self_heal'       => 'learning_enabled',
			'auto_fix_queue'  => 'god_mode',
			'silo_integrity'  => 'god_mode',
			'niche_expansion' => 'god_mode',
			'hydrate_pipeline'=> 'god_mode',
			'competitor_blitz'=> 'competitor_enabled',
			'sheet_sync'      => 'google_refresh_token',
			'growth_scan'     => 'auto_growth_mode',
			'content_defense' => 'learning_enabled',
			'semantic_mesh'   => 'vector_enabled',
			'video_pipeline'  => 'content_enabled',
			'freshness_boost' => 'god_mode',
		)[ $task ] ?? null;
	}

	public static function compute_plan() {
		$state   = self::site_state();
		$weights = class_exists( 'VMSB_Outcome_Ledger' ) ? VMSB_Outcome_Ledger::confidence_weights() : array();
		$plan    = array();

		// World-Class Dev Ops: AI Budget Awareness
		$router = new VMSB_AI_Router();
		$budget_spent_pct = ($router->calls_today() / max(1, (int)VMSB_Settings::get('max_ai_calls_day'))) * 100;
		$is_budget_critical = $budget_spent_pct > 80;

		foreach ( self::catalogue() as $task => $spec ) {
			$toggle = self::toggle_for( $task );
			if ( $toggle && ! (int) VMSB_Settings::get( $toggle ) ) {
				continue;
			}

			list( $module, $effort, $base ) = $spec;

			$impact = self::expected_impact( $task, $base, $state );
			if ( $impact <= 0 ) {
				continue;
			}

			$confidence = isset( $weights[ $module ] ) ? (float) $weights[ $module ] : 1.0;

			// Dynamic Score Scaling
			$score = ( $impact * $confidence ) / max( 0.5, $effort );

			// Budget Throttling: Deprioritize secondary tasks if low on tokens
			if ( $is_budget_critical && in_array($task, ['social_recycle', 'backlink_shield', 'aeo_sweep']) ) {
				$score *= 0.2;
			}

			// Authority Blitz: Boost production if behind on 1000-post goal
			if ( $state['published_posts'] < 3000 && $task === 'hydrate_pipeline' ) {
				$score *= 3.0; // TRIPLE priority for high-velocity sites
			}

			$plan[] = array(
				'task'       => $task,
				'module'     => $module,
				'score'      => round( $score, 2 ),
				'confidence' => round( min( 1.0, $confidence / 1.5 ) * 100 ),
				'reason'     => self::reason_for( $task, $impact, $confidence, $state ),
				'explanation' => self::explain_task( $task, $state ),
			);
		}

		usort( $plan, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return $plan;
	}

	private static function explain_task( $task, $state ) {
		$explanations = array(
			'aeo_sweep'        => 'Optimizes content structure to satisfy AI search engines and answer boxes.',
			'entity_sweep'     => 'Injects missing semantic entities to build topical authority in your niche.',
			'schema_sweep'     => 'Adds missing structured data to help Google understand page content.',
			'roi_scan'         => 'Identifies high-traffic pages with weak conversion paths.',
			'roi_sweep'        => 'Injects targeted call-to-actions into pages where users are dropping off.',
			'ctr_conclude'     => 'Completes running A/B tests and commits the winning variations.',
			'backlink_shield'  => 'Protects your site authority by monitoring for dead or toxic outbound links.',
			'weekly_roadmap'   => 'Generates a fresh weekly performance report via the Commander.',
			'market_assess'    => "Reassesses the niche's competitive saturation and updates the business DNA.",
			'improvement_loop' => 'Reviews recent losses in the outcome ledger and heals the prompts that caused them.',
			'graph_sync'       => "Rebuilds the site's semantic knowledge graph (Digital Twin) so entity/AEO work stays accurate.",
			'thief_scout'      => 'Scouts tracked rivals for rankings your site could realistically hijack.',
			'trend_scout'      => 'Watches RSS/Trends signals for timely angles worth news-jacking.',
			'link_rebalance'   => 'Redistributes internal link authority toward pages that are close to breaking through.',
			'battle_roadmap'   => "Builds a day-by-day plan toward the site's growth_target traffic goal.",
			'content_duel'     => "Pits a striking-distance page against a rival's equivalent to find the gap.",
			'self_heal'        => 'Same healing pass as Improvement Loop, triggered independently when learning is enabled.',
			'auto_fix_queue'   => 'Drains the open technical/on-page issues queue automatically.',
			'silo_integrity'   => "Finds the weakest content silo and queues supporting posts to reinforce it.",
			'niche_expansion'  => 'Expands the keyword universe into adjacent, unclaimed territory.',
			'monitor_decay'    => 'Flags pages that are losing traffic before the drop becomes serious.',
			'content_defense'  => 'Strategic monitor that auto-heals posts that have lost their Top 3 position.',
			'semantic_mesh'    => 'Builds a dense web of internal links based on semantic vector distance.',
			'video_pipeline'   => 'Generates YouTube scripts and video AI prompts for your best-performing content.',
			'freshness_boost'  => 'Injects real-time "Live Intelligence Updates" into high-traffic content to signal authority.',
			'opportunity_scan' => 'Runs the 21-step discovery cycle to identify growth gaps.',
			'social_recycle'   => 'Repackages recent posts into a social distribution pack (LinkedIn/X/Facebook/YouTube).',
			'link_autopilot'   => 'Funnels internal link authority toward rising pages automatically.',
			'competitor_blitz' => 'Aggressively targets keywords where competitors are ranking but vulnerable.',
			'hydrate_pipeline' => 'Fills in missing keyword/brief detail on thin plan rows before they go to production.',
			'sheet_sync'       => 'Checks for posts that were published outside the plugin and reconciles the sheet.',
			'growth_scan'      => 'Scans Search Console gaps, competitor gaps, and thin silos for new topic ideas and queues them for your approval - it never writes anything on its own.',
		);
		return $explanations[ $task ] ?? 'Autonomous maintenance task.';
	}

	/**
	 * Friendly display name for a task type - the roster shown on the Agents
	 * dashboard. Purely cosmetic; the task_type string is still what the rest
	 * of the system (queue, toggles, logs) actually keys on.
	 */
	public static function agent_label( $task ) {
		$labels = array(
			'aeo_sweep'        => 'AEO Specialist',
			'entity_sweep'     => 'Entity Specialist',
			'schema_sweep'     => 'Schema Specialist',
			'roi_scan'         => 'ROI Scout',
			'roi_sweep'        => 'Conversion Optimizer',
			'ctr_conclude'     => 'CTR Analyst',
			'backlink_shield'  => 'Backlink Guardian',
			'weekly_roadmap'   => 'Strategy Reporter',
			'market_assess'    => 'Market Analyst',
			'improvement_loop' => 'Self-Healer',
			'graph_sync'       => 'Knowledge Graph Sync',
			'thief_scout'      => 'Competitor Scout',
			'trend_scout'      => 'Trend Scout',
			'link_rebalance'   => 'Link Flow Engineer',
			'battle_roadmap'   => 'Growth Roadmapper',
			'content_duel'     => 'Content Duelist',
			'self_heal'        => 'Prompt Healer',
			'auto_fix_queue'   => 'Auto-Fixer',
			'silo_integrity'   => 'Silo Architect',
			'niche_expansion'  => 'Niche Explorer',
			'monitor_decay'    => 'Decay Monitor',
			'social_recycle'   => 'Social Recycler',
			'link_autopilot'   => 'Link Autopilot',
			'competitor_blitz' => 'Competitor Blitzer',
			'hydrate_pipeline' => 'Pipeline Hydrator',
			'sheet_sync'       => 'Sheet Sync Agent',
			'growth_scan'      => 'Growth Scanner',
			'content_defense'  => 'Strategic Defender',
			'semantic_mesh'    => 'Semantic Mesher',
			'video_pipeline'   => 'Video Producer',
			'freshness_boost'  => 'Freshness Scout',
			'opportunity_scan' => 'Opportunity Engine',
		);
		return isset( $labels[ $task ] ) ? $labels[ $task ] : ucwords( str_replace( '_', ' ', $task ) );
	}

	/**
	 * The Agent Fleet as shown on the Agents dashboard: every catalogued task
	 * merged with its current toggle state and its most recent run from the
	 * task-runner queue. Read-only - this never queues or runs anything.
	 */
	public static function fleet_status() {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_tasks';

		$latest_rows = $wpdb->get_results(
			"SELECT t1.* FROM {$table} t1
			 INNER JOIN ( SELECT task_type, MAX(id) AS max_id FROM {$table} GROUP BY task_type ) t2
			 ON t1.task_type = t2.task_type AND t1.id = t2.max_id"
		);
		$latest_by_type = array();
		foreach ( $latest_rows as $row ) {
			$latest_by_type[ $row->task_type ] = $row;
		}

		$counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} WHERE status IN ('queued','running') GROUP BY status" );
		$queued = 0;
		$running = 0;
		foreach ( $counts as $c ) {
			if ( 'queued' === $c->status ) {
				$queued = (int) $c->n;
			} elseif ( 'running' === $c->status ) {
				$running = (int) $c->n;
			}
		}

		$fleet = array();
		foreach ( self::catalogue() as $task => $spec ) {
			$toggle  = self::toggle_for( $task );
			$enabled = ! $toggle || (int) VMSB_Settings::get( $toggle );
			$latest  = isset( $latest_by_type[ $task ] ) ? $latest_by_type[ $task ] : null;

			$fleet[] = array(
				'task'        => $task,
				'label'       => self::agent_label( $task ),
				'module'      => $spec[0],
				'explanation' => self::explain_task( $task, array() ),
				'enabled'     => (bool) $enabled,
				'status'      => $latest ? $latest->status : 'idle',
				'last_ran'    => $latest ? $latest->ran_at : null,
				'last_reason' => $latest ? $latest->reason : null,
				'last_ok'     => ! $latest || 'failed' !== $latest->status,
				'last_score'  => $latest ? (float) $latest->score : 0,
			);
		}

		usort( $fleet, static fn( $a, $b ) => $b['last_score'] <=> $a['last_score'] );

		return array( 'fleet' => $fleet, 'queued' => $queued, 'running' => $running );
	}

	private static function site_state() {
		global $wpdb;
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$types_sql  = "'" . implode( "','", array_map( 'esc_sql', $safe_types ) ) . "'";

		$total_published = 0;
		foreach ( $safe_types as $type ) {
			$counts = wp_count_posts( $type );
			$total_published += (int) ($counts->publish ?? 0);
		}

		return array(
			'roi_leaks_open'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_issues WHERE rule = 'roi_leak' AND status = 'open'" ),
			'vectors_pending'  => class_exists( 'VMSB_Vector_Store' ) ? VMSB_Vector_Store::pending_count() : 0,
			'ctr_running_due'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_experiments WHERE status = 'running' AND concludes_at <= UTC_TIMESTAMP()" ),
			'published_posts'  => $total_published,
			'gsc_connected'    => ( new VMSB_Google() )->is_connected(),
			'competitors_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_competitors WHERE status = 'active'" ),
			// Check both native and Rank Math FAQ schema, same as VMSB_Schema::sweep(),
			// so an already-covered post via Rank Math isn't miscounted as missing.
			'schema_missing'   => (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m1 ON m1.post_id = p.ID AND m1.meta_key = '_vmsb_faq_schema'
				 LEFT JOIN {$wpdb->postmeta} m2 ON m2.post_id = p.ID AND m2.meta_key = 'rank_math_schema_FAQPage'
				 WHERE p.post_status = 'publish' AND p.post_type IN ({$types_sql}) AND m1.meta_id IS NULL AND m2.meta_id IS NULL"
			),
		);
	}

	private static function expected_impact( $task, $base, array $state ) {
		switch ( $task ) {
			case 'aeo_sweep':
			case 'entity_sweep':
				// Both reason better once the vector index is roughly current -
				// a badly stale index makes entity gap-detection unreliable.
				return $state['vectors_pending'] > 30 ? (int) ( $base * 0.4 ) : $base;

			case 'schema_sweep':
				return $state['schema_missing'] > 0 ? min( 100, $base + $state['schema_missing'] * 2 ) : 0;

			case 'roi_scan':
				return $state['gsc_connected'] && $state['published_posts'] >= 5 ? $base : 0;

			case 'roi_sweep':
				return $state['roi_leaks_open'] > 0 ? min( 100, $base + $state['roi_leaks_open'] * 5 ) : 0;

			case 'ctr_conclude':
				return $state['ctr_running_due'] > 0 ? min( 100, $base + $state['ctr_running_due'] * 10 ) : 0;

			case 'backlink_shield':
				return $state['published_posts'] >= 3 ? $base : 0;

			case 'weekly_roadmap':
				// Run roughly once a week
				$last_roadmap = (int) get_option( 'vmsb_last_roadmap', 0 );
				return ( time() - $last_roadmap ) > ( 6 * DAY_IN_SECONDS ) ? $base : 0;

			case 'market_assess':
				$last_market = (int) get_option( 'vmsb_last_market_assessment_task', 0 );
				return ( time() - $last_market ) > ( 14 * DAY_IN_SECONDS ) ? $base : 0;

			case 'improvement_loop':
				// Run if we have fresh losses to learn from
				$counts = class_exists( 'VMSB_Outcome_Ledger' ) ? VMSB_Outcome_Ledger::counts() : array();
				return ( $counts['losses'] ?? 0 ) > 0 ? $base : 0;

			case 'graph_sync':
				// Run if graph is stale
				$last_graph = (int) get_option( 'vmsb_last_graph_sync', 0 );
				return ( time() - $last_graph ) > DAY_IN_SECONDS ? $base : 0;

			case 'thief_scout':
				// Run if competitors are set
				return $state['gsc_connected'] ? $base : 0;

			case 'competitor_blitz':
				// Run if we have competitors and enough content
				return ( $state['competitors_count'] ?? 0 ) > 0 && $state['published_posts'] > 20 ? $base : 0;

			case 'trend_scout':
				// Run if trends/news enabled
				return (int) VMSB_Settings::get( 'news_enabled' ) ? $base : 0;

			case 'link_rebalance':
				return $state['published_posts'] > 10 ? $base : 0;

			case 'battle_roadmap':
				return empty( get_option( 'vmsb_battle_plan' ) ) ? $base : (int) ( $base * 0.2 );

			case 'content_duel':
				return $state['published_posts'] > 5 ? $base : 0;

			case 'self_heal':
				return (int) VMSB_Settings::get( 'learning_enabled' ) ? $base : 0;

			case 'auto_fix_queue':
				$counts = class_exists( 'VMSB_Fixer' ) ? ( new VMSB_Fixer() )->counts() : array();
				return ( $counts['total'] ?? 0 ) > 0 ? $base : 0;

			case 'silo_integrity':
				// Run roughly once a week or if graph is new
				$last_silo_check = (int) get_option( 'vmsb_last_silo_integrity', 0 );
				return ( time() - $last_silo_check ) > ( 7 * DAY_IN_SECONDS ) ? $base : 0;

			case 'niche_expansion':
				// Run monthly to find new territories
				$last_niche = (int) get_option( 'vmsb_last_niche_expansion', 0 );
				return ( time() - $last_niche ) > ( 25 * DAY_IN_SECONDS ) ? $base : 0;

			case 'sheet_sync':
				return $state['gsc_connected'] ? $base : 0;

			case 'content_defense':
				return $state['published_posts'] >= 5 ? $base : 0;

			case 'semantic_mesh':
				return $state['published_posts'] >= 10 ? $base : 0;

			case 'video_pipeline':
				return $state['published_posts'] >= 5 ? $base : 0;

			case 'freshness_boost':
				return $state['published_posts'] >= 3 ? $base : 0;

			case 'growth_scan':
				$last = (int) get_option( 'vmsb_last_growth_scan', 0 );
				if ( ( time() - $last ) < DAY_IN_SECONDS ) {
					return 0;
				}
				// Behind pace on the traffic target? Scan harder for opportunities.
				$growth_status = ( new VMSB_Growth() )->status();
				return empty( $growth_status['on_track'] ) ? min( 100, $base + 20 ) : $base;

			default:
				return $base;
		}
	}

	private static function reason_for( $task, $impact, $confidence, array $state ) {
		$parts = array( sprintf( 'impact %d', (int) $impact ) );
		if ( abs( $confidence - 1.0 ) > 0.01 ) {
			$parts[] = sprintf( 'confidence %.2f', $confidence );
		}
		switch ( $task ) {
			case 'roi_sweep':    $parts[] = $state['roi_leaks_open'] . ' open leaks'; break;
			case 'ctr_conclude': $parts[] = $state['ctr_running_due'] . ' tests due'; break;
			case 'schema_sweep': $parts[] = $state['schema_missing'] . ' posts missing schema'; break;
			case 'improvement_loop': $parts[] = 'learning from losses'; break;
			case 'graph_sync':   $parts[] = 'Digital Twin out of sync'; break;
			case 'thief_scout':  $parts[] = 'scouting competitor gaps'; break;
			case 'trend_scout':  $parts[] = 'news-jacking live signals'; break;
			case 'link_rebalance': $parts[] = 'funneling juice to rising stars'; break;
			case 'battle_roadmap': $parts[] = 'architecting the 50-day growth plan'; break;
			case 'content_duel': $parts[] = 'analyzing top 3 competitor gaps'; break;
			case 'self_heal':    $parts[] = 'healing broken prompts based on outcomes'; break;
			case 'auto_fix_queue': $parts[] = 'draining the 4000+ issues queue'; break;
			case 'silo_integrity': $parts[] = 'plugging authority gaps in weak silos'; break;
			case 'monitor_decay':  $parts[] = 'rescuing traffic-dropping pages'; break;
			case 'content_defense': $parts[] = 'healing Top 3 drops'; break;
			case 'semantic_mesh':   $parts[] = 'weaving internal link web'; break;
			case 'video_pipeline':  $parts[] = 'generating viral production packs'; break;
			case 'freshness_boost': $parts[] = 'signaling QDF authority'; break;
			case 'thief_scout':    $parts[] = 'hijacking competitor gaps'; break;
			case 'social_recycle': $parts[] = 'generating social distribution packs'; break;
			case 'link_autopilot': $parts[] = 'funneling Juice to rising stars'; break;
			case 'competitor_blitz': $parts[] = 'hijacking top rival rankings'; break;
			case 'sheet_sync':     $parts[] = 'checking for externally published posts'; break;
			case 'growth_scan':    $parts[] = 'scanning for new topic suggestions toward the growth target'; break;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Compute today's plan and queue it via the task runner, capped so a
	 * single day never queues more than a handful of AI-heavy operations.
	 */
	public static function plan_and_queue( $max_tasks = 6 ) {
		$plan = array_slice( self::compute_plan(), 0, $max_tasks );
		foreach ( $plan as $item ) {
			VMSB_Task_Runner::queue( $item['task'], array(), $item['score'], $item['reason'] );
		}
		return $plan;
	}
}
