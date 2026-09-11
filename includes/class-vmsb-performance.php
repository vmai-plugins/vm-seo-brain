<?php
defined( 'ABSPATH' ) || exit;

/**
 * Performance analytics + rank history.
 *
 * Two things the old plugin split apart that belong together: tracking a
 * keyword's position over time, and translating the resulting movement into
 * language a business owner cares about rather than a raw position number.
 */
class VMSB_Performance {

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_rank_history';
	}

	/**
	 * Snapshot today's position for the site's tracked keywords (from the
	 * keyword table, not a separate list - one source of truth).
	 */
	public function snapshot( $limit = 100 ) {
		global $wpdb;
		$keywords = $wpdb->get_results( $wpdb->prepare(
			"SELECT keyword, position, impressions, clicks FROM {$wpdb->prefix}vmsb_keywords WHERE position IS NOT NULL ORDER BY impressions DESC LIMIT %d",
			(int) $limit
		) );

		$today = current_time( 'Y-m-d' );
		$table = $this->table();
		$count = 0;

		foreach ( $keywords as $k ) {
			// One row per keyword per day - overwrite if we already snapshotted today.
			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE keyword = %s AND snapshot_date = %s", $k->keyword, $today
			) );
			$row = array(
				'keyword'       => $k->keyword,
				'position'      => (float) $k->position,
				'impressions'   => (int) $k->impressions,
				'clicks'        => (int) $k->clicks,
				'snapshot_date' => $today,
			);
			if ( $existing ) {
				$wpdb->update( $table, $row, array( 'id' => $existing ) );
			} else {
				$wpdb->insert( $table, $row );
			}
			$count++;
		}

		return array( 'snapshotted' => $count );
	}

	/**
	 * Position history for one keyword, oldest first - what a rank-tracking
	 * chart needs.
	 */
	public function history_for( $keyword, $days = 90 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$this->table()} WHERE keyword = %s AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) ORDER BY snapshot_date ASC",
			$keyword, (int) $days
		) );
	}

	/**
	 * The keywords that moved the most (either direction) in the last window -
	 * this is what "did anything I did last week actually work" looks like at
	 * a glance.
	 */
	public function biggest_movers( $days = 14, $limit = 10 ) {
		global $wpdb;
		$table = $this->table();

		// The aggregates are computed in a derived table and the filtering and
		// ordering happen outside it, against plain columns.
		//
		// The previous version selected MIN(...)/MAX(...) AS position_before /
		// position_now and then referenced those aliases in HAVING and inside
		// ORDER BY ABS(position_before - position_now). MySQL 8 tolerates
		// that, but MariaDB - which a large share of WordPress hosts run - and
		// MySQL 5.x reject it with error 1247, "Reference 'position_before'
		// not supported (reference to group function)", because the alias
		// stands for a group function and is being used inside another
		// expression. The whole monthly summary died on those servers.
		//
		// The per-keyword first/last dates also move from a correlated
		// subquery evaluated per row into a single grouped join.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT g.keyword, g.position_before, g.position_now
			 FROM (
				SELECT t.keyword,
					MIN(CASE WHEN t.snapshot_date = b.first_date THEN t.position END) AS position_before,
					MAX(CASE WHEN t.snapshot_date = b.last_date  THEN t.position END) AS position_now
				FROM {$table} t
				INNER JOIN (
					SELECT keyword,
						MIN(snapshot_date) AS first_date,
						MAX(snapshot_date) AS last_date
					FROM {$table}
					WHERE snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
					GROUP BY keyword
				) b ON b.keyword = t.keyword
				WHERE t.snapshot_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
				GROUP BY t.keyword
			 ) g
			 WHERE g.position_before IS NOT NULL AND g.position_now IS NOT NULL
			 ORDER BY ABS(g.position_before - g.position_now) DESC
			 LIMIT %d",
			$days, $days, (int) $limit
		) );

		foreach ( $rows as &$r ) {
			$r->delta = round( (float) $r->position_before - (float) $r->position_now, 1 ); // positive = improved
		}
		return $rows;
	}

	/**
	 * Plain-language monthly summary - what a non-technical business owner
	 * actually wants to read, not a metrics dump.
	 */
	public function business_summary() {
		$movers   = $this->biggest_movers( 30, 5 );
		$improved = array_filter( $movers, static fn( $m ) => $m->delta > 0 );
		$declined = array_filter( $movers, static fn( $m ) => $m->delta < 0 );

		global $wpdb;
		$this_month_clicks = (int) $wpdb->get_var(
			"SELECT SUM(clicks) FROM {$wpdb->prefix}vmsb_metrics WHERE source = 'gsc' AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
		);
		$last_month_clicks = (int) $wpdb->get_var(
			"SELECT SUM(clicks) FROM {$wpdb->prefix}vmsb_metrics WHERE source = 'gsc' AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND snapshot_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
		);
		$change = $last_month_clicks > 0 ? round( ( $this_month_clicks - $last_month_clicks ) / $last_month_clicks * 100 ) : null;

		return array(
			'clicks_this_month' => $this_month_clicks,
			'clicks_last_month' => $last_month_clicks,
			'pct_change'        => $change,
			'improved_keywords' => count( $improved ),
			'declined_keywords' => count( $declined ),
			'top_improved'      => array_slice( array_values( $improved ), 0, 3 ),
			'top_declined'      => array_slice( array_values( $declined ), 0, 3 ),
		);
	}

	/**
	 * Prune old rank history records to prevent unbounded table growth.
	 */
	public function prune( $days = 180 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table()} WHERE snapshot_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			(int) $days
		) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}vmsb_metrics WHERE snapshot_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
			(int) $days
		) );
	}
}
