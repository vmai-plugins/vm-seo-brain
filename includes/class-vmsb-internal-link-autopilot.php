<?php
defined( 'ABSPATH' ) || exit;

/**
 * Internal Link Autopilot.
 *
 * Four passes, in the order that actually moves rankings:
 *
 *   1. Hierarchy   - supporting posts link up to their pillar.
 *   2. Rescue      - orphans get their first inbound link, from the best
 *                    donor rather than from whatever was nearby.
 *   3. Rebalance   - striking-distance pages get authority from pages that
 *                    measurably have some to give.
 *   4. Bridges     - pillars connect across silos.
 *
 * The old version paired silos in a ring (silo 1 -> 2 -> 3 -> 1) regardless of
 * whether they had anything to do with each other, chose donors by vector
 * similarity alone with no idea whether the donor had any authority to pass,
 * and reset its own counter after the first pass so everything the rebalancer
 * did was reported as zero.
 */
class VMSB_Internal_Link_Autopilot {

	/** @var VMSB_Logger */
	private $log;

	/** @var VMSB_Silo */
	private $silo;

	/** @var array<int,float> Cached internal authority scores. */
	private $authority = null;

	public function __construct() {
		$this->log  = new VMSB_Logger();
		$this->silo = new VMSB_Silo();
	}

	/**
	 * Run an authority-funneling pass.
	 *
	 * @param int $limit Maximum number of links to write in this run.
	 * @return array{linked:int,passes:array<string,int>,skipped:string}
	 */
	public function funnel_authority( $limit = 10 ) {
		$limit  = max( 1, (int) $limit );
		$passes = array(
			'hierarchy' => 0,
			'rescue'    => 0,
			'rebalance' => 0,
			'bridges'   => 0,
		);

		// Every decision below is a query against the link graph, so build it
		// before deciding anything. On a fresh install this returns having
		// only indexed, which is the correct outcome for the first run.
		if ( class_exists( 'VMSB_Link_Index' ) && ! VMSB_Link_Index::is_ready() ) {
			$progress = VMSB_Link_Index::scan_batch( 60 );
			if ( $progress['remaining'] > 0 ) {
				$this->log->info( 'link_autopilot', "Indexing the site's existing links first ({$progress['remaining']} posts still to read)." );
				return array(
					'linked'  => 0,
					'passes'  => $passes,
					'skipped' => "Building the link index - {$progress['remaining']} posts remaining. Linking resumes on the next pass.",
				);
			}
		}

		$budget = $limit;

		$passes['hierarchy'] = $this->link_clusters_to_pillars( $budget );
		$budget -= $passes['hierarchy'];

		if ( $budget > 0 ) {
			$passes['rescue'] = $this->rescue_orphans( min( $budget, 5 ) );
			$budget -= $passes['rescue'];
		}

		if ( $budget > 0 ) {
			$passes['rebalance'] = $this->rebalance_juice( min( $budget, 3 ) );
			$budget -= $passes['rebalance'];
		}

		if ( $budget > 0 ) {
			$passes['bridges'] = $this->build_cross_silo_bridges( $budget );
			$budget -= $passes['bridges'];
		}

		$linked = array_sum( $passes );

		if ( $linked > 0 ) {
			$this->log->info(
				'link_autopilot',
				sprintf(
					'Authority Funnel wrote %d internal links (hierarchy %d, rescue %d, rebalance %d, bridges %d).',
					$linked,
					$passes['hierarchy'],
					$passes['rescue'],
					$passes['rebalance'],
					$passes['bridges']
				)
			);
			$this->forget_authority();
		}

		return array( 'linked' => $linked, 'passes' => $passes, 'skipped' => '' );
	}

	/* ---------------------------------------------------------------- pass 1 */

	/**
	 * Every supporting post links up to its pillar. This is the single highest
	 * value link in a silo and the one most often missing.
	 */
	private function link_clusters_to_pillars( $budget ) {
		$silos = $this->silo->map_for_display();
		if ( ! $silos ) {
			return 0;
		}

		$written = 0;

		foreach ( $silos as $silo ) {
			if ( $written >= $budget ) {
				break;
			}
			$pillar_id = isset( $silo['pillar_id'] ) ? (int) $silo['pillar_id'] : 0;
			if ( ! $pillar_id || empty( $silo['children'] ) ) {
				continue;
			}

			foreach ( $silo['children'] as $child ) {
				if ( $written >= $budget ) {
					break;
				}
				$child_id = isset( $child['existing_post_id'] ) ? (int) $child['existing_post_id'] : 0;
				if ( ! $child_id || $this->already_linked( $child_id, $pillar_id ) ) {
					continue;
				}

				if ( $this->write( $child_id, $pillar_id, sprintf( 'Silo hierarchy: supporting post to "%s" pillar', $silo['name'] ) ) ) {
					$written++;
				}
			}
		}

		return $written;
	}

	/* ---------------------------------------------------------------- pass 2 */

	/**
	 * Give orphans their first inbound link.
	 *
	 * The old implementation handed orphans to AI Link Genius and marked every
	 * one as handled whether or not a link resulted. This picks a donor
	 * ourselves - topically related first, then by measured authority - and
	 * only counts posts that actually received a link.
	 */
	public function rescue_orphans( $budget = 5 ) {
		if ( ! class_exists( 'VMSB_Link_Index' ) || ! VMSB_Link_Index::is_ready() ) {
			return 0;
		}

		$orphans = VMSB_Link_Index::orphans( max( 10, $budget * 3 ) );
		if ( ! $orphans ) {
			return 0;
		}

		$written = 0;

		foreach ( $orphans as $orphan ) {
			if ( $written >= $budget ) {
				break;
			}

			$donor = $this->best_donor( (int) $orphan->ID );
			if ( ! $donor ) {
				continue;
			}

			if ( $this->write( $donor, (int) $orphan->ID, 'Orphan rescue: first inbound link' ) ) {
				$written++;
			}
		}

		return $written;
	}

	/* ---------------------------------------------------------------- pass 3 */

	/**
	 * Funnel authority to "rising stars" - pages sitting just outside the
	 * positions that earn clicks, where one more strong internal link is the
	 * cheapest available move.
	 */
	public function rebalance_juice( $budget = 3 ) {
		$stars = ( new VMSB_Keywords() )->striking_distance( 10 );
		if ( ! $stars ) {
			return 0;
		}

		$written = 0;

		foreach ( $stars as $star ) {
			if ( $written >= $budget ) {
				break;
			}
			$target = (int) $star->post_id;
			if ( ! $target ) {
				continue;
			}

			$donor = $this->best_donor( $target );
			if ( ! $donor ) {
				continue;
			}

			if ( $this->write( $donor, $target, sprintf( 'Rising star: "%s" is in striking distance', $star->keyword ) ) ) {
				$written++;
			}
		}

		return $written;
	}

	/* ---------------------------------------------------------------- pass 4 */

	/**
	 * Connect pillars across silos - but only where the two are semantically
	 * related. Ring-pairing by array position produced links between a silo
	 * about roofing and a silo about tax returns purely because they were
	 * adjacent in the map.
	 */
	private function build_cross_silo_bridges( $budget ) {
		$silos = array_values(
			array_filter(
				$this->silo->map_for_display(),
				static function ( $s ) {
					return ! empty( $s['pillar_id'] );
				}
			)
		);

		if ( count( $silos ) < 2 ) {
			return 0;
		}

		$written = 0;

		foreach ( $silos as $silo ) {
			if ( $written >= $budget ) {
				break;
			}

			$pillar_id = (int) $silo['pillar_id'];
			$partner   = $this->related_pillar( $pillar_id, $silos );
			if ( ! $partner || $this->already_linked( $pillar_id, $partner ) ) {
				continue;
			}

			if ( $this->write( $pillar_id, $partner, 'Cross-silo bridge between related pillars' ) ) {
				$written++;
			}
		}

		return $written;
	}

	/**
	 * The most semantically similar pillar to $pillar_id, from the other
	 * silos. Returns 0 when nothing is related enough to justify a link.
	 */
	private function related_pillar( $pillar_id, array $silos ) {
		if ( ! class_exists( 'VMSB_Vector_Store' ) || ! (int) VMSB_Settings::get( 'vector_enabled' ) ) {
			return 0;
		}

		$others = array();
		foreach ( $silos as $silo ) {
			$id = (int) $silo['pillar_id'];
			if ( $id && $id !== (int) $pillar_id ) {
				$others[ $id ] = true;
			}
		}
		if ( ! $others ) {
			return 0;
		}

		foreach ( VMSB_Vector_Store::related_posts( $pillar_id, 25, 0.70 ) as $candidate ) {
			if ( isset( $others[ (int) $candidate['ID'] ] ) ) {
				return (int) $candidate['ID'];
			}
		}

		return 0;
	}

	/* ---------------------------------------------------------------- donors */

	/**
	 * Pick the page best placed to link to $target_id.
	 *
	 * Relevance decides the shortlist and authority breaks the tie, because a
	 * link from a page nothing else links to passes nothing. Pages already
	 * spending their authority on a dozen outbound links are skipped - each
	 * extra link divides what the rest receive.
	 */
	private function best_donor( $target_id ) {
		$candidates = array();

		if ( class_exists( 'VMSB_Vector_Store' ) && (int) VMSB_Settings::get( 'vector_enabled' ) ) {
			foreach ( VMSB_Vector_Store::related_posts( $target_id, 8, 0.70 ) as $rel ) {
				$candidates[ (int) $rel['ID'] ] = (float) $rel['score'];
			}
		}

		// No vector layer, or nothing related enough: fall back to the silo
		// the target belongs to rather than linking from anywhere at all.
		if ( ! $candidates ) {
			foreach ( $this->silo_siblings( $target_id ) as $sibling ) {
				$candidates[ $sibling ] = 0.5;
			}
		}

		if ( ! $candidates ) {
			return 0;
		}

		$authority = $this->authority();
		$best      = 0;
		$best_rank = -1.0;

		foreach ( $candidates as $id => $similarity ) {
			if ( $id === (int) $target_id || $this->already_linked( $id, $target_id ) ) {
				continue;
			}
			if ( is_wp_error( VMSB_Link_Inserter::can_edit( $id ) ) ) {
				continue;
			}
			if ( class_exists( 'VMSB_Link_Index' ) && VMSB_Link_Index::outbound_count( $id ) > 15 ) {
				continue;
			}

			// Similarity keeps the link editorially defensible; authority
			// decides which of the defensible options is worth spending.
			$score = ( $similarity * 0.6 ) + ( ( ( $authority[ $id ] ?? 0 ) / 100 ) * 0.4 );
			if ( $score > $best_rank ) {
				$best_rank = $score;
				$best      = (int) $id;
			}
		}

		return $best;
	}

	/**
	 * Post IDs sitting in the same silo as $post_id.
	 *
	 * @return int[]
	 */
	private function silo_siblings( $post_id ) {
		foreach ( $this->silo->map_for_display() as $silo ) {
			$ids = array();
			if ( ! empty( $silo['pillar_id'] ) ) {
				$ids[] = (int) $silo['pillar_id'];
			}
			foreach ( (array) $silo['children'] as $child ) {
				if ( ! empty( $child['existing_post_id'] ) ) {
					$ids[] = (int) $child['existing_post_id'];
				}
			}
			if ( in_array( (int) $post_id, $ids, true ) ) {
				return array_values( array_diff( $ids, array( (int) $post_id ) ) );
			}
		}
		return array();
	}

	private function authority() {
		if ( null === $this->authority ) {
			$this->authority = class_exists( 'VMSB_Link_Index' ) ? VMSB_Link_Index::authority() : array();
		}
		return $this->authority;
	}

	private function forget_authority() {
		$this->authority = null;
		delete_transient( 'vmsb_link_authority' );
	}

	/* ---------------------------------------------------------------- writing */

	private function already_linked( $source_id, $target_id ) {
		if ( class_exists( 'VMSB_Link_Index' ) && VMSB_Link_Index::is_ready() ) {
			return VMSB_Link_Index::has_link( $source_id, $target_id );
		}
		return VMSB_Link_Inserter::links_to( (string) get_post_field( 'post_content', $source_id ), get_permalink( $target_id ) );
	}

	/**
	 * One link, with every refusal logged rather than swallowed. Knowing that
	 * a run produced nothing because six posts were built in Elementor is a
	 * different problem from producing nothing because there was nothing to do.
	 */
	private function write( $source_id, $target_id, $reason ) {
		$res = VMSB_Link_Inserter::insert( $source_id, $target_id, array( 'reason' => $reason ) );

		if ( ! is_wp_error( $res ) ) {
			return true;
		}

		if ( ! in_array( $res->get_error_code(), array( 'vmsb_link_exists' ), true ) ) {
			$this->log->warn( 'link_autopilot', sprintf( 'Skipped #%d -> #%d: %s', $source_id, $target_id, $res->get_error_message() ) );
		}

		return false;
	}
}
