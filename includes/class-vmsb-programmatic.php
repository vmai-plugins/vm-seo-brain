<?php
defined( 'ABSPATH' ) || exit;

/**
 * Programmatic SEO.
 *
 * Generates a set of pages from one template plus a list of variables - e.g.
 * "[service] in [city]" across 40 cities. This is the single highest scaled-
 * content-abuse risk in the plugin: it is exactly the pattern search engines'
 * scaled-content policies name directly, and it is easy to produce 40 pages
 * that differ only in a swapped noun.
 *
 * Guardrails, modelled on the same reasoning as Quantum Mode in the sibling
 * plugin:
 *   - off by default, explicit opt-in
 *   - hard daily cap that no setting can raise
 *   - every generated page still goes through the full quality gate, with the
 *     duplicate check doing the real work here - two variable pages that come
 *     out too similar to each other get rejected, not just checked against
 *     hand-written content
 *   - the template must produce genuinely differentiated pages: each variable
 *     needs its own real detail (a pulled data point, a local fact), not just
 *     a find-and-replace of the variable name
 */
class VMSB_Programmatic {

	const HARD_DAILY_CAP = 15;

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
		$cap = min( self::HARD_DAILY_CAP, max( 1, (int) VMSB_Settings::get( 'programmatic_daily_cap', 5 ) ) );
		$key = 'vmsb_pseo_generated_' . gmdate( 'Ymd' );
		return max( 0, $cap - (int) get_option( $key, 0 ) );
	}

	private function count_generated( $n = 1 ) {
		$key = 'vmsb_pseo_generated_' . gmdate( 'Ymd' );
		update_option( $key, (int) get_option( $key, 0 ) + $n, false );
	}

	/**
	 * Build a set of plan rows from a template + variable list. Each variable
	 * gets its own brief asking the writer for a specific, sourced local/
	 * contextual detail - the anti-thin-content mechanism lives here, at
	 * brief-generation time, not as an afterthought at review time.
	 *
	 * @param string $template   e.g. "{service} in {city}"
	 * @param array  $variables  e.g. ['city' => ['Indore','Bhopal',...]]
	 * @param string $base_keyword e.g. "web design"
	 */
	public function build_set( $template, array $variables, $base_keyword = '' ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'vmsb_pseo', 'Programmatic SEO is not enabled.' );
		}

		$combos = self::expand( $template, $variables );
		if ( ! $combos ) {
			return new WP_Error( 'vmsb_pseo', 'No combinations produced from the template and variables.' );
		}

		$remaining = $this->remaining_today();
		$combos    = array_slice( $combos, 0, $remaining );

		if ( ! $combos ) {
			return new WP_Error( 'vmsb_pseo', 'Daily programmatic cap already reached.' );
		}

		$created = 0;

		foreach ( $combos as $combo ) {
			$title = strtr( $template, self::braces( $combo ) );

			// Each brief asks explicitly for what makes THIS instance different -
			// this is what stops the output from being a mail-merge.
			$brief = "Write about: {$title}.\n"
				. 'Variables for this specific page: ' . wp_json_encode( $combo ) . "\n"
				. 'This must read as if written specifically for these variables, not a generic template. '
				. 'Include at least one concrete, specific detail tied to the variable value itself (a real local reference, a relevant specific fact) - '
				. 'if you do not have a verifiable specific fact, write around it rather than inventing one.';

			$row = array(
				'row_uid'            => wp_generate_uuid4(),
				'title'              => $title,
				'primary_keyword'    => trim( $base_keyword . ' ' . implode( ' ', $combo ) ),
				'secondary_keywords' => wp_json_encode( array() ),
				'cluster'            => 'programmatic',
				'intent'             => 'transactional',
				'content_type'       => 'programmatic',
				'brief'              => $brief,
				'internal_links'     => wp_json_encode( array() ),
				'target_words'       => 900,
				'priority'           => 0.5,
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
	 * Cartesian product of the variable lists, applied to the template.
	 * Capped hard regardless of input size - this is a safety valve, not a
	 * feature; someone pasting 500 cities should get 15/day, not 500 pages.
	 */
	private static function expand( $template, array $variables ) {
		if ( ! $variables ) {
			return array();
		}
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
			if ( count( $combos ) > 200 ) {
				break; // sanity ceiling before the daily cap trims it further
			}
		}

		shuffle( $combos ); // don't always generate the same first N alphabetically
		return array_slice( $combos, 0, self::HARD_DAILY_CAP * 3 );
	}

	private static function braces( array $combo ) {
		$out = array();
		foreach ( $combo as $k => $v ) {
			$out[ '{' . $k . '}' ] = $v;
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
