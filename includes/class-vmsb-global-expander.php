<?php
defined( 'ABSPATH' ) || exit;

/**
 * Global / local expansion.
 *
 * Takes a post that is already proven to work (real clicks, real conversions)
 * and adapts it for another location - not a template swap, a genuine
 * localisation: local references, currency, units, and any location-specific
 * facts are asked for explicitly, and the result still passes the same
 * quality gate as anything else, with the vector duplicate check comparing the
 * new version against the original to make sure it earns its own URL.
 *
 * Shares the programmatic engine's daily cap rather than having a separate
 * one - both are the same underlying risk (scaled near-duplicate output), so
 * they draw from the same budget.
 */
class VMSB_Global_Expander {

	public function locations() {
		$raw = (string) VMSB_Settings::get( 'global_locations', '' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	public function expand( $post_id, array $locations = array() ) {
		$programmatic = new VMSB_Programmatic();
		if ( ! $programmatic->is_enabled() ) {
			return new WP_Error( 'vmsb_global', 'Enable Programmatic SEO in Settings first - global expansion shares its cap and guardrails.' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_global', 'Post not found.' );
		}

		$locations = $locations ?: $this->locations();
		if ( ! $locations ) {
			return new WP_Error( 'vmsb_global', 'No locations configured.' );
		}

		$remaining = $programmatic->remaining_today();
		$locations = array_slice( $locations, 0, $remaining );
		if ( ! $locations ) {
			return new WP_Error( 'vmsb_global', 'Daily programmatic cap already reached.' );
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'vmsb_plan';
		$queued = 0;

		foreach ( $locations as $location ) {
			$brief = "Adapt this proven article for {$location}:\n\n"
				. mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 ) . "\n\n"
				. "This must be a genuine localisation, not a find-and-replace: swap any currency, units, and specific place references for {$location}, "
				. "and include at least one detail genuinely specific to {$location}. If you don't have a verifiable local fact, write around it rather than inventing one.";

			$wpdb->insert( $table, array(
				'row_uid'            => wp_generate_uuid4(),
				'title'              => $post->post_title . ' in ' . $location,
				'primary_keyword'    => trim( get_post_meta( $post_id, 'rank_math_focus_keyword', true ) . ' ' . $location ),
				'secondary_keywords' => wp_json_encode( array() ),
				'cluster'            => 'global-expansion',
				'intent'             => 'transactional',
				'content_type'       => 'programmatic',
				'brief'              => $brief,
				'internal_links'     => wp_json_encode( array( array( 'url' => get_permalink( $post_id ), 'title' => $post->post_title ) ) ),
				'target_words'       => 900,
				'priority'           => 0.6,
				'status'             => 'planned',
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			) );
			$queued++;
		}

		return array( 'queued' => $queued, 'locations' => $locations );
	}
}
