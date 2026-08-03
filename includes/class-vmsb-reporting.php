<?php
defined( 'ABSPATH' ) || exit;

/**
 * Executive Reporting Engine.
 * Translates complex SEO metrics into "Plain English" growth narratives.
 */
class VMSB_Reporting {

	private $ai;
	private $brain;

	public function __construct() {
		$this->ai    = new VMSB_AI_Router();
		$this->brain = new VMSB_Brain();
	}

	/**
	 * Generate a weekly performance narrative.
	 */
	public function generate_weekly_summary() {
		$growth = new VMSB_Growth();
		$status = $growth->status();
		$summary = ( new VMSB_Performance() )->business_summary();
		$outcomes = class_exists('VMSB_Outcome_Ledger') ? VMSB_Outcome_Ledger::counts() : array();

		$prompt = "Generate a weekly executive SEO summary for the business owner.\n\n"
			. "STATS THIS WEEK:\n"
			. "- Clicks: {$summary['clicks_this_month']}\n"
			. "- Growth Target Progress: {$status['pct_of_target']}%\n"
			. "- Success Rate of AI actions: " . ( $outcomes['wins'] ?? 0 ) . " wins vs " . ( $outcomes['losses'] ?? 0 ) . " losses.\n\n"
			. "TASK: Write a 3-paragraph report in plain English. Paragraph 1: Headline achievement. Paragraph 2: What worked and what failed. Paragraph 3: The strategic focus for next week.\n"
			. "Tone: Expert, direct, no jargon.";

		$res = $this->ai->generate( $prompt, array( 'system' => $this->brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( $res['ok'] ) {
			update_option( 'vmsb_weekly_narrative', array(
				'text' => $res['text'],
				'date' => current_time( 'mysql' )
			) );
			return $res['text'];
		}

		return "Report pending data sync.";
	}
}
