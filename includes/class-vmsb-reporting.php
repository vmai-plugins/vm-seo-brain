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
	 * Generate a high-fidelity "Boardroom Briefing" for the current month.
	 */
	public function generate_boardroom_report() {
		$growth  = new VMSB_Growth();
		$status  = $growth->status();
		$summary = ( new VMSB_Performance() )->business_summary();
		$blitz   = VMSB_Outcome_Ledger::calculate_blitz_value();
		$lifecycle = VMSB_Lifecycle::get_stats();

		$prompt = "Act as an SEO Agency Director presenting to the Board of Directors.\n\n"
			. "MONTHLY PERFORMANCE SNAPSHOT:\n"
			. "- Total Organic Clicks: " . number_format($summary['clicks_this_month']) . " (Change: {$summary['pct_change']}%)\n"
			. "- Clicks Gained via AI: " . number_format($blitz['net_clicks']) . "\n"
			. "- Estimated PPC Value Saved: $" . number_format($blitz['estimated_value']) . "\n"
			. "- Progress toward 50,000 traffic goal: {$status['pct_of_target']}%\n"
			. "- Content Portfolio Health: " . (int)($lifecycle['stats']['growing'] ?? 0) . " pages growing, " . (int)($lifecycle['stats']['decaying'] ?? 0) . " decaying.\n\n"
			. "TASK: Generate a world-class strategic narrative for the Boardroom.\n"
			. "1. Executive Summary: The single most important financial takeaway.\n"
			. "2. Authority Dominance: How much market share we captured this month.\n"
			. "3. ROI Analysis: The dollar value of the organic visibility we built.\n"
			. "4. Primary Risk: The biggest threat to next month's growth.\n"
			. "5. Boardroom Verdict: The Brain's bottom-line recommendation.\n\n"
			. "Tone: Boardroom-ready, financial, predatory yet professional. Avoid technical SEO jargon.";

		$res = $this->ai->generate( $prompt, array( 'system' => $this->brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'strategist' ) );

		if ( ! empty($res['ok']) ) {
			update_option( 'vmsb_boardroom_report', array(
				'text' => $res['text'],
				'date' => current_time( 'mysql' ),
				'stats' => array(
					'clicks' => $summary['clicks_this_month'],
					'value'  => $blitz['estimated_value'],
					'roi'    => $summary['pct_change']
				)
			) );
			return $res['text'];
		}
		return false;
	}
}
