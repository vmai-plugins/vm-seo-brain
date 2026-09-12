<?php
defined( 'ABSPATH' ) || exit;

/**
 * Opportunity Engine (VM SEO Brain X).
 *
 * Central discovery node for all growth levers: GSC gaps, GA4 ROI,
 * decay, and internal link weaknesses.
 */
class VMSB_Opportunity_Engine {

	private $ai;
	private $brain;
	private $log;

	public function __construct() {
		$this->ai    = new VMSB_AI_Router();
		$this->brain = new VMSB_Brain();
		$this->log   = new VMSB_Logger();
	}

	/**
	 * Run the 21-step Opportunity Discovery cycle.
	 */
	public function discover_all() {
		$this->log->info( 'intelligence', 'Starting Opportunity Discovery cycle...' );

		$ops = array();

		// 1. Content Gaps (Search Console)
		$ops = array_merge( $ops, $this->scan_gsc_gaps() );

		// 2. Content Decay (Traffic Drops)
		$ops = array_merge( $ops, $this->scan_decay() );

		// 3. Conversion Leaks (GA4)
		$ops = array_merge( $ops, $this->scan_conversion_leaks() );

		// 4. Strategic Competitor Gaps
		$ops = array_merge( $ops, $this->scan_competitor_gaps() );

		// 5. Cannibalization Audit (Semantic)
		$ops = array_merge( $ops, $this->scan_cannibalization() );

		// 6. Tactical Competitor Gaps (Feature Deficit)
		$ops = array_merge( $ops, $this->scan_tactical_gaps() );

		// Score and Prioritize
		foreach ( $ops as &$op ) {
			$op['priority'] = $this->calculate_growth_score( $op );
		}

		usort( $ops, fn($a, $b) => $b['priority'] <=> $a['priority'] );

		$this->brain->remember( 'intelligence', 'active_opportunities', array_slice($ops, 0, 30), 0.9, 'opportunity_engine' );

		return count($ops);
	}

	/**
	 * Growth Opportunity Score (Verified Standard).
	 * Divide high-value metrics by execution effort.
	 */
	private function calculate_growth_score( $op ) {
		$impact     = (float) ($op['impact'] ?? 50);
		$confidence = (float) ($op['confidence'] ?? 0.7);
		$effort     = (float) ($op['effort'] ?? 1.5);

		// 2026 ROI-FIRST Math
		$business_value = (float) ($op['business_value'] ?? 1.0);
		if ( ! empty($op['object_id']) && (int)$op['object_id'] > 0 ) {
			$roi_agent = new VMSB_ROI();
			$rev_opp = $roi_agent->calculate_revenue_opportunity( $op['object_id'] );
			if ( $rev_opp > 500 ) $business_value *= 2.0; // Significant revenue upside
		}

		// Scoring logic: (Impact * Confidence * Business Value) / Effort
		$score = ( $impact * $confidence * $business_value ) / max(0.5, $effort);

		return round( min(100, $score), 1 );
	}

	private function scan_gsc_gaps() {
		$keywords = new VMSB_Keywords();
		$striking = $keywords->striking_distance( 10 );
		$found = array();

		foreach ( $striking as $kw ) {
			$position = (float) $kw->position;
			// Closer to position 4 is a genuinely easier, higher-payoff
			// climb to Top 3 than closer to position 20 - was a flat 75
			// regardless of whether this was position 5 or position 19.
			$impact = round( max( 40, 100 - ( $position * 3 ) ) );
			// More impressions is more evidence this keyword is real,
			// steady demand rather than a one-off blip.
			$confidence = min( 0.95, 0.6 + ( min( (int) $kw->impressions, 2000 ) / 2000 ) * 0.35 );

			$found[] = array(
				'type'           => 'UPDATE_CONTENT',
				'target'         => get_the_title($kw->post_id),
				'object_id'      => $kw->post_id,
				'reason'         => "Striking distance: Position {$kw->position} for keyword '{$kw->keyword}' ({$kw->impressions} impressions).",
				'impact'         => $impact,
				'confidence'     => round( $confidence, 2 ),
				'business_value' => 1.2,
				'effort'         => 1.0,
				'recommended'    => 'Deep refresh to reclaim Top 3.'
			);
		}
		return $found;
	}

	private function scan_decay() {
		$lifecycle = new VMSB_Lifecycle();
		$report = $lifecycle->audit_all_assets();
		$google  = new VMSB_Google();
		$found = array();

		// Priority 1: Decaying (Losing Clicks). Every one of these used to
		// get impact=85 flat, whether the underlying drop was 31% or 90% -
		// audit_all_assets() only returns post IDs bucketed by stage, not
		// the magnitude that put them there, so re-check the real current-
		// vs-previous ratio directly (bounded to the same 5-candidate slice
		// as before, so this can't become an unbounded per-post API loop).
		foreach ( array_slice($report['decaying'] ?? array(), 0, 5) as $id ) {
			$drop_pct = null;
			$url = get_permalink( $id );
			if ( $url && $google->is_connected() ) {
				$now  = $google->gsc_page_metrics( $url, 28, 0 );
				$prev = $google->gsc_page_metrics( $url, 28, 29 );
				if ( ! is_wp_error( $now ) && ! is_wp_error( $prev ) && (int) $prev['clicks'] > 0 ) {
					$drop_pct = round( ( 1 - ( $now['clicks'] / $prev['clicks'] ) ) * 100 );
				}
			}

			if ( null !== $drop_pct ) {
				$impact     = round( min( 95, max( 55, 40 + $drop_pct ) ) );
				$confidence = 0.9;
				$reason     = "Click decay: -{$drop_pct}% vs. the prior 28-day window.";
			} else {
				// Couldn't re-verify the real ratio (GSC unreachable, or
				// this run) - proceed on the classifier's bucket alone but
				// say so honestly rather than presenting a guess as measured.
				$impact     = 70;
				$confidence = 0.6;
				$reason     = 'Flagged as decaying by the lifecycle classifier; exact drop % unavailable this run.';
			}

			$found[] = array(
				'type'           => 'REFRESH_DECAYING_CONTENT',
				'target'         => get_the_title($id),
				'object_id'      => $id,
				'reason'         => $reason,
				'impact'         => $impact,
				'confidence'     => $confidence,
				'business_value' => 1.1,
				'effort'         => 0.8,
				'recommended'    => 'Inject fresh intelligence to stop the slide.'
			);
		}

		// Priority 2: Underperforming (Low CTR). Same reasoning: re-check
		// the real current CTR/impressions for these specific candidates
		// instead of a flat impact regardless of how far under 1% they are.
		foreach ( array_slice($report['underperforming'] ?? array(), 0, 5) as $id ) {
			$url = get_permalink( $id );
			$metrics = ( $url && $google->is_connected() ) ? $google->gsc_page_metrics( $url, 28, 0 ) : null;

			if ( $metrics && ! is_wp_error( $metrics ) && $metrics['impressions'] > 0 ) {
				$ctr_pct    = round( $metrics['ctr'] * 100, 2 );
				$impact     = round( min( 90, max( 50, 60 + ( ( 500 + $metrics['impressions'] ) / 100 ) ) ) );
				$confidence = 0.85;
				$reason     = "High reach ({$metrics['impressions']} impressions) but {$ctr_pct}% click-through rate.";
			} else {
				$impact     = 65;
				$confidence = 0.6;
				$reason     = 'Flagged as low-CTR by the lifecycle classifier; exact numbers unavailable this run.';
			}

			$found[] = array(
				'type'           => 'IMPROVE_CTR',
				'target'         => get_the_title($id),
				'object_id'      => $id,
				'reason'         => $reason,
				'impact'         => $impact,
				'confidence'     => $confidence,
				'business_value' => 1.0,
				'effort'         => 0.4,
				'recommended'    => 'Run a surgical CTR title/meta experiment.'
			);
		}

		return $found;
	}

	private function scan_conversion_leaks() {
		$ga4 = new VMSB_GA4();
		if ( ! $ga4->is_connected() ) return array();

		$metrics = $ga4->get_all_landing_page_metrics();
		$found = array();

		foreach ( $metrics as $path => $data ) {
			$post_id = url_to_postid( $path );
			if ( ! $post_id ) continue;

			// If sessions are high but conversions are 0
			if ( $data['sessions'] > 100 && $data['conversions'] === 0 ) {
				// More sessions converting to zero is a bigger, more
				// confidently-real leak than one just over the 100
				// threshold - was a flat 85/0.75 either way.
				$impact     = round( min( 95, 70 + ( min( $data['sessions'], 2000 ) / 100 ) ) );
				$confidence = min( 0.9, 0.65 + ( min( $data['sessions'], 1000 ) / 1000 ) * 0.25 );

				$found[] = array(
					'type'           => 'ADD_SENTIENT_CTA',
					'target'         => get_the_title($post_id),
					'object_id'      => $post_id,
					'reason'         => "High traffic ({$data['sessions']} sessions) but 0 conversions.",
					'impact'         => $impact,
					'confidence'     => round( $confidence, 2 ),
					'business_value' => 2.0, // High ROI potential
					'effort'         => 0.5, // Quick fix
					'recommended'    => 'Inject a surgical CTA matched to search intent.'
				);
			}
		}
		return $found;
	}

	private function scan_competitor_gaps() {
		global $wpdb;
		$issues = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_issues WHERE rule = 'competitor_gap' AND status = 'open' LIMIT 10" );
		$found = array();

		foreach ( $issues as $issue ) {
			$suggested = json_decode($issue->suggested, true);
			$found[] = array(
				'type'           => 'CREATE_TAKEDOWN_CONTENT',
				'target'         => $suggested['query'] ?? 'Competitor Gap',
				'object_id'      => 0,
				'reason'         => $issue->detail,
				'impact'         => 80,
				'confidence'     => 0.7,
				'business_value' => 1.5,
				'effort'         => 2.5,
				'recommended'    => 'Execute a Quantum Heist to steal this ranking.'
			);
		}
		return $found;
	}

	private function scan_cannibalization() {
		if ( ! (int) VMSB_Settings::get( 'vector_enabled' ) || ! class_exists( 'VMSB_Vector_Store' ) ) {
			return array();
		}

		$found = array();
		global $wpdb;

		// Find keywords with multiple pages ranking (simulated via high impressions on same keywords)
		$duplicates = $wpdb->get_results( "
			SELECT keyword, COUNT(DISTINCT post_id) as posts
			FROM {$wpdb->prefix}vmsb_keywords
			WHERE post_id > 0
			GROUP BY keyword
			HAVING posts > 1
			LIMIT 10
		" );

		foreach ( $duplicates as $dupe ) {
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}vmsb_keywords WHERE keyword = %s", $dupe->keyword ) );
			if ( count($post_ids) < 2 ) continue;

			// 2 pages splitting a query's authority is a real but modest
			// problem; 5+ pages fighting over the same query is much worse
			// - was a flat 80 either way.
			$competing_count = count( $post_ids );
			$impact = round( min( 95, 65 + ( ( $competing_count - 2 ) * 10 ) ) );

			$found[] = array(
				'type'           => 'CONSOLIDATE_CONTENT',
				'target'         => $dupe->keyword,
				'object_id'      => $post_ids[0], // Primary post candidate
				'reason'         => "Cannibalization detected: {$competing_count} pages competing for '{$dupe->keyword}'.",
				'impact'         => $impact,
				'confidence'     => 0.85,
				'business_value' => 1.3,
				'effort'         => 2.5,
				'recommended'    => "Consolidate into #{$post_ids[0]} and redirect #{$post_ids[1]}.",
				'meta'           => array( 'duplicate_ids' => array_slice($post_ids, 1) )
			);
		}

		return $found;
	}

	private function scan_tactical_gaps() {
		$keywords = new VMSB_Keywords();
		$striking = $keywords->striking_distance( 5 ); // Limit to top 5 to save tokens
		$serp_agent = new VMSB_SERP();
		$found = array();

		foreach ( $striking as $kw ) {
			if ( ! $kw->post_id ) continue;

			$blueprint = $serp_agent->get_blueprint( $kw->keyword );
			if ( empty($blueprint['trust_features']) && empty($blueprint['required_entities']) ) continue;

			// Logic: If Top 3 all use a 'Calculator' or 'Case Study' and we don't, it's a Tactical Gap.
			$post = get_post($kw->post_id);
			if ( ! $post ) {
				continue;
			}
			$missing_features = array();
			foreach ( (array)$blueprint['trust_features'] as $feature ) {
				if ( stripos($post->post_content, $feature) === false ) {
					$missing_features[] = $feature;
				}
			}

			if ( ! empty($missing_features) ) {
				// Missing one of three trust signals is a smaller gap than
				// missing all of them - was a flat 85 either way. Real
				// fetched-page blueprints (see VMSB_SERP) also carry more
				// weight than the AI-estimate fallback.
				$missing_count = count( $missing_features );
				$impact        = round( min( 92, 65 + ( $missing_count * 10 ) ) );
				$confidence    = ( 'fetched_pages' === ( $blueprint['source'] ?? '' ) ) ? 0.9 : 0.7;

				$found[] = array(
					'type'           => 'ADD_TACTICAL_FEATURES',
					'target'         => $post->post_title,
					'object_id'      => $kw->post_id,
					'reason'         => "Tactical Deficit: Top 3 results use features we lack: " . implode(', ', $missing_features),
					'impact'         => $impact,
					'confidence'     => $confidence,
					'business_value' => 1.2,
					'effort'         => 1.0,
					'recommended'    => "Inject " . implode(' and ', $missing_features) . " to reclaim competitive parity.",
					'meta'           => array( 'features' => $missing_features )
				);
			}
		}

		return $found;
	}
}
