<?php
defined( 'ABSPATH' ) || exit;

/**
 * Vector store: the brain's semantic memory.
 *
 * The brain already has a {prefix}vmsb_memory table for facts and decisions.
 * This is the other half - meaning-based recall across the site's own content,
 * so the brain can answer "have we already covered this?" and "what should this
 * link to?" without keyword guessing.
 *
 * Vectors are packed float32 in a LONGBLOB, L2-normalised at write time, so
 * cosine similarity is a dot product and search is array arithmetic. Fine to
 * ~50k vectors on normal hosting.
 */
class VMSB_Vector_Store {

	const TYPE_POST    = 'post';
	const TYPE_CLUSTER = 'cluster';

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_vectors';
	}

	/* ---------------------------------------------------------------- writing */

	public static function upsert( $object_type, $object_id, $text, array $meta = array() ) {
		global $wpdb;

		$hash  = md5( $text );
		$model = VMSB_Embeddings::model();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT id, content_hash, model FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				(int) $object_id
			)
		);

		if ( $row && $row->content_hash === $hash && $row->model === $model ) {
			return array( 'ok' => true, 'skipped' => true );
		}

		$embed = VMSB_Embeddings::embed( $text );
		if ( empty( $embed['ok'] ) ) {
			return array( 'ok' => false, 'skipped' => false, 'error' => $embed['error'] );
		}

		$data = array(
			'object_type'  => $object_type,
			'object_id'    => (int) $object_id,
			'label'        => mb_substr( (string) ( $meta['label'] ?? '' ), 0, 250 ),
			'content_hash' => $hash,
			'model'        => $embed['model'],
			'dims'         => $embed['dims'],
			'vector'       => self::pack( $embed['vector'] ),
			'meta'         => $meta ? wp_json_encode( $meta ) : null,
			'updated_at'   => current_time( 'mysql' ),
		);

		if ( $row ) {
			$wpdb->update( self::table(), $data, array( 'id' => $row->id ) );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( self::table(), $data );
		}

		return array( 'ok' => true, 'skipped' => false );
	}

	public static function forget( $object_type, $object_id ) {
		global $wpdb;
		return $wpdb->delete( self::table(), array( 'object_type' => $object_type, 'object_id' => (int) $object_id ) );
	}

	/* ---------------------------------------------------------------- search */

	public static function search( $text, array $args = array() ) {
		$embed = VMSB_Embeddings::embed( $text );
		if ( empty( $embed['ok'] ) ) {
			return array();
		}
		return self::search_by_vector( $embed['vector'], $args );
	}

	public static function similar_to( $object_type, $object_id, array $args = array() ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT vector FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d',
				$object_type,
				(int) $object_id
			)
		);
		if ( ! $row ) {
			return array();
		}
		$args['exclude'] = array_merge( (array) ( $args['exclude'] ?? array() ), array( (int) $object_id ) );
		return self::search_by_vector( self::unpack( $row->vector ), $args );
	}

	public static function search_by_vector( array $vector, array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'type'      => self::TYPE_POST,
			'limit'     => 10,
			'threshold' => 0.0,
			'exclude'   => array(),
		) );

		$dims = count( $vector );
		if ( ! $dims ) {
			return array();
		}

		// Only compare same-dimension vectors - a model change mid-index would
		// otherwise produce nonsense scores.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_id, object_type, label, vector, meta FROM ' . self::table() . ' WHERE object_type = %s AND dims = %d',
				$args['type'],
				$dims
			)
		);
		if ( ! $rows ) {
			return array();
		}

		$exclude = array_flip( array_map( 'intval', (array) $args['exclude'] ) );
		$scored  = array();

		foreach ( $rows as $row ) {
			if ( isset( $exclude[ (int) $row->object_id ] ) ) {
				continue;
			}
			$score = self::dot( $vector, self::unpack( $row->vector ) );
			if ( $score < (float) $args['threshold'] ) {
				continue;
			}
			$scored[] = array(
				'object_id' => (int) $row->object_id,
				'label'     => $row->label,
				'score'     => round( $score, 4 ),
				'meta'      => $row->meta ? json_decode( $row->meta, true ) : array(),
			);
		}

		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $scored, 0, (int) $args['limit'] );
	}

	public static function related_posts( $post_id, $limit = 8, $threshold = null ) {
		if ( null === $threshold ) {
			$threshold = VMSB_Embeddings::threshold( 'related' );
		}
		$out = array();
		foreach ( self::similar_to( self::TYPE_POST, $post_id, array( 'limit' => $limit, 'threshold' => $threshold ) ) as $hit ) {
			$post = get_post( $hit['object_id'] );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$out[] = array(
				'ID'    => $post->ID,
				'title' => $post->post_title,
				'url'   => get_permalink( $post ),
				'score' => $hit['score'],
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- indexing */

	public static function index_batch( $limit = 25 ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN " . self::table() . " v ON v.object_id = p.ID AND v.object_type = 'post'
				 WHERE p.post_status = 'publish' AND p.post_type IN ('post','page')
				 AND ( v.id IS NULL OR v.updated_at < p.post_modified )
				 ORDER BY p.post_modified DESC LIMIT %d",
				(int) $limit
			)
		);

		$indexed = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$res = self::upsert( self::TYPE_POST, $id, self::post_text( $post ), array( 'label' => $post->post_title, 'url' => get_permalink( $post ) ) );
			if ( ! empty( $res['skipped'] ) ) {
				$skipped++;
			} elseif ( ! empty( $res['ok'] ) ) {
				$indexed++;
			} else {
				$failed++;
			}
		}

		return array( 'indexed' => $indexed, 'skipped' => $skipped, 'failed' => $failed, 'remaining' => self::pending_count() );
	}

	public static function pending_count() {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			 LEFT JOIN " . self::table() . " v ON v.object_id = p.ID AND v.object_type = 'post'
			 WHERE p.post_status = 'publish' AND p.post_type IN ('post','page')
			 AND ( v.id IS NULL OR v.updated_at < p.post_modified )"
		);
	}

	public static function stats() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT COUNT(*) total, MAX(updated_at) last_indexed FROM ' . self::table(), ARRAY_A );
		return array(
			'total'        => (int) ( $row['total'] ?? 0 ),
			'pending'      => self::pending_count(),
			'provider'     => VMSB_Embeddings::provider(),
			'model'        => VMSB_Embeddings::model(),
			'last_indexed' => $row['last_indexed'] ?? null,
		);
	}

	public static function post_text( $post ) {
		$keyword = get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
		return trim(
			$post->post_title . "\n" . $post->post_title . "\n"
			. ( $keyword ? $keyword . "\n" : '' )
			. wp_strip_all_tags( strip_shortcodes( $post->post_content ) )
		);
	}

	public static function rebuild() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
		return true;
	}

	/* ---------------------------------------------------------------- maths */

	public static function dot( array $a, array $b ) {
		$sum = 0.0;
		$n   = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}

	private static function pack( array $vector ) {
		return pack( 'f*', ...$vector );
	}

	private static function unpack( $binary ) {
		$out = unpack( 'f*', $binary );
		return $out ? array_values( $out ) : array();
	}
}
