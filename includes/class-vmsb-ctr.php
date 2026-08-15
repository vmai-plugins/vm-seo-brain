<?php
defined( 'ABSPATH' ) || exit;

/**
 * CTR A/B testing.
 *
 * Unlike most of the fixer's meta rewrites, this is a genuine experiment, not
 * a one-shot fix: swap the title (or meta description), record the baseline
 * CTR, wait a real measurement window, then compare. If the variant loses, it
 * reverts automatically - a test that never checks its own result is not a
 * test.
 */
class VMSB_CTR {

	private $ai;
	private $google;

	public function __construct() {
		$this->ai     = new VMSB_AI_Router();
		$this->google = new VMSB_Google();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_experiments';
	}

	/**
	 * Pages with real impressions but a CTR meaningfully below what their
	 * average position would predict - the click-gap the old plugin's
	 * ctr-optimizer named directly.
	 */
	public function find_candidates( $limit = 10 ) {
		$rows = $this->google->gsc_query( array( 'page' ), 28, 300 );
		if ( is_wp_error( $rows ) ) {
			return array();
		}

		$min_impr = (int) VMSB_Settings::get( 'ctr_test_min_impressions', 200 );
		$candidates = array();

		foreach ( $rows as $r ) {
			$impressions = (int) ( $r['impressions'] ?? 0 );
			$position    = (float) ( $r['position'] ?? 0 );
			$ctr         = (float) ( $r['ctr'] ?? 0 );
			if ( $impressions < $min_impr || ! $position ) {
				continue;
			}
			$expected = self::expected_ctr( $position );
			if ( $expected > 0 && $ctr < $expected * 0.7 ) {
				$post_id = url_to_postid( $r['keys'][0] ?? '' );
				if ( $post_id ) {
					$candidates[] = array( 'post_id' => $post_id, 'ctr' => $ctr, 'expected' => $expected, 'impressions' => $impressions, 'position' => $position );
				}
			}
		}

		usort( $candidates, static fn( $a, $b ) => $b['impressions'] <=> $a['impressions'] );
		return array_slice( $candidates, 0, $limit );
	}

	/** Rough position -> expected CTR curve, organic search averages. */
	private static function expected_ctr( $position ) {
		$curve = array( 1 => .28, 2 => .15, 3 => .11, 4 => .08, 5 => .06, 6 => .05, 7 => .04, 8 => .03, 9 => .03, 10 => .02 );
		$p     = (int) round( $position );
		return $curve[ $p ] ?? ( $p > 10 ? .01 : .28 );
	}

	/**
	 * Start a test on a post: draft a variant title and description,
	 * record the baseline, and open a running experiment.
	 */
	public function start( $post_id ) {
		global $wpdb;
		$running = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $this->table() . " WHERE post_id = %d AND status = 'running'", $post_id ) );
		if ( $running ) {
			return new WP_Error( 'vmsb_ctr', 'A test is already running on this post.' );
		}

		$post    = get_post( $post_id );
		$rm      = new VMSB_RankMath();

		$current_title = $rm->get_title( $post_id ) ?: $post->post_title;
		$current_desc  = $rm->get_description( $post_id );

		$metrics = $this->google->gsc_page_metrics( get_permalink( $post_id ), 28 );
		$baseline_ctr = is_wp_error( $metrics ) ? 0 : (float) ( $metrics['ctr'] ?? 0 );

		$brain  = new VMSB_Brain();
		$prompt = "Act as a High-Conversion CTR Specialist.\n"
			. "ARTICLE: \"{$post->post_title}\"\n"
			. "CURRENT TITLE: \"{$current_title}\"\n"
			. "CURRENT DESC: \"{$current_desc}\"\n\n"
			. "TASK: Write one alternative SEO title and one meta description that will double the CTR.\n"
			. "1. Title: Under 60 chars. Use a 'Curiosity Gap' or 'Power Word'.\n"
			. "2. Description: 140-155 chars. Include a clear 'Value-First' CTA.\n\n"
			. 'Return JSON: {"variant_title":"","variant_desc":""}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'creative' ) );

		if ( empty( $data['variant_title'] ) ) {
			return new WP_Error( 'vmsb_ctr', 'Could not draft a conversion variant.' );
		}

		$days = (int) VMSB_Settings::get( 'ctr_test_days', 14 );
		$wpdb->insert( $this->table(), array(
			'post_id'        => $post_id,
			'field'          => 'meta_bundle',
			'original_value' => wp_json_encode( array( 'title' => $current_title, 'desc' => $current_desc ) ),
			'variant_value'  => wp_json_encode( array( 'title' => $data['variant_title'], 'desc' => $data['variant_desc'] ?? '' ) ),
			'baseline_ctr'   => $baseline_ctr,
			'status'         => 'running',
			'started_at'     => current_time( 'mysql' ),
			'concludes_at'   => gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ),
		) );

		$rm->apply( $post_id, array(
			'title' => $data['variant_title'],
			'description' => $data['variant_desc'] ?? $current_desc
		) );

		return array( 'started' => true, 'variant' => $data['variant_title'], 'concludes_in_days' => $days );
	}

	/**
	 * Conclude any experiments past their measurement window.
	 * Winning variants are COMMITTED; losers are REVERTED.
	 */
	public function conclude_due() {
		global $wpdb;
		$due = $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' WHERE status = "running" AND concludes_at <= UTC_TIMESTAMP()' );

		$concluded = 0;
		$rm = new VMSB_RankMath();

		foreach ( $due as $exp ) {
			$metrics = $this->google->gsc_page_metrics( get_permalink( $exp->post_id ), 14 );
			$result_ctr = is_wp_error( $metrics ) ? 0 : (float) ( $metrics['ctr'] ?? 0 );

			// Determine Winner (Statistically significant if impressions are high enough)
			$won = $result_ctr > ($exp->baseline_ctr * 1.05); // Needs 5% improvement to win

			if ( ! $won ) {
				// Revert to original
				$orig = json_decode($exp->original_value, true);
				$rm->apply( $exp->post_id, array( 'title' => $orig['title'], 'description' => $orig['desc'] ) );
			}

			$wpdb->update( $this->table(), array(
				'result_ctr'   => $result_ctr,
				'status'       => $won ? 'won' : 'reverted',
				'concluded_at' => current_time( 'mysql' ),
			), array( 'id' => $exp->id ) );

			// Log the lesson to Brain Memory
			$brain = new VMSB_Brain();
			$lesson = $won ? "CTR WIN: Title angle '" . wp_trim_words($exp->variant_value, 5) . "' beat baseline by " . round(($result_ctr - $exp->baseline_ctr) * 100, 2) . "%." : "CTR LOSS: Variant was rejected.";
			$brain->remember( 'ctr_lessons', "post_{$exp->post_id}", $lesson, 1.0, 'ctr_agent' );

			$concluded++;
		}

		return array( 'concluded' => $concluded );
	}

	public function running() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' WHERE status = "running" ORDER BY started_at DESC' );
	}

	public function recent_results( $limit = 30 ) {
		return $this->history( $limit );
	}

	public function history( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE status != "running" ORDER BY concluded_at DESC LIMIT %d', $limit ) );
	}
}
