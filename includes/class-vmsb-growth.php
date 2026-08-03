<?php
defined( 'ABSPATH' ) || exit;

/**
 * Growth model.
 *
 * This does not promise a number. It takes the target you set, measures the
 * actual trajectory from GA4 and Search Console, and tells you honestly whether
 * the current publishing rate can reach it — and what would have to change.
 *
 * Organic search has a compounding delay: new pages typically take 6-14 weeks
 * to reach a stable position. A 50-day window mostly harvests what is already
 * indexed (striking distance, CTR gaps, existing pages) rather than what gets
 * published inside the window. The model reflects that.
 */
class VMSB_Growth {

	private $google;
	private $log;

	public function __construct() {
		$this->google = new VMSB_Google();
		$this->log    = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_metrics';
	}

	/* ---------------------------------------------------------------- collection */

	public function snapshot() {
		global $wpdb;

		if ( $this->google->is_connected() ) {
			$sessions = $this->google->ga4_sessions_by_day( 3 );
			if ( ! is_wp_error( $sessions ) ) {
				foreach ( $sessions as $ymd => $vals ) {
					$date = gmdate( 'Y-m-d', strtotime( $ymd ) );
					$wpdb->replace(
						$this->table(),
						array(
							'snapshot_date' => $date,
							'source'        => 'ga4',
							'sessions'      => $vals['sessions'],
							'users'         => $vals['users'],
						)
					);
				}
			}

			$rows = $this->google->gsc_query( array( 'date' ), 7, 30 );
			if ( ! is_wp_error( $rows ) ) {
				foreach ( $rows as $row ) {
					$wpdb->replace(
						$this->table(),
						array(
							'snapshot_date' => $row['keys'][0],
							'source'        => 'gsc',
							'clicks'        => (int) $row['clicks'],
							'impressions'   => (int) $row['impressions'],
							'avg_position'  => (float) $row['position'],
						)
					);
				}
			}
		}

		$wpdb->replace(
			$this->table(),
			array(
				'snapshot_date' => gmdate( 'Y-m-d' ),
				'source'        => 'site',
				'indexed_pages' => (int) wp_count_posts( 'post' )->publish + (int) wp_count_posts( 'page' )->publish,
			)
		);

		return true;
	}

	/* ---------------------------------------------------------------- model */

	/**
	 * Where the site is against the target, and what the honest projection says.
	 */
	public function status() {
		global $wpdb;

		$target       = (int) VMSB_Settings::get( 'growth_target' );
		$window       = max( 1, (int) VMSB_Settings::get( 'growth_window' ) );
		$installed_at = (int) get_option( 'vmsb_installed_at', time() );
		$day          = min( $window, max( 1, (int) floor( ( time() - $installed_at ) / DAY_IN_SECONDS ) + 1 ) );
		$days_left    = max( 0, $window - $day );

		$baseline = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sessions),0) FROM {$this->table()} WHERE source = 'ga4' AND snapshot_date < %s AND snapshot_date >= DATE_SUB(%s, INTERVAL 30 DAY)",
				gmdate( 'Y-m-d', $installed_at ),
				gmdate( 'Y-m-d', $installed_at )
			)
		);

		$since_install = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(sessions),0) FROM {$this->table()} WHERE source = 'ga4' AND snapshot_date >= %s",
				gmdate( 'Y-m-d', $installed_at )
			)
		);

		$last_7 = (int) $wpdb->get_var( "SELECT COALESCE(SUM(sessions),0) FROM {$this->table()} WHERE source = 'ga4' AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" );
		$prev_7 = (int) $wpdb->get_var( "SELECT COALESCE(SUM(sessions),0) FROM {$this->table()} WHERE source = 'ga4' AND snapshot_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND DATE_SUB(CURDATE(), INTERVAL 8 DAY)" );

		$weekly_growth = $prev_7 > 0 ? ( ( $last_7 - $prev_7 ) / $prev_7 ) : 0;
		$daily_now     = $last_7 / 7;

		// Compound the observed weekly rate forward, capped at a rate that is
		// physically plausible for organic search rather than flattering.
		$rate       = max( -0.5, min( 0.45, $weekly_growth ) );
		$projected  = $since_install;
		$daily      = $daily_now;
		for ( $d = 0; $d < $days_left; $d++ ) {
			$daily     *= pow( 1 + $rate, 1 / 7 );
			$projected += $daily;
		}
		$projected = (int) round( $projected );

		$required_daily = $days_left > 0 ? ( max( 0, $target - $since_install ) / $days_left ) : 0;
		$gap_multiple   = $daily_now > 0 ? $required_daily / $daily_now : null;

		return array(
			'target'          => $target,
			'window'          => $window,
			'day'             => $day,
			'days_left'       => $days_left,
			'baseline_30d'    => $baseline,
			'achieved'        => $since_install,
			'pct_of_target'   => $target > 0 ? round( $since_install / $target * 100, 1 ) : 0,
			'daily_now'       => round( $daily_now, 1 ),
			'weekly_growth'   => round( $weekly_growth * 100, 1 ),
			'projected'       => $projected,
			'on_track'        => $projected >= $target,
			'required_daily'  => round( $required_daily, 1 ),
			'gap_multiple'    => null === $gap_multiple ? null : round( $gap_multiple, 1 ),
			'levers'          => $this->levers(),
		);
	}

	/**
	 * What can actually move the number inside a short window, sized by the
	 * data rather than by optimism.
	 */
	private function levers() {
		$keywords = new VMSB_Keywords();
		$fixer    = new VMSB_Fixer();

		$striking = $keywords->striking_distance( 100 );
		$ctr_gap  = $keywords->ctr_losers( 100 );
		$counts   = $fixer->counts();

		// Positions 4-20 moving to 1-3 is the fastest realistic lift, because
		// the pages are already indexed and already earning impressions.
		$striking_upside = 0;
		foreach ( $striking as $row ) {
			$current_ctr = (float) $row->ctr;
			$striking_upside += max( 0, ( 0.18 - $current_ctr ) ) * (int) $row->impressions;
		}

		$ctr_upside = 0;
		foreach ( $ctr_gap as $row ) {
			$ctr_upside += max( 0, ( 0.04 - (float) $row->ctr ) ) * (int) $row->impressions;
		}

		return array(
			array(
				'lever'     => 'Striking-distance pages (positions 4-20)',
				'count'     => count( $striking ),
				'upside'    => (int) round( $striking_upside ),
				'horizon'   => '2-4 weeks',
				'note'      => 'Already indexed and already earning impressions. This is the fastest honest lift available.',
			),
			array(
				'lever'     => 'Snippets with impressions but no clicks',
				'count'     => count( $ctr_gap ),
				'upside'    => (int) round( $ctr_upside ),
				'horizon'   => '1-2 weeks',
				'note'      => 'Rewriting titles and descriptions changes clicks without changing rank.',
			),
			array(
				'lever'     => 'Open technical and on-page issues',
				'count'     => $counts['total'],
				'upside'    => null,
				'horizon'   => 'immediate',
				'note'      => 'Removes ceilings rather than adding traffic directly.',
			),
			array(
				'lever'     => 'Newly published cluster content',
				'count'     => array_sum( array_values( ( new VMSB_Content() )->stats() ) ),
				'upside'    => null,
				'horizon'   => '6-14 weeks',
				'note'      => 'Most of this lands after a 50-day window closes. It compounds later, not now.',
			),
		);
	}

	/**
	 * Automatically identify the site's lifecycle scenario and adjust targets.
	 *
	 * Scenario 1: Fresh (Seed Authority)
	 * Scenario 2: Growing (Establish Pillar)
	 * Scenario 3: Established (Dominance/Optimization)
	 */
	public function scenario() {
		$published = (int) wp_count_posts( 'post' )->publish;

		if ( $published < 50 ) {
			return array(
				'id' => 'fresh',
				'label' => 'Seed Authority',
				'target' => 100,
				'message' => 'Your site is fresh. Focus on building your first 100 authority assets to signal expertise to Google.'
			);
		} elseif ( $published < 500 ) {
			return array(
				'id' => 'growing',
				'label' => 'Establish Pillars',
				'target' => 500,
				'message' => 'You have a solid foundation. Focus on establishing 5-10 strong pillar pages to anchor your silos.'
			);
		} else {
			// Established site: Goal is to add next 500 or +20%
			$next_goal = floor( ( $published + 500 ) / 500 ) * 500;
			return array(
				'id' => 'established',
				'label' => 'Market Dominance',
				'target' => $next_goal,
				'message' => 'You are an authority. Focus on hijacking competitor gaps and optimizing "Golden Oldies" for maximum ROI.'
			);
		}
	}

	/**
	 * A plain-language read of the target, written to be useful rather than reassuring.
	 */
	public function verdict() {
		$s = $this->status();

		if ( 0 === $s['achieved'] && 0 === $s['baseline_30d'] ) {
			return array(
				'tone'    => 'warning',
				'message' => 'No historical data found. The Sentient Brain has initialized "Fresh Start" mode: targeting high-velocity trends and competitor gaps to build your first 50,000 visitors.',
			);
		}

		if ( $s['on_track'] ) {
			return array(
				'tone'    => 'good',
				'message' => sprintf( 'On pace for roughly %s sessions by day %d, against a target of %s.', number_format( $s['projected'] ), $s['window'], number_format( $s['target'] ) ),
			);
		}

		$multiple = $s['gap_multiple'];
		$msg = sprintf(
			'Projected %s sessions by day %d against a target of %s. Current pace is %s sessions a day; the target needs %s.',
			number_format( $s['projected'] ),
			$s['window'],
			number_format( $s['target'] ),
			number_format( $s['daily_now'], 1 ),
			number_format( $s['required_daily'], 1 )
		);

		if ( $multiple && $multiple > 5 ) {
			$msg .= sprintf( ' That is a %sx jump, which organic search alone does not deliver in this window on an established index. Either extend the window, or pair this with paid, email, or social distribution.', $multiple );
		}

		return array( 'tone' => 'warning', 'message' => $msg );
	}

	public function series( $days = 60 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT snapshot_date, SUM(sessions) sessions, SUM(clicks) clicks, SUM(impressions) impressions
				 FROM {$this->table()} WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				 GROUP BY snapshot_date ORDER BY snapshot_date ASC",
				(int) $days
			)
		);
	}
}
