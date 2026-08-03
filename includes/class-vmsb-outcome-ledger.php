<?php
defined( 'ABSPATH' ) || exit;

/**
 * Outcome ledger.
 *
 * The brain acts every day. Without this it never learns whether any of it
 * worked - it re-runs the same behaviour on the same priors forever. Every
 * autonomous action files a hypothesis and a baseline; the same rows are then
 * measured against Search Console at 7 and 28 days, and the verdicts roll up
 * into a per-module confidence weight the brain can factor into what it does
 * next.
 */
class VMSB_Outcome_Ledger {

	const SHORT      = 7;
	const LONG       = 28;
	const MIN_SAMPLE = 5;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_outcomes';
	}

	/* ---------------------------------------------------------------- recording */

	public static function record( array $args ) {
		global $wpdb;

		$args = wp_parse_args( $args, array( 'module' => 'brain', 'action' => 'change', 'object_id' => 0, 'hypothesis' => '' ) );

		$wpdb->insert(
			self::table(),
			array(
				'module'           => $args['module'],
				'action'           => $args['action'],
				'object_id'        => (int) $args['object_id'],
				'object_label'     => $args['object_id'] ? mb_substr( (string) get_the_title( $args['object_id'] ), 0, 250 ) : '',
				'hypothesis'       => $args['hypothesis'],
				'baseline'         => wp_json_encode( self::measure( (int) $args['object_id'], self::LONG ) ),
				'status'           => 'pending',
				'created_at'       => current_time( 'mysql' ),
				'measure_short_at' => gmdate( 'Y-m-d H:i:s', time() + self::SHORT * DAY_IN_SECONDS ),
				'measure_long_at'  => gmdate( 'Y-m-d H:i:s', time() + self::LONG * DAY_IN_SECONDS ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------- measuring */

	public static function measure_due( $limit = 60 ) {
		global $wpdb;

		$short = 0;
		$long  = 0;

		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status = 'pending' AND measure_short_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT %d", $limit ) ) as $row ) {
			self::apply( $row, 'short' );
			$short++;
		}
		foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE status IN ('pending','measured_short') AND measure_long_at <= UTC_TIMESTAMP() ORDER BY id ASC LIMIT %d", $limit ) ) as $row ) {
			self::apply( $row, 'long' );
			$long++;
		}

		delete_transient( 'vmsb_module_confidence' );
		return array( 'short' => $short, 'long' => $long );
	}

	private static function apply( $row, $horizon ) {
		global $wpdb;

		$days    = 'short' === $horizon ? self::SHORT : self::LONG;
		$current = self::measure( (int) $row->object_id, $days );
		$delta   = self::delta( json_decode( $row->baseline, true ), $current );

		$update = array(
			( 'short' === $horizon ? 'result_short' : 'result_long' ) => wp_json_encode( $current ),
			'delta_' . $horizon => $delta['clicks'],
			'measured_at'       => current_time( 'mysql' ),
		);
		if ( 'long' === $horizon ) {
			$update['status']  = 'complete';
			$update['verdict'] = self::verdict( $delta );
		} else {
			$update['status'] = 'measured_short';
		}

		$wpdb->update( self::table(), $update, array( 'id' => (int) $row->id ) );
	}

	private static function measure( $object_id, $days ) {
		$empty = array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'available' => false );
		if ( ! $object_id || ! class_exists( 'VMSB_Google' ) ) {
			return $empty;
		}
		$url = get_permalink( $object_id );
		if ( ! $url ) {
			return $empty;
		}

		$google = new VMSB_Google();
		if ( ! method_exists( $google, 'gsc_page_metrics' ) ) {
			return $empty;
		}
		$m = $google->gsc_page_metrics( $url, $days );
		if ( empty( $m ) || is_wp_error( $m ) ) {
			return $empty;
		}

		return array(
			'clicks'      => (int) ( $m['clicks'] ?? 0 ),
			'impressions' => (int) ( $m['impressions'] ?? 0 ),
			'position'    => round( (float) ( $m['position'] ?? 0 ), 2 ),
			'available'   => true,
		);
	}

	private static function delta( $before, $after ) {
		$before = wp_parse_args( (array) $before, array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'available' => false ) );
		$after  = wp_parse_args( (array) $after, array( 'clicks' => 0, 'impressions' => 0, 'position' => 0, 'available' => false ) );

		$position = ( $before['position'] > 0 && $after['position'] > 0 ) ? $before['position'] - $after['position'] : 0;

		return array(
			'clicks'      => $after['clicks'] - $before['clicks'],
			'impressions' => $after['impressions'] - $before['impressions'],
			'position'    => round( $position, 2 ),
			'measurable'  => ( $before['available'] || $after['available'] ),
		);
	}

	/**
	 * A page with no Search Console data either way is "unmeasurable", not
	 * "failed". Counting silence as failure would teach the brain to avoid new
	 * pages, which is backwards.
	 */
	private static function verdict( array $delta ) {
		if ( empty( $delta['measurable'] ) ) {
			return 'unmeasurable';
		}
		if ( $delta['clicks'] > 0 || $delta['position'] >= 1.0 ) {
			return 'win';
		}
		if ( $delta['clicks'] < 0 || $delta['position'] <= -1.0 ) {
			return 'loss';
		}
		return 'neutral';
	}

	/* ---------------------------------------------------------------- learning */

	public static function confidence_weights() {
		$cached = get_transient( 'vmsb_module_confidence' );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT module, COUNT(*) n, SUM(verdict='win') wins, SUM(verdict='loss') losses
			 FROM " . self::table() . " WHERE status='complete' AND verdict!='unmeasurable' GROUP BY module",
			ARRAY_A
		);

		$weights = array();
		foreach ( $rows as $row ) {
			$n = (int) $row['n'];
			if ( $n < self::MIN_SAMPLE ) {
				$weights[ $row['module'] ] = 1.0;
				continue;
			}
			$wins   = (int) $row['wins'];
			$losses = (int) $row['losses'];
			$rate   = ( $wins + 1 ) / ( $n + 2 ); // shrink toward neutral
			$weight = 0.5 + $rate;
			if ( $losses > $wins ) {
				$weight *= 0.75;
			}
			$weights[ $row['module'] ] = round( max( 0.3, min( 1.8, $weight ) ), 3 );
		}

		set_transient( 'vmsb_module_confidence', $weights, 6 * HOUR_IN_SECONDS );
		return $weights;
	}

	public static function weight_for( $module ) {
		return (float) ( self::confidence_weights()[ $module ] ?? 1.0 );
	}

	public static function scoreboard() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT module, COUNT(*) total, SUM(status='complete') measured,
				SUM(CASE WHEN verdict='win' THEN 1 ELSE 0 END) wins,
				SUM(CASE WHEN verdict='neutral' THEN 1 ELSE 0 END) neutrals,
				SUM(CASE WHEN verdict='loss' THEN 1 ELSE 0 END) losses,
				SUM(CASE WHEN verdict='unmeasurable' THEN 1 ELSE 0 END) unmeasurable,
				ROUND(COALESCE(SUM(delta_long), 0), 0) net_clicks
			 FROM " . self::table() . " GROUP BY module ORDER BY net_clicks DESC",
			ARRAY_A
		);
		$w = self::confidence_weights();
		foreach ( $rows as &$r ) {
			$r['confidence'] = $w[ $r['module'] ] ?? 1.0;
		}
		return $rows;
	}

	public static function counts() {
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT COUNT(*) total,
				SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending,
				SUM(CASE WHEN status='complete' THEN 1 ELSE 0 END) complete,
				SUM(CASE WHEN verdict='win' THEN 1 ELSE 0 END) wins,
				SUM(CASE WHEN verdict='loss' THEN 1 ELSE 0 END) losses,
				ROUND(COALESCE(SUM(delta_long), 0), 0) net_clicks
			 FROM " . self::table(),
			ARRAY_A
		);
		return wp_parse_args( (array) $row, array( 'total' => 0, 'pending' => 0, 'complete' => 0, 'wins' => 0, 'losses' => 0, 'net_clicks' => 0 ) );
	}

	public static function recent( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}

	public static function prune( $days = 365 ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)', $days ) );
	}
}
