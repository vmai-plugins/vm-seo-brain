<?php
defined( 'ABSPATH' ) || exit;

/**
 * Goal planner and goal achiever.
 *
 * The plugin already knew how to measure a target (VMSB_Growth) and how to
 * draw a 50-day plan toward one (VMSB_Roadmap). What it had no way to express
 * was a *sequence* of goals, and nothing closed the loop between "we are
 * behind" and "so do more of the thing that helps". growth_window was a single
 * flat window anchored to the install date that never rolled over: on day 51
 * days_left hit zero and the whole model went quiet instead of starting the
 * next phase.
 *
 * This owns three things:
 *
 *   1. The ladder      - phase 1 is 50,000 sessions; each phase that lands
 *                        promotes the next, with a window sized from the
 *                        growth rate actually observed rather than a guess.
 *   2. The posture     - ahead / on_track / behind / unreachable, from the
 *                        same projection VMSB_Growth uses.
 *   3. The directive   - what the autopilot should do differently *now*,
 *                        expressed inside hard bounds.
 *
 * The deliberate limit: this never escalates past a sustainable pace. Organic
 * search compounds on a 6-14 week delay, which is why VMSB_Growth caps its
 * projection at a rate it calls "physically plausible rather than flattering".
 * A controller that pushes harder every day it is behind would spend a whole
 * window escalating into content the quality gate then rejects. When a target
 * cannot be reached, this says so and holds - see posture 'unreachable'.
 */
class VMSB_Goal {

	const PHASES_KEY = 'vmsb_goal_phases';
	const STATE_KEY  = 'vmsb_goal_state';

	/** Multiplier applied to each phase target to set the next one. */
	const LADDER_STEP = 3;

	/** Windows are never sized outside this range, however the maths lands. */
	const MIN_WINDOW = 30;
	const MAX_WINDOW = 365;

	/**
	 * Beyond this multiple of the current daily rate, a target is treated as
	 * out of reach for its window rather than as something to push harder at.
	 * Chosen to sit above what the projection model itself can produce: it
	 * compounds at most 45%/week, so roughly 3x over a 50-day window.
	 */
	const REACHABLE_GAP = 3.0;

	private $growth;

	public function __construct() {
		$this->growth = new VMSB_Growth();
	}

	/* ---------------------------------------------------------------- ladder */

	/**
	 * The phase ladder, seeded on first read from the site's growth_target so
	 * an existing install keeps the number it was already aiming at.
	 */
	public function phases() {
		$phases = get_option( self::PHASES_KEY, null );
		if ( is_array( $phases ) && $phases ) {
			return $phases;
		}

		$first  = max( 1, (int) VMSB_Settings::get( 'growth_target', 50000 ) );
		$window = max( self::MIN_WINDOW, (int) VMSB_Settings::get( 'growth_window', 50 ) );

		$phases = array(
			array(
				'phase'       => 1,
				'target'      => $first,
				'window'      => $window,
				'status'      => 'active',
				// Phase 1 is measured from install, so a site that has been
				// running for weeks does not restart its own history.
				'started_at'  => (int) get_option( 'vmsb_installed_at', time() ),
				'achieved_at' => null,
			),
		);

		update_option( self::PHASES_KEY, $phases, false );
		return $phases;
	}

	public function current() {
		foreach ( $this->phases() as $phase ) {
			if ( 'active' === $phase['status'] ) {
				return $phase;
			}
		}
		return null;
	}

	/**
	 * Where the active phase stands, using VMSB_Growth's projection anchored
	 * to the phase rather than to the install date.
	 */
	public function status() {
		$phase = $this->current();
		if ( ! $phase ) {
			return null;
		}

		$s = $this->growth->status_for( $phase['target'], $phase['window'], $phase['started_at'] );

		$s['phase']        = (int) $phase['phase'];
		$s['phase_status'] = $phase['status'];
		$s['posture']      = $this->posture( $s );
		$s['reachable']    = 'unreachable' !== $s['posture'];

		return $s;
	}

	/**
	 * ahead    - projection clears the target with room to spare
	 * on_track - projection clears the target
	 * behind   - short, but within a multiple the model could still close
	 * unreachable - the required pace is beyond what organic search can do
	 *               inside this window; hold and report rather than escalate
	 */
	private function posture( array $s ) {
		$target = (int) $s['target'];
		if ( $target <= 0 ) {
			return 'on_track';
		}

		if ( (int) $s['achieved'] >= $target ) {
			return 'ahead';
		}

		// No traffic data at all yet: not a judgement, just too early.
		if ( $s['daily_now'] <= 0 ) {
			return 'behind';
		}

		if ( ! empty( $s['on_track'] ) ) {
			return (int) $s['projected'] >= $target * 1.15 ? 'ahead' : 'on_track';
		}

		$gap = isset( $s['gap_multiple'] ) ? (float) $s['gap_multiple'] : null;
		if ( null !== $gap && $gap > self::REACHABLE_GAP ) {
			return 'unreachable';
		}

		return 'behind';
	}

	/* ---------------------------------------------------------------- achiever */

	/**
	 * What the autopilot should do differently right now.
	 *
	 * Bounded on purpose: the only lever moved is publishing pace, and only
	 * between the floor and ceiling in settings. Nothing here touches
	 * auto_publish or require_review - whether a human sees a post before it
	 * goes live stays a human's decision.
	 */
	public function directive() {
		$s = $this->status();
		if ( ! $s ) {
			return array(
				'active'  => false,
				'posture' => 'none',
				'note'    => 'No active phase. The ladder has been completed or cleared.',
			);
		}

		$floor   = max( 1, (int) VMSB_Settings::get( 'goal_min_posts', 1 ) );
		$ceiling = max( $floor, (int) VMSB_Settings::get( 'goal_max_posts', 12 ) );

		// Settings::get('posts_per_day') clamps to the licence limit on read.
		// Without the same clamp here the ceiling could sit above anything
		// that will ever read back, so apply() would rewrite the option on
		// every review, log a pace change every time, and never converge.
		if ( class_exists( 'VMSB_License' ) ) {
			$limits  = VMSB_License::limits();
			$ceiling = min( $ceiling, max( 1, (int) $limits['posts_per_day'] ) );
			$floor   = min( $floor, $ceiling );
		}

		$current = max( 1, (int) VMSB_Settings::get( 'posts_per_day', 3 ) );

		switch ( $s['posture'] ) {
			case 'ahead':
				// Comfortably clear: ease off and let the compounding work.
				$pace = max( $floor, $current - 1 );
				$note = 'Ahead of pace. Easing publishing back to protect quality and budget.';
				break;

			case 'on_track':
				$pace = $current;
				$note = 'On pace for this phase. Holding the current rate.';
				break;

			case 'behind':
				$pace = min( $ceiling, $current + 1 );
				$note = 'Behind pace. Raising publishing one step and prioritising quick wins.';
				break;

			case 'unreachable':
			default:
				// The point of the whole design: stop climbing.
				$pace = $ceiling;
				$note = sprintf(
					'This phase needs about %s sessions/day against the %s/day running now - beyond what organic search compounds to in the time left. Holding the maximum sustainable pace; the phase will be re-based when the window closes.',
					number_format_i18n( (float) $s['required_daily'], 1 ),
					number_format_i18n( (float) $s['daily_now'], 1 )
				);
				break;
		}

		return array(
			'active'         => true,
			'phase'          => $s['phase'],
			'target'         => $s['target'],
			'achieved'       => $s['achieved'],
			'pct_of_target'  => $s['pct_of_target'],
			'day'            => $s['day'],
			'days_left'      => $s['days_left'],
			'posture'        => $s['posture'],
			'reachable'      => $s['reachable'],
			'required_daily' => $s['required_daily'],
			'daily_now'      => $s['daily_now'],
			'gap_multiple'   => $s['gap_multiple'],
			'posts_per_day'  => $pace,
			'pace_changed'   => $pace !== $current,
			'pace_bounds'    => array( 'min' => $floor, 'max' => $ceiling ),
			'focus'          => $this->focus( $s ),
			'note'           => $note,
		);
	}

	/**
	 * Which levers to lean on, biggest measured upside first.
	 *
	 * These come from VMSB_Growth::levers(), which sizes them from real
	 * impression data - striking-distance pages and CTR gaps are the only
	 * things that can move a number inside a short window, because they are
	 * already indexed. Newly published pages cannot rank in time to help.
	 */
	private function focus( array $s ) {
		$levers = isset( $s['levers'] ) && is_array( $s['levers'] ) ? $s['levers'] : array();

		usort( $levers, static function ( $a, $b ) {
			return (int) ( $b['upside'] ?? 0 ) <=> (int) ( $a['upside'] ?? 0 );
		} );

		$out = array();
		foreach ( array_slice( $levers, 0, 3 ) as $lever ) {
			if ( (int) ( $lever['upside'] ?? 0 ) <= 0 ) {
				continue;
			}
			$out[] = array(
				'lever'  => (string) ( $lever['lever'] ?? '' ),
				'upside' => (int) ( $lever['upside'] ?? 0 ),
				'count'  => (int) ( $lever['count'] ?? 0 ),
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- advance */

	/**
	 * Close the active phase when it is met or its window has run out, and
	 * open the next one. Safe to call repeatedly; it only acts on a boundary.
	 *
	 * @return array{advanced:bool,reason:string,phase:?int}
	 */
	public function review() {
		$phase = $this->current();
		if ( ! $phase ) {
			return array( 'advanced' => false, 'reason' => 'No active phase.', 'phase' => null );
		}

		$s   = $this->status();
		$hit = (int) $s['achieved'] >= (int) $phase['target'];

		if ( ! $hit && (int) $s['days_left'] > 0 ) {
			return array(
				'advanced' => false,
				'reason'   => sprintf( 'Phase %d still running: %s of %s sessions, %d days left.',
					$phase['phase'],
					number_format_i18n( (int) $s['achieved'] ),
					number_format_i18n( (int) $phase['target'] ),
					(int) $s['days_left']
				),
				'phase'    => (int) $phase['phase'],
			);
		}

		$phases = $this->phases();
		$daily  = max( 0.1, (float) $s['daily_now'] );

		foreach ( $phases as $i => $row ) {
			if ( 'active' !== $row['status'] ) {
				continue;
			}

			if ( $hit ) {
				$phases[ $i ]['status']      = 'achieved';
				$phases[ $i ]['achieved_at'] = time();
				$next_target = (int) round( $row['target'] * self::LADDER_STEP );
				$reason      = sprintf( 'Phase %d achieved: %s sessions.', $row['phase'], number_format_i18n( (int) $s['achieved'] ) );
			} else {
				// Window closed short. Per the "hold and report" posture the
				// phase is not failed and not silently retried at the same
				// number - it is re-based onto what the measured rate can
				// actually deliver, and the shortfall is carried forward.
				$phases[ $i ]['status']      = 'missed';
				$phases[ $i ]['achieved_at'] = time();
				$next_target = max(
					(int) round( $daily * self::MIN_WINDOW ),
					(int) $row['target'] - (int) $s['achieved']
				);
				$reason = sprintf(
					'Phase %d window closed at %s of %s sessions. Re-basing the next phase onto the measured rate.',
					$row['phase'],
					number_format_i18n( (int) $s['achieved'] ),
					number_format_i18n( (int) $row['target'] )
				);
			}

			// Size the next window from the rate actually being achieved, not
			// from a fixed 50 days that happened to be the default.
			$window = (int) ceil( $next_target / $daily );
			$window = max( self::MIN_WINDOW, min( self::MAX_WINDOW, $window ) );

			$phases[] = array(
				'phase'       => (int) $row['phase'] + 1,
				'target'      => (int) $next_target,
				'window'      => $window,
				'status'      => 'active',
				'started_at'  => time(),
				'achieved_at' => null,
			);

			update_option( self::PHASES_KEY, $phases, false );
			update_option( self::STATE_KEY, array( 'last_review' => time(), 'reason' => $reason ), false );

			( new VMSB_Logger() )->info( 'goal', $reason, array(
				'next_target' => $next_target,
				'next_window' => $window,
			) );

			if ( class_exists( 'VMSB_Webhooks' ) ) {
				VMSB_Webhooks::dispatch( 'goal_phase_changed', array(
					'phase'       => (int) $row['phase'],
					'achieved'    => (int) $s['achieved'],
					'target'      => (int) $row['target'],
					'next_target' => $next_target,
					'next_window' => $window,
				) );
			}

			return array( 'advanced' => true, 'reason' => $reason, 'phase' => (int) $row['phase'] + 1 );
		}

		return array( 'advanced' => false, 'reason' => 'Nothing to advance.', 'phase' => (int) $phase['phase'] );
	}

	/**
	 * Apply the directive's publishing pace. Separated from directive() so
	 * reading the goal never has a side effect - only the scheduled review
	 * writes.
	 *
	 * @return array{applied:bool,from:int,to:int}
	 */
	public function apply( array $directive = null ) {
		$directive = $directive ?: $this->directive();

		if ( empty( $directive['active'] ) || empty( $directive['pace_changed'] ) ) {
			return array( 'applied' => false, 'from' => (int) VMSB_Settings::get( 'posts_per_day', 3 ), 'to' => (int) VMSB_Settings::get( 'posts_per_day', 3 ) );
		}

		if ( ! (int) VMSB_Settings::get( 'goal_autopilot', 1 ) ) {
			return array( 'applied' => false, 'from' => (int) VMSB_Settings::get( 'posts_per_day', 3 ), 'to' => (int) $directive['posts_per_day'] );
		}

		$from = (int) VMSB_Settings::get( 'posts_per_day', 3 );
		$to   = (int) $directive['posts_per_day'];

		VMSB_Settings::update( array( 'posts_per_day' => $to ) );

		( new VMSB_Logger() )->info( 'goal', sprintf(
			'Publishing pace moved %d -> %d/day (%s).', $from, $to, $directive['posture']
		) );

		return array( 'applied' => true, 'from' => $from, 'to' => $to );
	}
}
