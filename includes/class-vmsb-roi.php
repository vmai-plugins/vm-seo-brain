<?php
defined( 'ABSPATH' ) || exit;

/**
 * ROI and conversion engine.
 *
 * Merges what the old plugin split into four (ROI engine, ROI leak detector,
 * conversion engine, conversion forecaster) because they're one question asked
 * at different points: is traffic actually worth anything, where is it
 * leaking before it converts, and what should we do about the pages that get
 * clicks but produce nothing.
 *
 * "Conversion" here is deliberately whatever the site owner defines it as in
 * settings (a form, a call, a purchase) - this module never assumes an
 * e-commerce checkout event exists.
 */
class VMSB_ROI {

	private $ai;
	private $google;

	public function __construct() {
		$this->ai     = new VMSB_AI_Router();
		$this->google = new VMSB_Google();
	}

	/**
	 * Pages that earn clicks but show no sign of the conversion goal being
	 * reachable - no CTA, no clear next step, or (when GA4 is connected) a high
	 * bounce/short-session signal on high-traffic pages.
	 */
	public function find_leaks( $limit = 15 ) {
		$rows = $this->google->gsc_query( array( 'page' ), 28, 200 );
		if ( is_wp_error( $rows ) ) {
			return array( 'checked' => 0, 'leaks' => 0 );
		}

		usort( $rows, static fn( $a, $b ) => ( $b['clicks'] ?? 0 ) <=> ( $a['clicks'] ?? 0 ) );
		$top = array_slice( $rows, 0, $limit );

		$leaks = 0;
		foreach ( $top as $row ) {
			$url     = $row['keys'][0] ?? '';
			$post_id = $url ? url_to_postid( $url ) : 0;
			if ( ! $post_id ) {
				continue;
			}

			$post = get_post( $post_id );
			$has_cta = (bool) get_post_meta( $post_id, '_vmsb_cta_inserted', true )
				|| (bool) preg_match( '/<a\s[^>]*class=["\'][^"\']*\b(cta|button|btn)\b/i', $post->post_content )
				|| (bool) preg_match( '/\b(contact us|get a quote|book a|call now|sign up|buy now|schedule)\b/i', $post->post_content );

			if ( ! $has_cta && (int) $row['clicks'] >= 5 ) {
				$this->file_leak( $post_id, (int) $row['clicks'] );
				$leaks++;
			}
		}

		return array( 'checked' => count( $top ), 'leaks' => $leaks );
	}

	private function file_leak( $post_id, $clicks ) {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_issues';
		$dupe  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE object_id = %d AND rule = 'roi_leak' AND status = 'open'", $post_id
		) );
		if ( $dupe ) {
			return;
		}
		$wpdb->insert( $table, array(
			'object_type' => 'post',
			'object_id'   => $post_id,
			'rule'        => 'roi_leak',
			'severity'    => $clicks >= 20 ? 'high' : 'medium',
			'detail'      => "Earns {$clicks} clicks/month with no clear conversion path on the page.",
			'suggested'   => wp_json_encode( array( 'clicks' => $clicks ) ),
			'status'      => 'open',
			'detected_at' => current_time( 'mysql' ),
		) );
	}

	/**
	 * Sentient Sniper: Insert a surgical CTA matched to search intent.
	 */
	public function insert_cta( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_roi', 'Post not found.' );
		}
		if ( get_post_meta( $post_id, '_vmsb_cta_inserted', true ) ) {
			return array( 'skipped' => 'already has one' );
		}

		if ( class_exists( 'VMSB_Integrations' ) && VMSB_Integrations::is_elementor_page( $post_id ) && (int) VMSB_Settings::get( 'elementor_safe_mode', 1 ) ) {
			return new WP_Error( 'vmsb_roi', 'This page is built in Elementor - surgical insertion skipped to avoid layout corruption.' );
		}

		$goal  = VMSB_Settings::get( 'conversion_goal' );
		$style = VMSB_Settings::get( 'cta_style', 'direct, one clear action, no pressure tactics' );
		if ( ! $goal ) {
			return new WP_Error( 'vmsb_roi', 'No conversion goal set in Settings.' );
		}

		// Detect Intent and Context
		$keyword = ( new VMSB_RankMath() )->get_focus_keyword( $post_id );
		global $wpdb;
		$kw_data = $wpdb->get_row( $wpdb->prepare( "SELECT intent FROM {$wpdb->prefix}vmsb_keywords WHERE keyword = %s", $keyword ) );
		$intent = $kw_data ? $kw_data->intent : 'informational';

		$brain  = new VMSB_Brain();
		$prompt = "Act as an Elite Conversion Rate Optimizer (CRO).\n"
			. "ARTICLE: \"{$post->post_title}\"\n"
			. "INTENT: {$intent}\n"
			. "BUSINESS GOAL: {$goal}\n"
			. "STYLE: {$style}\n\n"
			. "TASK: Create a surgical CTA block for this article.\n"
			. "1. If intent is INFORMATIONAL: Offer a valuable 'Lead Magnet' (e.g., Free Guide, Checklist, or Deep Insight) related to the topic.\n"
			. "2. If intent is TRANSACTIONAL/COMMERCIAL: Offer a 'Direct Path' (e.g., Free Quote, Consultation, or Product Demo).\n"
			. "3. Tone must be highly relevant to the article context—not a generic footer.\n\n"
			. 'Return JSON: {"headline":"","body":"","button_text":"","target_url":"#contact","html_block":""}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 600, 'complexity' => 'premium', 'persona' => 'creative' ) );

		if ( empty( $data['html_block'] ) ) {
			// Fallback to basic HTML construction if the model failed the specific field
			if ( ! empty($data['headline']) ) {
				$data['html_block'] = "<div class='vmsb-cta-box'><h4>{$data['headline']}</h4><p>{$data['body']}</p><a href='{$data['target_url']}' class='vmsb-cta-btn'>{$data['button_text']}</a></div>";
			} else {
				return new WP_Error( 'vmsb_roi', 'Could not generate surgical CTA.' );
			}
		}

		$before = $post->post_content;
		$block  = "\n\n<!-- wp:group {\"className\":\"vmsb-sentient-cta\"} -->\n"
				. "<div class=\"wp-block-group vmsb-sentient-cta\">" . $data['html_block'] . "</div>\n"
				. "<!-- /wp:group -->\n\n";

		// SURGICAL PLACEMENT: Try to find the second H2 or the middle of the post
		$parts = preg_split( '/(<h2[^>]*>.*?<\/h2>)/is', $before, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $parts ) >= 5 ) {
			// Insert after the second H2 (parts[1] is 1st H2, parts[2] is text, parts[3] is 2nd H2)
			$updated = $parts[0] . $parts[1] . $parts[2] . $parts[3] . $block . implode( '', array_slice( $parts, 4 ) );
		} else {
			// Fallback: Append to end
			$updated = $before . $block;
		}

		wp_update_post( array( 'ID' => $post_id, 'post_content' => $updated ) );
		update_post_meta( $post_id, '_vmsb_cta_inserted', 1 );
		update_post_meta( $post_id, '_vmsb_cta_data', $data );

		if ( class_exists( 'VMSB_Outcome_Ledger' ) && (int) VMSB_Settings::get( 'learning_enabled' ) ) {
			VMSB_Outcome_Ledger::record( array(
				'module'     => 'roi',
				'action'     => 'sniper_cta',
				'object_id'  => $post_id,
				'hypothesis' => "Surgical {$intent} CTA match should increase goal completion rate.",
			) );
		}

		return array( 'inserted' => true, 'post_id' => $post_id, 'post_content' => $before );
	}

	/**
	 * Sweep open ROI-leak issues and insert CTAs on the strongest few. Respects
	 * God Mode scope the same way the fixer does - this only runs automatically
	 * when God Mode is on, since it edits live content.
	 */
	public function sweep( $limit = 3 ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT object_id FROM {$wpdb->prefix}vmsb_issues WHERE rule = 'roi_leak' AND status = 'open' ORDER BY severity = 'high' DESC, id ASC LIMIT %d",
			(int) $limit
		) );
		$done = 0;
		foreach ( $ids as $id ) {
			$res = $this->insert_cta( (int) $id );
			if ( ! is_wp_error( $res ) && empty( $res['skipped'] ) ) {
				$done++;
			}
			$wpdb->update( $wpdb->prefix . 'vmsb_issues', array( 'status' => 'fixed', 'fixed_at' => current_time( 'mysql' ), 'fixed_by' => 'roi' ), array( 'object_id' => $id, 'rule' => 'roi_leak', 'status' => 'open' ) );
		}
		return array( 'processed' => count( $ids ), 'ctas_added' => $done );
	}

	/**
	 * Forecast conversions from current traffic trajectory, using the growth
	 * model's own trend plus an assumed conversion rate the model estimates
	 * from the business type and the CTA coverage on the site.
	 */
	public function forecast() {
		global $wpdb;
		$growth = ( new VMSB_Growth() )->status();

		// Get real conversion data from the metrics table if available (Upgraded)
		$real_conversions = (int) $wpdb->get_var(
			"SELECT SUM(conversions) FROM {$wpdb->prefix}vmsb_metrics WHERE source = 'ga4' AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
		);
		$real_sessions = (int) $wpdb->get_var(
			"SELECT SUM(sessions) FROM {$wpdb->prefix}vmsb_metrics WHERE source = 'ga4' AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
		);
		$actual_rate = ( $real_sessions > 0 ) ? round( ($real_conversions / $real_sessions) * 100, 2) : null;

		$pages_with_cta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_vmsb_cta_inserted'" );
		$total_posts    = (int) wp_count_posts( 'post' )->publish;
		$cta_coverage   = $total_posts > 0 ? round( $pages_with_cta / $total_posts * 100 ) : 0;

		$brain  = new VMSB_Brain();
		$goal   = VMSB_Settings::get( 'conversion_goal', 'a lead or sale' );

		$data_context = "Current traffic trend: " . wp_json_encode( $growth ) . "\n"
			. "CTA coverage: {$cta_coverage}% of published posts.\n"
			. "Actual 30d Conversion Rate: " . ( $actual_rate !== null ? $actual_rate . "%" : "No historical data yet" ) . "\n"
			. "Conversion goal: {$goal}\n\n";

		$prompt = $data_context
			. "Estimate a plausible, conservative conversion rate range for a business like this. "
			. "If actual historical data is provided above, anchor your estimate to it. "
			. "Project monthly conversions at current traffic and at +25% traffic. "
			. "Be honest that this is an estimate, not a guarantee.\n\n"
			. 'Return JSON: {"estimated_rate_low":0.0,"estimated_rate_high":0.0,"conversions_at_current":0,"conversions_at_plus25pct":0,"caveat":""}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 400, 'temperature' => 0.2 ) );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'vmsb_roi', $this->ai->get_last_error() ?: 'Could not build a forecast.' );
		}

		$data['actual_historical_rate'] = $actual_rate;
		update_option( 'vmsb_conversion_forecast', array_merge( $data, array( 'generated_at' => current_time( 'mysql' ), 'cta_coverage' => $cta_coverage ) ), false );
		return $data;
	}

	public function calculate_revenue_opportunity( $post_id ) {
		$ga4 = new VMSB_GA4();
		if ( ! $ga4->is_connected() ) return 0;

		$post = get_post($post_id);
		$path = wp_parse_url(get_permalink($post_id), PHP_URL_PATH);

		$metrics = $ga4->get_all_landing_page_metrics( 30 );
		$page_data = $metrics[$path] ?? array('sessions' => 0, 'conversions' => 0, 'revenue' => 0);

		// Revenue Opportunity Score = (Sessions * Avg Order Value * Potential Conversion Rate)
		// Or if we have real revenue, use that as the baseline.
		$current_revenue = (float) $page_data['revenue'];
		$avg_order_value = $current_revenue > 0 && $page_data['conversions'] > 0 ? ($current_revenue / $page_data['conversions']) : 50; // Default $50

		$intent = (new VMSB_Keywords())->get_keyword_intent_for_post($post_id);
		$intent_multiplier = ($intent === 'transactional' || $intent === 'commercial') ? 2.5 : 1.0;

		$score = ( ($page_data['sessions'] + 1) * $avg_order_value * 0.02 ) * $intent_multiplier;

		return round($score, 2);
	}
}
