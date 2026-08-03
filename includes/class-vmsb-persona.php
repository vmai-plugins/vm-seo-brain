<?php
defined( 'ABSPATH' ) || exit;

/**
 * Persona Manager (E-E-A-T Engine).
 * Generates and assigns expert personas to content categories to build topical authority.
 */
class VMSB_Persona {

	public static function get_persona_for_category( $cat_id ) {
		$personas = get_option( 'vmsb_personas', array() );
		if ( isset( $personas[ $cat_id ] ) ) {
			return $personas[ $cat_id ];
		}

		// Generate a new expert persona for this category
		$cat   = get_term( $cat_id, 'category' );
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		$prompt = "Create a professional expert persona for the niche: '{$cat->name}'.\n"
			. "Include: Name, Title (e.g. Senior Strategist), 2-sentence bio, and specific areas of expertise.\n"
			. "Return JSON: {\"name\":\"\",\"title\":\"\",\"bio\":\"\",\"expertise\":[]}";

		$res = $ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'standard' ) );

		if ( $res ) {
			$personas[ $cat_id ] = $res;
			update_option( 'vmsb_personas', $personas );
			return $res;
		}

		return null;
	}

	public static function apply_to_post( $post_id ) {
		$categories = wp_get_post_categories( $post_id );
		if ( ! $categories ) return;

		$persona = self::get_persona_for_category( $categories[0] );
		if ( $persona ) {
			update_post_meta( $post_id, '_vmsb_author_persona', $persona );

			// Every other autonomous post_content rewrite in the plugin skips
			// Elementor-built pages when safe mode is on (a full-content
			// wp_update_post() corrupts the page builder's layout) - this path
			// was missing that same guard.
			if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
				return;
			}

			// Add a bio block to the end of the content if not already present
			$post    = get_post( $post_id );
			$bio_html = "\n\n<!-- wp:separator --><hr class=\"wp-block-separator\"/><!-- /wp:separator -->\n"
				. "<!-- wp:paragraph -->"
				. '<p><strong>About the Author:</strong> ' . esc_html( $persona['name'] ?? '' ) . ' is a ' . esc_html( $persona['title'] ?? '' ) . '. ' . esc_html( $persona['bio'] ?? '' ) . '</p>'
				. "<!-- /wp:paragraph -->";

			if ( strpos( $post->post_content, 'About the Author' ) === false ) {
				wp_update_post( array( 'ID' => $post_id, 'post_content' => $post->post_content . $bio_html ) );
			}
		}
	}
}
