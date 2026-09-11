<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pipeline capacity, order, and the publish preflight.
 *
 * The queue could always be listed. What it could never answer was whether
 * anything in it would ever be written, or - when a finished article came out
 * as a draft again - which gate stopped it. Both were knowable the whole time;
 * they were just spread across VMSB_Content::produce(), VMSB_Quality_Gate and
 * three settings that only make sense read together.
 *
 * The publish decision is a single line in produce():
 *
 *     $status = ( $auto && ! $review && ! $held ) ? 'publish' : 'draft';
 *
 * Three conditions, and a site can satisfy two of them forever without any
 * screen mentioning the third. This install ran for months with auto_publish
 * and require_review both on - a combination that guarantees a draft every
 * time, no matter how well the article scores. preflight() states that plainly
 * rather than leaving it to be inferred from an empty Published column.
 */
class VMSB_Pipeline {

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_plan';
	}

	/* ------------------------------------------------------------ capacity */

	/**
	 * What the queue holds against what the site can actually produce.
	 *
	 * The drain figure is the honest one: a queue is only a plan if it can be
	 * worked through. Past a certain size "approved" stops meaning "scheduled"
	 * and starts meaning "filed away", and the number is worth showing before
	 * anyone approves more.
	 */
	public static function capacity() {
		global $wpdb;
		$t = self::table();

		$per_day = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );

		$stages = array();
		foreach ( array( 'suggested', 'approved', 'writing', 'drafted', 'published', 'failed', 'rejected' ) as $s ) {
			$stages[ $s ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = %s", $s ) );
		}

		$queued = $stages['approved'] + $stages['writing'];
		$days   = (int) ceil( $queued / $per_day );

		// Measured rather than assumed: what actually shipped, not what the
		// setting permits. A cap of 3/day means nothing if 0 are landing.
		$actual_7d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$t} WHERE status = 'published'
			 AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
		);

		return array(
			'stages'        => $stages,
			'per_day_cap'   => $per_day,
			'actual_7d'     => $actual_7d,
			'actual_daily'  => round( $actual_7d / 7, 2 ),
			'queued'        => $queued,
			'drain_days'    => $days,
			'drain_label'   => VMSB_Command::humanise_days( $days ),
			// At the rate actually observed, not the configured ceiling.
			'real_drain'    => $actual_7d > 0 ? VMSB_Command::humanise_days( (int) ceil( $queued / max( 0.01, $actual_7d / 7 ) ) ) : 'never at the current rate',
		);
	}

	/* --------------------------------------------------------------- order */

	/**
	 * The next items the runner will actually pick up, in its own order.
	 *
	 * Mirrors the ordering VMSB_Content uses rather than inventing one, so
	 * this is a genuine forecast and not a differently-sorted list that
	 * happens to look plausible.
	 */
	public static function up_next( $limit = 12 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title, primary_keyword, cluster, priority, status, scheduled_for, geo_city
				 FROM " . self::table() . "
				 WHERE status IN ('approved', 'writing')
				 ORDER BY FIELD(status,'writing','approved'), priority DESC, id ASC
				 LIMIT %d",
				(int) $limit
			)
		);

		$per_day = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );
		$out     = array();

		foreach ( (array) $rows as $i => $r ) {
			$out[] = array(
				'id'       => (int) $r->id,
				'title'    => $r->title,
				'keyword'  => $r->primary_keyword,
				'cluster'  => $r->cluster,
				'priority' => (float) $r->priority,
				'status'   => $r->status,
				'city'     => $r->geo_city,
				// Which day this lands on, at the configured pace.
				'day'      => (int) floor( $i / $per_day ) + 1,
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------ preflight */

	/**
	 * Would a finished article publish, or be held as a draft?
	 *
	 * Answers before anything is generated, so the operator is not paying for
	 * a full article to discover the gate. Each gate reports pass/fail with
	 * the setting responsible, because "held for review" without naming the
	 * reason is what made this invisible for months.
	 *
	 * @return array{will_publish:bool,gates:array,summary:string}
	 */
	public static function preflight() {
		$gates = array();

		$auto   = (int) VMSB_Settings::get( 'auto_publish' );
		$review = (int) VMSB_Settings::get( 'require_review' );

		$gates[] = array(
			'name'    => 'Auto-publish enabled',
			'pass'    => (bool) $auto,
			'detail'  => $auto
				? 'Finished articles are allowed to go live without a manual publish.'
				: 'Every article is saved as a draft by design. Turn on Auto Publish to let them go live.',
			'setting' => 'auto_publish',
		);

		$gates[] = array(
			'name'    => 'Review not required',
			'pass'    => ! $review,
			'detail'  => $review
				? 'Require Review is on, so every article is held as a draft regardless of score. This overrides Auto Publish.'
				: 'Articles are not automatically held for a human read.',
			'setting' => 'require_review',
		);

		// The quality gate can still hold an individual article even when the
		// two switches above allow publishing.
		$gate_on = (int) VMSB_Settings::get( 'quality_gate_enabled', 1 );
		$gates[] = array(
			'name'    => 'Quality gate',
			'pass'    => true, // never a hard blocker; it decides per article
			'detail'  => $gate_on
				? sprintf(
					'Active. An article must reach %d/100 and %s words, or it is held for review rather than published.',
					(int) VMSB_Settings::get( 'quality_min_score' ),
					number_format_i18n( (int) VMSB_Settings::get( 'quality_min_words' ) )
				)
				: 'Disabled - nothing is checked before publishing.',
			'setting' => 'quality_min_score',
		);

		// A model has to be reachable at all.
		$creds  = VMSB_Command::credentials();
		$usable = 0;
		foreach ( $creds['items'] as $c ) {
			if ( 'ok' === $c['state'] ) {
				$usable++;
			}
		}
		$gates[] = array(
			'name'    => 'A provider is reachable',
			'pass'    => $usable > 0,
			'detail'  => $usable
				? sprintf( '%d credential%s usable.', $usable, 1 === $usable ? '' : 's' )
				: 'No usable credentials - nothing can be generated at all.',
			'setting' => 'ai_primary',
		);

		// Budget.
		$router = new VMSB_AI_Router();
		$used   = (int) $router->calls_today();
		$cap    = (int) VMSB_Settings::get( 'max_ai_calls_day' );
		$gates[] = array(
			'name'    => 'Daily AI budget',
			'pass'    => ( $cap <= 0 || $used < $cap ),
			'detail'  => $cap > 0
				? sprintf( '%s of %s calls used today.', number_format_i18n( $used ), number_format_i18n( $cap ) )
				: 'No daily cap set.',
			'setting' => 'max_ai_calls_day',
		);

		$will_publish = $auto && ! $review;

		if ( ! $usable ) {
			$summary = 'Nothing will be produced: no provider can be reached.';
		} elseif ( $will_publish ) {
			$summary = 'A finished article that clears the quality gate will be published automatically.';
		} elseif ( $auto && $review ) {
			$summary = 'Every finished article will be saved as a draft. Auto Publish is on, but Require Review overrides it.';
		} else {
			$summary = 'Every finished article will be saved as a draft, because Auto Publish is off.';
		}

		return array(
			'will_publish' => (bool) $will_publish,
			'gates'        => $gates,
			'summary'      => $summary,
		);
	}
}
