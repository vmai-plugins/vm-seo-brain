<?php
defined( 'ABSPATH' ) || exit;

/**
 * Close the Rank Math on-page gaps that VMSB-written content consistently has.
 *
 * Auditing the existing posts with VMSB_RankMath_Score turned up the same four
 * failures on every one: no internal links, no external links, no table of
 * contents, and image alt text without the focus keyword. None of those are
 * writing-quality problems - they are mechanical omissions, which means they
 * can be closed mechanically.
 *
 * Everything here is conservative on purpose:
 *  - it only touches post types on the safe list;
 *  - it never edits the front page or the posts page;
 *  - it only rewrites a slug on a draft, because changing a published URL to
 *    win an on-page test is a bad trade;
 *  - it records the previous values so a run can be reverted;
 *  - it re-runs the analyzer afterwards and reports the real before/after
 *    rather than assuming a fix landed.
 */
class VMSB_RankMath_Optimizer {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	/**
	 * Audit a post, apply what can be fixed, and report both scores.
	 */
	public function optimize( $post_id, $keyword = '' ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_rm_opt', 'Post not found.', array( 'status' => 404 ) );
		}

		// These are policy refusals, not failures - they must not surface as
		// 500s, or a caller cannot tell "we declined" from "we broke".
		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_rm_opt', "Post type '{$post->post_type}' is not on the safe list for automated edits.", array( 'status' => 409 ) );
		}
		if ( $post_id === (int) get_option( 'page_on_front' ) || $post_id === (int) get_option( 'page_for_posts' ) ) {
			return new WP_Error( 'vmsb_rm_opt', 'Refusing to auto-edit the home or blog page.', array( 'status' => 409 ) );
		}

		$before = VMSB_RankMath_Score::analyze( $post_id, $keyword );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		if ( '' === trim( (string) $before['focus_keyword'] ) ) {
			return new WP_Error( 'vmsb_rm_opt', 'No focus keyword set; most on-page tests cannot be satisfied without one.', array( 'status' => 409 ) );
		}

		$kw      = $before['focus_keyword'];
		$applied = array();
		$revert  = array(
			'post_content'          => $post->post_content,
			'post_name'             => $post->post_name,
			'rank_math_title'       => get_post_meta( $post_id, 'rank_math_title', true ),
			'rank_math_description' => get_post_meta( $post_id, 'rank_math_description', true ),
		);

		$fails = array_flip( $before['failures'] );

		// Content edits are batched into one update so the post is not saved
		// four times over.
		$content = $post->post_content;
		$dirty   = false;

		if ( isset( $fails['contentHasTOC'] ) ) {
			$next = $this->add_toc( $content );
			if ( null !== $next ) {
				$content = $next;
				$dirty   = true;
				$applied[] = 'contentHasTOC';
			}
		}

		if ( isset( $fails['keywordInImageAlt'] ) ) {
			$next = $this->add_keyword_alt( $content, $kw );
			if ( null !== $next ) {
				$content = $next;
				$dirty   = true;
				$applied[] = 'keywordInImageAlt';
			}
		}

		if ( isset( $fails['linksHasExternals'] ) ) {
			$next = $this->add_external_link( $content, $kw, get_the_title( $post_id ) );
			if ( null !== $next ) {
				$content = $next;
				$dirty   = true;
				$applied[] = 'linksHasExternals';
			}
		}

		if ( $dirty ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $content ) );
		}

		// Internal linking goes through the Silo engine, which already handles
		// anchor placement and its own safety checks, and writes the post
		// itself - so it runs after the batched update, not inside it.
		if ( isset( $fails['linksHasInternal'] ) && $this->add_internal_link( $post_id, $kw ) ) {
			$applied[] = 'linksHasInternal';
		}

		if ( isset( $fails['keywordInMetaDescription'] ) && $this->fix_meta_description( $post_id, $kw ) ) {
			$applied[] = 'keywordInMetaDescription';
		}

		if ( isset( $fails['keywordInTitle'] ) || isset( $fails['titleStartWithKeyword'] ) ) {
			if ( $this->fix_seo_title( $post_id, $kw ) ) {
				$applied[] = 'titleStartWithKeyword';
			}
		}

		// Only on drafts: a published URL is worth more than an on-page test.
		if ( isset( $fails['keywordInPermalink'] ) && 'draft' === $post->post_status && $this->fix_slug( $post_id, $kw ) ) {
			$applied[] = 'keywordInPermalink';
		}

		$after = VMSB_RankMath_Score::analyze( $post_id, $keyword );
		if ( is_wp_error( $after ) ) {
			return $after;
		}

		if ( $applied && class_exists( 'VMSB_Actions' ) ) {
			// record() reads 'before'/'after', not '*_value' - passing the
			// column names instead of the argument names stores nulls and
			// leaves the change unrevertable.
			VMSB_Actions::record( array(
				'object_type' => 'post',
				'object_id'   => $post_id,
				'action_type' => 'rankmath_optimize',
				'before'      => $revert,
				'after'       => array( 'applied' => $applied ),
				'reason'      => sprintf( 'Rank Math on-page: %d/%d to %d/%d tests.', $before['passed'], $before['total'], $after['passed'], $after['total'] ),
			) );
		}

		update_post_meta( $post_id, '_vmsb_rankmath_audit', array(
			'pass_pct'   => $after['pass_pct'],
			'passed'     => $after['passed'],
			'total'      => $after['total'],
			'failures'   => $after['failures'],
			'checked_at' => current_time( 'mysql', true ),
		) );

		$this->log->info( 'rankmath', sprintf(
			'Optimised post #%d: %d/%d to %d/%d tests. Applied: %s. Still failing: %s',
			$post_id, $before['passed'], $before['total'], $after['passed'], $after['total'],
			$applied ? implode( ', ', $applied ) : 'nothing',
			$after['failures'] ? implode( ', ', $after['failures'] ) : 'none'
		) );

		$result = array(
			'post_id' => $post_id,
			'applied' => $applied,
			'before'  => array( 'passed' => $before['passed'], 'total' => $before['total'], 'pct' => $before['pass_pct'], 'failures' => $before['failures'], 'words' => $before['word_count'] ),
			'after'   => array( 'passed' => $after['passed'], 'total' => $after['total'], 'pct' => $after['pass_pct'], 'failures' => $after['failures'], 'words' => $after['word_count'] ),
		);

		// A table of contents and a further-reading line are markup, but their
		// text still counts as body copy to Rank Math's extractor - the same
		// way it counts to ours. That means a thin post can cross the 600-word
		// line on insertions alone, passing lengthContent without a single new
		// sentence of substance. Say so rather than bank it as a win.
		if ( in_array( 'lengthContent', $before['failures'], true )
			&& ! in_array( 'lengthContent', $after['failures'], true ) ) {
			$result['caveats'][] = sprintf(
				'lengthContent went from %d to %d words, but %d of those came from the elements inserted here, not from new writing. Treat this post as still thin.',
				$before['word_count'],
				$after['word_count'],
				$after['word_count'] - $before['word_count']
			);
		}

		return $result;
	}

	/**
	 * Insert Rank Math's own TOC block after the opening paragraph.
	 *
	 * The test looks specifically for rank-math/toc-block, not for a hand-made
	 * list of anchors, so this builds the real block: attributes in the shape
	 * their converters produce, plus inner HTML for themes that render the
	 * saved markup.
	 */
	private function add_toc( $content ) {
		if ( false !== strpos( $content, 'rank-math/toc-block' ) ) {
			return null;
		}

		preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>/is', $content, $m, PREG_SET_ORDER );
		if ( count( $m ) < 2 ) {
			return null; // Nothing worth a table of contents.
		}

		$headings = array();
		$items    = '';
		foreach ( $m as $h ) {
			$text = trim( wp_strip_all_tags( $h[2] ) );
			if ( '' === $text ) {
				continue;
			}
			$anchor     = sanitize_title( $text );
			$headings[] = array(
				'key'     => uniqid( 'toc-' ),
				'link'    => '#' . $anchor,
				'content' => $text,
				'level'   => (int) $h[1],
				'disable' => false,
			);
			$items .= '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $text ) . '</a></li>';
		}
		if ( ! $headings ) {
			return null;
		}

		$attrs = array(
			'title'           => 'Table of Contents',
			'headings'        => $headings,
			'listStyle'       => 'ul',
			'titleWrapper'    => 'h2',
			'excludeHeadings' => array( 'h4', 'h5', 'h6' ),
		);

		$block = "\n<!-- wp:rank-math/toc-block " . wp_json_encode( $attrs ) . " -->\n"
			. '<div id="rank-math-toc"><h2>Table of Contents</h2><nav><ul>' . $items . '</ul></nav></div>'
			. "\n<!-- /wp:rank-math/toc-block -->\n";

		// After the first paragraph, which is where Rank Math's own guidance
		// puts it - not before the intro.
		if ( preg_match( '/<\/p>/i', $content, $pm, PREG_OFFSET_CAPTURE ) ) {
			$at = $pm[0][1] + strlen( $pm[0][0] );
			return substr( $content, 0, $at ) . $block . substr( $content, $at );
		}
		return $block . $content;
	}

	/**
	 * Put the focus keyword into one image's alt text.
	 *
	 * Prefers an image with no alt at all; failing that, the first one whose
	 * alt does not already mention the keyword. Existing descriptive alt text
	 * is extended rather than replaced, since alt is an accessibility feature
	 * first and an SEO test second.
	 */
	private function add_keyword_alt( $content, $keyword ) {
		if ( ! preg_match_all( '/<img[^>]*>/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		foreach ( $m[0] as $img ) {
			$tag = $img[0];
			$pos = $img[1];
			preg_match( '/alt\s*=\s*("|\')(.*?)\1/i', $tag, $a );
			$alt = isset( $a[2] ) ? $a[2] : null;

			if ( null !== $alt && false !== mb_stripos( $alt, $keyword ) ) {
				return null; // Already satisfied by this image.
			}

			if ( null === $alt ) {
				$new = preg_replace( '/<img/i', '<img alt="' . esc_attr( $keyword ) . '"', $tag, 1 );
			} elseif ( '' === trim( $alt ) ) {
				$new = str_replace( $a[0], 'alt="' . esc_attr( $keyword ) . '"', $tag );
			} else {
				$new = str_replace( $a[0], 'alt="' . esc_attr( $alt . ' - ' . $keyword ) . '"', $tag );
			}

			if ( $new !== $tag ) {
				return substr( $content, 0, $pos ) . $new . substr( $content, $pos + strlen( $tag ) );
			}
		}
		return null;
	}

	/**
	 * Add one authoritative outbound link.
	 *
	 * The URL is asked for rather than invented locally, but it is then
	 * checked: it must parse, be https, sit on a different host, and respond.
	 * A fabricated citation is worse than a missing one, so a URL that fails
	 * any of those is dropped rather than published.
	 */
	private function add_external_link( $content, $keyword, $title ) {
		$data = $this->ai->generate_json(
			"Article title: \"{$title}\"\nTopic: \"{$keyword}\"\n\n"
			. "Name ONE authoritative, long-lived public source a reader would genuinely benefit from - an official tourism board, government body, standards org, encyclopaedia or major reference site. "
			. "It must be a real URL you are confident exists, not a guess at a deep link. Prefer a site's stable top-level or section page over a specific article.\n\n"
			. 'Return JSON: {"url":"","anchor":"","why":""}',
			array( 'complexity' => 'standard', 'persona' => 'auditor' )
		);

		if ( ! is_array( $data ) || empty( $data['url'] ) ) {
			return null;
		}

		$url = esc_url_raw( trim( $data['url'] ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return null;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host || $host === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return null;
		}
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return null;
		}

		// Confirm it actually resolves before citing it.
		$probe = wp_remote_head( $url, array( 'timeout' => 6, 'redirection' => 3 ) );
		$code  = is_wp_error( $probe ) ? 0 : (int) wp_remote_retrieve_response_code( $probe );
		if ( $code < 200 || $code >= 400 ) {
			$this->log->warn( 'rankmath', "Discarded suggested external link {$url} (HTTP {$code})." );
			return null;
		}

		$anchor = ! empty( $data['anchor'] ) ? sanitize_text_field( $data['anchor'] ) : $host;
		$para   = '<p>Further reading: <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $anchor ) . '</a></p>';

		return rtrim( $content ) . "\n" . $para . "\n";
	}

	/**
	 * Link out to the most semantically related post we already have.
	 */
	private function add_internal_link( $post_id, $keyword ) {
		$candidates = get_posts( array(
			'post_type'      => get_post_type( $post_id ),
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'post__not_in'   => array( $post_id ),
			's'              => $keyword,
			'fields'         => 'ids',
		) );

		if ( ! $candidates ) {
			// Nothing topically close; fall back to the newest published sibling
			// so the post is not left with zero internal links.
			$candidates = get_posts( array(
				'post_type'      => get_post_type( $post_id ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'post__not_in'   => array( $post_id ),
				'orderby'        => 'date',
				'fields'         => 'ids',
			) );
		}
		if ( ! $candidates ) {
			return false;
		}

		$res = ( new VMSB_Silo() )->insert_internal_link( $post_id, (int) $candidates[0], $keyword );
		return ! is_wp_error( $res ) && false !== $res;
	}

	private function fix_meta_description( $post_id, $keyword ) {
		$desc = (string) get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' !== $desc && false !== mb_stripos( $desc, $keyword ) ) {
			return false;
		}

		if ( '' === $desc ) {
			$body = wp_strip_all_tags( get_post_field( 'post_content', $post_id ) );
			$desc = trim( mb_substr( preg_replace( '/\s+/', ' ', $body ), 0, 150 ) );
		}

		// Lead with the keyword, keep it inside Rank Math's 160-character band.
		$new = $keyword . ': ' . $desc;
		$new = mb_substr( $new, 0, 158 );
		update_post_meta( $post_id, 'rank_math_description', $new );
		return true;
	}

	/**
	 * Lead the SEO title with the focus keyword - but only when the title is
	 * not already about it.
	 *
	 * An exact-substring test is not enough. "The Unified Marketing Engine:
	 * 2026 MarTech Integration Guide" does not literally contain "martech
	 * stack integration", so a naive check prepends and produces
	 * "Martech Stack Integration: The Unified Marketing Engine: 2026 MarTech
	 * Integration Guide" - two colons, and the same idea three times. Compare
	 * on the significant words instead, and refuse to stack a second colon
	 * onto a title that already has one.
	 */
	private function fix_seo_title( $post_id, $keyword ) {
		$title = (string) get_post_meta( $post_id, 'rank_math_title', true );
		if ( '' === $title ) {
			$title = get_the_title( $post_id );
		}

		if ( 0 === mb_stripos( $title, $keyword ) ) {
			return false; // Already leads with it.
		}

		// Which meaningful keyword words does the title already carry?
		$stop  = array( 'a', 'an', 'the', 'to', 'for', 'of', 'in', 'on', 'and', 'or', 'how', 'your' );
		$words = array_filter(
			preg_split( '/\W+/u', mb_strtolower( $keyword ) ),
			static function ( $w ) use ( $stop ) {
				return mb_strlen( $w ) > 2 && ! in_array( $w, $stop, true );
			}
		);
		if ( ! $words ) {
			return false;
		}

		$title_l = mb_strtolower( $title );
		$present = 0;
		foreach ( $words as $w ) {
			if ( false !== mb_strpos( $title_l, $w ) ) {
				$present++;
			}
		}

		// Most of the keyword already in the title: leave it alone. Rank Math
		// wants the phrase near the front, but a readable title that ranks is
		// worth more than a stuffed one that passes one test.
		if ( ( $present / count( $words ) ) >= 0.6 ) {
			return false;
		}

		$sep = false !== mb_strpos( $title, ':' ) ? ' - ' : ': ';
		$new = $this->title_case( $keyword ) . $sep . $title;
		if ( mb_strlen( $new ) > 120 ) {
			return false; // Would be truncated in the SERP anyway.
		}

		update_post_meta( $post_id, 'rank_math_title', $new );
		return true;
	}

	private function fix_slug( $post_id, $keyword ) {
		$slug = sanitize_title( $keyword );
		if ( '' === $slug || strlen( $slug ) > 75 ) {
			return false;
		}
		$current = get_post_field( 'post_name', $post_id );
		if ( $current === $slug ) {
			return false;
		}
		wp_update_post( array( 'ID' => $post_id, 'post_name' => $slug ) );
		return true;
	}

	private function title_case( $s ) {
		return ucwords( mb_strtolower( $s ) );
	}
}
