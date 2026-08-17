<?php
defined( 'ABSPATH' ) || exit;

/**
 * Rank Math on-page test runner.
 *
 * Rank Math scores a post with a JavaScript analyzer that only runs inside the
 * editor; the PHP side gathers content and explicitly "does NOT score or judge
 * anything" (see Content_Analysis_Data in seo-by-rank-math). That means nothing
 * server-side can read a score for a post the editor has not been opened on -
 * which is every post this plugin generates unattended.
 *
 * So this class runs the same tests itself. The test list is taken verbatim
 * from Rank Math's own DEFAULT_TESTS constant, and the content is extracted
 * with the same rules its analyzer uses (shortcodes stripped, then tags, then
 * whitespace collapsed; the "first 10%" measured in characters, not words).
 *
 * What this deliberately does NOT claim: to reproduce Rank Math's exact number.
 * The per-test weights live in minified editor JS, and guessing at them would
 * produce a figure that looks authoritative and drifts on their next release.
 * What it reports is how many applicable tests pass - which is what moves their
 * score - and exactly which ones do not, so the content can be fixed before it
 * is ever published.
 */
class VMSB_RankMath_Score {

	/**
	 * Rank Math's DEFAULT_TESTS, minus the ones no server-side check can
	 * honestly answer:
	 *
	 * - hasContentAI is a paid Rank Math service credit, not a property of
	 *   the content.
	 * - titleSentiment and titleHasPowerWords are scored against word lists
	 *   Rank Math ships in JS. Approximations are included below, but they are
	 *   marked soft: they never count as failures, only as opportunities, so a
	 *   guessed word list can never block a publish.
	 */
	const TESTS = array(
		'keywordInTitle',
		'titleStartWithKeyword',
		'keywordInMetaDescription',
		'keywordInPermalink',
		'lengthPermalink',
		'keywordIn10Percent',
		'keywordInContent',
		'keywordInSubheadings',
		'keywordInImageAlt',
		'keywordDensity',
		'keywordNotUsed',
		'lengthContent',
		'linksHasInternal',
		'linksHasExternals',
		'linksNotAllExternals',
		'contentHasTOC',
		'contentHasShortParagraphs',
		'contentHasAssets',
		'titleHasNumber',
		'titleHasPowerWords',
		'titleSentiment',
	);

	/** Tests that are advisory - reported, never counted as a failure. */
	const SOFT_TESTS = array( 'titleHasPowerWords', 'titleSentiment', 'titleHasNumber' );

	/**
	 * Rank Math's power-word list is not exposed to PHP. This is a working
	 * subset; being generous here is harmless because the test is soft.
	 */
	const POWER_WORDS = array(
		'best', 'ultimate', 'complete', 'essential', 'proven', 'guide', 'top', 'free',
		'easy', 'quick', 'simple', 'stunning', 'incredible', 'amazing', 'perfect',
		'must', 'expert', 'secret', 'powerful', 'unmissable', 'definitive', 'hidden',
		'affordable', 'authentic', 'unforgettable', 'breathtaking', 'insider',
	);

	/**
	 * Extract exactly what Rank Math's analyzer sees.
	 */
	public static function extract( $post, $keyword = '' ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return null;
		}

		$raw  = (string) $post->post_content;
		$body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $raw ) ) ) );

		$title = (string) get_post_meta( $post->ID, 'rank_math_title', true );
		if ( '' === $title ) {
			$title = get_the_title( $post->ID );
		}
		$description = (string) get_post_meta( $post->ID, 'rank_math_description', true );

		if ( '' === $keyword ) {
			$keyword = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
			// Rank Math stores a comma-separated list; the first is primary.
			$keyword = trim( explode( ',', $keyword )[0] );
		}

		preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $raw, $hm, PREG_SET_ORDER );
		$headings = array();
		foreach ( $hm as $h ) {
			$headings[] = array( 'level' => (int) $h[1], 'text' => trim( wp_strip_all_tags( $h[2] ) ) );
		}

		preg_match_all( '/<img[^>]*>/i', $raw, $im );
		$images = array();
		foreach ( $im[0] as $tag ) {
			preg_match( '/alt\s*=\s*("|\')(.*?)\1/i', $tag, $a );
			$images[] = array( 'alt' => isset( $a[2] ) ? $a[2] : '' );
		}
		$has_video = (bool) preg_match( '/<(video|iframe)[^>]*>/i', $raw );

		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		preg_match_all( '/<a\b[^>]*href\s*=\s*("|\')(.*?)\1[^>]*>/i', $raw, $lm, PREG_SET_ORDER );
		$internal = 0;
		$external = 0;
		$external_dofollow = 0;
		foreach ( $lm as $l ) {
			$href = trim( $l[2] );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || $host === $home ) {
				$internal++;
				continue;
			}
			$external++;
			if ( ! preg_match( '/rel\s*=\s*("|\')[^"\']*nofollow/i', $l[0] ) ) {
				$external_dofollow++;
			}
		}

		return array(
			'post_id'       => $post->ID,
			'title'         => $title,
			'description'   => $description,
			'permalink'     => str_replace( home_url(), '', (string) get_permalink( $post->ID ) ),
			'slug'          => $post->post_name,
			'focus_keyword' => $keyword,
			'raw'           => $raw,
			'body'          => $body,
			'word_count'    => str_word_count( $body ),
			// Rank Math measures the opening tenth in characters, not words.
			'excerpt_10pct' => mb_substr( $body, 0, (int) ceil( mb_strlen( $body ) * 0.1 ) ),
			'headings'      => $headings,
			'images'        => $images,
			'has_video'     => $has_video,
			'links'         => array( 'internal' => $internal, 'external' => $external, 'external_dofollow' => $external_dofollow ),
		);
	}

	/**
	 * Run every test. Returns per-test results plus a pass ratio.
	 */
	public static function analyze( $post, $keyword = '' ) {
		$c = self::extract( $post, $keyword );
		if ( ! $c ) {
			return new WP_Error( 'vmsb_rm', 'Post not found.' );
		}

		$kw    = mb_strtolower( trim( $c['focus_keyword'] ) );
		$has   = static function ( $haystack ) use ( $kw ) {
			return '' !== $kw && false !== mb_strpos( mb_strtolower( (string) $haystack ), $kw );
		};
		$tests = array();

		$tests['keywordInTitle'] = self::t( $has( $c['title'] ), 'Focus keyword is missing from the SEO title.' );

		$title_l = mb_strtolower( $c['title'] );
		$tests['titleStartWithKeyword'] = self::t(
			'' !== $kw && mb_strpos( $title_l, $kw ) !== false && mb_strpos( $title_l, $kw ) <= ( mb_strlen( $title_l ) * 0.3 ),
			'Move the focus keyword nearer the start of the title.'
		);

		$tests['keywordInMetaDescription'] = self::t( $has( $c['description'] ), 'Focus keyword is missing from the meta description.' );
		$tests['keywordInPermalink']       = self::t(
			'' !== $kw && false !== strpos( $c['slug'], sanitize_title( $kw ) ),
			'Focus keyword is missing from the URL slug.'
		);
		$tests['lengthPermalink'] = self::t( strlen( $c['slug'] ) > 0 && strlen( $c['slug'] ) <= 75, 'Slug is longer than 75 characters.' );

		$tests['keywordIn10Percent'] = self::t( $has( $c['excerpt_10pct'] ), 'Focus keyword does not appear in the opening 10% of the content.' );
		$tests['keywordInContent']   = self::t( $has( $c['body'] ), 'Focus keyword does not appear in the content.' );

		$sub_hit = false;
		foreach ( $c['headings'] as $h ) {
			if ( $h['level'] >= 2 && $has( $h['text'] ) ) {
				$sub_hit = true;
				break;
			}
		}
		$tests['keywordInSubheadings'] = self::t( $sub_hit, 'No H2/H3 subheading contains the focus keyword.' );

		$alt_hit = false;
		foreach ( $c['images'] as $img ) {
			if ( $has( $img['alt'] ) ) {
				$alt_hit = true;
				break;
			}
		}
		$tests['keywordInImageAlt'] = self::t( $alt_hit, 'No image alt text contains the focus keyword.' );

		// Rank Math treats roughly 1%-2.5% as the healthy band.
		$density = 0.0;
		if ( '' !== $kw && $c['word_count'] > 0 ) {
			$occurrences = mb_substr_count( mb_strtolower( $c['body'] ), $kw );
			$density     = round( ( $occurrences / max( 1, $c['word_count'] ) ) * 100, 2 );
		}
		$tests['keywordDensity'] = self::t(
			$density >= 0.5 && $density <= 2.5,
			sprintf( 'Keyword density is %s%%; aim for 1%%-2.5%%.', $density )
		);
		$tests['keywordDensity']['value'] = $density;

		$tests['keywordNotUsed'] = self::t( ! self::keyword_used_elsewhere( $kw, $c['post_id'] ), 'This focus keyword is already assigned to another post.' );

		$tests['lengthContent'] = self::t( $c['word_count'] >= 600, sprintf( 'Content is %d words; Rank Math wants at least 600.', $c['word_count'] ) );
		$tests['lengthContent']['value'] = $c['word_count'];

		$tests['linksHasInternal']     = self::t( $c['links']['internal'] > 0, 'No internal links.' );
		$tests['linksHasExternals']    = self::t( $c['links']['external_dofollow'] > 0, 'No external dofollow links.' );
		$tests['linksNotAllExternals'] = self::t( $c['links']['external'] === 0 || $c['links']['internal'] > 0, 'Every link points off-site.' );

		$tests['contentHasTOC'] = self::t(
			false !== strpos( $c['raw'], 'rank-math/toc-block' ) || false !== strpos( $c['raw'], 'wp-block-rank-math-toc-block' ),
			'No table of contents. Rank Math looks for its own TOC block (rank-math/toc-block).'
		);

		$tests['contentHasShortParagraphs'] = self::t( self::paragraphs_are_short( $c['raw'] ), 'At least one paragraph runs past 120 words.' );
		$tests['contentHasAssets']          = self::t( count( $c['images'] ) > 0 || $c['has_video'], 'No images or video in the content.' );

		$tests['titleHasNumber'] = self::t( (bool) preg_match( '/\d/', $c['title'] ), 'Title contains no number.' );

		$power = false;
		foreach ( self::POWER_WORDS as $w ) {
			if ( false !== mb_strpos( $title_l, $w ) ) {
				$power = true;
				break;
			}
		}
		$tests['titleHasPowerWords'] = self::t( $power, 'Title has no power word.' );
		$tests['titleSentiment']     = self::t( $power, 'Title reads neutrally; a positive or negative sentiment word helps CTR.' );

		$hard_total = 0;
		$hard_pass  = 0;
		foreach ( $tests as $id => $r ) {
			$tests[ $id ]['soft'] = in_array( $id, self::SOFT_TESTS, true );
			if ( $tests[ $id ]['soft'] ) {
				continue;
			}
			$hard_total++;
			if ( $r['pass'] ) {
				$hard_pass++;
			}
		}

		return array(
			'post_id'       => $c['post_id'],
			'focus_keyword' => $c['focus_keyword'],
			'tests'         => $tests,
			'passed'        => $hard_pass,
			'total'         => $hard_total,
			// A pass ratio over the tests that can be judged server-side. This
			// is VMSB's own measure, not Rank Math's published score.
			'pass_pct'      => $hard_total ? (int) round( ( $hard_pass / $hard_total ) * 100 ) : 0,
			'failures'      => array_keys( array_filter( $tests, static function ( $r ) {
				return ! $r['pass'] && empty( $r['soft'] );
			} ) ),
			'word_count'    => $c['word_count'],
			'density'       => $density,
		);
	}

	private static function t( $pass, $fix ) {
		return array( 'pass' => (bool) $pass, 'fix' => $pass ? '' : $fix );
	}

	private static function paragraphs_are_short( $raw ) {
		preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $raw, $m );
		if ( empty( $m[1] ) ) {
			return true; // No <p> markup to judge; do not fail on formatting we cannot see.
		}
		foreach ( $m[1] as $p ) {
			if ( str_word_count( wp_strip_all_tags( $p ) ) > 120 ) {
				return false;
			}
		}
		return true;
	}

	private static function keyword_used_elsewhere( $keyword, $post_id ) {
		if ( '' === $keyword ) {
			return false;
		}
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = 'rank_math_focus_keyword' AND meta_value = %s AND post_id != %d LIMIT 1",
			$keyword,
			(int) $post_id
		) );
	}
}
