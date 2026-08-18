<?php
defined( 'ABSPATH' ) || exit;

/**
 * Strategic Roadmap & Planner.
 * Projects traffic growth and schedules the "Battle Plan" to hit targets.
 */
class VMSB_Roadmap {

	/** Last roadmap day actually executed, as array{phase:int,day:int}. */
	const EXECUTED_KEY = 'vmsb_roadmap_executed';

	public function generate_plan( $target_visitors = 50000, $days = 50 ) {
		$keywords = new VMSB_Keywords();
		$gaps     = $keywords->content_gaps( 100 );
		$current  = ( new VMSB_Growth() )->status();

		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		$prompt = "Act as a Chief Growth Officer. Our goal is {$target_visitors} monthly visitors in {$days} days.\n"
			. "Current Stats: " . wp_json_encode( $current ) . "\n"
			. "Available Keyword Gaps: " . count( $gaps ) . "\n\n"
			. "Build a day-by-day content & optimization roadmap. Focus on 'Quick Wins' first, then high-volume silos.\n"
			// The keyword is the only field anything downstream can act on -
			// execute_today() queues it into the content plan - so it has to
			// be a real search term, not a restatement of the task prose.
			. "Every entry's \"keyword\" MUST be a specific, real search query a person would type. Never leave it blank and never write 'N/A'.\n"
			. "Return JSON: {\"roadmap\":[{\"day\":1,\"task\":\"\",\"keyword\":\"\",\"expected_impact\":0,\"reason\":\"\"}]}";

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium' ) );

		if ( $res && ! empty( $res['roadmap'] ) ) {
			update_option( 'vmsb_battle_plan', $res['roadmap'] );
			// A fresh plan restarts the executor's day counter, or day 1 of a
			// new roadmap would be skipped as "already done" by the marker
			// left behind from the previous one.
			delete_option( self::EXECUTED_KEY );
			return $res['roadmap'];
		}

		return array();
	}

	/**
	 * Which day of the roadmap today is.
	 *
	 * Anchored to the active phase rather than the install date: phase 2's
	 * roadmap has to start at its own day 1, not carry on counting from the
	 * day the plugin was installed - which, on a site three phases in, would
	 * point past the end of every roadmap ever generated.
	 */
	public function current_day() {
		$anchor = (int) get_option( 'vmsb_installed_at', time() );

		if ( class_exists( 'VMSB_Goal' ) ) {
			$phase = ( new VMSB_Goal() )->current();
			if ( $phase && ! empty( $phase['started_at'] ) ) {
				$anchor = (int) $phase['started_at'];
			}
		}

		return max( 1, (int) floor( ( time() - $anchor ) / DAY_IN_SECONDS ) + 1 );
	}

	/**
	 * How many roadmap days are waiting to be actioned, without doing any of
	 * the work. Cheap enough for the hourly scheduler to consult before it
	 * decides whether queueing the executor is worth a task slot.
	 *
	 * Queued once a day the executor could never close a gap - it advances one
	 * day per run while the calendar also advances one day, so a five-day hole
	 * stays five days wide forever. Running it hourly *while behind* is what
	 * lets it catch up; this is how the scheduler knows.
	 */
	public function days_behind() {
		if ( ! get_option( 'vmsb_battle_plan' ) ) {
			return 0;
		}

		$phase_no = 0;
		if ( class_exists( 'VMSB_Goal' ) ) {
			$phase    = ( new VMSB_Goal() )->current();
			$phase_no = $phase ? (int) $phase['phase'] : 0;
		}

		$marker = (array) get_option( self::EXECUTED_KEY, array() );
		$last   = ( isset( $marker['phase'] ) && (int) $marker['phase'] === $phase_no && isset( $marker['day'] ) )
			? (int) $marker['day']
			: 0;

		return max( 0, $this->current_day() - $last );
	}

	public function get_todays_task() {
		$plan = get_option( 'vmsb_battle_plan', array() );
		if ( ! is_array( $plan ) || ! $plan ) {
			return null;
		}

		$day = $this->current_day();

		foreach ( $plan as $task ) {
			if ( is_array( $task ) && isset( $task['day'] ) && (int) $task['day'] === $day ) {
				return $task;
			}
		}
		return null;
	}

	/**
	 * Turn today's roadmap entry into queued work.
	 *
	 * The roadmap was generated, rendered on the Battle Roadmap tab, and then
	 * read by nothing - get_todays_task() had no callers anywhere in the
	 * codebase, so a 50-day plan produced exactly zero actions. This is the
	 * missing half.
	 *
	 * Only the keyword is executable. The 'task' field is free prose written
	 * by a model ("Publish a pillar comparing X and Y"), which cannot be
	 * dispatched to an agent without guessing; the keyword can be planned
	 * directly, and the prose rides along as the title and brief so the
	 * writer still gets the strategic intent.
	 *
	 * Idempotent: safe to call on every task-runner pass, acts once per day.
	 */
	public function execute_today() {
		$phase_no = 0;
		if ( class_exists( 'VMSB_Goal' ) ) {
			$phase    = ( new VMSB_Goal() )->current();
			$phase_no = $phase ? (int) $phase['phase'] : 0;
		}

		$today = $this->current_day();

		$plan = get_option( 'vmsb_battle_plan', array() );
		if ( ! is_array( $plan ) || ! $plan ) {
			return array( 'executed' => false, 'reason' => 'No battle plan yet. The Growth Roadmapper builds one first.', 'day' => $today, 'needs_plan' => true );
		}

		// Where we got to last time. A phase change resets the counter,
		// because day numbers restart with each phase's own roadmap.
		$marker = (array) get_option( self::EXECUTED_KEY, array() );
		$last   = ( isset( $marker['phase'] ) && (int) $marker['phase'] === $phase_no && isset( $marker['day'] ) )
			? (int) $marker['day']
			: 0;

		if ( $last >= $today ) {
			return array( 'executed' => false, 'reason' => "Day {$today} already actioned.", 'day' => $today );
		}

		// Catch up rather than only ever looking at today. This agent competes
		// for a limited number of queue slots per day, so it will sometimes
		// not run on the day it was meant to - and a roadmap day that is only
		// ever actionable on its own date is a day lost for good, leaving
		// permanent holes in the plan. Walking forward from the last one
		// actioned means a missed day is simply picked up on the next pass.
		//
		// One entry per run keeps the cost bounded: a five-day gap closes over
		// the next five hourly passes rather than queueing five posts at once.
		$by_day = array();
		foreach ( $plan as $entry ) {
			if ( is_array( $entry ) && isset( $entry['day'] ) ) {
				$by_day[ (int) $entry['day'] ] = $entry;
			}
		}

		$skipped = array();

		for ( $day = $last + 1; $day <= $today; $day++ ) {
			$task = isset( $by_day[ $day ] ) ? $by_day[ $day ] : null;
			if ( ! $task ) {
				$skipped[] = $day; // Plan has a gap at this day number.
				continue;
			}

			$keyword = trim( (string) ( $task['keyword'] ?? '' ) );
			$title   = trim( (string) ( $task['task'] ?? '' ) );
			$reason  = trim( (string) ( $task['reason'] ?? '' ) );

			// Models do return "N/A" here despite the instruction. That is a
			// legitimately non-content day, not an error - step over it.
			if ( '' === $keyword || in_array( strtolower( $keyword ), array( 'n/a', 'na', 'none', '-' ), true ) ) {
				$skipped[] = $day;
				continue;
			}

			// 'approved' means cleared to *write*, not cleared to publish -
			// the draft still meets require_review before it reaches a URL.
			$id = ( new VMSB_Content() )->plan_specific(
				$title ?: $keyword,
				$keyword,
				$reason,
				'',
				0,
				'approved'
			);

			update_option( self::EXECUTED_KEY, array( 'phase' => $phase_no, 'day' => $day ), false );

			( new VMSB_Logger() )->info( 'roadmap', sprintf(
				'Roadmap day %d actioned: queued "%s".%s',
				$day,
				$keyword,
				$skipped ? ' Caught up past day(s) ' . implode( ', ', $skipped ) . ' (nothing actionable).' : ''
			), array( 'phase' => $phase_no, 'plan_id' => $id, 'behind_by' => $today - $day ) );

			return array(
				'executed'   => true,
				'day'        => $day,
				'today'      => $today,
				'behind_by'  => $today - $day,
				'phase'      => $phase_no,
				'keyword'    => $keyword,
				'task'       => $title,
				'plan_id'    => (int) $id,
				'skipped'    => $skipped,
				'reason'     => sprintf( 'Queued "%s" from roadmap day %d.', $keyword, $day ),
			);
		}

		// Nothing actionable anywhere in the range - bank the position so the
		// same empty span is not rewalked on every pass.
		update_option( self::EXECUTED_KEY, array( 'phase' => $phase_no, 'day' => $today ), false );

		return array(
			'executed' => false,
			'day'      => $today,
			'skipped'  => $skipped,
			'reason'   => $skipped
				? 'No actionable keyword on day(s) ' . implode( ', ', $skipped ) . '.'
				: "No roadmap entry for day {$today}.",
			'needs_plan' => $today > count( $plan ),
		);
	}
}
