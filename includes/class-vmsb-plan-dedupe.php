<?php
defined( 'ABSPATH' ) || exit;

/**
 * Collapse duplicate topics in the content plan.
 *
 * Eight call sites build row_uid eight different ways, and most of them salt it
 * with the article title. The UNIQUE index on row_uid therefore only ever
 * prevented an identical row - never a second article aimed at the same
 * keyword. Over time that let one keyword accumulate 125 rows across 123
 * near-identical titles, all queued, all destined to compete with each other
 * for the same search result.
 *
 * This collapses each keyword group down to a single row. Two things make it
 * safe to run against a live plan:
 *
 *   1. Nothing that produced content is ever touched. A row carrying a post_id,
 *      or sitting in published/drafted/writing, is protected - the article
 *      exists, and deleting its plan row would orphan the record of why it was
 *      written. Where a group contains such a row, it automatically becomes the
 *      survivor and every unprotected sibling is removed around it.
 *   2. Survivor choice is deterministic - highest priority, then oldest, then
 *      lowest id - so a dry run and the real run always agree. A preview that
 *      could disagree with the run it previews is worse than no preview.
 *
 * The survivor inherits any brief its duplicates had and it lacked, so research
 * already paid for is not thrown away with the row that held it.
 */
class VMSB_Plan_Dedupe {

	/** Statuses whose rows are never deleted: the work is done or in flight. */
	const PROTECTED_STATUSES = array( 'published', 'drafted', 'writing' );

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_plan';
	}

	/**
	 * Grouping key. Case- and punctuation-insensitive, so "Conversion
	 * Architecture", "conversion architecture" and "conversion-architecture"
	 * are recognised as one topic rather than three.
	 */
	public static function key( $keyword ) {
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( trim( (string) $keyword ) ) );
	}

	/**
	 * Is this row off-limits?
	 */
	private static function is_protected( $row ) {
		if ( (int) $row->post_id > 0 ) {
			return true;
		}
		return in_array( $row->status, self::PROTECTED_STATUSES, true );
	}

	/**
	 * Rank rows so the best one survives. Lower sorts first = survives.
	 *
	 * Protected rows always win. Beyond that, priority is what the planner
	 * itself used to order the queue, so it is the honest signal for "which of
	 * these did the system think mattered most".
	 */
	private static function sort_group( array $rows ) {
		usort( $rows, static function ( $a, $b ) {
			$pa = self::is_protected( $a ) ? 0 : 1;
			$pb = self::is_protected( $b ) ? 0 : 1;
			if ( $pa !== $pb ) {
				return $pa - $pb;
			}

			$prio = (float) $b->priority <=> (float) $a->priority; // higher first
			if ( 0 !== $prio ) {
				return $prio;
			}

			$age = strcmp( (string) $a->created_at, (string) $b->created_at ); // older first
			if ( 0 !== $age ) {
				return $age;
			}

			return (int) $a->id - (int) $b->id;
		} );

		return $rows;
	}

	/**
	 * Every duplicate group, with the survivor and the removable rows resolved.
	 *
	 * @return array{
	 *   groups: array<int, array{keyword:string,keep:object,remove:object[],protected:bool}>,
	 *   totals: array{groups:int,removable:int,rows_scanned:int,protected_groups:int}
	 * }
	 */
	public static function preview() {
		global $wpdb;

		$rows = $wpdb->get_results(
			'SELECT id, primary_keyword, title, status, post_id, priority, brief, created_at FROM ' . self::table()
		);

		$buckets = array();
		foreach ( (array) $rows as $row ) {
			$key = self::key( $row->primary_keyword );
			if ( '' === $key ) {
				continue; // no keyword to group on; leave it alone
			}
			$buckets[ $key ][] = $row;
		}

		$groups           = array();
		$removable        = 0;
		$protected_groups = 0;

		foreach ( $buckets as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}

			$sorted = self::sort_group( $group );
			$keep   = array_shift( $sorted );

			// Anything still protected cannot be removed, even as a duplicate.
			$remove = array();
			foreach ( $sorted as $row ) {
				if ( self::is_protected( $row ) ) {
					continue;
				}
				$remove[] = $row;
			}

			if ( ! $remove ) {
				continue;
			}

			$is_protected = self::is_protected( $keep );
			if ( $is_protected ) {
				$protected_groups++;
			}

			$groups[]   = array(
				'keyword'   => $keep->primary_keyword,
				'keep'      => $keep,
				'remove'    => $remove,
				'protected' => $is_protected,
			);
			$removable += count( $remove );
		}

		// Biggest offenders first - that is the order a human wants to read.
		usort( $groups, static function ( $a, $b ) {
			return count( $b['remove'] ) - count( $a['remove'] );
		} );

		return array(
			'groups' => $groups,
			'totals' => array(
				'groups'           => count( $groups ),
				'removable'        => $removable,
				'rows_scanned'     => count( (array) $rows ),
				'protected_groups' => $protected_groups,
			),
		);
	}

	/**
	 * Collapse the duplicates.
	 *
	 * @param bool $dry_run When true, nothing is written - the same preview is
	 *                      returned so the caller can show it and ask.
	 * @param int  $limit   Cap on rows removed in one pass, so a very large
	 *                      plan can be worked through in reviewable batches.
	 */
	public static function run( $dry_run = true, $limit = 0 ) {
		global $wpdb;

		$preview = self::preview();

		if ( $dry_run ) {
			$preview['dry_run'] = true;
			$preview['removed'] = 0;
			return $preview;
		}

		$limit   = (int) $limit;
		$removed = 0;
		$ids     = array();

		foreach ( $preview['groups'] as $group ) {
			foreach ( $group['remove'] as $row ) {
				if ( $limit > 0 && count( $ids ) >= $limit ) {
					break 2;
				}
				$ids[] = (int) $row->id;
			}

			// Carry a brief across before the row holding it disappears.
			if ( empty( $group['keep']->brief ) ) {
				foreach ( $group['remove'] as $row ) {
					if ( ! empty( $row->brief ) ) {
						$wpdb->update(
							self::table(),
							array( 'brief' => $row->brief, 'updated_at' => current_time( 'mysql', true ) ),
							array( 'id' => (int) $group['keep']->id ),
							array( '%s', '%s' ),
							array( '%d' )
						);
						break;
					}
				}
			}
		}

		if ( $ids ) {
			// Chunked: a single IN() over a thousand ids is a needlessly large
			// statement, and a partial failure should not lose the whole pass.
			foreach ( array_chunk( $ids, 200 ) as $chunk ) {
				$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
				$deleted      = $wpdb->query(
					$wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", $chunk )
				);
				$removed += (int) $deleted;
			}

			( new VMSB_Logger() )->info( 'plan', sprintf( 'Removed %d duplicate plan rows across %d topics.', $removed, $preview['totals']['groups'] ) );
		}

		$preview['dry_run'] = false;
		$preview['removed'] = $removed;

		return $preview;
	}
}
