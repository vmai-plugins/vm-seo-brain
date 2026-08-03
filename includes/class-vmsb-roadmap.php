<?php
defined( 'ABSPATH' ) || exit;

/**
 * Strategic Roadmap & Planner.
 * Projects traffic growth and schedules the "Battle Plan" to hit targets.
 */
class VMSB_Roadmap {

	public function generate_plan( $target_visitors = 50000, $days = 50 ) {
		$keywords = new VMSB_Keywords();
		$gaps     = $keywords->content_gaps( 100 );
		$current  = ( new VMSB_Growth() )->status();

		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		$prompt = "Act as a Chief Growth Officer. Our goal is {$target_visitors} monthly visitors in {$days} days.\n"
			. "Current Stats: " . wp_json_encode( $current ) . "\n"
			. "Available Keyword Gaps: " . count( $gaps ) . "\n\n"
			. "Build a day-by-day content & optimization roadmap. Focus on 'Quick Wins' first, then high-volume silos.\n"
			. "Return JSON: {\"roadmap\":[{\"day\":1,\"task\":\"\",\"keyword\":\"\",\"expected_impact\":0,\"reason\":\"\"}]}";

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( $res && ! empty( $res['roadmap'] ) ) {
			update_option( 'vmsb_battle_plan', $res['roadmap'] );
			return $res['roadmap'];
		}

		return array();
	}

	public function get_todays_task() {
		$plan = get_option( 'vmsb_battle_plan', array() );
		$installed_at = (int) get_option( 'vmsb_installed_at', time() );
		$day  = (int) ( ( time() - $installed_at ) / DAY_IN_SECONDS ) + 1;

		foreach ( $plan as $task ) {
			if ( (int) $task['day'] === $day ) {
				return $task;
			}
		}
		return null;
	}
}
