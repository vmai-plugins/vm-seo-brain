<?php
defined( 'ABSPATH' ) || exit;

/**
 * The internal link graph, stored rather than recomputed.
 *
 * Before this, every question about linking was answered by reading post
 * content and running strpos() over it. Finding orphans meant concatenating
 * the body of every published post into one string and searching it once per
 * candidate; asking "does A link to B" meant two get_post_field() calls. That
 * works on a demo site and collapses on a real one, and it can only ever
 * answer yes/no questions - never "which page has the most authority to give"
 * or "which anchor am I over-using".
 *
 * So links are parsed once, on save, into a table with real indexes. Orphans
 * become a LEFT JOIN. Inbound counts become a GROUP BY. And with the graph in
 * hand, internal authority can be computed properly with PageRank instead of
 * guessed at from post counts.
 */
class VMSB_Link_Index {

	const SCAN_META    = '_vmsb_link_scan';
	const READY_OPTION = 'vmsb_link_index_built';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_links';
	}

	/**
	 * Keep the index honest as the site changes. Deliberately cheap: one
	 * parse of one post's content on save, nothing on read.
	 */
	public static function boot() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'on_delete' ) );
	}

	public static function on_save( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post && 'publish' !== $post->post_status ) {
			// An unpublished post gives no link equity, so its outbound edges
			// leave the graph - but keep it cheap and just drop its rows.
			self::purge_source( $post_id );
			return;
		}
		self::scan_post( $post_id );
	}

	public static function on_delete( $post_id ) {
		global $wpdb;
		$table = self::table();
		$wpdb->delete( $table, array( 'source_id' => (int) $post_id ) );
		$wpdb->update( $table, array( 'target_id' => 0 ), array( 'target_id' => (int) $post_id ) );
	}

	/* ---------------------------------------------------------------- scanning */

	/**
	 * Parse one post's links and replace its rows.
	 *
	 * @return int Number of links recorded.
	 */
	public static function scan_post( $post_id ) {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			self::purge_source( $post_id );
			return 0;
		}

		$rows = self::parse( $post->post_content );

		self::purge_source( $post_id );

		$now = current_time( 'mysql', true );
		foreach ( $rows as $row ) {
			$wpdb->insert(
				self::table(),
				array(
					'source_id'   => (int) $post_id,
					'source_type' => $post->post_type,
					'target_id'   => (int) $row['target_id'],
					'target_url'  => $row['url'],
					'target_host' => $row['host'],
					'anchor'      => mb_substr( $row['anchor'], 0, 250 ),
					'is_internal' => $row['internal'] ? 1 : 0,
					'is_managed'  => $row['managed'] ? 1 : 0,
					'created_at'  => $now,
				)
			);
		}

		update_post_meta( $post_id, self::SCAN_META, time() );

		return count( $rows );
	}

	/**
	 * Walk the site a batch at a time. Called from the task runner, so the
	 * whole index is built across several cron passes rather than in one
	 * request that would time out on a large site.
	 *
	 * @return array{scanned:int,links:int,remaining:int}
	 */
	public static function scan_batch( $limit = 40 ) {
		global $wpdb;

		$types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$types = array_values( array_unique( array_merge( $types, array( 'page' ) ) ) );
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

		// Anything never scanned, or scanned before its last edit.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
				   AND ( m.meta_value IS NULL OR m.meta_value < UNIX_TIMESTAMP( p.post_modified_gmt ) )
				 ORDER BY p.post_modified_gmt DESC
				 LIMIT %d",
				self::SCAN_META,
				(int) $limit
			)
		);

		$links = 0;
		foreach ( $ids as $id ) {
			$links += self::scan_post( (int) $id );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				 WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
				   AND ( m.meta_value IS NULL OR m.meta_value < UNIX_TIMESTAMP( p.post_modified_gmt ) )",
				self::SCAN_META
			)
		);

		if ( 0 === $remaining ) {
			update_option( self::READY_OPTION, time(), false );
		}

		delete_transient( 'vmsb_link_authority' );

		return array(
			'scanned'   => count( $ids ),
			'links'     => $links,
			'remaining' => $remaining,
		);
	}

	/**
	 * Throw the index away and start again. Used after a permalink structure
	 * change, when every stored target_id is suspect.
	 */
	public static function rebuild() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
		$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => self::SCAN_META ) );
		delete_option( self::READY_OPTION );
		delete_transient( 'vmsb_link_authority' );
		return true;
	}

	public static function is_ready() {
		return (bool) get_option( self::READY_OPTION );
	}

	private static function purge_source( $post_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'source_id' => (int) $post_id ) );
	}

	/**
	 * Pull every anchor out of a chunk of content.
	 *
	 * @return array<int,array{url:string,host:string,anchor:string,internal:bool,target_id:int,managed:bool}>
	 */
	public static function parse( $content ) {
		if ( ! $content || false === strpos( $content, '<a' ) ) {
			return array();
		}

		if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*([\'"])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$home = self::home_host();
		$out  = array();
		$seen = array();

		foreach ( $matches as $match ) {
			$url = trim( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ) );

			// Anchors, mailto:, tel: and javascript: are not links between pages.
			if ( '' === $url || 0 === strpos( $url, '#' ) || preg_match( '/^(mailto|tel|javascript|data):/i', $url ) ) {
				continue;
			}

			$host     = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$internal = ( '' === $host || $host === $home );
			$key      = $url;

			if ( isset( $seen[ $key ] ) ) {
				continue; // One edge per destination per post.
			}
			$seen[ $key ] = true;

			$out[] = array(
				'url'       => esc_url_raw( $url ),
				'host'      => mb_substr( $host ?: $home, 0, 190 ),
				'anchor'    => trim( wp_strip_all_tags( $match[3] ) ),
				'internal'  => $internal,
				'target_id' => $internal ? self::resolve( $url ) : 0,
				'managed'   => false !== strpos( $match[0], 'data-vmsb' ),
			);
		}

		return $out;
	}

	/**
	 * URL to post ID, memoised. url_to_postid() runs its own query every call
	 * and a single content-heavy post can contain dozens of links to the same
	 * handful of pages.
	 */
	private static function resolve( $url ) {
		static $cache = array();

		$key = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '' === $key || '/' === $key ) {
			return 0;
		}
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$id = (int) url_to_postid( $url );
		if ( ! $id && 0 === strpos( $url, '/' ) ) {
			$id = (int) url_to_postid( home_url( $url ) );
		}

		$cache[ $key ] = $id;
		return $id;
	}

	private static function home_host() {
		static $host = null;
		if ( null === $host ) {
			$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		}
		return $host;
	}

	/* ---------------------------------------------------------------- reads */

	/** Does A already link to B? One indexed lookup. */
	public static function has_link( $source_id, $target_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE source_id = %d AND target_id = %d LIMIT 1',
				(int) $source_id,
				(int) $target_id
			)
		);
	}

	public static function inbound_count( $post_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . ' WHERE target_id = %d AND is_internal = 1',
				(int) $post_id
			)
		);
	}

	public static function outbound_count( $post_id, $internal_only = true ) {
		global $wpdb;
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE source_id = %d';
		if ( $internal_only ) {
			$sql .= ' AND is_internal = 1';
		}
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, (int) $post_id ) );
	}

	/** Every post that links to $post_id, newest first. */
	public static function inbound( $post_id, $limit = 50 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.source_id, l.anchor, p.post_title
				 FROM ' . self::table() . ' l
				 INNER JOIN ' . $wpdb->posts . ' p ON p.ID = l.source_id
				 WHERE l.target_id = %d AND l.is_internal = 1
				 ORDER BY p.post_date DESC LIMIT %d',
				(int) $post_id,
				(int) $limit
			)
		);
	}

	/**
	 * Published posts nothing links to. A LEFT JOIN now, instead of loading
	 * every post body into memory and running a regex per candidate.
	 */
	public static function orphans( $limit = 100 ) {
		global $wpdb;

		$types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
		$skip  = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$skip  = $skip ? ' AND p.ID NOT IN (' . implode( ',', array_map( 'intval', $skip ) ) . ')' : '';

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_date
				 FROM {$wpdb->posts} p
				 LEFT JOIN " . self::table() . " l ON l.target_id = p.ID AND l.is_internal = 1
				 WHERE p.post_status = 'publish' AND p.post_type IN ({$in}){$skip}
				 GROUP BY p.ID
				 HAVING COUNT(l.id) = 0
				 ORDER BY p.post_date DESC
				 LIMIT %d",
				(int) $limit
			)
		);
	}

	/**
	 * Published posts with the fewest inbound links. Unlike orphans() this
	 * includes pages that have one or two links but are still starved, which
	 * is where most of the real ranking upside sits.
	 */
	public static function underlinked( $limit = 20, $threshold = 3 ) {
		global $wpdb;

		$types = (array) VMSB_Settings::get( 'safe_post_types', array( 'post' ) );
		$in    = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, COUNT(l.id) AS inbound
				 FROM {$wpdb->posts} p
				 LEFT JOIN " . self::table() . " l ON l.target_id = p.ID AND l.is_internal = 1
				 WHERE p.post_status = 'publish' AND p.post_type IN ({$in})
				 GROUP BY p.ID
				 HAVING inbound < %d
				 ORDER BY inbound ASC, p.post_date DESC
				 LIMIT %d",
				(int) $threshold,
				(int) $limit
			)
		);
	}

	/**
	 * Anchors used so often they read as manipulation rather than editorial.
	 * Google's own guidance on this is old and unambiguous; nobody checks it
	 * because until now nothing recorded anchors in a queryable form.
	 */
	public static function anchor_report( $limit = 25, $min_uses = 3 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT anchor, COUNT(*) AS uses, COUNT(DISTINCT target_id) AS targets
				 FROM ' . self::table() . '
				 WHERE is_internal = 1 AND anchor != \'\'
				 GROUP BY anchor
				 HAVING uses >= %d
				 ORDER BY uses DESC LIMIT %d',
				(int) $min_uses,
				(int) $limit
			)
		);
	}

	public static function stats() {
		global $wpdb;
		$table = self::table();

		$row = $wpdb->get_row(
			"SELECT COUNT(*) AS total,
			        SUM(is_internal) AS internal,
			        SUM(CASE WHEN is_internal = 0 THEN 1 ELSE 0 END) AS external,
			        SUM(is_managed) AS managed,
			        COUNT(DISTINCT source_id) AS sources
			 FROM {$table}",
			ARRAY_A
		);

		$broken = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_internal = 1 AND target_id = 0" );

		return array(
			'total'     => (int) ( $row['total'] ?? 0 ),
			'internal'  => (int) ( $row['internal'] ?? 0 ),
			'external'  => (int) ( $row['external'] ?? 0 ),
			'managed'   => (int) ( $row['managed'] ?? 0 ),
			'sources'   => (int) ( $row['sources'] ?? 0 ),
			'unresolved'=> $broken,
			'orphans'   => count( self::orphans( 500 ) ),
			'ready'     => self::is_ready(),
		);
	}

	/* ---------------------------------------------------------------- authority */

	/**
	 * Internal PageRank over the site's own link graph.
	 *
	 * This is the number the whole linking strategy should be driven by, and
	 * it is the one thing no keyword tool can tell you: which of your pages
	 * actually has authority to pass, and which pages are starved of it. The
	 * silo map guesses at this from post counts (calculate_silo_strength()
	 * literally scores "10 posts = full marks"). This measures it.
	 *
	 * Standard formulation: rank = (1-d)/N + d * sum(rank(in)/outdegree(in)),
	 * with dangling nodes' mass redistributed evenly so the total stays 1.
	 *
	 * @return array<int,float> post ID => score, normalised to 0-100.
	 */
	public static function authority( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( 'vmsb_link_authority' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$edges = $wpdb->get_results(
			'SELECT source_id, target_id FROM ' . self::table() . '
			 WHERE is_internal = 1 AND target_id > 0 AND source_id != target_id
			 LIMIT 200000',
			ARRAY_A
		);

		if ( ! $edges ) {
			return array();
		}

		$out   = array();
		$in    = array();
		$nodes = array();

		foreach ( $edges as $edge ) {
			$s = (int) $edge['source_id'];
			$t = (int) $edge['target_id'];

			$nodes[ $s ] = true;
			$nodes[ $t ] = true;

			$out[ $s ]  = ( $out[ $s ] ?? 0 ) + 1;
			$in[ $t ][] = $s;
		}

		$n = count( $nodes );
		if ( $n < 2 ) {
			return array();
		}

		$damping = 0.85;
		$base    = 1 / $n;
		$rank    = array_fill_keys( array_keys( $nodes ), $base );

		for ( $i = 0; $i < 25; $i++ ) {
			// Mass held by nodes with no outbound links would otherwise leak
			// out of the system on every pass.
			$dangling = 0.0;
			foreach ( $rank as $id => $score ) {
				if ( empty( $out[ $id ] ) ) {
					$dangling += $score;
				}
			}

			$next  = array();
			$floor = ( 1 - $damping ) * $base + $damping * $dangling * $base;

			foreach ( $nodes as $id => $unused ) {
				$sum = 0.0;
				foreach ( ( $in[ $id ] ?? array() ) as $source ) {
					$sum += $rank[ $source ] / $out[ $source ];
				}
				$next[ $id ] = $floor + $damping * $sum;
			}

			$rank = $next;
		}

		$max = max( $rank ) ?: 1;
		foreach ( $rank as $id => $score ) {
			$rank[ $id ] = round( ( $score / $max ) * 100, 2 );
		}
		arsort( $rank );

		set_transient( 'vmsb_link_authority', $rank, 6 * HOUR_IN_SECONDS );

		return $rank;
	}

	/**
	 * The pages best placed to hand authority to something else: high internal
	 * rank, and not already spending it on dozens of outbound links.
	 *
	 * @return array<int,array{ID:int,title:string,authority:float,outbound:int}>
	 */
	public static function donors( $limit = 10, $max_outbound = 12 ) {
		$authority = self::authority();
		if ( ! $authority ) {
			return array();
		}

		$out = array();
		foreach ( $authority as $id => $score ) {
			if ( count( $out ) >= $limit ) {
				break;
			}
			$outbound = self::outbound_count( $id );
			if ( $outbound > $max_outbound ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$out[] = array(
				'ID'        => (int) $id,
				'title'     => $post->post_title,
				'authority' => $score,
				'outbound'  => $outbound,
			);
		}

		return $out;
	}
}
