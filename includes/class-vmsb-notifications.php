<?php
defined( 'ABSPATH' ) || exit;

/**
 * Global Notification Center.
 * Aggregates critical alerts across all modules.
 */
class VMSB_Notifications {

	public static function get_all() {
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
