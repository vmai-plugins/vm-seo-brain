<?php
defined( 'ABSPATH' ) || exit;

/**
 * Integration bridges: Google Site Kit and Elementor.
 *
 * Neither of these does new work - they exist so the brain doesn't step on a
 * tool the site already has installed. A site running Site Kit already has a
 * GA4/GSC connection; a site built in Elementor stores its layout as JSON
 * meta, not in post_content, so writing to post_content directly would be
 * invisible or (worse) get discarded on next Elementor save.
 */
class VMSB_Integrations {

	/* ---------------------------------------------------------------- Site Kit */

	public static function sitekit_active() {
		return defined( 'GOOGLESITEKIT_VERSION' ) || class_exists( 'Google\Site_Kit\Plugin' );
	}

	/**
	 * When Site Kit is active and preferred, and its own GSC/GA4 connection
	 * exists, the brain's own OAuth flow is unnecessary - this just tells the
	 * settings screen so it can say "Site Kit is already connected" instead of
	 * asking the user to connect twice.
	 */
	public static function sitekit_status() {
		if ( ! self::sitekit_active() ) {
			return array( 'active' => false );
		}
		$connected = (bool) get_option( 'googlesitekit_has_connected_admins' ) || (bool) get_option( 'googlesitekit_site_verification' );
		return array(
			'active'    => true,
			'connected' => $connected,
			'prefer'    => (int) VMSB_Settings::get( 'sitekit_prefer', 1 ),
		);
	}

	/* ---------------------------------------------------------------- Elementor */

	public static function elementor_active() {
		return did_action( 'elementor/loaded' ) || class_exists( '\Elementor\Plugin' );
	}

	public static function is_elementor_page( $post_id ) {
		return self::elementor_active() && '1' === get_post_meta( $post_id, '_elementor_edit_mode', true );
	}

	/**
	 * Elementor-safe write: if the page is built in Elementor, edits must go
	 * into the Elementor JSON tree (_elementor_data), not post_content, or
	 * they will not render and can be silently overwritten on the next
	 * Elementor save. This targets text widgets only - a structural change to
	 * an Elementor layout is out of scope for an SEO plugin.
	 *
	 * @return true|WP_Error
	 */
	public static function safe_text_update( $post_id, $find, $replace ) {
		if ( ! self::is_elementor_page( $post_id ) ) {
			return new WP_Error( 'vmsb_elementor', 'Not an Elementor page - use the normal post_content path.' );
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );
		$tree = json_decode( $raw, true );
		if ( ! is_array( $tree ) ) {
			return new WP_Error( 'vmsb_elementor', 'Could not parse the Elementor layout.' );
		}

		$changed = 0;
		self::walk_elementor_tree( $tree, function ( &$element ) use ( $find, $replace, &$changed ) {
			if ( empty( $element['widgetType'] ) || ! in_array( $element['widgetType'], array( 'text-editor', 'heading' ), true ) ) {
				return;
			}
			foreach ( array( 'editor', 'title' ) as $field ) {
				if ( ! empty( $element['settings'][ $field ] ) && false !== strpos( $element['settings'][ $field ], $find ) ) {
					$element['settings'][ $field ] = str_replace( $find, $replace, $element['settings'][ $field ] );
					$changed++;
				}
			}
		} );

		if ( ! $changed ) {
			return new WP_Error( 'vmsb_elementor', 'Text not found in any editable widget.' );
		}

		update_post_meta( $post_id, '_elementor_data', wp_json_encode( $tree ) );
		// Force Elementor to regenerate its CSS/render cache for this page.
		delete_post_meta( $post_id, '_elementor_css' );

		return true;
	}

	private static function walk_elementor_tree( array &$elements, callable $callback ) {
		foreach ( $elements as &$element ) {
			$callback( $element );
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::walk_elementor_tree( $element['elements'], $callback );
			}
		}
	}

	/* ---------------------------------------------------------------- VM Social AI */

	public static function social_active() {
		return class_exists( 'VMSAI_Plugin' );
	}
}
