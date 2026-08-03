<?php
defined( 'ABSPATH' ) || exit;

/**
 * Competitor Gap Hijacker (Thief Mode).
 *
 * Advanced 2026 Strategy: Aggressively targets rival weak points and
 * high-velocity gaps to dominate search results.
 */
class VMSB_Thief {

	private $google;
	private $ai;
	private $brain;
	private $log;

	public function __construct() {
		$this->google = new VMSB_Google();
		$this->ai     = new VMSB_AI_Router();
		$this->brain  = new VMSB_Brain();
		$this->log    = new VMSB_Logger();
	}

	/**
	 * Task Runner Entry point for Scouting.
	 */
	public function steal() {
		return array( 'found' => $this->scout( 5 ) );
	}

	/**
	 * Scout for competitor gaps.
	 */
	public function scout( $limit = 5 ) {
		if ( ! $this->google->is_connected() ) return 0;

		$competitor_engine = new VMSB_Competitor();
		$competitors = $competitor_engine->list_all();
		if ( ! $competitors ) return 0;

		$found = 0;
		foreach ( $competitors as $c ) {
			if ( $found >= $limit ) break;

			// Perform a 'Discovery Duel' to find fresh gaps
			$gaps_report = $competitor_engine->duel( 0, $c->domain );

			if ( ! empty($gaps_report['gaps']) ) {
				foreach ( $gaps_report['gaps'] as $gap ) {
					if ( $this->queue_hijack_post( $gap, $c->domain ) ) {
						$found++;
					}
					if ( $found >= $limit ) break;
				}
			}
		}

		return $found;
	}

	/**
	 * Competitor Blitz: Aggressive takeover of established rival keywords.
	 */
	public function blitz( $limit = 3 ) {
		$this->log->info( 'thief', 'Initiating Competitor Blitz...' );

		$competitor_engine = new VMSB_Competitor();
		$competitors = $competitor_engine->list_all();
		if ( ! $competitors ) return array( 'planned' => 0 );

		$planned = 0;
		foreach ( $competitors as $c ) {
			if ( $planned >= $limit ) break;

			// Logic: Find their 'shared keywords' (where they rank better than us)
			// and trigger a heavy 'God-Fix' style content improvement.
			$gaps = $this->find_shared_high_value_gaps( $c->domain );

			foreach ( $gaps as $gap ) {
				if ( $this->queue_hijack_post( $gap, $c->domain, true ) ) {
					$planned++;
				}
				if ( $planned >= $limit ) break;
			}
		}

		return array( 'planned' => $planned );
	}

	private function find_shared_high_value_gaps( $domain ) {
		global $wpdb;
		// Pull keywords where we rank poorly (6+) and have impressions,
		// that the Brain notes as a competitor strength.
		return $wpdb->get_results( $wpdb->prepare( "
			SELECT * FROM {$wpdb->prefix}vmsb_keywords
			WHERE position > 6 AND impressions > 100
			AND status = 'new'
			ORDER BY impressions DESC LIMIT 10
		" ) );
	}

	private function queue_hijack_post( $gap_data, $competitor_domain, $is_blitz = false ) {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_plan';

		$keyword = is_object($gap_data) ? $gap_data->keyword : ($gap_data['topic'] ?? '');
		if ( ! $keyword ) return false;

		// Skip if already planned
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE primary_keyword = %s", $keyword ) );
		if ( $exists ) return false;

		$priority = $is_blitz ? 8.0 : 5.0;
		$title_prefix = $is_blitz ? "[BLITZ] " : "[THIEF] ";
		$hijack_angle = is_object( $gap_data ) ? ( $gap_data->hijack_angle ?? '' ) : ( $gap_data['hijack_angle'] ?? '' );

		$wpdb->insert( $table, array(
			'row_uid'         => substr( md5( $keyword . '|hijack' ), 0, 24 ),
			'title'           => $title_prefix . $keyword,
			'primary_keyword' => $keyword,
			'status'          => 'planned',
			'priority'        => $priority,
			'brief'           => "HIJACK STRATEGY: Target rival '{$competitor_domain}'. " . ( $hijack_angle ?: 'Create 10x content.' ),
			'target_words'    => $is_blitz ? 2000 : 1400,
			'created_at'      => current_time( 'mysql' ),
		) );

		return true;
	}
}
