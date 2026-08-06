<?php
defined( 'ABSPATH' ) || exit;

/**
 * Cluster Architect: High-Fidelity Silo Planning.
 *
 * Takes a seed topic and designs a 100% complete authority cluster
 * including Pillar, Sub-topics, and Interlinking logic.
 */
class VMSB_Cluster_Architect {

	private $ai;
	private $brain;
	private $log;

	public function __construct() {
		$this->ai    = new VMSB_AI_Router();
		$this->brain = new VMSB_Brain();
		$this->log   = new VMSB_Logger();
	}

	/**
	 * Design a new cluster from a seed keyword.
	 *
	 * @param string $seed The main topic (e.g. "Kitchen Remodeling")
	 * @param int $size Number of supporting posts (default 6)
	 */
	public function design_cluster( $seed, $size = 6 ) {
		$this->log->info( 'architect', "Designing authority cluster for: '{$seed}'" );

		$prompt = "Act as a Senior SEO Silo Architect. Design a 'Power Cluster' around the seed topic: '{$seed}'.\n\n"
			. "REQUIREMENTS:\n"
			. "1. Identify 1 Master Pillar Page (Comprehensive hub).\n"
			. "2. Identify {$size} Supporting Articles (Cluster posts) covering specific long-tail angles.\n"
			. "3. For each piece, provide: Title, Primary Keyword, Search Intent, and a 2-sentence content brief.\n"
			. "4. Ensure zero topical overlap between supporting posts.\n\n"
			. 'Return JSON: {"pillar":{"title":"","keyword":"","brief":""}, "supporting":[{"title":"","keyword":"","brief":"","intent":""}]}';

		$data = $this->ai->generate_json( $prompt, array(
			'system' => $this->brain->context_prompt(),
			'complexity' => 'premium',
			'persona' => 'strategist',
			'action' => 'niche_expansion'
		) );

		if ( empty($data['pillar']['title']) ) {
			return new WP_Error( 'vmsb_architect', 'Could not architect cluster. AI returned invalid structure.' );
		}

		$content = new VMSB_Content();
		$cluster_name = $data['pillar']['title'];
		$pushed = 0;

		// 1. Push Pillar (High Priority)
		$content->plan_specific(
			$data['pillar']['title'],
			$data['pillar']['keyword'],
			"MASTER PILLAR: " . $data['pillar']['brief'],
			$cluster_name,
			1 // is_pillar
		);
		$pushed++;

		// 2. Push Supporting Content
		foreach ( $data['supporting'] as $s ) {
			$content->plan_specific(
				$s['title'],
				$s['keyword'],
				"CLUSTER POST: " . $s['brief'] . "\n\nIntent: " . $s['intent'],
				$cluster_name,
				0
			);
			$pushed++;
		}

		$this->log->info( 'architect', "Cluster Architect complete. Pushed {$pushed} pieces to pipeline for '{$cluster_name}'." );
		return array( 'success' => true, 'cluster' => $cluster_name, 'count' => $pushed );
	}
}
