<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sentient Self-Healer.
 * Analyzes failures and rewrites internal prompts to improve future performance.
 */
class VMSB_Healer {

	public function heal_losses() {
		$losses = ( new VMSB_Outcome_Ledger() )->recent( 10 );
		$log    = new VMSB_Logger();

		foreach ( $losses as $loss ) {
			if ( $loss->verdict === 'loss' ) {
				$this->analyze_and_correct( $loss );
				$log->warn( 'healer', "Analyzed and corrected for loss on Post #{$loss->object_id}" );
			}
		}
	}

	private function analyze_and_correct( $loss ) {
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		$prompt = "POST-MORTEM: An automated SEO action failed.\n"
			. "Action: {$loss->action} on Post: " . get_the_title( $loss->object_id ) . "\n"
			. "Hypothesis was: {$loss->hypothesis}\n"
			. "Result: Traffic/Ranking dropped.\n\n"
			. "What went wrong? Suggest an improvement for the AI's internal instruction for this module.\n"
			. "Return JSON: {\"critique\":\"\",\"new_instruction_additive\":\"\"}";

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( ! empty( $res['new_instruction_additive'] ) ) {
			// Store this in the brain's "Memory" to be appended to future prompts for this module.
			$brain->remember( 'healer', "lesson_{$loss->action}", $res['new_instruction_additive'], 1.0, 'healer' );
		}
	}
}
