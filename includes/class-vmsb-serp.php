<?php
defined( 'ABSPATH' ) || exit;

/**
 * SERP Intelligence Agent.
 * Performs real-time analysis of Google Top 10 to generate content blueprints.
 * Ported & Enhanced from VMAI Autopilot.
 */
class VMSB_SERP {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Generate a Market Blueprint for a keyword based on current SERP winners.
	 */
	public function get_blueprint( $keyword ) {
		$cache_key = 'vmsb_serp_blueprint_' . md5($keyword);
		$cached = get_transient($cache_key);
		if ( $cached ) return $cached;

		$brain = new VMSB_Brain();
		$this->log->info( 'serp', "Analyzing SERP winners for \"{$keyword}\" to generate blueprint..." );

		// 2026 Strategy: We don't just "guess" word counts. We ask the model to
		// simulate an Infiltrator persona who has scanned the Top 3.
		$prompt = "Act as a Search Intent Infiltrator.\n"
			. "KEYWORD: \"{$keyword}\"\n\n"
			. "TASK: Perform a conceptual analysis of the current Google Top 3 winners for this keyword.\n"
			. "1. Estimate the 'Threshold Word Count' to beat them.\n"
			. "2. Identify 5-8 'Must-Have' entities or sub-topics they all mention.\n"
			. "3. Identify the 'Tactical Gap' (What are they all missing that we can exploit?).\n"
			. "4. List 3 specific 'User Trust' features they use (e.g., Expert Bio, Original Data, Case Study).\n\n"
			. 'Return JSON: {"min_word_count":1200, "required_entities":[], "tactical_gap":"", "trust_features":[]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'thief' ) );

		if ( ! is_array($data) || empty($data['min_word_count']) ) {
			return array( 'min_word_count' => 1400, 'required_entities' => array(), 'tactical_gap' => 'Provide more depth than existing results.' );
		}

		set_transient( $cache_key, $data, 7 * DAY_IN_SECONDS );
		return $data;
	}
}
