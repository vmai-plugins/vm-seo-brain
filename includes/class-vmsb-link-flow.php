<?php
defined( 'ABSPATH' ) || exit;

/**
 * Link Flow Rebalancer.
 * Identifies "Rising Stars" (pages moving up in rankings) and funnels internal link juice to them.
 */
class VMSB_Link_Flow {

	public function rebalance() {
		$rising_stars = $this->find_rising_stars();
		$log          = new VMSB_Logger();

		foreach ( $rising_stars as $star ) {
			$this->funnel_links_to( $star['post_id'] );
			$log->info( 'link_flow', "Rebalanced links for Rising Star: " . get_the_title( $star['post_id'] ) );
		}
	}

	private function find_rising_stars() {
		global $wpdb;
		// Find posts whose position improved by more than 5 in the last 14 days
		// or those sitting in "Striking Distance" (pos 11-20).
		$keywords = new VMSB_Keywords();
		$striking = $keywords->striking_distance( 10 );

		$stars = array();
		foreach ( $striking as $kw ) {
			if ( $kw->post_id ) {
				$stars[] = array( 'post_id' => $kw->post_id, 'keyword' => $kw->keyword );
			}
		}
		return $stars;
	}

	private function funnel_links_to( $target_id ) {
		$silo   = new VMSB_Silo();
		$graph  = new VMSB_Graph();

		// Find related posts using the Knowledge Graph
		$related = $graph->get_topical_competitors( $target_id );
		foreach ( array_slice( $related, 0, 3 ) as $row ) {
			$silo->insert_internal_link( (int) $row->subject_id, $target_id );
		}
	}
}
