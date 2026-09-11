<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Citability Analyzer & Scorer.
 *
 * Evaluates how easily modern AI search engines (Perplexity, Google AI Overviews,
 * ChatGPT Search, Claude) can extract and cite claims from an article.
 * Focuses on information gain, structured data tables, statistical references,
 * definition blocks, and conciseness.
 */
class VMSB_Citability {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * Score post citability (0-100) with diagnostic feedback.
	 *
	 * @param int $post_id
	 * @return array{score:int,verdict:string,strengths:array,recommendations:array,metrics:array}
	 */
	public function analyze( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_citability', 'Post not found.' );
		}

		$content    = $post->post_content;
		$text       = wp_strip_all_tags( $content );
		$word_count = str_word_count( $text );

		$score           = 50; // Neutral baseline
		$strengths       = array();
		$recommendations = array();

		// 1. Definition Anchors ("is defined as", "refers to", bolded definitions)
		$has_definitions = preg_match( '/\b(is defined as|refers to|means that|is a method of|is an approach to)\b/i', $text );
		if ( $has_definitions ) {
			$score += 10;
			$strengths[] = 'Clear direct definition anchors for instant LLM quoting.';
		} else {
			$recommendations[] = 'Add a direct definition anchor ("X is defined as...") in the first 2 paragraphs.';
		}

		// 2. Data & Statistics Density (numbers, percentages, metrics)
		$num_matches = preg_match_all( '/\b\d+(\.\d+)?(%|x|\s*(percent|users|growth|increase|decrease|ms|seconds|dollars|USD|INR))\b/i', $text );
		if ( $num_matches >= 3 ) {
			$score += 15;
			$strengths[] = "High statistical density ({$num_matches} data points detected).";
		} elseif ( $num_matches >= 1 ) {
			$score += 5;
		} else {
			$recommendations[] = 'Cite at least 2-3 specific statistics, metrics, or benchmarks to boost authority.';
		}

		// 3. Structured Data & Comparison Tables
		$has_table = ( false !== strpos( $content, '<table' ) || false !== strpos( $content, '<!-- wp:table' ) );
		if ( $has_table ) {
			$score += 15;
			$strengths[] = 'Structured comparison/data table present (preferred format for AI Overviews).';
		} else {
			$recommendations[] = 'Include a structured comparison or summary table for quick AI data synthesis.';
		}

		// 4. Bullet & Numbered List Organization
		$list_count = preg_match_all( '/<(ul|ol)[^>]*>/i', $content );
		if ( $list_count >= 2 ) {
			$score += 10;
			$strengths[] = "Well-structured list formatting ({$list_count} list sections).";
		} elseif ( $list_count === 0 ) {
			$recommendations[] = 'Break dense paragraphs into structured bullet lists or steps.';
		}

		// 5. Direct Question Heading Matches (H2/H3 ending in ?)
		$question_headings = preg_match_all( '/<h[23][^>]*>[^<]*\?[^<]*<\/h[23]>/i', $content );
		if ( $question_headings >= 2 ) {
			$score += 10;
			$strengths[] = "{$question_headings} question-based headings matched to user intent queries.";
		} else {
			$recommendations[] = 'Phrase at least 2 subheadings as specific questions users ask AI.';
		}

		// 6. Penalty for Fluff & Throat-Clearing Openings
		$first_100_words = wp_trim_words( $text, 100 );
		if ( preg_match( '/\b(in this modern era|in today\'s fast-paced|look no further|without further ado|dive right in)\b/i', $first_100_words ) ) {
			$score -= 15;
			$recommendations[] = 'Remove generic introductory throat-clearing fluff to speed up AI citation.';
		}

		$score = max( 10, min( 100, $score ) );

		$verdict = 'High AI Citability (Prime for AI Overview / Perplexity)';
		if ( $score < 60 ) {
			$verdict = 'Low AI Citability (Requires structuring)';
		} elseif ( $score < 80 ) {
			$verdict = 'Moderate AI Citability (Good, but can be sharpened)';
		}

		return array(
			'score'           => $score,
			'verdict'         => $verdict,
			'strengths'       => $strengths,
			'recommendations' => $recommendations,
			'metrics'         => array(
				'word_count'        => $word_count,
				'data_points'       => $num_matches,
				'has_table'         => $has_table,
				'question_headings' => $question_headings,
				'list_count'        => $list_count,
			),
		);
	}
}
