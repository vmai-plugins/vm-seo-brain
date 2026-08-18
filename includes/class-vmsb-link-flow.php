<?php
defined( 'ABSPATH' ) || exit;

/**
 * Link Flow Rebalancer.
 *
 * This was a second, weaker copy of the autopilot's rebalance pass: it found
 * the same striking-distance pages, then linked from whatever the knowledge
 * graph called a "topical competitor" without checking whether that page had
 * any authority to pass or whether the link already existed. It also returned
 * nothing, so the task runner recorded every run as a success with no result.
 *
 * There is now one implementation of "funnel authority to a rising star", and
 * this is the entry point the scheduler and task runner call into.
 */
class VMSB_Link_Flow {

	/**
	 * @return array{linked:int}
	 */
	public function rebalance( $limit = 3 ) {
		$linked = ( new VMSB_Internal_Link_Autopilot() )->rebalance_juice( (int) $limit );

		( new VMSB_Logger() )->info( 'link_flow', "Rebalanced internal authority: {$linked} link(s) written to rising stars." );

		return array( 'linked' => $linked );
	}
}
