<?php
defined( 'ABSPATH' ) || exit;

/**
 * The one place in this plugin that writes a link into somebody's post.
 *
 * Everything used to go through a single line in VMSB_Silo:
 *
 *     preg_replace( '/' . preg_quote( $anchor ) . '/', $link, $content, 1 );
 *
 * The anchor was chosen by a model reading wp_strip_all_tags() output, but the
 * replacement ran against the raw markup. The first match is just as likely to
 * sit inside an existing <a>, inside an alt="" or title="", inside a shortcode
 * argument, or inside the JSON of a Gutenberg block comment - where it breaks
 * the block and the editor offers to "attempt recovery" the next time anyone
 * opens the post. On a page-builder site the body text is not in post_content
 * at all, so the write either did nothing or damaged the fallback copy.
 *
 * This class answers the same question the other way round: first work out
 * every region of the content that must not be touched, then look for the
 * anchor only in what is left, then splice by byte offset rather than pattern.
 * If there is nowhere safe, it refuses and says why. It never guesses.
 */
class VMSB_Link_Inserter {

	/**
	 * Page builders that keep the real body text somewhere other than
	 * post_content. Writing to post_content on these sites changes nothing a
	 * visitor will ever see, so we refuse rather than report a phantom success.
	 *
	 * @var array<string,string> meta key => builder label
	 */
	const BUILDER_META = array(
		'_elementor_data'           => 'Elementor',
		'_et_pb_use_builder'        => 'Divi',
		'_fl_builder_enabled'       => 'Beaver Builder',
		'panels_data'               => 'SiteOrigin Page Builder',
		'ct_builder_shortcodes'     => 'Oxygen',
		'tve_updated_post'          => 'Thrive Architect',
		'_themify_builder_settings' => 'Themify Builder',
		'mfn-page-items'            => 'Muffin Builder',
		'_cornerstone_data'         => 'Cornerstone',
		'_wpb_vc_js_status'         => 'WPBakery',
	);

	/**
	 * Insert a link to $target_id inside $post_id.
	 *
	 * @param int    $post_id   Post whose content is edited.
	 * @param int    $target_id Post being linked to.
	 * @param array  $args      anchor (string), reason (string), task_id (int),
	 *                          dry_run (bool), min_anchor_words (int).
	 * @return array|WP_Error   {post_id, target_id, anchor, url, offset, action_id}
	 */
	public static function insert( $post_id, $target_id, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'anchor'  => '',
				'reason'  => 'Internal link',
				'task_id' => null,
				'dry_run' => false,
			)
		);

		$gate = self::can_edit( $post_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
        }

		$post = get_post( $post_id );

		$target = get_post( $target_id );
		if ( ! $target || 'publish' !== $target->post_status ) {
			return new WP_Error( 'vmsb_link', 'The link target is missing or not published.' );
		}
		if ( (int) $post_id === (int) $target_id ) {
			return new WP_Error( 'vmsb_link', 'A post cannot link to itself.' );
		}

		$url = get_permalink( $target_id );
		if ( ! $url ) {
			return new WP_Error( 'vmsb_link', 'The link target has no resolvable URL.' );
		}

		$content = (string) $post->post_content;

		if ( self::links_to( $content, $url ) ) {
			return new WP_Error( 'vmsb_link_exists', 'That link is already in this post.' );
		}

		// Everything that must not be rewritten, resolved once and reused for
		// every candidate anchor below.
		$blocked = self::protected_ranges( $content );

		$anchor = trim( (string) $args['anchor'] );
		if ( '' === $anchor ) {
			$anchor = self::propose_anchor( $post, $target );
			if ( is_wp_error( $anchor ) ) {
				return $anchor;
			}
		}

		$hit = self::locate( $content, $anchor, $blocked );
		if ( ! $hit ) {
			return new WP_Error(
				'vmsb_link_anchor',
				sprintf( 'No safe place to put the anchor "%s" - it is either absent, or every occurrence sits inside a tag, a heading, an existing link, or a shortcode.', $anchor )
			);
		}

		// Use the text exactly as it appears in the content, not the model's
		// copy of it. They differ whenever the source contains an entity
		// (&amp;, &nbsp;) or non-standard whitespace, and re-escaping the
		// model's version is what turned "R&D" into "R&amp;amp;D".
		$matched = substr( $content, $hit['offset'], $hit['length'] );

		$updated = substr( $content, 0, $hit['offset'] )
			. '<a href="' . esc_url( $url ) . '" class="vmsb-internal-link" data-vmsb="1">' . $matched . '</a>'
			. substr( $content, $hit['offset'] + $hit['length'] );

		if ( $args['dry_run'] ) {
			return array(
				'post_id'   => (int) $post_id,
				'target_id' => (int) $target_id,
				'anchor'    => $matched,
				'url'       => $url,
				'offset'    => $hit['offset'],
				'dry_run'   => true,
			);
		}

		$saved = self::save_content( $post_id, $updated );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Every path that writes a link records its own reversal. Previously
		// only the semantic mesh did, so links placed by the autopilot and by
		// the publisher could not be undone from the Actions log at all.
		$action_id = VMSB_Actions::record(
			array(
				'task_id'     => $args['task_id'],
				'object_type' => 'post',
				'object_id'   => (int) $post_id,
				'action_type' => 'internal_link',
				'before'      => $content,
				'after'       => $updated,
				'reason'      => sprintf( '%s: linked "%s" to %s', $args['reason'], $matched, get_the_title( $target_id ) ),
			)
		);

		if ( class_exists( 'VMSB_Link_Index' ) ) {
			VMSB_Link_Index::scan_post( $post_id );
		}

		( new VMSB_Logger() )->info(
			'links',
			sprintf( 'Linked #%d -> #%d on anchor "%s".', $post_id, $target_id, $matched ),
			array( 'reason' => $args['reason'] )
		);

		return array(
			'post_id'   => (int) $post_id,
			'target_id' => (int) $target_id,
			'anchor'    => $matched,
			'url'       => $url,
			'offset'    => $hit['offset'],
			'action_id' => $action_id,
		);
	}

	/* ---------------------------------------------------------------- gates */

	/**
	 * Whether this post may be rewritten at all. Returns WP_Error with the
	 * specific reason so the caller can log something useful instead of a
	 * generic failure.
	 */
	public static function can_edit( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_link', 'Post not found.' );
		}

		if ( (int) $post_id === (int) get_option( 'page_on_front' ) || (int) $post_id === (int) get_option( 'page_for_posts' ) ) {
			return new WP_Error( 'vmsb_link', 'The home and blog pages are never rewritten automatically.' );
		}

		$safe_types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		if ( ! in_array( $post->post_type, $safe_types, true ) ) {
			return new WP_Error( 'vmsb_link', sprintf( 'The "%s" post type is not in the safe list for automated linking.', $post->post_type ) );
		}

		if ( get_post_meta( $post_id, '_vmsb_no_autolink', true ) ) {
			return new WP_Error( 'vmsb_link', 'This post is excluded from automated linking.' );
		}

		$builder = self::detect_builder( $post_id );
		if ( $builder ) {
			return new WP_Error(
				'vmsb_link_builder',
				sprintf( 'This post is built with %s, which stores its text outside post_content. Editing it here would not change the published page, so no link was written.', $builder )
			);
		}

		return true;
	}

	/**
	 * Which page builder owns this post's body text, if any.
	 *
	 * @return string Builder label, or '' when the post is plain content.
	 */
	public static function detect_builder( $post_id ) {
		foreach ( self::BUILDER_META as $key => $label ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( empty( $value ) ) {
				continue;
			}
			// Divi and WPBakery store an explicit off/false state rather than
			// deleting the key, so a bare "is it set" check flags every post
			// that was ever opened in the builder.
			if ( '_et_pb_use_builder' === $key && 'on' !== $value ) {
				continue;
			}
			if ( '_wpb_vc_js_status' === $key && 'true' !== $value ) {
				continue;
			}
			return $label;
		}
		return '';
	}

	/* ---------------------------------------------------------------- parsing */

	/**
	 * Byte ranges of $content that must never be rewritten.
	 *
	 * Order matters only for readability - the ranges are merged at the end,
	 * so overlaps between (say) an anchor and the tags inside it are harmless.
	 *
	 * @return array<int,array{0:int,1:int}> [start, end) pairs, ascending.
	 */
	public static function protected_ranges( $content ) {
		$patterns = array(
			// HTML comments. This is also how Gutenberg delimits blocks, and
			// their JSON attributes are the single easiest thing to corrupt.
			'/<!--.*?-->/s',
			// Anything whose text is not prose.
			'/<(script|style|pre|code|textarea|svg)\b[^>]*>.*?<\/\1>/is',
			// Existing links, element and contents - a link inside a link is
			// invalid HTML and browsers silently tear it apart.
			'/<a\b[^>]*>.*?<\/a>/is',
			// Headings. Anchors in headings look automated and dilute the
			// heading's own keyword signal.
			'/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is',
			// Captions and figure markup.
			'/<figcaption\b[^>]*>.*?<\/figcaption>/is',
			// Every remaining tag, so no match can land inside an attribute
			// such as alt="", title="" or a data- payload.
			'/<[^>]*>/s',
			// Shortcodes, including their arguments.
			'/\[[^\]\[]{0,300}\]/s',
		);

		$ranges = array();
		foreach ( $patterns as $pattern ) {
			if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			foreach ( $matches[0] as $match ) {
				$ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
			}
		}

		return self::merge_ranges( $ranges );
	}

	/**
	 * Collapse overlapping ranges so is_free() can stop at the first range
	 * that starts past the candidate.
	 */
	private static function merge_ranges( array $ranges ) {
		if ( ! $ranges ) {
			return array();
		}

		usort(
			$ranges,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);

		$merged  = array();
		$current = array_shift( $ranges );
		foreach ( $ranges as $range ) {
			if ( $range[0] <= $current[1] ) {
				$current[1] = max( $current[1], $range[1] );
				continue;
			}
			$merged[] = $current;
			$current  = $range;
		}
		$merged[] = $current;

		return $merged;
	}

	/**
	 * Is [start, start+length) entirely outside every protected range?
	 */
	private static function is_free( $start, $length, array $ranges ) {
		$end = $start + $length;
		foreach ( $ranges as $range ) {
			if ( $range[0] >= $end ) {
				return true; // Ranges are sorted; nothing further can overlap.
			}
			if ( $range[1] > $start ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Find the first safe occurrence of $anchor.
	 *
	 * The model's anchor and the stored content rarely match byte for byte:
	 * entities, non-breaking spaces and collapsed newlines all differ. Rather
	 * than normalise the content (which loses the offsets we need to splice
	 * with), the anchor is turned into a whitespace- and entity-tolerant
	 * pattern and matched against the content as it actually is.
	 *
	 * @return array{offset:int,length:int}|null
	 */
	public static function locate( $content, $anchor, array $blocked ) {
		$anchor = trim( preg_replace( '/\s+/u', ' ', $anchor ) );
		if ( mb_strlen( $anchor ) < 3 ) {
			return null;
		}

		$pattern = self::anchor_pattern( $anchor );
		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		foreach ( $matches[0] as $match ) {
			$offset = $match[1];
			$length = strlen( $match[0] );

			if ( ! self::is_free( $offset, $length, $blocked ) ) {
				continue;
			}
			return array( 'offset' => $offset, 'length' => $length );
		}

		return null;
	}

	/**
	 * Build a forgiving but still literal pattern for an anchor phrase.
	 * Whitespace becomes \s+, an ampersand matches its entity form, and the
	 * whole thing is bounded so "cost" never matches inside "costume".
	 */
	private static function anchor_pattern( $anchor ) {
		$quoted = preg_quote( $anchor, '/' );

		// preg_quote escapes the space in "\ " under some PHP configurations
		// and leaves it bare in others; handle both before anything else.
		$quoted = preg_replace( '/(\\\\ |\s)+/', '\\s+', $quoted );
		$quoted = str_replace( '&', '(?:&|&amp;)', $quoted );
		$quoted = str_replace( "'", "(?:'|&#0?39;|&apos;|\u{2019})", $quoted );
		$quoted = str_replace( '"', '(?:"|&quot;|&#0?34;)', $quoted );

		// \b is wrong here: an anchor may begin or end with a non-word
		// character. Assert on the neighbouring character instead, so a match
		// is rejected only when it would split a word.
		return '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu';
	}

	/**
	 * Does this content already link to $url? Compares paths, so the http/https
	 * and trailing-slash variants of the same page all count as linked.
	 */
	public static function links_to( $content, $url ) {
		$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( ! $path || '/' === $path ) {
			return false;
		}
		// Bounded so /guide never matches inside /guide-to-everything.
		return (bool) preg_match( '#href=["\'][^"\']*' . preg_quote( $path, '#' ) . '/?["\']#i', $content );
	}

	/* ---------------------------------------------------------------- anchor choice */

	/**
	 * Ask the model for an anchor, then verify its answer against the content
	 * before it is trusted. The model is allowed to be wrong here; it just
	 * cannot be wrong in a way that reaches the database.
	 *
	 * @return string|WP_Error
	 */
	private static function propose_anchor( $post, $target ) {
		$body = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 4000 );

		$data = ( new VMSB_AI_Router() )->generate_json(
			"Here is the body of an article:\n\n{$body}\n\n"
			. "I need to link naturally to a page titled \"{$target->post_title}\".\n"
			. "Find the single best existing phrase in the article to carry that link.\n"
			. "Rules: the phrase must appear in the article word for word, be 2-6 words long, be descriptive of the destination, "
			. "and never be 'click here', 'read more', 'this page' or the bare title of the article itself.\n"
			. "If no phrase in the article genuinely fits, return an empty anchor rather than inventing one.\n\n"
			. 'Return JSON: {"anchor":"","confidence":0.0}',
			array(
				'max_tokens'  => 300,
				'temperature' => 0.2,
				'action'      => 'link_anchor',
			)
		);

		if ( empty( $data['anchor'] ) ) {
			return new WP_Error( 'vmsb_link_anchor', 'No natural anchor phrase was found for this target.' );
		}

		$anchor = trim( (string) $data['anchor'] );
		$words  = preg_split( '/\s+/u', $anchor );

		if ( count( $words ) > 8 ) {
			return new WP_Error( 'vmsb_link_anchor', 'The proposed anchor is too long to read as a natural link.' );
		}

		$banned = array( 'click here', 'read more', 'this page', 'learn more', 'here', 'link' );
		if ( in_array( strtolower( $anchor ), $banned, true ) ) {
			return new WP_Error( 'vmsb_link_anchor', 'The proposed anchor was generic boilerplate.' );
		}

		return $anchor;
	}

	/* ---------------------------------------------------------------- writing */

	/**
	 * Write content back without letting kses eat it.
	 *
	 * wp_update_post() runs content through wp_filter_post_kses for any request
	 * with no user who holds unfiltered_html - which is every cron run and
	 * every task-runner pass. On a site using iframes, embeds or SVG that
	 * silently strips markup the author put there, and the post comes back
	 * smaller than it went in. The filters are removed for the duration of the
	 * write and restored immediately, exactly as core does for imports.
	 */
	private static function save_content( $post_id, $content ) {
		$filtered = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $filtered ) {
			kses_remove_filters();
		}

		$result = wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( $filtered ) {
			kses_init_filters();
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'vmsb_link', 'WordPress refused the content update.' );
		}

		return true;
	}
}
