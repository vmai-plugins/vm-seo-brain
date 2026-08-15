<?php
defined( 'ABSPATH' ) || exit;

/**
 * High-Fidelity Gap Finder.
 *
 * Aggregates signals from Search Console, Competitor Duels, and Niche
 * Anomalies to identify the "Golden Gaps" in topical authority.
 */
class VMSB_Gap_Finder {

	private $ai;
	private $keywords;
	private $log;

	public function __construct() {
		$this->ai       = new VMSB_AI_Router();
		$this->keywords = new VMSB_Keywords();
		$this->log      = new VMSB_Logger();
	}

	/**
	 * Run a Global Gap Audit.
	 * Discovers the 10 highest-leverage gaps across all signals.
	 */
	public function discover_golden_gaps( $limit = 10 ) {
		$this->log->info( 'gap_finder', "Starting Global Gap Audit..." );

		// 1. Fetch Real Data Gaps (GSC)
		$gsc_gaps = $this->keywords->content_gaps( 20 );
		$gsc_context = array_map( fn($k) => $k->keyword . " (Imp: {$k->impressions})", $gsc_gaps );

		// 2. Fetch Competitor Signals
		$competitors = ( new VMSB_Competitor() )->list_all();
		$comp_context = array_map( fn($c) => $c->domain, array_slice($competitors, 0, 5) );

		// 3. AI Reasoning: Identify "Golden Gaps"
		$brain = new VMSB_Brain();
		$cpts  = $brain->profile()['cpts'];
		$cpt_context = ! empty( $cpts )
			? "\n\nSITE CONTENT TYPES (Routes): " . wp_json_encode( $cpts )
			. "\nSMART ROUTING: set 'content_type' to 'post' for a standard blog gap, or one of the slugs above when the gap "
			. "is specifically that kind of entity (a place for a 'destinations' type, a happening for an 'events' type)."
			: '';
		$prompt = "Act as a Market Dominance Strategist. Find the top {$limit} content gaps for this business.\n\n"
			. "DATA SIGNALS:\n"
			. "- Under-served Keywords (GSC): " . implode( ", ", $gsc_context ) . "\n"
			. "- Top Rivals: " . implode( ", ", $comp_context ) . "\n"
			. $cpt_context . "\n\n"
			. "TASK:\n"
			. "Select/Identify the absolute best opportunities that will drive the most REVENUE and AUTHORITY.\n"
			. "For each, provide a Title, Primary Keyword, and the 'Gap Type' (Market/Topical/Intent).\n\n"
			. 'Return JSON: {"gaps":[{"title":"","keyword":"","type":"","reasoning":"","content_type":"post"}]}';

		$data = $this->ai->generate_json( $prompt, array(
			'system' => $brain->context_prompt(),
			'complexity' => 'premium',
			'persona' => 'strategist',
			'action' => 'niche_expansion'
		) );

		if ( empty($data['gaps']) ) {
			return new WP_Error( 'vmsb_gap', 'Gap Discovery returned no usable data.' );
		}

		return array_slice( (array)$data['gaps'], 0, $limit );
	}

	/**
	 * Push a specific gap to the pipeline.
	 */
	public function push_to_pipeline( $gap ) {
		$content = new VMSB_Content();
		$brief = "GAP ANALYSIS: This was identified as a '{$gap['type']}' gap. " . ($gap['reasoning'] ?? '');

		$content_type = isset( $gap['content_type'] ) ? $gap['content_type'] : '';
		$content_type = ( $content_type && 'post' !== $content_type && post_type_exists( $content_type ) ) ? $content_type : '';

		return $content->plan_specific(
			$gap['title'],
			$gap['keyword'],
			$brief,
			'Gap Discovery',
			0,
			'approved',
			$content_type
		);
	}
}
