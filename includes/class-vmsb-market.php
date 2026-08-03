<?php
defined( 'ABSPATH' ) || exit;

/**
 * Market intelligence.
 *
 * Answers a question none of the other modules do: given everything else the
 * brain knows (our keyword footprint, tracked competitors, the business
 * profile), is this market saturated, growing, or under-served right now -
 * and does that change where effort should go this month. This is a monthly
 * question, not a daily one; it feeds the brain's memory (and so future
 * decide() calls) rather than triggering its own actions.
 */
class VMSB_Market {

	private $ai;

	public function __construct() {
		$this->ai = new VMSB_AI_Router();
	}

	/**
	 * @return array{saturation:string,opportunity_areas:array,recommendation:string}
	 */
	public function assess() {
		global $wpdb;

		$brain       = new VMSB_Brain();
		$profile     = $brain->profile();
		$keywords    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_keywords" );
		$ranking     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_keywords WHERE position <= 10" );
		$competitors = ( new VMSB_Competitor() )->list_all();

		$competitor_summary = array_map(
			static fn( $c ) => array( 'domain' => $c->domain, 'overlap' => $c->overlap_score ),
			array_slice( $competitors, 0, 8 )
		);

		$prompt = "Business: {$profile['name']} — {$profile['services']}\n"
			. "We track {$keywords} keywords, ranking top-10 for {$ranking} of them.\n"
			. 'Tracked competitors and overlap: ' . wp_json_encode( $competitor_summary ) . "\n\n"
			. "Assess this market: is it saturated (many strong competitors, hard to gain ground), growing (room for a new entrant), or under-served "
			. "(few good options, real opportunity)? What specific sub-areas of this business's offering look like the best opportunity right now, "
			. "and what should NOT be prioritised because competitors already dominate it?\n\n"
			. 'Return JSON: {"saturation":"saturated|growing|underserved|mixed","opportunity_areas":[""],"deprioritise":[""],"recommendation":""}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 700, 'temperature' => 0.3 ) );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_market', $this->ai->get_last_error() ?: 'Could not produce an assessment.' );
		}

		$result = array(
			'saturation'        => $data['saturation'] ?? 'mixed',
			'opportunity_areas' => (array) ( $data['opportunity_areas'] ?? array() ),
			'deprioritise'      => (array) ( $data['deprioritise'] ?? array() ),
			'recommendation'    => $data['recommendation'] ?? '',
			'assessed_at'       => current_time( 'mysql' ),
		);

		// File as brain memory so it colours future decide() calls, not just
		// this screen.
		$brain->remember( 'market', 'assessment', $result, 0.7, 'market_intelligence' );

		update_option( 'vmsb_market_assessment', $result, false );
		return $result;
	}

	public function latest() {
		return get_option( 'vmsb_market_assessment', null );
	}
}
