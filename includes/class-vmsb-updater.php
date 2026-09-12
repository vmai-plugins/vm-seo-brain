<?php
defined( 'ABSPATH' ) || exit;

/**
 * Backward compatibility wrapper for VMSB_Updater.
 * Extends the new enterprise VMSB_GitHub_Updater.
 */
class VMSB_Updater extends VMSB_GitHub_Updater {

	/**
	 * Legacy wrapper for apply_github() -> perform_direct_update().
	 */
	public static function apply_github() {
		$res = self::perform_direct_update();
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return true;
	}
}
