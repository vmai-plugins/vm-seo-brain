<?php
/**
 * License and subscription manager for VM SEO Brain.
 * Sovereign Free & Unlimited Edition.
 *
 * @package VM_SEO_Brain
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles feature gating and subscription status.
 * All SEO intelligence, agents, and programmatic engines are permanently unlocked and free.
 */
class VMSB_License {

	const OPTION = 'vmsb_license';

	/**
	 * Backward compatibility alias for plan().
	 */
	public static function tier() {
		return self::plan();
	}

	/**
	 * Current plan slug. Free and sovereign without paid restrictions.
	 *
	 * @return string elite
	 */
	public static function plan() {
		return 'elite';
	}

	/**
	 * Re-check license. Always returns true.
	 */
	public static function revalidate() {
		return true;
	}

	/**
	 * Check if the active plan is at least a certain level.
	 *
	 * @param string $min_plan free|pro|elite.
	 * @return bool
	 */
	public static function at_least( $min_plan ) {
		return true;
	}

	/**
	 * Gate a feature based on the plan.
	 * All features permanently unlocked and free.
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public static function has_feature( $feature ) {
		return true;
	}

	/**
	 * Get usage limits for the current plan.
	 * Unlimited posts and keywords.
	 */
	public static function limits() {
		return array(
			'posts_per_day' => 9999,
			'keywords'      => 999999,
			'label'         => 'Sovereign Edition (Free & Unlimited)',
		);
	}

	/**
	 * Verify a license key. Always returns true.
	 */
	public static function verify( $key = '' ) {
		return true;
	}
}
