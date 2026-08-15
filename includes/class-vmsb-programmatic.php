<?php
defined( 'ABSPATH' ) || exit;

/**
 * Programmatic SEO Engine (Elite Standard).
 *
 * Ported & Hardened from VM AI SEO.
 * Generates high-volume, data-driven content clusters to dominate specific niches.
 * Includes "Sentient Briefs" to avoid thin content penalties.
 */
class VMSB_Programmatic {

	const HARD_DAILY_CAP = 30; // Increased for Elite port

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	public function is_enabled() {
		return (int) VMSB_Settings::get( 'programmatic_enabled' );
	}

	public function remaining_today() {
		$cap = min( self::HARD_DAILY_CAP, max( 1, (int) VMSB_Settings::get( 'programmatic_daily_cap', 15 ) ) );
		$key = 'vmsb_pseo_generated_' . gmdate( 'Ymd' );
		return max( 0, $cap - (int) get_option( $key, 0 ) );
	}

	private function count_generated( $n = 1 ) {
		$key = 'vmsb_pseo_generated_' . gmdate( 'Ymd' );
		update_option( $key, (int) get_option( $key, 0 ) + $n, false );
	}

	/**
	 * Build a set of plan rows from a template + variable list.
	 * Enhanced with Sentient Brief logic, Uniqueness Gates, and Dry Run support.
	 */
	public function build_set( $template, array $variables, $base_keyword = '', $dry_run = false ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'vmsb_pseo', 'Programmatic SEO is not enabled.' );
		}

		$combos = self::expand( $template, $variables );
		if ( ! $combos ) {
			return new WP_Error( 'vmsb_pseo', 'No combinations produced.' );
		}

		if ( $dry_run ) {
			$previews = array();
			foreach ( array_slice($combos, 0, 5) as $combo ) {
				$previews[] = strtr( $template, self::braces( $combo ) );
			}
			return array( 'dry_run' => true, 'count' => count($combos), 'previews' => $previews );
		}

		$remaining = $this->remaining_today();
		$combos    = array_slice( $combos, 0, $remaining );

		if ( ! $combos ) {
			return new WP_Error( 'vmsb_pseo', 'Daily programmatic cap already reached.' );
		}

		$created = 0;
		$this->log->info( 'programmatic', "Building pSEO batch for template: '{$template}'" );

		// Travel Scenario Logic: Detect CPT Target
		$target_cpt = 'post';
		if ( strpos(strtolower($template), 'destination') !== false && post_type_exists('destinations') ) {
			$target_cpt = 'destinations';
		} elseif ( strpos(strtolower($template), 'event') !== false && post_type_exists('events') ) {
			$target_cpt = 'events';
		}

		foreach ( $combos as $combo ) {
			$title = strtr( $template, self::braces( $combo ) );
			$var_value = reset($combo); // Usually first var is the main one (city/product)

			// PORTED: Sentient Brief Generation (Pre-researching uniqueness)
			$brief = $this->generate_sentient_brief( $title, $var_value );

			$row = array(
				'row_uid'            => substr(md5($title . '|' . wp_json_encode($combo)), 0, 24),
				'title'              => $title,
				'primary_keyword'    => trim( $base_keyword . ' ' . implode( ' ', $combo ) ),
				'cluster'            => 'programmatic',
				'intent'             => 'transactional',
				'content_type'       => $target_cpt, // Use detected CPT
				'brief'              => "SENTIENT pSEO BRIEF: " . $brief,
				'internal_links'     => wp_json_encode( array() ),
				'target_words'       => 1200,
				'priority'           => 1.5,
				'status'             => 'planned',
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			);

			global $wpdb;
			$wpdb->insert( $wpdb->prefix . 'vmsb_plan', $row );
			$created++;
		}

		$this->count_generated( $created );
		$this->log->info( 'programmatic', "Queued {$created} programmatic pages.", array( 'template' => $template ) );

		return array( 'queued' => $created, 'remaining_today' => $this->remaining_today() );
	}

	/**
	 * Generates a high-fidelity brief for a programmatic page.
	 */
	private function generate_sentient_brief( $title, $var ) {
		$prompt = "Act as a Content Architect. We are generating a programmatic SEO page.\n"
			. "TITLE: \"{$title}\"\n"
			. "VARIABLE: \"{$var}\"\n\n"
			. "TASK: Provide 3 unique, data-driven talking points or 'insider facts' about this specific variable that MUST be included in the post to avoid 'Thin Content' penalties.\n"
			. "Ensure the content is high-utility for a local/specific user. Return ONLY the talking points as a list.";

		$res = $this->ai->generate( $prompt, array( 'max_tokens' => 400, 'persona' => 'strategist' ) );
		return ! empty($res['ok']) ? $res['text'] : "Programmatic SEO Page for {$var}";
	}

	/**
	 * PORTED: Variable Discovery Engine.
	 */
	public function discover_variables( $seed_topic, $count = 20 ) {
		$prompt = "Act as a Programmatic SEO Expert. Seed Topic: \"{$seed_topic}\"\n\n"
			. "TASK: Generate a list of {$count} highly relevant variables that can be used to create programmatic pages.\n"
			. "If it's a local service, provide cities. If it's a product, provide categories or use cases.\n"
			. "Return ONLY a JSON array of strings: [\"Var 1\", \"Var 2\", ...]";

		return $this->ai->generate_json( $prompt, array( 'max_tokens' => 1000, 'persona' => 'strategist' ) );
	}

	/**
	 * PORTED: Strategic "Quantum Blast" - Orchestrates mass niche domination.
	 */
	public function run_quantum_blast() {
		$profile = ( new VMSB_Brain() )->profile();
		$prompt = "Act as a Data-Growth Scientist for '{$profile['name']}'.\n\n"
			. "TASK: Identify 3 distinct high-volume programmatic SEO niches for this business.\n"
			. "Example: 'Best [Service] in [City]', 'Comparing [Product A] vs [Product B]'.\n"
			. "Return ONLY a JSON array of 3 objects: [{\"template\": \"...\", \"base_keyword\": \"\"}]";

		$ideas = $this->ai->generate_json( $prompt, array( 'complexity' => 'premium', 'persona' => 'strategist' ) );
		$total_added = 0;

		if ( is_array( $ideas ) ) {
			foreach ( $ideas as $idea ) {
				$vars = $this->discover_variables( $idea['template'], 10 );
				if ( is_array($vars) ) {
					$res = $this->build_set( $idea['template'], array( 'var' => $vars ), $idea['base_keyword'] );
					if ( ! is_wp_error($res) ) $total_added += $res['queued'];
				}
			}
		}

		return $total_added;
	}

	private static function expand( $template, array $variables ) {
		if ( ! $variables ) return array();
		$keys  = array_keys( $variables );
		$lists = array_values( $variables );

		$combos = array( array() );
		foreach ( $lists as $i => $values ) {
			$next = array();
			foreach ( $combos as $partial ) {
				foreach ( array_slice( (array) $values, 0, 50 ) as $value ) {
					$next[] = $partial + array( $keys[ $i ] => trim( (string) $value ) );
				}
			}
			$combos = $next;
			if ( count( $combos ) > 200 ) break;
		}

		shuffle( $combos );
		return array_slice( $combos, 0, self::HARD_DAILY_CAP * 2 );
	}

	private static function braces( array $combo ) {
		$out = array();
		foreach ( $combo as $k => $v ) {
			$out[ '{' . $k . '}' ] = $v;
			$out[ '{{' . $k . '}}' ] = $v; // Support both single and double braces
			$out[ '{{var}}' ] = $v; // Support generic var placeholder from old plugin
		}
		return $out;
	}

	public function stats() {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_plan';
		return array(
			'total'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type = 'programmatic'" ),
			'published'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type = 'programmatic' AND status IN ('published','drafted')" ),
			'rejected'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE content_type = 'programmatic' AND status = 'rejected'" ),
			'remaining_today' => $this->remaining_today(),
		);
	}
}
