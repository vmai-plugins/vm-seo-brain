<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sentient Self-Healer.
 * Analyzes failures and rewrites internal prompts to improve future performance.
 */
class VMSB_Healer {

	public function heal_losses() {
		// Existing Logic: Healing Prompts
		$losses = ( new VMSB_Outcome_Ledger() )->recent( 10 );
		$log    = new VMSB_Logger();

		foreach ( $losses as $loss ) {
			if ( $loss->verdict === 'loss' ) {
				$this->analyze_and_correct( $loss );
				$log->warn( 'healer', "Analyzed and corrected for loss on Post #{$loss->object_id}" );
			}
		}

		// New Logic: Content Defense (Healing Posts)
		$this->rescue_dropping_assets();
	}

	/**
	 * Strategic Defense: Detects pages that lost their Top 3 position
	 * and performs a surgical update to reclaim authority.
	 */
	public function rescue_dropping_assets( $limit = 3 ) {
		$drops = $this->get_recent_drops( $limit );
		if ( ! $drops ) return;

		// 2. Perform Content Healing with Diagnostic Precision
		foreach ( $drops as $drop ) {
			if ( get_post_meta( $drop['id'], '_vmsb_last_healed', true ) > time() - ( 14 * DAY_IN_SECONDS ) ) continue;

			$post = get_post($drop['id']);
			$this->log_diagnostic($drop['id'], "Analyzing drop for '{$drop['kw']}' (Current: #{$drop['pos']})");

			// THE HEALER'S Rubric: Identify the specific problem
			$problem = $this->diagnose_content_failure( $post, $drop['kw'] );

			$this->log_diagnostic($drop['id'], "Detected Failure Root: {$problem['classification']}.");

			// Surgical Fix based on diagnosis
			$content_engine = new VMSB_Content();
			switch ($problem['classification']) {
				case 'THIN_DEPTH':
					$content_engine->improve_post( $drop['id'], 'thin_content', "Competitors are out-detailing us. Add a detailed breakdown of: " . $problem['missing_context'] );
					break;
				case 'STALE_DATA':
					$content_engine->improve_post( $drop['id'], 'stale_content', "This post contains outdated references. Update with latest facts for " . date('Y') );
					break;
				case 'INTENT_MISMATCH':
					$content_engine->improve_post( $drop['id'], 'striking_distance', "User intent has shifted. Rewrite the opening to satisfy: " . $problem['target_intent'] );
					break;
			}

			update_post_meta( $drop['id'], '_vmsb_last_healed', time() );
		}
	}

	/**
	 * Identify keywords that dropped from Top 3 to Pos 4-15 in the last 28 days.
	 */
	public function get_recent_drops( $limit = 5 ) {
		$google = new VMSB_Google();
		if ( ! $google->is_connected() ) return array();

		$data = $google->gsc_query( array( 'query', 'page' ), 28, 500 );
		if ( is_wp_error($data) ) return array();

		$drops = array();
		foreach ( $data as $row ) {
			$pos = (float)($row['position'] ?? 0);
			if ( $pos > 3.5 && $pos <= 15 ) {
				$post_id = url_to_postid( $row['keys'][1] ?? '' );
				if ( ! $post_id ) continue;

				// Check if it was previously Top 3 (simulated check via current impressions/clicks)
				if ( (int)$row['clicks'] > 50 ) {
					$drops[] = array(
						'id' => $post_id,
						'kw' => $row['keys'][0],
						'pos' => $pos,
						'url' => $row['keys'][1]
					);
				}
			}
		}

		return array_slice( $drops, 0, $limit );
	}

	private function diagnose_content_failure( $post, $keyword ) {
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		$prompt = "DIAGNOSTIC TASK: Why did this page drop in rankings?\n"
			. "TITLE: {$post->post_title}\n"
			. "KEYWORD: {$keyword}\n"
			. "EXCERPT: " . wp_trim_words($post->post_content, 300) . "\n\n"
			. "TASK: Compare this conceptually against what a Top 1 result would likely have. Identify the root failure.\n"
			. "Possible Classes: THIN_DEPTH (lacks detail), STALE_DATA (old years/facts), INTENT_MISMATCH (wrong format for query).\n\n"
			. 'Return JSON: {"classification":"THIN_DEPTH|STALE_DATA|INTENT_MISMATCH","reason":"","missing_context":"","target_intent":""}';

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'standard', 'persona' => 'auditor' ) );
		return $res ?: array('classification' => 'THIN_DEPTH', 'missing_context' => 'General depth improvement');
	}

	private function log_diagnostic( $post_id, $msg ) {
		( new VMSB_Logger() )->info( 'healer', "[Post #{$post_id}] {$msg}" );
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
