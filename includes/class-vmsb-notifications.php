<?php
defined( 'ABSPATH' ) || exit;

/**
 * Global Notification Center.
 * Aggregates critical alerts across all modules.
 */
class VMSB_Notifications {

	public static function get_all() {
		// admin.js polls the REST route wrapping this every 60s, on every
		// single VMSB admin page, unconditionally (VMSB.notifications), for
		// as long as any tab is open. get_recent_drops() below now does real
		// historical-position verification against the live GSC API (up to
		// 15 extra calls per invocation, since an earlier fix this session
		// replaced its old click-count heuristic) - uncached, that is up to
		// ~16 live Google API calls every single minute per open tab, for
		// data (rankings, issue counts, task failures) that has no need to
		// be that fresh. A short cache turns "constant background GSC
		// hammering" into "checked once every 5 minutes."
		$cache_key = 'vmsb_notifications';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$notes = self::compute_all();
		set_transient( $cache_key, $notes, 5 * MINUTE_IN_SECONDS );
		return $notes;
	}

	private static function compute_all() {
		$notes = array();

		// 1. Critical Technical Issues
		$fixer = new VMSB_Fixer();
		$tech = $fixer->counts();
		if ( $tech['critical'] > 0 ) {
			$notes[] = array(
				'level'   => 'critical',
				'title'   => "{$tech['critical']} Critical Issues",
				'message' => 'Your technical SEO health is compromised.',
				'url'     => admin_url('admin.php?page=vmsb-seo&tab=technical')
			);
		}

		// 2. Ranking Drops
		$healer = new VMSB_Healer();
		$drops = method_exists($healer, 'get_recent_drops') ? $healer->get_recent_drops(5) : array();
		if ( ! empty($drops) ) {
			$notes[] = array(
				'level'   => 'warning',
				'title'   => count($drops) . ' Ranking Drops',
				'message' => 'High-value keywords are losing Top 3 visibility.',
				'url'     => admin_url('admin.php?page=vmsb-seo&tab=technical')
			);
		}

		// 3. Task Failures
		global $wpdb;
		// ran_at is stored in UTC; NOW() follows the MySQL server clock.
		$failed_tasks = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_tasks WHERE status = 'failed' AND ran_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)");
		if ( $failed_tasks > 0 ) {
			$notes[] = array(
				'level'   => 'warning',
				'title'   => "{$failed_tasks} Agent Failures",
				'message' => 'Some autonomous tasks failed in the last 24h.',
				'url'     => admin_url('admin.php?page=vmsb-production&tab=queue')
			);
		}

		// 4. Content Due for Review
		$pending = ( new VMSB_Content() )->pending_reviews( 1 );
		if ( ! empty($pending) ) {
			$notes[] = array(
				'level'   => 'info',
				'title'   => 'Drafts Pending Review',
				'message' => 'New content is ready for your final approval.',
				'url'     => admin_url('admin.php?page=vmsb-production&tab=approvals')
			);
		}

		return $notes;
	}
}
