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

		// Same CPT catalog VMSB_Content::plan()'s "SMART ROUTING" already uses -
		// without this, every piece this method queued defaulted to a plain
		// blog post regardless of a site's registered custom post types (e.g.
		// a travel site's "destinations" CPT), and only accidentally landed in
		// the right place if the AI-written title happened to contain the
		// literal word "destination" (VMSB_Content::produce()'s fallback match).
		$cpts        = $this->brain->profile()['cpts'];
		$cpt_context = ! empty( $cpts )
			? "\n\nSITE CONTENT TYPES (Routes):\n" . wp_json_encode( $cpts )
			. "\n\nSMART ROUTING: Assign each piece's 'content_type' to the correct route - 'post' for a standard blog "
			. "article, or one of the SITE CONTENT TYPES slugs above when a piece is that specific kind of entity "
			. "(e.g. a single named place/venue/product listed above, not a general guide about the topic). Default to 'post'.\n"
			: '';

		$prompt = "Act as a Senior SEO Silo Architect. Design a 'Power Cluster' around the seed topic: '{$seed}'.\n\n"
			. "REQUIREMENTS:\n"
			. "1. Identify 1 Master Pillar Page (Comprehensive hub).\n"
			. "2. Identify {$size} Supporting Articles (Cluster posts) covering specific long-tail angles.\n"
			. "3. For each piece, provide: Title, Primary Keyword, Search Intent, and a 2-sentence content brief.\n"
			. "4. Ensure zero topical overlap between supporting posts.\n"
			. $cpt_context . "\n"
			. 'Return JSON: {"pillar":{"title":"","keyword":"","brief":"","content_type":"post"}, "supporting":[{"title":"","keyword":"","brief":"","intent":"","content_type":"post"}]}';

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
			1, // is_pillar
			'approved',
			$this->route( isset( $data['pillar']['content_type'] ) ? $data['pillar']['content_type'] : '' )
		);
		$pushed++;

		// 2. Push Supporting Content
		foreach ( $data['supporting'] as $s ) {
			$content->plan_specific(
				$s['title'],
				$s['keyword'],
				"CLUSTER POST: " . $s['brief'] . "\n\nIntent: " . $s['intent'],
				$cluster_name,
				0,
				'approved',
				$this->route( isset( $s['content_type'] ) ? $s['content_type'] : '' )
			);
			$pushed++;
		}

		$this->log->info( 'architect', "Cluster Architect complete. Pushed {$pushed} pieces to pipeline for '{$cluster_name}'." );
		return array( 'success' => true, 'cluster' => $cluster_name, 'count' => $pushed );
	}

	/**
	 * Never trust an AI-suggested content_type blindly - a hallucinated or
	 * stale slug passed straight to wp_insert_post() would silently create a
	 * post of an unregistered type with no admin listing, no template, no way
	 * to manage it. Only ever pass through a slug that's actually registered.
	 */
	private function route( $content_type ) {
		if ( $content_type && 'post' !== $content_type && post_type_exists( $content_type ) ) {
			return $content_type;
		}
		return '';
	}
}
