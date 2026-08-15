<?php
defined( 'ABSPATH' ) || exit;

/**
 * Growth Engine: the suggestion-and-approval layer.
 *
 * Every other discovery mechanism in this plugin (Cluster Architect, the
 * Silo gap-push button, Gap Radar's "Push to Pipeline") writes straight to
 * plan status 'approved' the moment a human clicks something - there was no
 * point where the brain proposes topics and waits for a yes/no. This class
 * is that point: it aggregates every signal source the plugin already
 * tracks - Search Console + competitor gaps, silo pillar/support holes, and
 * clusters too thin to carry authority - and queues them as 'suggested'
 * rows that sit in a review inbox until approved or rejected.
 */
class VMSB_Growth_Engine {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_plan';
	}

	private function keyword_already_queued( $keyword ) {
		global $wpdb;
		$keyword = trim( mb_strtolower( $keyword ) );
		if ( ! $keyword ) {
			return true;
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$this->table()} WHERE LOWER(primary_keyword) = %s LIMIT 1", $keyword )
		);
	}

	/**
	 * Scan every signal source and queue new candidates for review. Existing
	 * plan rows for the same keyword (any status) are skipped, so a rejected
	 * or already-planned topic never comes back as a fresh suggestion.
	 */
	public function scan( $limit = 15 ) {
		$content = new VMSB_Content();
		$found   = 0;

		// 1. Golden Gaps: under-served Search Console queries + competitor
		// rivals, reasoned over by the Gap Finder's own AI call.
		$gaps = ( new VMSB_Gap_Finder() )->discover_golden_gaps( 8 );
		if ( ! is_wp_error( $gaps ) ) {
			foreach ( $gaps as $g ) {
				$keyword = isset( $g['keyword'] ) ? $g['keyword'] : '';
				if ( ! $keyword || $this->keyword_already_queued( $keyword ) ) {
					continue;
				}
				$content_type = isset( $g['content_type'] ) ? $g['content_type'] : '';
				$content_type = ( $content_type && 'post' !== $content_type && post_type_exists( $content_type ) ) ? $content_type : '';
				$content->plan_specific(
					isset( $g['title'] ) ? $g['title'] : $keyword,
					$keyword,
					'[Gap Radar - ' . ( isset( $g['type'] ) ? $g['type'] : 'Market' ) . '] ' . ( isset( $g['reasoning'] ) ? $g['reasoning'] : '' ),
					'Gap Discovery',
					0,
					'suggested',
					$content_type
				);
				$found++;
			}
		}

		// 2. Custom post type gaps (a travel site's "destinations"/"events"
		// types, or any other registered CPT) - given a guaranteed slice of
		// the budget before Silo's uncapped push below, since Silo alone can
		// exceed $limit on its own and would otherwise crowd this out
		// entirely. Every other source here only ever proposes plain blog
		// posts; this is the one that actually knows the site's CPTs exist.
		$found += $this->cpt_gaps( $content, min( 5, max( 0, $limit - $found ) ) );

		// 3. Silo pillar/support holes - same AI-built map the Silos page
		// uses, queued for review instead of pushed straight into production.
		$silo_res = ( new VMSB_Silo() )->push_gaps_to_plan( true );
		$found   += (int) ( isset( $silo_res['pushed'] ) ? $silo_res['pushed'] : 0 );

		// 4. Clusters too thin to read as an authority - catch the weakest
		// ones up with their single best unclaimed keyword, no AI call needed.
		$found += $this->weak_cluster_gaps( $content, max( 0, $limit - $found ) );

		update_option( 'vmsb_last_growth_scan', time() );
		$this->log->info( 'growth', "Growth scan complete: {$found} new suggestion(s) queued for review." );

		return array( 'found' => $found );
	}

	/**
	 * Ask specifically for entries in the site's registered custom post
	 * types (a travel site's "destinations"/"events", or whatever else is
	 * registered) - the generic keyword-gap sources above have no notion
	 * these types exist, so a "destinations" CPT would otherwise only ever
	 * get filled by luck (a keyword gap happening to be phrased like a
	 * place name). Requires Business DNA to have been calibrated at least
	 * once, since that's what populates the CPT catalog this reads.
	 */
	private function cpt_gaps( VMSB_Content $content, $max ) {
		if ( $max <= 0 ) {
			return 0;
		}

		$cpts = ( new VMSB_Brain() )->profile()['cpts'];
		if ( empty( $cpts ) ) {
			return 0;
		}

		global $wpdb;
		$existing = $wpdb->get_col( "SELECT title FROM {$this->table()} ORDER BY id DESC LIMIT 200" );

		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();
		$prompt = "Act as a Content Strategist for this business. It has these custom content types beyond standard blog posts:\n"
			. wp_json_encode( $cpts ) . "\n\n"
			. ( $existing ? "ALREADY IN THE PIPELINE (do not repeat these):\n- " . implode( "\n- ", array_slice( $existing, 0, 200 ) ) . "\n\n" : '' )
			. "TASK: For each content type above, propose up to 3 specific, individually-named entries not already covered - "
			. "e.g. for a 'destinations' type, name real specific places relevant to this business; for an 'events' type, name real "
			. "specific events. Ground every suggestion in the business's actual profile and location - no generic filler.\n\n"
			. 'Return JSON: {"items":[{"title":"","keyword":"","content_type":"","brief":""}]}';

		$data  = $ai->generate_json( $prompt, array(
			'system'     => $brain->context_prompt(),
			'complexity' => 'premium',
			'persona'    => 'strategist',
			'action'     => 'niche_expansion',
		) );
		$items = isset( $data['items'] ) ? (array) $data['items'] : array();

		$done = 0;
		foreach ( $items as $item ) {
			if ( $done >= $max ) {
				break;
			}
			$keyword = isset( $item['keyword'] ) ? $item['keyword'] : '';
			$title   = isset( $item['title'] ) ? $item['title'] : $keyword;
			if ( ! $keyword || ! $title || $this->keyword_already_queued( $keyword ) ) {
				continue;
			}

			// Same untrusted-slug guard as Cluster Architect: only ever pass
			// through a content_type that's a real, currently-registered post
			// type, never whatever the AI happened to write.
			$content_type = isset( $item['content_type'] ) ? $item['content_type'] : '';
			$content_type = ( $content_type && 'post' !== $content_type && post_type_exists( $content_type ) ) ? $content_type : '';

			$content->plan_specific(
				$title,
				$keyword,
				'[' . ( $content_type ? ucfirst( $content_type ) : 'Content' ) . ' Radar] ' . ( isset( $item['brief'] ) ? $item['brief'] : '' ),
				$content_type ? ucfirst( $content_type ) : 'Content Radar',
				0,
				'suggested',
				$content_type
			);
			$done++;
		}
		return $done;
	}

	private function weak_cluster_gaps( VMSB_Content $content, $max ) {
		if ( $max <= 0 ) {
			return 0;
		}

		$keywords = new VMSB_Keywords();
		$stats    = $keywords->get_cluster_stats( 50 );
		$weak     = array_filter( $stats, static fn( $c ) => (int) $c->keywords < 3 || (float) $c->cluster_health < 35 );
		if ( ! $weak ) {
			return 0;
		}

		// content_gaps() is already ordered by opportunity DESC, so the
		// first hit per cluster is that cluster's strongest unclaimed pick.
		$by_cluster = array();
		foreach ( $keywords->content_gaps( 150 ) as $g ) {
			if ( $g->cluster && ! isset( $by_cluster[ $g->cluster ] ) ) {
				$by_cluster[ $g->cluster ] = $g;
			}
		}

		$done = 0;
		foreach ( $weak as $c ) {
			if ( $done >= $max ) {
				break;
			}
			$pick = isset( $by_cluster[ $c->cluster ] ) ? $by_cluster[ $c->cluster ] : null;
			if ( ! $pick || $this->keyword_already_queued( $pick->keyword ) ) {
				continue;
			}
			$content->plan_specific(
				ucwords( $pick->keyword ),
				$pick->keyword,
				"[Authority Gap] \"{$c->cluster}\" is thin ({$c->keywords} piece(s), health {$c->cluster_health}) - this is its strongest unclaimed keyword.",
				$c->cluster,
				0,
				'suggested'
			);
			$done++;
		}
		return $done;
	}

	public function pending( $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE status = 'suggested' ORDER BY priority DESC, created_at DESC LIMIT %d", (int) $limit )
		);
	}

	public function count_pending() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} WHERE status = 'suggested'" );
	}

	public function approve( $id ) {
		global $wpdb;
		$ok = $wpdb->update(
			$this->table(),
			array( 'status' => 'approved', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id, 'status' => 'suggested' )
		);
		return array( 'approved' => (bool) $ok );
	}

	public function reject( $id ) {
		global $wpdb;
		$ok = $wpdb->update(
			$this->table(),
			array( 'status' => 'rejected', 'updated_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id, 'status' => 'suggested' )
		);
		return array( 'rejected' => (bool) $ok );
	}

	/**
	 * Progress toward the site's growth_target, reusing VMSB_Growth's own
	 * honest projection model rather than tracking a second number.
	 */
	public function progress() {
		$status = ( new VMSB_Growth() )->status();
		return array(
			'achieved' => $status['achieved'],
			'target'   => $status['target'],
			'pct'      => $status['pct_of_target'],
			'on_track' => $status['on_track'],
		);
	}
}
