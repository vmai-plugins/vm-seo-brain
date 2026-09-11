<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP-CLI Commands for VM SEO Brain.
 * Perfect for VPS environments to bypass web timeouts.
 */
class VMSB_CLI {

	/**
	 * Run the autonomous SEO cycle.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : How many tasks to process. Default 5.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vmsb run --limit=10
	 */
	public function run( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 5;
		WP_CLI::line( "Starting VM SEO Brain Autonomous Cycle (Limit: {$limit})..." );

		if ( ! class_exists( 'VMSB_Task_Runner' ) ) {
			WP_CLI::error( "Task Runner not found." );
		}

		// 1. Plan new tasks if queue is empty.
		$pending = VMSB_Task_Runner::pending_count();
		if ( $pending === 0 ) {
			WP_CLI::line( "Queue empty. Computing new strategic plan..." );
			$plan = VMSB_Strategist::plan_and_queue( $limit );
			WP_CLI::success( "Queued " . count( $plan ) . " new strategic tasks." );
		}

		// 2. Process the queue.
		$batch = VMSB_Task_Runner::process( $limit );

		// process() returns array( 'ran' =>, 'results' =>, ['skipped' =>] ) -
		// this used to foreach over $batch itself (the wrapper), not
		// $batch['results'] (the actual per-task list), so every iteration
		// value was either the 'ran' count (an int) or the 'results' array
		// as one single value - neither has 'ok'/'task' keys, and if a
		// concurrent batch was already running, 'skipped' is a plain string,
		// which PHP 8 throws a fatal TypeError on for ['ok'] string-offset
		// access rather than just warning.
		if ( ! empty( $batch['skipped'] ) ) {
			WP_CLI::line( "⏭️  " . $batch['skipped'] );
		}
		foreach ( (array) ( $batch['results'] ?? array() ) as $res ) {
			$status = ! empty( $res['ok'] ) ? "✅" : "❌";
			WP_CLI::line( "{$status} Task: " . ( $res['task'] ?? '?' ) . " - " . ( $res['message'] ?? '' ) );
		}

		WP_CLI::success( "Cycle complete." );
	}

	/**
	 * Perform a full site audit.
	 */
	public function scan( $args, $assoc_args ) {
		WP_CLI::line( "Scanning site for SEO issues..." );
		$fixer = new VMSB_Fixer();
		$found = $fixer->scan( 500 );
		WP_CLI::success( "Scan complete. Found {$found} open issues." );
	}

	/**
	 * Talk to the Sentient Commander.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vmsb chat "/report"
	 */
	public function chat( $args, $assoc_args ) {
		$input     = implode( ' ', $args );
		$commander = new VMSB_Commander();
		$reply     = $commander->execute( $input );
		WP_CLI::line( "---" );
		WP_CLI::line( $reply );
		WP_CLI::line( "---" );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'vmsb', 'VMSB_CLI' );
}
