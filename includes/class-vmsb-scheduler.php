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

		// Drain a few of the strategist's queued intelligence tasks - a small,
		// steady trickle rather than a burst inside the daily request.
		if ( class_exists( 'VMSB_Task_Runner' ) ) {
			VMSB_Task_Runner::process( 2 );
		}
	}

	public function run_daily() {
		$log = new VMSB_Logger();
		update_option( 'vmsb_last_daily_run', time(), false );

		( new VMSB_Growth() )->snapshot();
		( new VMSB_Keywords() )->pull_search_console();
		( new VMSB_Performance() )->snapshot( 100 );

		// Cheap self-check so a broken connection surfaces immediately instead
		// of showing up as "nothing happened" three days later.
		( new VMSB_Health() )->check();

		// Keep the semantic index current before anything reasons off it.
		if ( (int) VMSB_Settings::get( 'vector_enabled' ) && class_exists( 'VMSB_Vector_Store' ) ) {
			$idx = VMSB_Vector_Store::index_batch( 40 );
			$log->info( 'vectors', 'Indexed a batch.', $idx );
		}

		// Measure what past actions actually did, so confidence weights update.
		if ( (int) VMSB_Settings::get( 'learning_enabled' ) && class_exists( 'VMSB_Outcome_Ledger' ) ) {
			$measured = VMSB_Outcome_Ledger::measure_due();
			$log->info( 'learning', 'Measured due outcomes.', $measured );
		}

		$fixer = new VMSB_Fixer();
		$fixer->scan( 100 );

		if ( (int) VMSB_Settings::get( 'god_mode' ) ) {
			$result = $fixer->god_fix( 25 );
			$log->info( 'scheduler', 'God Mode daily pass.', $result );
		}

		// The heavier optional intelligence work (AEO, entity, ROI, schema,
		// CTR conclusion, backlink shield) no longer runs synchronously here
		// in a fixed order. The strategist scores each against this site's
		// actual state and the outcome ledger's earned confidence, drops
		// anything with nothing to do, and queues what's left - the hourly
		// cron drains a couple at a time, so one daily request never risks a
		// timeout running everything at once.
		if ( class_exists( 'VMSB_Strategist' ) && class_exists( 'VMSB_Task_Runner' ) ) {
			$plan = VMSB_Strategist::plan_and_queue( 6 );
			$log->info( 'strategist', 'Computed and queued today\'s plan.', array( 'tasks' => wp_list_pluck( $plan, 'task' ) ) );
		}

		// Refresh the traffic forecast from the metrics history that just grew.
		( new VMSB_Forecaster() )->forecast();

		( new VMSB_Content() )->push_to_sheet();
		$log->prune( 45 );

		if ( class_exists( 'VMSB_Task_Runner' ) ) {
			VMSB_Task_Runner::prune( 14 );
		}

		self::prune_drip_counters( 14 );
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

	public function run_weekly() {
		$keywords = new VMSB_Keywords();
		$keywords->research( 40 );

		( new VMSB_Silo() )->generate_map( true );
		( new VMSB_Content() )->plan( 20 );

		// Generate the executive narrative
		( new VMSB_Reporting() )->generate_boardroom_report();

		if ( (int) VMSB_Settings::get( 'competitor_enabled' ) ) {
			( new VMSB_Competitor() )->scan( 10 );
		}

		if ( (int) VMSB_Settings::get( 'news_enabled' ) ) {
			( new VMSB_News() )->scout( 5 );
		}

		// Market assessment is a monthly-cadence question riding the weekly
		// cron - gated internally so it doesn't re-run every single week.
		$last_market = (int) get_option( 'vmsb_last_market_assessment', 0 );
		if ( ( time() - $last_market ) > 28 * DAY_IN_SECONDS ) {
			( new VMSB_Market() )->assess();
			update_option( 'vmsb_last_market_assessment', time(), false );
		}

		if ( class_exists( 'VMSB_Outcome_Ledger' ) ) {
			VMSB_Outcome_Ledger::prune( 365 );
		}

		$brain = new VMSB_Brain();
		$brain->decide(
			array(
				'issues'   => ( new VMSB_Fixer() )->counts(),
				'growth'   => ( new VMSB_Growth() )->status(),
				'keywords' => array( 'total' => $keywords->count(), 'new' => $keywords->count( 'new' ) ),
				'content'  => ( new VMSB_Content() )->stats(),
			)
		);
	}
}
