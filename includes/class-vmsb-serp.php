<?php
defined( 'ABSPATH' ) || exit;

/**
 * SERP Intelligence Agent.
 *
 * Generates a content blueprint for a keyword. Real data first: when
 * SEMrush is configured (Tier 3, optional), fetches the actual current
 * Top 3 ranking pages (real URLs from SEMrush's own index, then a normal
 * HTTP GET on each - content their owners already published for anyone to
 * read) and asks the model to summarize what's actually in them. Nothing
 * here scrapes Google directly - that's against Google's own ToS and
 * actively blocked, a different thing entirely from reading a SEMrush
 * report or fetching a public page. Without a SEMrush key, falls back to
 * asking the model to reason about what results "typically" look like -
 * an honest estimate, not a live read, and get_blueprint()'s 'source'
 * field says which one produced the result.
 * Ported & Enhanced from VMAI Autopilot.
 */
class VMSB_SERP {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Generate a Market Blueprint for a keyword based on current SERP winners.
	 */
	public function get_blueprint( $keyword ) {
		$cache_key = 'vmsb_serp_blueprint_' . md5($keyword);
		$cached = get_transient($cache_key);
		if ( $cached ) return $cached;

		$this->log->info( 'serp', "Analyzing SERP winners for \"{$keyword}\" to generate blueprint..." );

		$data = VMSB_External_Data::semrush_configured() ? $this->blueprint_from_real_pages( $keyword ) : null;
		if ( ! $data ) {
			$data = $this->blueprint_from_ai_estimate( $keyword );
		}

		set_transient( $cache_key, $data, 7 * DAY_IN_SECONDS );
		return $data;
	}

	/**
	 * Fetch the real Top 3 (by SEMrush position) and ask the model to
	 * summarize what's actually in them - min_word_count is a real average
	 * of real fetched pages, not a guess, and entities/gaps/trust features
	 * are extracted from real text instead of imagined.
	 */
	private function blueprint_from_real_pages( $keyword ) {
		$rows = VMSB_External_Data::semrush_phrase_organic( $keyword, 'us', 10 );
		if ( is_wp_error( $rows ) || ! is_array( $rows ) ) {
			return null;
		}

		usort( $rows, static fn( $a, $b ) => (int) ( $a['Position'] ?? 999 ) <=> (int) ( $b['Position'] ?? 999 ) );

		$pages = array();
		foreach ( array_slice( $rows, 0, 3 ) as $row ) {
			if ( empty( $row['Url'] ) ) {
				continue;
			}
			$text = VMSB_External_Data::fetch_page_text( esc_url_raw( $row['Url'] ) );
			if ( is_wp_error( $text ) || mb_strlen( $text ) < 200 ) {
				continue;
			}
			$pages[] = array( 'url' => $row['Url'], 'text' => $text );
		}

		if ( ! $pages ) {
			return null; // Nothing real fetchable - caller falls back to the AI estimate.
		}

		$word_counts = array_map( static fn( $p ) => str_word_count( $p['text'] ), $pages );
		$avg_words   = (int) round( array_sum( $word_counts ) / count( $word_counts ) );

		$excerpt_block = '';
		foreach ( $pages as $i => $p ) {
			$excerpt_block .= "PAGE " . ( $i + 1 ) . " ({$p['url']}):\n" . mb_substr( $p['text'], 0, 2500 ) . "\n\n";
		}

		$brain  = new VMSB_Brain();
		$prompt = "KEYWORD: \"{$keyword}\"\n\n"
			. "Below is the real text of the top-ranking pages for this keyword. Analyze what's actually there, don't invent anything not present in the text.\n\n"
			. $excerpt_block
			. "TASK:\n"
			. "1. Identify 5-8 entities or sub-topics ALL of these pages actually mention.\n"
			. "2. Identify a genuine gap: something relevant that NONE of these pages actually cover.\n"
			. "3. List up to 3 real trust signals you can see in this text (e.g., cited sources, an author bio, a stated methodology, original data).\n\n"
			. 'Return JSON: {"required_entities":[], "tactical_gap":"", "trust_features":[]}';

		$analysis = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'thief' ) );
		if ( ! is_array( $analysis ) ) {
			$analysis = array( 'required_entities' => array(), 'tactical_gap' => '', 'trust_features' => array() );
		}

		return array(
			'min_word_count'    => max( 800, $avg_words + 200 ), // Beat the real average, not match it.
			'required_entities' => (array) ( $analysis['required_entities'] ?? array() ),
			'tactical_gap'      => (string) ( $analysis['tactical_gap'] ?? '' ),
			'trust_features'    => (array) ( $analysis['trust_features'] ?? array() ),
			'source'            => 'fetched_pages',
			'source_urls'       => wp_list_pluck( $pages, 'url' ),
		);
	}

	/**
	 * No SEMrush key, or nothing real was fetchable - reason about what
	 * competitive results for this keyword typically look like instead.
	 * An honest estimate, clearly labeled as one.
	 */
	private function blueprint_from_ai_estimate( $keyword ) {
		$brain  = new VMSB_Brain();
		$prompt = "Act as a Search Intent Infiltrator.\n"
			. "KEYWORD: \"{$keyword}\"\n\n"
			. "TASK: Perform a conceptual analysis of what the current Google Top 3 winners for this keyword likely look like.\n"
			. "1. Estimate the 'Threshold Word Count' to beat them.\n"
			. "2. Identify 5-8 'Must-Have' entities or sub-topics they likely all mention.\n"
			. "3. Identify a plausible 'Tactical Gap' (What might they be missing that we can exploit?).\n"
			. "4. List 3 specific 'User Trust' features they likely use (e.g., Expert Bio, Original Data, Case Study).\n\n"
			. 'Return JSON: {"min_word_count":1200, "required_entities":[], "tactical_gap":"", "trust_features":[]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'complexity' => 'premium', 'persona' => 'thief' ) );

		if ( ! is_array($data) || empty($data['min_word_count']) ) {
			return array( 'min_word_count' => 1400, 'required_entities' => array(), 'tactical_gap' => 'Provide more depth than existing results.', 'source' => 'ai_estimate' );
		}

		$data['source'] = 'ai_estimate';
		return $data;
	}
}
