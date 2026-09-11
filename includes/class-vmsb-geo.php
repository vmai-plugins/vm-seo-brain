<?php
defined( 'ABSPATH' ) || exit;

/**
 * Geographic expansion: the service x city coverage matrix.
 *
 * The plugin could already generate a location page - VMSB_Programmatic builds
 * "{service} in {city}" from a template and a pasted list, and
 * VMSB_Global_Expander localises one proven post to other places. Neither knew
 * anything about *geography*: no state/city hierarchy, no record of which
 * combinations already exist, and no way to answer "what have we not covered
 * yet". Expansion meant retyping a list and hoping.
 *
 * This owns three things:
 *
 *   1. The map      - a state -> cities hierarchy the operator defines. No
 *                     country data ships with the plugin; a site in Uttar
 *                     Pradesh and a site in Ohio use the same code path.
 *   2. The matrix   - services (from the business profile) x cities, each cell
 *                     resolved to published / queued / missing.
 *   3. The cell     - queueing one combination, exactly once, ever.
 *
 * The deliberate constraint is on that last point. A 6 x 40 matrix is 240
 * pages; the existing plan table already carries 1,072 rows that duplicate
 * another row's keyword because eight different call sites compute row_uid
 * eight different ways, each salting it with a title so the unique index never
 * fires. Here the uid is derived from the cell coordinates alone
 * (geo|service|city) and nothing else, so the database itself makes a
 * duplicate impossible - re-running expansion is idempotent by construction
 * rather than by remembering to check.
 */
class VMSB_Geo {

	const MAP_KEY = 'vmsb_geo_map';

	/** A single expansion run never queues more than this, whatever is asked. */
	const MAX_PER_RUN = 50;

	/* ------------------------------------------------------------------ map */

	/**
	 * The state -> cities map.
	 *
	 * @return array<string, string[]>
	 */
	public static function map() {
		$map = get_option( self::MAP_KEY, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * Replace the map. Values are cleaned here rather than at every call site:
	 * blank rows dropped, names trimmed, duplicates removed case-insensitively
	 * so "Noida" and "noida" cannot become two cells covering one city.
	 *
	 * @param array<string, string[]> $map
	 * @return array<string, string[]> The map as actually stored.
	 */
	public static function save_map( array $map ) {
		$clean = array();

		foreach ( $map as $state => $cities ) {
			$state = trim( wp_strip_all_tags( (string) $state ) );
			if ( '' === $state ) {
				continue;
			}

			$seen = array();
			foreach ( (array) $cities as $city ) {
				$city = trim( wp_strip_all_tags( (string) $city ) );
				if ( '' === $city ) {
					continue;
				}
				$key = self::key( $city );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = $city;
			}

			if ( $seen ) {
				$clean[ $state ] = array_values( $seen );
			}
		}

		ksort( $clean );
		update_option( self::MAP_KEY, $clean, false );

		return $clean;
	}

	/**
	 * Parse a pasted map. One state per block, cities indented or comma
	 * separated:
	 *
	 *   Uttar Pradesh: Noida, Ghaziabad, Lucknow
	 *   Delhi
	 *     New Delhi
	 *     Dwarka
	 *
	 * Both shapes appear in real use - people paste from a spreadsheet column
	 * or from a document - so both are accepted rather than forcing one.
	 *
	 * @return array<string, string[]>
	 */
	public static function parse( $text ) {
		$map   = array();
		$state = '';

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$indented = (bool) preg_match( '/^[ \t]+/', $line );
			$line     = trim( $line );

			// "State: City, City" - a header and its cities on one line.
			if ( ! $indented && false !== strpos( $line, ':' ) ) {
				list( $head, $rest ) = explode( ':', $line, 2 );
				$state = trim( $head );
				if ( '' === $state ) {
					continue;
				}
				$map[ $state ] = isset( $map[ $state ] ) ? $map[ $state ] : array();
				foreach ( explode( ',', $rest ) as $city ) {
					if ( '' !== trim( $city ) ) {
						$map[ $state ][] = trim( $city );
					}
				}
				continue;
			}

			// Indented, or comma-separated under a bare state header.
			if ( $indented || ( $state && false !== strpos( $line, ',' ) ) ) {
				if ( '' === $state ) {
					continue;
				}
				foreach ( explode( ',', $line ) as $city ) {
					if ( '' !== trim( $city ) ) {
						$map[ $state ][] = trim( $city );
					}
				}
				continue;
			}

			// A bare line opens a new state.
			$state         = $line;
			$map[ $state ] = isset( $map[ $state ] ) ? $map[ $state ] : array();
		}

		return $map;
	}

	public static function states() {
		return array_keys( self::map() );
	}

	/**
	 * Cities, optionally for one state, each carrying the state it belongs to
	 * so callers never have to re-walk the map to find out.
	 *
	 * @return array<int, array{city:string,state:string}>
	 */
	public static function cities( $state = '' ) {
		$out = array();
		foreach ( self::map() as $st => $cities ) {
			if ( $state && $st !== $state ) {
				continue;
			}
			foreach ( $cities as $city ) {
				$out[] = array( 'city' => $city, 'state' => $st );
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------- services */

	/**
	 * The service axis, taken from the business profile rather than invented.
	 *
	 * This is deliberately the same list the operator already maintains in
	 * Settings. The plan table currently holds 352 distinct "clusters" that the
	 * model invented one article at a time - "SEO & Growth Services" and "SEO &
	 * Growth Authority" as separate things - which is precisely the drift a
	 * controlled vocabulary prevents.
	 *
	 * @return string[]
	 */
	public static function services() {
		$raw = (string) VMSB_Settings::get( 'services' );
		$out = array();

		foreach ( explode( ',', $raw ) as $service ) {
			$service = trim( $service );
			if ( '' !== $service ) {
				$out[] = $service;
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ cell */

	/** Normalised comparison key: case- and punctuation-insensitive. */
	private static function key( $value ) {
		$value = strtolower( trim( (string) $value ) );
		return preg_replace( '/[^a-z0-9]+/', '', $value );
	}

	/**
	 * The row_uid for one cell.
	 *
	 * Derived from the coordinates only. Two runs over the same matrix produce
	 * the same uid, so the UNIQUE index on row_uid rejects the second insert -
	 * which is what makes expansion safe to re-run.
	 */
	public static function uid( $service, $city ) {
		return substr( md5( 'geo|' . self::key( $service ) . '|' . self::key( $city ) ), 0, 24 );
	}

	public static function title( $service, $city ) {
		return sprintf( '%s in %s', $service, $city );
	}

	public static function keyword( $service, $city ) {
		return strtolower( $service . ' ' . $city );
	}

	/* -------------------------------------------------------------- coverage */

	/**
	 * The full matrix: every service x city cell, resolved against the plan.
	 *
	 * One query, keyed by uid, rather than a lookup per cell - a 6 x 40 matrix
	 * is 240 cells and this renders on a dashboard.
	 *
	 * @return array{
	 *   services: string[],
	 *   cities: array<int, array{city:string,state:string}>,
	 *   cells: array<string, array{status:string,plan_id:int,post_id:int}>,
	 *   totals: array{published:int,queued:int,missing:int,total:int}
	 * }
	 */
	public static function coverage() {
		global $wpdb;

		$services = self::services();
		$cities   = self::cities();
		$cells    = array();
		$totals   = array( 'published' => 0, 'queued' => 0, 'missing' => 0, 'total' => 0 );

		if ( ! $services || ! $cities ) {
			return compact( 'services', 'cities', 'cells', 'totals' );
		}

		$uids = array();
		foreach ( $services as $service ) {
			foreach ( $cities as $c ) {
				$uids[ self::uid( $service, $c['city'] ) ] = true;
			}
		}
		$uids = array_keys( $uids );

		$rows = array();
		if ( $uids ) {
			$placeholders = implode( ',', array_fill( 0, count( $uids ), '%s' ) );
			$found        = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT row_uid, id, status, post_id FROM {$wpdb->prefix}vmsb_plan WHERE row_uid IN ({$placeholders})",
					$uids
				)
			);
			foreach ( (array) $found as $r ) {
				$rows[ $r->row_uid ] = $r;
			}
		}

		foreach ( $services as $service ) {
			foreach ( $cities as $c ) {
				$uid = self::uid( $service, $c['city'] );
				$row = isset( $rows[ $uid ] ) ? $rows[ $uid ] : null;

				if ( ! $row ) {
					$status = 'missing';
				} elseif ( 'published' === $row->status ) {
					$status = 'published';
				} else {
					$status = 'queued';
				}

				$cells[ $uid ] = array(
					'service' => $service,
					'city'    => $c['city'],
					'state'   => $c['state'],
					'status'  => $status,
					'plan_id' => $row ? (int) $row->id : 0,
					'post_id' => $row ? (int) $row->post_id : 0,
				);

				$totals[ $status ]++;
				$totals['total']++;
			}
		}

		return compact( 'services', 'cities', 'cells', 'totals' );
	}

	/* ------------------------------------------------------------- expansion */

	/**
	 * Queue the missing cells.
	 *
	 * Respects the plan's own admission limits: never more than MAX_PER_RUN in
	 * one go, and rows land as 'suggested' so a human still approves them.
	 * Queueing straight to 'approved' is how the existing backlog reached four
	 * years of work nobody chose.
	 *
	 * @param string $service Restrict to one service, or '' for all.
	 * @param string $state   Restrict to one state, or '' for all.
	 * @param int    $limit   Cells to queue this run.
	 * @param bool   $dry_run Report what would happen without writing.
	 */
	public static function expand( $service = '', $state = '', $limit = 20, $dry_run = false ) {
		global $wpdb;

		$limit    = max( 1, min( self::MAX_PER_RUN, (int) $limit ) );
		$coverage = self::coverage();

		if ( ! $coverage['services'] ) {
			return new WP_Error( 'vmsb_geo', 'No services are defined. Add them to your business profile in Settings first.' );
		}
		if ( ! $coverage['cities'] ) {
			return new WP_Error( 'vmsb_geo', 'No cities are defined. Build the state and city map first.' );
		}

		$queued  = array();
		$skipped = 0;
		$now     = current_time( 'mysql', true );

		foreach ( $coverage['cells'] as $uid => $cell ) {
			if ( count( $queued ) >= $limit ) {
				break;
			}
			if ( 'missing' !== $cell['status'] ) {
				continue;
			}
			if ( $service && $cell['service'] !== $service ) {
				continue;
			}
			if ( $state && $cell['state'] !== $state ) {
				continue;
			}

			$title   = self::title( $cell['service'], $cell['city'] );
			$keyword = self::keyword( $cell['service'], $cell['city'] );

			if ( $dry_run ) {
				$queued[] = array( 'title' => $title, 'city' => $cell['city'], 'state' => $cell['state'], 'service' => $cell['service'] );
				continue;
			}

			$brief = sprintf(
				'Write a genuinely local page about %s for %s, %s. Ground it in that specific place: reference real neighbourhoods, '
				. 'local business conditions, and the way people there actually search for this. Do not write a template that would '
				. 'read identically for any other city - if you have no verifiable local detail, write around it rather than inventing one. '
				. 'Write in English throughout, even if the place name is in another script.',
				$cell['service'],
				$cell['city'],
				$cell['state']
			);

			// INSERT IGNORE, not INSERT: the UNIQUE index on row_uid is the
			// guard, so a concurrent run or a re-click cannot create a second
			// row for the same cell.
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}vmsb_plan
					 (row_uid, title, primary_keyword, cluster, geo_service, geo_city, geo_state, brief, content_type, status, priority, created_at, updated_at)
					 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 'blog', 'suggested', 12, %s, %s)",
					$uid,
					$title,
					$keyword,
					$cell['service'],
					$cell['service'],
					$cell['city'],
					$cell['state'],
					$brief,
					$now,
					$now
				)
			);

			if ( $ok ) {
				$queued[] = array( 'title' => $title, 'city' => $cell['city'], 'state' => $cell['state'], 'service' => $cell['service'] );
			} else {
				$skipped++;
			}
		}

		if ( ! $dry_run && $queued ) {
			( new VMSB_Logger() )->info( 'geo', sprintf( 'Queued %d location pages.', count( $queued ) ), array(
				'service' => $service ?: 'all',
				'state'   => $state ?: 'all',
			) );
		}

		return array(
			'queued'  => count( $queued ),
			'skipped' => $skipped,
			'dry_run' => (bool) $dry_run,
			'items'   => $queued,
			'totals'  => $coverage['totals'],
		);
	}

	/**
	 * Apply the geo taxonomy to a produced post: city becomes a tag, service
	 * becomes the category.
	 *
	 * Called from VMSB_Content::produce() after the post exists. This is the
	 * controlled half of the taxonomy - the model still suggests tags for
	 * non-geo posts, but for a location page the city tag is guaranteed rather
	 * than left to whatever the model happened to return.
	 */
	public static function apply_taxonomy( $post_id, $row ) {
		$city    = isset( $row->geo_city ) ? trim( (string) $row->geo_city ) : '';
		$service = isset( $row->geo_service ) ? trim( (string) $row->geo_service ) : '';

		if ( '' === $city && '' === $service ) {
			return false;
		}

		if ( '' !== $city ) {
			// append: never clear tags the content engine already set.
			wp_set_post_terms( $post_id, array( $city ), 'post_tag', true );
		}

		if ( '' !== $service ) {
			$term = term_exists( $service, 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $service, 'category' );
			}
			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				wp_set_post_terms( $post_id, array( (int) $term['term_id'] ), 'category', true );
			}
		}

		return true;
	}
}
