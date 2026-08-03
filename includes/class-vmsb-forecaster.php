<?php
defined( 'ABSPATH' ) || exit;

/**
 * Traffic forecaster.
 *
 * Projects from the metrics snapshots the growth module already takes daily -
 * no separate data source, just a trend fit over what's real. Kept honest
 * about lag: organic search results from today's work show up in 6-14 weeks,
 * so this always separates "already in motion" (published, indexed pages
 * still climbing) from "compounding later" (new content, links).
 */
class VMSB_Forecaster {

	/**
	 * @return array{trend:string,current_daily:float,projected_30d:int,projected_90d:int,confidence:string,note:string}
	 */
	public function forecast() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT snapshot_date, clicks, impressions, indexed_pages FROM {$wpdb->prefix}vmsb_metrics
			 WHERE source = 'gsc' ORDER BY snapshot_date DESC LIMIT 60"
		);

		if ( count( $rows ) < 7 ) {
			return array(
				'trend'          => 'insufficient_data',
				'current_daily'  => 0,
				'projected_30d'  => 0,
				'projected_90d'  => 0,
				'confidence'     => 'low',
				'note'           => 'Fewer than 7 days of snapshots. Check back after a week of daily runs.',
			);
		}

		$rows = array_reverse( $rows ); // oldest first for a clean regression

		$n  = count( $rows );
		$xs = range( 0, $n - 1 );
		$ys = array_map( static fn( $r ) => (float) $r->clicks, $rows );

		// Simple linear regression - slope tells us daily click trend.
		$mean_x = array_sum( $xs ) / $n;
		$mean_y = array_sum( $ys ) / $n;
		$num    = 0.0;
		$den    = 0.0;
		foreach ( $xs as $i => $x ) {
			$num += ( $x - $mean_x ) * ( $ys[ $i ] - $mean_y );
			$den += ( $x - $mean_x ) ** 2;
		}
		$slope     = $den > 0 ? $num / $den : 0;
		$intercept = $mean_y - $slope * $mean_x;

		$today_clicks = max( 0, $slope * ( $n - 1 ) + $intercept );

		$projected_30 = 0;
		$projected_90 = 0;
		for ( $d = 1; $d <= 90; $d++ ) {
			$v = max( 0, $slope * ( $n - 1 + $d ) + $intercept );
			if ( $d <= 30 ) {
				$projected_30 += $v;
			}
			$projected_90 += $v;
		}

		$trend = $slope > 0.5 ? 'growing' : ( $slope < -0.5 ? 'declining' : 'flat' );

		// Confidence tracks how much of the historical window actually has
		// variation - a flat, sparse series should not be reported with false
		// precision.
		$variance   = 0.0;
		foreach ( $ys as $y ) {
			$variance += ( $y - $mean_y ) ** 2;
		}
		$variance /= $n;
		$confidence = ( $n >= 30 && $variance > 1 ) ? 'medium' : 'low';

		$note = 'Search results from work done today typically take 6-14 weeks to show up. '
			. 'This projection extrapolates the current trend in already-indexed pages - it does not credit content written this week, which will compound later.';

		$result = array(
			'trend'         => $trend,
			'current_daily' => round( $today_clicks, 1 ),
			'projected_30d' => (int) round( $projected_30 ),
			'projected_90d' => (int) round( $projected_90 ),
			'confidence'    => $confidence,
			'note'          => $note,
			'generated_at'  => current_time( 'mysql' ),
		);

		update_option( 'vmsb_traffic_forecast', $result, false );
		return $result;
	}

	public function latest() {
		return get_option( 'vmsb_traffic_forecast', null );
	}
}
