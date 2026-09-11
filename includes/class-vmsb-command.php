<?php
defined( 'ABSPATH' ) || exit;

/**
 * The Command snapshot: one honest answer to "is this working?"
 *
 * Every failure this plugin suffered in practice was silent or misattributed.
 * Stored API keys were erased and nothing said so for weeks. The health screen
 * reported a working component as failed because two broken links on the site
 * made one check falsy. A provider test named a provider it had never
 * contacted. Publishing sat at zero for months because auto_publish and
 * require_review were both on - a combination that guarantees drafts forever
 * and which no screen mentioned.
 *
 * So this deliberately answers questions the existing screens could not:
 *
 *   1. Do the credentials actually work?  Not "is a key stored" - the wipe left
 *      empty strings that read as "configured" everywhere. Presence and
 *      validity are reported as separate facts.
 *   2. What is failing right now, and why?  Grouped by cause from the log,
 *      newest first, rather than 182,000 rows nobody reads.
 *   3. What is blocking publication?  Named explicitly, including settings
 *      combinations that are individually reasonable and jointly fatal.
 *
 * Everything is computed behind one transient. The screen is opened often and
 * the underlying reads are not cheap - VMSB_Health::check() alone makes live
 * calls - so an uncached snapshot would repeat the mistake that had the
 * notification poller hammering the GSC API every sixty seconds.
 */
class VMSB_Command {

	const CACHE_KEY = 'vmsb_command_snapshot';
	const CACHE_TTL = 180; // 3 minutes

	public static function snapshot( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$snapshot = array(
			'generated_at' => time(),
			'credentials'  => self::credentials(),
			'blockers'     => self::blockers(),
			'errors'       => self::recent_errors(),
			'throughput'   => self::throughput(),
		);

		set_transient( self::CACHE_KEY, $snapshot, self::CACHE_TTL );
		return $snapshot;
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	/* ---------------------------------------------------------- credentials */

	/**
	 * Which credentials are stored, and does the stored value survive
	 * decryption?
	 *
	 * "Stored" and "readable" are reported separately on purpose. The bug that
	 * destroyed every key on this install produced empty strings in the option
	 * row, and an empty string is indistinguishable from "never configured"
	 * unless the two are asked about separately. No network calls are made
	 * here - this is a dashboard, and a live auth check per provider belongs
	 * behind the existing Test buttons.
	 */
	public static function credentials() {
		$raw = get_option( 'vmsb_settings', array() );
		$raw = is_array( $raw ) ? $raw : array();

		$watched = array(
			'aipuffer_key'   => 'AI Puffer',
			'openai_key'     => 'OpenAI',
			'gemini_key'     => 'Gemini',
			'openrouter_key' => 'OpenRouter',
			'omniroute_key'  => 'OmniRoute',
			'pexels_key'     => 'Pexels',
		);

		$out          = array();
		$decrypt_fail = (bool) get_option( 'vmsb_decryption_failed' );

		foreach ( $watched as $key => $label ) {
			$stored   = isset( $raw[ $key ] ) && '' !== $raw[ $key ];
			$readable = $stored && '' !== (string) VMSB_Settings::get( $key );

			if ( ! $stored ) {
				$state = 'absent';
			} elseif ( ! $readable ) {
				$state = 'unreadable';
			} else {
				$state = 'ok';
			}

			$out[] = array(
				'key'   => $key,
				'label' => $label,
				'state' => $state,
			);
		}

		return array(
			'items'            => $out,
			'decryption_failed' => $decrypt_fail,
		);
	}

	/* ------------------------------------------------------------- blockers */

	/**
	 * Everything currently standing between the plugin and a published post.
	 *
	 * Ordered hard-stop first. Each entry says what is wrong and where to fix
	 * it, because "0 published" on its own sent this operator looking in the
	 * wrong place for months.
	 */
	public static function blockers() {
		global $wpdb;

		$out = array();

		// 1. Can it call a model at all?
		$creds   = self::credentials();
		$usable  = 0;
		foreach ( $creds['items'] as $c ) {
			if ( 'ok' === $c['state'] ) {
				$usable++;
			}
		}
		if ( ! $usable ) {
			$out[] = array(
				'level'  => 'critical',
				'title'  => 'No usable AI credentials',
				'detail' => 'Every stored key is missing or cannot be decrypted, so no provider can be reached. Nothing will generate until at least one is re-entered.',
				'url'    => admin_url( 'admin.php?page=vmsb-settings' ),
				'action' => 'Open Settings',
			);
		}

		// 2. The settings combination that guarantees drafts forever.
		$auto   = (int) VMSB_Settings::get( 'auto_publish' );
		$review = (int) VMSB_Settings::get( 'require_review' );
		if ( $auto && $review ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => 'Auto-publish is on, but every post needs review',
				'detail' => 'The publish gate requires auto_publish AND not require_review. With both enabled every finished article is saved as a draft, however well it scores. This is a valid choice - but if you expected posts to go live, this is why they do not.',
				'url'    => admin_url( 'admin.php?page=vmsb-settings' ),
				'action' => 'Review the setting',
			);
		}

		// 3. A queue that cannot be worked through.
		$approved = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_plan WHERE status = 'approved'" );
		$per_day  = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );
		$days     = (int) ceil( $approved / $per_day );
		if ( $days > 180 ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => sprintf( '%s topics queued - %s of work at the current pace', number_format_i18n( $approved ), self::humanise_days( $days ) ),
				'detail' => sprintf( 'At %d posts a day nothing approved today would be written for years. Anything genuinely urgent needs to jump the queue rather than join it.', $per_day ),
				'url'    => admin_url( 'admin.php?page=vmsb-production&tab=content' ),
				'action' => 'Open the queue',
			);
		}

		// 4. Duplicate topics already queued.
		$dupes = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(c - 1), 0) FROM (
				SELECT COUNT(*) c FROM {$wpdb->prefix}vmsb_plan
				WHERE (post_id IS NULL OR post_id = 0)
				  AND status NOT IN ('published','drafted','writing')
				GROUP BY LOWER(TRIM(primary_keyword)) HAVING COUNT(*) > 1
			) d"
		);
		if ( $dupes > 0 ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => sprintf( '%s queued topics duplicate another', number_format_i18n( $dupes ) ),
				'detail' => 'Written as they stand these would compete with each other for the same search result. Merging keeps one row per topic and drops the rest.',
				'url'    => admin_url( 'admin.php?page=vmsb-production&tab=content' ),
				'action' => 'Merge duplicates',
			);
		}

		// 5. Autonomous work switched off.
		if ( ! (int) VMSB_Settings::get( 'god_mode' ) ) {
			$fails = (int) get_option( 'vmsb_consecutive_failures', 0 );
			$out[] = array(
				'level'  => $fails >= 5 ? 'warning' : 'info',
				'title'  => 'God Mode is off',
				'detail' => $fails >= 5
					? sprintf( 'The circuit breaker disabled it after %d consecutive AI failures. It stays off until re-enabled by hand, so no automated fixes are running.', $fails )
					: 'Automated fixes are not running. Turn it on in Settings once the provider chain is healthy.',
				'url'    => admin_url( 'admin.php?page=vmsb-settings' ),
				'action' => 'Open Settings',
			);
		}

		// 6. Budget exhausted for today.
		$router = new VMSB_AI_Router();
		$used   = (int) $router->calls_today();
		$cap    = (int) VMSB_Settings::get( 'max_ai_calls_day' );
		if ( $cap > 0 && $used >= $cap ) {
			$out[] = array(
				'level'  => 'warning',
				'title'  => 'Daily AI budget reached',
				'detail' => sprintf( '%d of %d calls used. Generation resumes tomorrow, or raise the cap in Settings.', $used, $cap ),
				'url'    => admin_url( 'admin.php?page=vmsb-settings' ),
				'action' => 'Raise the cap',
			);
		}

		return $out;
	}

	/* --------------------------------------------------------------- errors */

	/**
	 * Today's failures, grouped by message rather than listed row by row.
	 *
	 * The log holds 182,000 rows and nothing in the product reads them, which
	 * is how 16,857 identical "every provider failed" entries accumulated
	 * unnoticed. Grouping turns that into one line with a count.
	 */
	public static function recent_errors( $hours = 24, $limit = 6 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( (int) $hours * HOUR_IN_SECONDS ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT channel, LEFT(message, 180) AS message, COUNT(*) AS hits, MAX(created_at) AS latest
				 FROM {$wpdb->prefix}vmsb_log
				 WHERE level = 'error' AND created_at >= %s
				 GROUP BY channel, message
				 ORDER BY hits DESC
				 LIMIT %d",
				$since,
				(int) $limit
			)
		);

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'channel' => $r->channel,
				'message' => $r->message,
				'hits'    => (int) $r->hits,
				'latest'  => $r->latest,
			);
		}

		return $out;
	}

	/* ----------------------------------------------------------- throughput */

	/**
	 * What the plugin actually produced, against what it was asked to produce.
	 * Published counts come from the plan rather than the post table so a post
	 * written by hand is not credited to the engine.
	 */
	public static function throughput() {
		global $wpdb;

		$table = $wpdb->prefix . 'vmsb_plan';

		$published_7d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE status = 'published' AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
		);

		$failed_7d = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE status = 'failed' AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)"
		);

		$per_day  = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );
		$expected = $per_day * 7;

		return array(
			'published_7d' => $published_7d,
			'failed_7d'    => $failed_7d,
			'expected_7d'  => $expected,
			'drafted'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'drafted'" ),
			'approved'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'approved'" ),
			'suggested'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'suggested'" ),
		);
	}

	/** "4.1 years" reads better than "1511 days" on a dashboard. */
	public static function humanise_days( $days ) {
		$days = (int) $days;
		if ( $days < 60 ) {
			return sprintf( '%d days', $days );
		}
		if ( $days < 730 ) {
			return sprintf( '%.1f months', $days / 30.4 );
		}
		return sprintf( '%.1f years', $days / 365 );
	}
}
