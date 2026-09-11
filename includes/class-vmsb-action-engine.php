<?php
defined( 'ABSPATH' ) || exit;

/**
 * Action Engine (VM SEO Brain X).
 *
 * Orchestrates the execution of strategic decisions.
 * Decides HOW to safely perform what the Brain decided WHAT to do.
 */
class VMSB_Action_Engine {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * Execute a growth action.
	 */
	public function execute( $action_type, $object_id, array $args = array() ) {
		$this->log->info( 'action', "Executing: {$action_type} on #{$object_id}." );

		switch ( $action_type ) {
			case 'UPDATE_CONTENT':
			case 'REFRESH_DECAYING_CONTENT':
				return $this->handle_content_update( $object_id, $args );

			case 'CONSOLIDATE_CONTENT':
				return $this->handle_consolidation( $object_id, $args['duplicate_ids'] ?? array() );

			case 'IMPROVE_CTR':
				return ( new VMSB_CTR() )->start( $object_id );

			case 'FIX_TECHNICAL_SEO':
				return ( new VMSB_Fixer() )->god_fix( 1 );

			case 'ADD_SENTIENT_CTA':
				return ( new VMSB_ROI() )->insert_cta( $object_id );

			case 'ADD_TACTICAL_FEATURES':
				return $this->handle_tactical_injection( $object_id, $args['features'] ?? array() );

			case 'CREATE_TAKEDOWN_CONTENT':
				return ( new VMSB_Competitor() )->execute_targeted_heist( $args['competitor_url'] ?? '' );

			case 'ADD_INTERNAL_LINKS':
				return ( new VMSB_Silo() )->insert_internal_link( $object_id, $args['target_id'] ?? 0 );

			case 'SOCIAL_AMPLIFICATION':
				return ( new VMSB_Social_Recycler() )->generate_social_pack( $object_id );

			default:
				return new WP_Error( 'vmsb_action', "Unknown action type: {$action_type}" );
		}
	}

	private function handle_content_update( $post_id, $args ) {
		$post = get_post( $post_id );
		if ( ! $post ) return false;

		$before = $post->post_content;
		$res = ( new VMSB_Content() )->improve_post( $post_id, $args['reason'] ?? 'striking_distance' );

		if ( ! is_wp_error($res) ) {
			VMSB_Actions::record( array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'action_type' => 'update_content',
				'before'      => $before,
				'after'       => get_post_field('post_content', $post_id),
				'reason'      => $args['reason'] ?? 'Autonomous refresh'
			) );
			return true;
		}
		return $res;
	}

	private function handle_tactical_injection( $post_id, array $features ) {
		if ( empty($features) ) return false;

		$post = get_post($post_id);
		if ( ! $post ) return false;

		$this->log->info( 'action', "Surgical Infiltrator: Injecting missing tactical features (" . implode(', ', $features) . ") into #{$post_id}." );

		$brain = new VMSB_Brain();
		$prompt = "Act as an SEO Tactical Specialist.\n"
			. "ARTICLE: \"{$post->post_title}\"\n"
			. "CONTENT: " . wp_trim_words($post->post_content, 500) . "\n\n"
			. "TASK: Rivals are winning because they have these features: " . implode(', ', $features) . ".\n"
			. "1. Generate high-value content blocks (HTML/Gutenberg) for each missing feature.\n"
			. "2. Ensure the blocks are data-rich and satisfy user intent better than rivals.\n"
			. "Return JSON: {\"features_html\":[]}";

		$data = ( new VMSB_AI_Router() )->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'auditor' ) );

		if ( empty($data['features_html']) ) return false;

		$before = $post->post_content;
		$block = "\n\n" . implode("\n\n", $data['features_html']);

		// Inject before the conclusion or at the end
		$updated = $before . $block;
		wp_update_post( array( 'ID' => $post_id, 'post_content' => $updated ) );

		VMSB_Actions::record( array(
			'object_type' => 'post',
			'object_id'   => $post_id,
			'action_type' => 'tactical_injection',
			'before'      => $before,
			'after'       => $updated,
			'reason'      => "Tactical Injection: Added " . implode(', ', $features)
		) );

		return true;
	}

	private function handle_consolidation( $primary_id, array $duplicate_ids ) {
		if ( empty($duplicate_ids) ) return false;

		$primary_post = get_post($primary_id);
		if ( ! $primary_post ) return false;

		$before_content = $primary_post->post_content;
		$added_context = "";

		foreach ( $duplicate_ids as $id ) {
			$dupe = get_post($id);
			if ( ! $dupe ) continue;
			$added_context .= "\n\n--- From #{$id} ---\n" . $dupe->post_content;
		}

		// Use AI to consolidate into a single superior post
		$res = ( new VMSB_Content() )->improve_post( $primary_id, 'consolidation', "We are merging these duplicate posts into this primary one. Combine the unique facts and value from the added context into the primary content. Maintain the primary post's URL and title. Added Context: " . $added_context );

		if ( ! is_wp_error($res) ) {
			VMSB_Actions::record( array(
				'object_type' => 'post',
				'object_id'   => $primary_id,
				'action_type' => 'consolidate_content',
				'before'      => $before_content,
				'after'       => get_post_field('post_content', $primary_id),
				'reason'      => "Semantic Consolidation of # " . implode(', #', $duplicate_ids)
			) );

			// Draft the duplicates now that their content has been merged
			// into the primary post above - this runs for real, not a stub.
			foreach ( $duplicate_ids as $id ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			}
			return true;
		}
		return $res;
	}
}
