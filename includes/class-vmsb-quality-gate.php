<?php
defined( 'ABSPATH' ) || exit;

/**
 * Pre-publish quality gate.
 *
 * The brain writes well, but "well" is not "safe to publish unattended." This
 * is the last check before a generated draft becomes a live URL. Six weighted
 * checks, a 0-100 score, three verdicts:
 *
 *   pass   -> publish as configured
 *   review -> hold as draft, never auto-publish, whatever the settings say
 *   reject -> do not keep the post at all
 *
 * Any single blocking failure forces reject regardless of the average - a
 * strong score must not be able to carry a near-duplicate through.
 */
class VMSB_Quality_Gate {

	const PASS   = 'pass';
	const REVIEW = 'review';
	const REJECT = 'reject';

	/**
	 * @param array $context title, keyword, post_id
	 * @return array{verdict:string,score:int,checks:array,blocking:array,summary:string}
	 */
	public static function evaluate( $content, array $context = array() ) {
		$context = wp_parse_args( $context, array( 'title' => '', 'keyword' => '', 'post_id' => 0 ) );

		if ( ! (int) VMSB_Settings::get( 'quality_gate' ) ) {
			return self::verdict( self::PASS, 100, array(), array(), 'Quality gate is off.' );
		}

		$text  = trim( wp_strip_all_tags( $content ) );
		$words = str_word_count( $text );

		$checks   = array();
		$blocking = array();

		// 1. Substance.
		$min = (int) VMSB_Settings::get( 'quality_min_words' );
		$checks['length'] = array(
			'weight' => 15,
			'score'  => $words >= $min ? 100 : (int) max( 0, $words / max( 1, $min ) * 100 ),
			'detail' => "{$words} words (min {$min}).",
		);
		if ( $words < $min * 0.6 ) {
			$blocking[] = 'Far below minimum length - would publish as thin content.';
		}

		// 2. Near-duplicate against the site's own content (vector-first).
		$dupe = self::duplicate_check( $text, $context['post_id'] );
		$checks['duplicate'] = array(
			'weight' => 25,
			'score'  => (int) round( ( 1 - $dupe['similarity'] ) * 100 ),
			'detail' => $dupe['match_id']
				? sprintf( '%d%% similar to "%s".', round( $dupe['similarity'] * 100 ), get_the_title( $dupe['match_id'] ) )
				: 'No close match on the site.',
		);
		if ( (int) VMSB_Settings::get( 'quality_dup_block' ) && $dupe['match_id'] && $dupe['similarity'] >= VMSB_Embeddings::threshold( 'duplicate' ) ) {
			$blocking[] = sprintf( 'Near-duplicate of post #%d (%d%%). Update that page instead of adding another.', $dupe['match_id'], round( $dupe['similarity'] * 100 ) );
		}

		// 3. Structure.
		$structure          = self::structure_check( $content );
		$checks['structure'] = array( 'weight' => 15, 'score' => $structure['score'], 'detail' => $structure['detail'] );

		// 4. Readability (Flesch-ish).
		$checks['readability'] = array( 'weight' => 10, 'score' => self::readability( $text ), 'detail' => 'Reading ease.' );

		// 5. Keyword actually present.
		$kw = mb_strtolower( $context['keyword'] );
		$in = $kw && false !== mb_strpos( mb_strtolower( $text ), $kw );
		$checks['keyword'] = array(
			'weight' => 10,
			'score'  => $kw ? ( $in ? 100 : 30 ) : 60,
			'detail' => $kw ? ( $in ? 'Target keyword present.' : 'Target keyword missing from body.' ) : 'No target keyword set.',
		);

		// 6. Business-DNA alignment, judged by the model against the brain's
		//    own understanding of the business. A writer grading itself is
		//    worthless, so this reads the brain profile, not the draft's promise.
		$alignment = self::alignment_check( $text, $context );
		$checks['alignment'] = array(
			'weight'             => 25,
			'score'              => $alignment['score'],
			'detail'             => $alignment['detail'],
			// Previously computed only to decide the verdict, then discarded -
			// a reviewer saw "contains claims that need checking" with no way
			// to see what those claims actually were, short of re-reading the
			// whole article hunting for anything that looked unsourced.
			'unverified_claims'  => $alignment['unverified_claims'],
		);
		if ( $alignment['score'] > 0 && $alignment['score'] < (int) VMSB_Settings::get( 'quality_min_alignment' ) ) {
			$blocking[] = 'Does not match the business: ' . $alignment['detail'];
		}

		// 7. Originality. This is NOT third-party plagiarism detection - that
		// needs a paid service (Copyscape, Originality.ai, etc.) this plugin
		// has no credentials for and cannot honestly claim to do. What this
		// catches is the specific, common failure of unattended AI writing:
		// generic, could-be-any-business filler dressed up as an article.
		// Piggybacks on alignment_check()'s existing AI call instead of
		// spending a second one - see the JSON schema there.
		$checks['originality'] = array(
			'weight' => 15,
			'score'  => $alignment['originality_score'],
			'detail' => $alignment['generic_patterns']
				? 'Generic patterns found: ' . implode( '; ', $alignment['generic_patterns'] )
				: 'Reads as distinctive, not generic AI filler.',
		);
		if ( $alignment['originality_score'] > 0 && $alignment['originality_score'] < (int) VMSB_Settings::get( 'quality_min_originality' ) ) {
			$blocking[] = 'Reads as generic AI filler rather than genuine expertise: ' . implode( '; ', $alignment['generic_patterns'] );
		}

		// Weighted total.
		$total = 0;
		$wsum  = 0;
		foreach ( $checks as $c ) {
			$total += $c['score'] * $c['weight'];
			$wsum  += $c['weight'];
		}
		$score = $wsum ? (int) round( $total / $wsum ) : 0;

		if ( $blocking ) {
			return self::verdict( self::REJECT, $score, $checks, $blocking, $blocking[0] );
		}
		if ( $score < (int) VMSB_Settings::get( 'quality_min_score' ) ) {
			return self::verdict( self::REVIEW, $score, $checks, array(), "Scored {$score}, below threshold. Held for review." );
		}
		if ( ! empty( $alignment['unverified_claims'] ) ) {
			return self::verdict( self::REVIEW, $score, $checks, array(), 'Contains claims that need checking before publishing.' );
		}
		return self::verdict( self::PASS, $score, $checks, array(), "Passed at {$score}." );
	}

	/* ---------------------------------------------------------------- checks */

	public static function duplicate_check( $text, $exclude_id = 0 ) {
		if ( (int) VMSB_Settings::get( 'vector_enabled' ) && class_exists( 'VMSB_Vector_Store' ) ) {
			$hits = VMSB_Vector_Store::search( $text, array(
				'type'      => VMSB_Vector_Store::TYPE_POST,
				'limit'     => 1,
				'exclude'   => $exclude_id ? array( $exclude_id ) : array(),
			) );
			if ( $hits ) {
				return array( 'similarity' => (float) $hits[0]['score'], 'match_id' => (int) $hits[0]['object_id'] );
			}
		}
		return array( 'similarity' => 0.0, 'match_id' => 0 );
	}

	private static function structure_check( $content ) {
		$score = 0;
		$notes = array();

		$h2 = preg_match_all( '/<h2[\s>]/i', $content );
		if ( $h2 >= 3 ) {
			$score += 35;
		} elseif ( $h2 >= 1 ) {
			$score += 18;
			$notes[] = 'few H2s';
		} else {
			$notes[] = 'no H2s';
		}

		$home     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$external = 0;
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $href ) {
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $home ) {
					$internal++;
				} else {
					$external++;
				}
			}
		}
		$score += $internal >= 2 ? 30 : ( $internal === 1 ? 15 : 0 );
		if ( $internal < 1 ) {
			$notes[] = 'no internal links';
		}
		$score += $external >= 1 ? 20 : 0;
		$score += preg_match( '/<(ul|ol|table)[\s>]/i', $content ) ? 15 : 0;

		return array( 'score' => min( 100, $score ), 'detail' => $notes ? implode( ', ', $notes ) : 'Well structured.' );
	}

	private static function readability( $text ) {
		$sentences = max( 1, preg_match_all( '/[.!?]+/', $text ) );
		$words     = max( 1, str_word_count( $text ) );
		$syllables = max( 1, preg_match_all( '/[aeiouy]+/i', $text ) );
		$flesch    = 206.835 - 1.015 * ( $words / $sentences ) - 84.6 * ( $syllables / $words );
		return (int) max( 0, min( 100, $flesch ) );
	}

	private static function alignment_check( $text, array $context ) {
		$empty = array( 'score' => 0, 'detail' => '', 'unverified_claims' => array(), 'originality_score' => 0, 'generic_patterns' => array() );

		if ( ! class_exists( 'VMSB_Brain' ) ) {
			return array_merge( $empty, array( 'detail' => 'No brain.' ) );
		}

		$brain   = new VMSB_Brain();
		$profile = $brain->context_prompt();
		if ( ! $profile ) {
			return array_merge( $empty, array( 'detail' => 'No business DNA set.' ) );
		}

		$ai     = new VMSB_AI_Router();
		$prompt = "You are the final check before this draft is published. Be strict.\n\n"
			. "TITLE: {$context['title']}\nTARGET QUERY: {$context['keyword']}\n\n"
			// 12000 chars covers a full ~1600-2000 word article (this
			// plugin's typical target_words) - 6000 was cutting the claim
			// check off partway through most articles, so anything in the
			// back half never got checked at all.
			. "DRAFT (first 12000 chars):\n" . mb_substr( $text, 0, 12000 ) . "\n\n"
			. "Assess against the business you know:\n"
			. "1. Does this read like it was written by THIS business with its real expertise, or like generic filler on the topic?\n"
			. "2. Does it answer the query in the first paragraph?\n"
			. "3. List any factual/statistical/numeric claim a reader would expect sourced but is not - invented awards, client names, certifications, dates.\n"
			. "4. Separately from brand fit: score how much this reads as generic, templated AI writing regardless of topic - hedge-everything "
			. "phrasing, empty transitions ('in today's fast-paced world', 'it's important to note'), zero concrete specifics (no numbers, "
			. "names, or examples that couldn't apply to literally any competitor). Quote the specific phrases that read this way, if any.\n\n"
			. 'Return JSON: {"alignment_score":0,"answers_query":true,"reasoning":"","unverified_claims":[],"originality_score":0,"generic_patterns":[]}';

		$data = $ai->generate_json( $prompt, array( 'system' => $profile, 'max_tokens' => 700, 'temperature' => 0.2 ) );
		if ( ! is_array( $data ) || ! isset( $data['alignment_score'] ) ) {
			return array_merge( $empty, array( 'detail' => 'Alignment response unparseable.' ) );
		}

		$raw    = (float) $data['alignment_score'];
		$score  = (int) round( $raw <= 10 ? $raw * 10 : $raw );
		$detail = isset( $data['reasoning'] ) ? wp_strip_all_tags( $data['reasoning'] ) : '';
		if ( isset( $data['answers_query'] ) && ! $data['answers_query'] ) {
			$score  = min( $score, 55 );
			$detail = 'Does not answer the query up front. ' . $detail;
		}

		$orig_raw = (float) ( $data['originality_score'] ?? 0 );
		$orig     = (int) round( $orig_raw <= 10 ? $orig_raw * 10 : $orig_raw );

		return array(
			'score'             => max( 0, min( 100, $score ) ),
			'detail'            => $detail ?: 'Aligned.',
			'unverified_claims' => array_filter( (array) ( $data['unverified_claims'] ?? array() ) ),
			'originality_score' => max( 0, min( 100, $orig ) ),
			'generic_patterns'  => array_filter( (array) ( $data['generic_patterns'] ?? array() ) ),
		);
	}

	/* ---------------------------------------------------------------- output */

	private static function verdict( $verdict, $score, $checks, $blocking, $summary ) {
		return compact( 'verdict', 'score', 'checks', 'blocking', 'summary' );
	}

	public static function attach_report( $post_id, array $report ) {
		update_post_meta( $post_id, '_vmsb_quality', $report );
		update_post_meta( $post_id, '_vmsb_quality_score', (int) $report['score'] );
		update_post_meta( $post_id, '_vmsb_quality_verdict', $report['verdict'] );
	}

	public static function get_report( $post_id ) {
		return get_post_meta( $post_id, '_vmsb_quality', true );
	}
}
