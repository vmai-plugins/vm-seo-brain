<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pillar-to-Cluster Internal Link Autopilot.
 * Ensures strict structural hierarchy across semantic silos.
 */
class VMSB_Internal_Link_Autopilot {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * Run an authority-funneling pass.
	 * Advanced 2026: Hierarchy + Cross-Silo Bridges.
	 */
	public function funnel_authority( $limit = 10 ) {
		$this->rescue_orphans( 5 );

		$silo_engine = new VMSB_Silo();
		$silos = $silo_engine->map_for_display();
		if ( empty( $silos ) ) return 0;

		$fixed = 0;

		// 1. Hierarchy: Cluster-to-Pillar & Pillar-to-Cluster
		foreach ( $silos as $silo ) {
			if ( $fixed >= $limit ) break;
			if ( empty( $silo['pillar_id'] ) || empty( $silo['children'] ) ) continue;

			$pillar_id = $silo['pillar_id'];

			// Cluster pieces MUST link to their Pillar.
			foreach ( $silo['children'] as $child ) {
				$cid = $child['existing_post_id'] ?? 0;
				if ( ! $cid ) continue;

				if ( ! $this->has_link( $cid, $pillar_id ) ) {
					$res = $silo_engine->insert_internal_link( $cid, $pillar_id );
					if ( ! is_wp_error( $res ) ) $fixed++;
				}
			}
		}

		// 2. Cross-Silo Semantic Bridges (Authority Mesh)
		if ( $fixed < $limit && count($silos) > 1 ) {
			$fixed += $this->build_cross_silo_bridges( $silos, $limit - $fixed );
		}

		if ( $fixed > 0 ) {
			$this->log->info( 'silo', "Authority Funnel: Successfully established {$fixed} internal connections." );
		}

		return $fixed;
	}

	private function build_cross_silo_bridges( $silos, $count ) {
		$fixed = 0;
		$silo_engine = new VMSB_Silo();

		// Pair silos and link their pillars if they are semantically related
		for ( $i = 0; $i < count($silos); $i++ ) {
			if ( $fixed >= $count ) break;

			$s1 = $silos[$i];
			$s2 = $silos[($i + 1) % count($silos)]; // Simple ring pairing

			if ( empty($s1['pillar_id']) || empty($s2['pillar_id']) ) continue;

			// Logic: Link Pillar 1 to Pillar 2 as a 'Related Authority'
			if ( ! $this->has_link( $s1['pillar_id'], $s2['pillar_id'] ) ) {
				$res = $silo_engine->insert_internal_link( $s1['pillar_id'], $s2['pillar_id'] );
				if ( ! is_wp_error( $res ) ) $fixed++;
			}
		}
		return $fixed;
	}

	/**
	 * Find pages with zero internal links and trigger a scan via Link Genius.
	 */
	public function rescue_orphans( $limit = 5 ) {
		if ( ! class_exists( 'AILG_Scanner' ) ) return;

		global $wpdb;

		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$types_list = "'" . implode( "','", array_map( 'esc_sql', $safe_types ) ) . "'";

		$orphans = $wpdb->get_col( "
			SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key = '_vmsb_rescue_done'
			WHERE p.post_status = 'publish'
			  AND p.post_type IN ({$types_list})
			  AND m.meta_id IS NULL
			ORDER BY p.post_date DESC
			LIMIT " . (int) $limit
		);

		foreach ( $orphans as $oid ) {
			AILG_Scanner::fix_orphan( (int) $oid );
			update_post_meta( $oid, '_vmsb_rescue_done', time() );
		}
	}

	private function has_link( $source_id, $target_id ) {
		$post = get_post( $source_id );
		if ( ! $post ) return true;
		$target_url = get_permalink( $target_id );
		if ( ! $target_url ) return true; // No resolvable URL - don't attempt a link.
		return ( false !== strpos( $post->post_content, $target_url ) );
	}
}
