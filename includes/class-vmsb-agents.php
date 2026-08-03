<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-Agent Controller.
 * Orchestrates specialized AI "Agents" for different parts of the SEO cycle.
 */
class VMSB_Agents {

	/**
	 * Run a full content production cycle through specialized agents.
	 */
	public static function orchestrated_produce( $plan_id ) {
		$content = new VMSB_Content();
		$log     = new VMSB_Logger();

		$log->info( 'agents', "Agent Cycle Started for Plan #{$plan_id}" );

		// 1. The Researcher Agent: Deep dive into the topic.
		$research = self::run_agent( 'researcher', $plan_id );

		// 2. The Architect Agent: Structure the article and silo links.
		$structure = self::run_agent( 'architect', $plan_id, array( 'research' => $research ) );

		// 3. The Writer Agent: Produce the actual content.
		$post_id = $content->produce( $plan_id, array( 'agent_context' => $structure ) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// 4. The Optimizer Agent: Apply AEO and Entity Injection instantly.
		( new VMSB_AEO() )->apply( $post_id );
		( new VMSB_Entity() )->inject( $post_id );

		$log->info( 'agents', "Agent Cycle Complete for Post #{$post_id}" );
		return $post_id;
	}

	private static function run_agent( $type, $plan_id, $context = array() ) {
		global $wpdb;
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		// Without the actual plan row, both agents only ever see each other's
		// generic output and never the real topic/keyword/brief - producing
		// content-free "research"/"architecture" text that costs a full AI
		// call each and adds nothing to the final article prompt.
		$plan = $wpdb->get_row( $wpdb->prepare(
			"SELECT title, primary_keyword, secondary_keywords, intent, brief FROM {$wpdb->prefix}vmsb_plan WHERE id = %d", (int) $plan_id
		) );
		$topic = $plan
			? "TITLE: {$plan->title}\nPRIMARY KEYWORD: {$plan->primary_keyword}\nSECONDARY KEYWORDS: {$plan->secondary_keywords}\nINTENT: {$plan->intent}\nBRIEF: {$plan->brief}"
			: '';

		$prompts = array(
			'researcher' => "You are the Lead SEO Researcher. Analyze this topic and provide 5 semantic entities and 3 competitor angles to cover.",
			'architect'  => "You are the Content Architect. Using the research provided, build a detailed H2/H3 outline that ensures zero-click utility.",
		);

		$prompt = $prompts[ $type ] . "\n\nTOPIC:\n" . $topic . "\n\nContext: " . wp_json_encode( $context );
		$res    = $ai->generate( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 1000 ) );

		return $res['ok'] ? $res['text'] : '';
	}
}
