<?php
defined( 'ABSPATH' ) || exit;

/**
 * Niche & Silo Planner.
 *
 * Specifically designed to fill content gaps in existing silos and discover
 * fresh topical clusters to expand the site's authority.
 */
class VMSB_Niche_Planner {

	private $ai;
	private $brain;
	private $keywords;
	private $silo;
	private $log;

	public function __construct() {
		$this->ai       = new VMSB_AI_Router();
		$this->brain    = new VMSB_Brain();
		$this->keywords = new VMSB_Keywords();
		$this->silo     = new VMSB_Silo();
		$this->log      = new VMSB_Logger();
	}

	/**
	 * Orchestrate a niche expansion plan.
	 *
	 * @param int $total_pieces Total number of new pieces to plan.
	 * @param string $cluster   Optional specific cluster to expand.
	 * @return array{planned:int, clusters:array}
	 */
	public function plan_expansion( $total_pieces = 20, $cluster = '' ) {
		if ( $cluster ) {
			$this->log->info( 'niche_planner', "Starting targeted expansion for silo: {$cluster} ({$total_pieces} pieces)." );
			$total_created = $this->plan_for_silo( $cluster, $total_pieces );
			$content = new VMSB_Content();
			$pushed = $content->push_to_sheet();

			return array(
				'planned' => $total_created,
				'pushed'  => $pushed,
				'fresh_clusters' => array( $cluster )
			);
		}

		$this->log->info( 'niche_planner', "Starting niche expansion plan for {$total_pieces} pieces." );

		// 1. Identify existing silo health
		$map = $this->silo->map_for_display();
		$weak_silos = array();
		foreach ( $map as $silo ) {
			if ( $silo['assets'] < 5 ) {
				$weak_silos[] = $silo;
			}
		}

		// 2. Discover fresh topical clusters (blue ocean)
		$fresh_clusters = $this->discover_fresh_clusters( 3 );

		// 3. Allocate pieces: 50% to weak silos, 50% to fresh clusters
		$pieces_per_category = floor( $total_pieces / ( count( $weak_silos ) + count( $fresh_clusters ) ?: 1 ) );

		$content = new VMSB_Content();
		$total_created = 0;

		// Build weak silos
		foreach ( $weak_silos as $silo ) {
			$total_created += $this->plan_for_silo( $silo['name'], $pieces_per_category );
		}

		// Build fresh clusters
		foreach ( $fresh_clusters as $cluster ) {
			$total_created += $this->plan_for_new_cluster( $cluster, $pieces_per_category );
		}

		// 4. Sync to Google Sheets
		$pushed = $content->push_to_sheet();

		$this->log->info( 'niche_planner', "Expansion complete. Created {$total_created} pieces, pushed {$pushed} to sheet." );

		return array(
			'planned' => $total_created,
			'pushed'  => $pushed,
			'fresh_clusters' => wp_list_pluck( $fresh_clusters, 'name' )
		);
	}

	/**
	 * Discover topics that the site DOES NOT rank for yet but are highly relevant.
	 */
	private function discover_fresh_clusters( $count = 3 ) {
		$profile = $this->brain->profile();
		$inventory = $this->silo->map_for_display();

		$prompt = "Act as a Niche Authority Architect. Analyze this business and its current content structure.\n\n"
			. "BUSINESS DNA: " . $this->brain->context_prompt() . "\n\n"
			. "CURRENT SILOS: " . wp_json_encode( wp_list_pluck( $inventory, 'name' ) ) . "\n\n"
			. "TASK:\n"
			. "Identify {$count} 'Blue Ocean' topical clusters that are highly relevant to the business but NOT covered by existing silos.\n"
			. "Each cluster should represent a major pillar of topical authority.\n\n"
			. 'Return JSON: {"clusters":[{"name":"","reason":"","target_audience":"","commercial_intent":"high|medium|low"}]}';

		$data = $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'temperature' => 0.7 ) );

		return isset( $data['clusters'] ) ? array_slice( (array) $data['clusters'], 0, $count ) : array();
	}

	private function plan_for_silo( $silo_name, $count ) {
		$prompt = "Plan {$count} highly specific supporting articles to strengthen the '{$silo_name}' silo.\n"
			. "Focus on long-tail informational and commercial intent keywords that fill logical gaps in a user's journey.\n"
			. "Each piece must link up to the main '{$silo_name}' pillar.\n\n"
			. 'Return JSON: {"plan":[{"title":"","keyword":"","brief":"","intent":""}]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 2000 ) );

		return $this->process_ai_plan( $data, $silo_name );
	}

	private function plan_for_new_cluster( $cluster, $count ) {
		$prompt = "Create a complete authority plan for a NEW topical cluster: '{$cluster['name']}'.\n"
			. "Reasoning: {$cluster['reason']}\n"
			. "Targeting: {$cluster['target_audience']}\n\n"
			. "Plan {$count} pieces: 1 main pillar article and " . ($count - 1) . " supporting articles.\n"
			. 'Return JSON: {"plan":[{"title":"","keyword":"","brief":"","intent":"","is_pillar":false}]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $this->brain->context_prompt(), 'max_tokens' => 2500 ) );

		return $this->process_ai_plan( $data, $cluster['name'] );
	}

	private function process_ai_plan( $data, $cluster_name ) {
		if ( empty( $data['plan'] ) ) return 0;

		$content = new VMSB_Content();
		$created = 0;

		foreach ( $data['plan'] as $item ) {
			// Register keyword in the universe
			$this->keywords->upsert( $item['keyword'], array(
				'cluster' => $cluster_name,
				'intent'  => $item['intent'] ?: 'informational',
				'source'  => 'niche_planner'
			) );

			// Add to editorial plan
			$content->plan_specific(
				$item['title'],
				$item['keyword'],
				$item['brief'] . "\n\nSilo: {$cluster_name}. Intent: {$item['intent']}.",
				$cluster_name,
				! empty( $item['is_pillar'] )
			);
			$created++;
		}

		return $created;
	}
}
