<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cron. Every autonomous behaviour runs from here, and every one of them
 * respects the review and budget settings.
 */
class VMSB_Scheduler {

	const HOURLY   = 'vmsb_cron_hourly';
	const DAILY    = 'vmsb_cron_daily';
	const WEEKLY   = 'vmsb_cron_weekly';

	public function __construct() {
		add_action( self::HOURLY, array( $this, 'run_hourly' ) );
		add_action( self::DAILY, array( $this, 'run_daily' ) );
		add_action( self::WEEKLY, array( $this, 'run_weekly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOURLY ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOURLY );
		}
		if ( ! wp_next_scheduled( self::DAILY ) ) {
			wp_schedule_event( strtotime( 'tomorrow 3:00am' ), 'daily', self::DAILY );
		}
		if ( ! wp_next_scheduled( self::WEEKLY ) ) {
			wp_schedule_event( strtotime( 'next monday 4:00am' ), 'weekly', self::WEEKLY );
		}
	}

	public static function unschedule() {
		foreach ( array( self::HOURLY, self::DAILY, self::WEEKLY ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
	}

	/* ---------------------------------------------------------------- runs */

	public function run_hourly() {
		global $wpdb;
		$content = new VMSB_Content();
		$content->pull_from_sheet();

		// Cleanup: Reset items stuck in 'writing' for > 3 hours (likely timeout)
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}vmsb_plan SET status = 'failed', last_error = 'Writing timeout or server crash.'
				 WHERE status = 'writing' AND updated_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - ( 3 * HOUR_IN_SECONDS ) )
			)
		);

		$due   = $content->due_items( max( 1, (int) ceil( (int) VMSB_Settings::get( 'posts_per_day' ) / 8 ) ) );
		foreach ( $due as $item ) {
			$content->produce( $item->id );
		}

		// Drain the queue - a small, steady trickle rather than a burst inside
		// the daily request. Raised from 2 now that the daily and weekly
		// passes queue their work here instead of running it themselves; the
		// runner's own budget is what actually bounds this, not the number.
		VMSB_Task_Runner::process( 4 );
	}

	/**
	 * Daily pass. Cheap, bounded data collection happens here; anything that
	 * calls a model or walks the whole site is queued for the runner, for the
	 * same reason as run_weekly() above.
	 */
	public function run_daily() {
		$log = new VMSB_Logger();
		update_option( 'vmsb_last_daily_run', time(), false );

		// One API call each, and everything downstream reads what they write,
		// so these stay inline.
		( new VMSB_Growth() )->snapshot();
		( new VMSB_Keywords() )->pull_search_console();
		( new VMSB_Performance() )->snapshot( 100 );

		// Cheap self-check so a broken connection surfaces immediately instead
		// of showing up as "nothing happened" three days later.
		( new VMSB_Health() )->check();

		// Housekeeping: pure DELETEs.
		$log->prune( 45 );
		VMSB_Task_Runner::prune( 14 );
		self::prune_drip_counters( 14 );

		// Everything below used to run right here, in this request.
		VMSB_Task_Runner::queue( 'vector_index', array( 'limit' => 40 ), 95, 'Keep the semantic index current.' );
		VMSB_Task_Runner::queue( 'link_index', array( 'limit' => 60 ), 94, 'Keep the internal link graph current.' );
		VMSB_Task_Runner::queue( 'issue_scan', array( 'limit' => 100 ), 88, 'Daily technical and on-page audit.' );
		VMSB_Task_Runner::queue( 'traffic_forecast', array(), 40, 'Refresh the traffic forecast.' );
		VMSB_Task_Runner::queue( 'sheet_push', array(), 35, 'Mirror the content plan to Google Sheets.' );

		if ( (int) VMSB_Settings::get( 'learning_enabled' ) && class_exists( 'VMSB_Outcome_Ledger' ) ) {
			VMSB_Task_Runner::queue( 'measure_outcomes', array(), 86, 'Measure whether past actions moved anything.' );
		}

		if ( (int) VMSB_Settings::get( 'god_mode' ) ) {
			VMSB_Task_Runner::queue( 'auto_fix_queue', array( 'limit' => 25 ), 82, 'God Mode daily fix pass.' );
		}

		// The strategist scores the optional intelligence agents against this
		// site's actual state and the outcome ledger's earned confidence,
		// drops anything with nothing to do, and queues what is left.
		if ( class_exists( 'VMSB_Strategist' ) ) {
			$plan = VMSB_Strategist::plan_and_queue( 6 );
			$log->info( 'strategist', 'Computed and queued today\'s plan.', array( 'tasks' => wp_list_pluck( $plan, 'task' ) ) );
		}
	}

	/**
	 * Drop the per-day publish counters (vmsb_pub_YYYYMMDD). One is created
	 * every day the plugin publishes and nothing ever removed them, so they
	 * accumulated forever - roughly 365 rows a year, historically autoloaded
	 * on every single request. Only the current day is ever read.
	 */
	private static function prune_drip_counters( $keep_days = 14 ) {
		global $wpdb;

		$cutoff = gmdate( 'Ymd', time() - ( (int) $keep_days * DAY_IN_SECONDS ) );
		$names  = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'vmsb\\_pub\\_%'"
		);

		foreach ( $names as $name ) {
			$stamp = substr( $name, strlen( 'vmsb_pub_' ) );
			// Only touch keys that really are a date stamp, so an unrelated
			// option sharing the prefix is never deleted.
			if ( preg_match( '/^\d{8}$/', $stamp ) && $stamp < $cutoff ) {
				delete_option( $name );
			}
		}
	}

	/**
	 * The weekly pass used to run eight AI-bound operations back to back in
	 * one request - 40 keywords of research, a forced silo map rebuild, a
	 * 20-item content plan, a boardroom report, a 10-domain competitor scan, a
	 * news scout, a possible market assessment and a Brain::decide() - with no
	 * time check anywhere. On any host with a normal max_execution_time it
	 * died partway through, having done some of the work and recorded none of
	 * it.
	 *
	 * The task runner right next door already solved this: a real budget, a
	 * refusal to start work it cannot finish, retries with backoff, and rows
	 * that survive the request dying. So this queues instead of running, and
	 * the hourly drain does the work a couple of items at a time.
	 */
	public function run_weekly() {
		$log = new VMSB_Logger();

		// Cheap and bounded - safe to do inline.
		if ( class_exists( 'VMSB_Outcome_Ledger' ) ) {
			VMSB_Outcome_Ledger::prune( 365 );
		}

		// One request, and it is the only thing that ever re-checks a licence
		// after activation. Without it, verification was a one-time boolean.
		VMSB_License::revalidate();

		$queued = array();

		$queued[] = VMSB_Task_Runner::queue( 'keyword_research', array( 'count' => 40 ), 90, 'Weekly keyword universe refresh.' );
		$queued[] = VMSB_Task_Runner::queue( 'silo_rebuild', array(), 85, 'Weekly silo architecture rebuild.' );
		$queued[] = VMSB_Task_Runner::queue( 'content_plan', array( 'count' => 20 ), 80, 'Weekly editorial plan.' );
		$queued[] = VMSB_Task_Runner::queue( 'link_autopilot', array(), 78, 'Weekly internal linking pass.' );
		$queued[] = VMSB_Task_Runner::queue( 'weekly_roadmap', array(), 60, 'Weekly boardroom report.' );
		$queued[] = VMSB_Task_Runner::queue( 'brain_decide', array(), 55, 'Weekly strategic review.' );

		if ( (int) VMSB_Settings::get( 'competitor_enabled' ) ) {
			$queued[] = VMSB_Task_Runner::queue( 'competitor_scan', array( 'limit' => 10 ), 70, 'Weekly competitor scan.' );
		}

		if ( (int) VMSB_Settings::get( 'news_enabled' ) ) {
			$queued[] = VMSB_Task_Runner::queue( 'news_scout', array(), 50, 'Weekly trend scan.' );
		}

		// A monthly question riding the weekly cron.
		$last_market = (int) get_option( 'vmsb_last_market_assessment', 0 );
		if ( ( time() - $last_market ) > 28 * DAY_IN_SECONDS ) {
			$queued[] = VMSB_Task_Runner::queue( 'market_assess', array(), 45, 'Monthly market assessment.' );
		}

		$log->info( 'scheduler', 'Weekly plan queued.', array( 'tasks' => count( array_filter( $queued ) ) ) );
	}
}
