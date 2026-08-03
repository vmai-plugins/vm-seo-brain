<?php
defined( 'ABSPATH' ) || exit;

/**
 * Link Juice Heatmap Engine.
 * Calculates authority flow based on the Knowledge Graph.
 */
class VMSB_Heatmap {

	/**
	 * Get the authority distribution across the site.
	 */
	public function get_data() {
		$density = VMSB_Graph::get_link_density_map();
		if ( ! $density ) {
			return array();
		}

		$max_links = max( wp_list_pluck( $density, 'inbound_links' ) );
		$out = array();

		foreach ( $density as $item ) {
			$post = get_post( $item->post_id );
			if ( ! $post ) continue;

			// Link Juice Score (0-100)
			$score = ( $item->inbound_links / ( $max_links ?: 1 ) ) * 100;

			$out[] = array(
				'id'            => $item->post_id,
				'title'         => $post->post_title,
				'url'           => get_permalink( $post ),
				'inbound'       => (int) $item->inbound_links,
				'juice_score'   => round( $score, 1 ),
				'is_pillar'     => ( new VMSB_RankMath() )->is_pillar( $item->post_id ),
				'type'          => get_post_type( $item->post_id )
			);
		}

		return $out;
	}
}
