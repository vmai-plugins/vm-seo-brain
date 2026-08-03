<?php
defined( 'ABSPATH' ) || exit;

/**
 * Embedding provider bridge.
 *
 * The brain's AI router writes prose; it has no embeddings method, and the
 * writing provider (AI Puffer, Claude via OpenRouter) may not offer one. So
 * embeddings get their own provider, defaulting to whatever key is present and
 * falling back to a deterministic local driver that costs nothing.
 */
class VMSB_Embeddings {

	const LOCAL_DIMS = 512;
	const MAX_CHARS  = 24000;

	/** @return array{ok:bool,vector:array,model:string,dims:int,error:string} */
	public static function embed( $text ) {
		$text = self::prepare( $text );
		if ( '' === $text ) {
			return self::fail( 'Nothing to embed.' );
		}

		$provider = self::provider();
		$method   = 'via_' . $provider;
		if ( ! method_exists( __CLASS__, $method ) ) {
			$method = 'via_local';
		}

		$result = self::$method( $text );

		// A remote failure falls back to local rather than leaving a hole in
		// the index. A weaker vector beats a missing one.
		if ( empty( $result['ok'] ) && 'local' !== $provider ) {
			$result = self::via_local( $text );
			$result['error'] = 'fell back to local';
		}

		return $result;
	}

	public static function provider() {
		$configured = VMSB_Settings::get( 'embedding_provider', 'auto' );
		if ( 'auto' !== $configured ) {
			return $configured;
		}
		if ( VMSB_Settings::get( 'openai_key' ) ) {
			return 'openai';
		}
		if ( VMSB_Settings::get( 'gemini_key' ) ) {
			return 'gemini';
		}
		if ( VMSB_Settings::get( 'ollama_url' ) ) {
			return 'ollama';
		}
		return 'local';
	}

	public static function model() {
		$configured = VMSB_Settings::get( 'embedding_model', '' );
		if ( $configured ) {
			return $configured;
		}
		return array(
			'openai' => 'text-embedding-3-small',
			'gemini' => 'text-embedding-004',
			'ollama' => 'nomic-embed-text',
			'local'  => 'local-hash-v1',
		)[ self::provider() ] ?? 'local-hash-v1';
	}

	/**
	 * Cosine thresholds are not portable between model families. Measured on the
	 * local driver an unrelated pair scores ~0.00 and a paraphrase ~0.77; real
	 * models push everything up (two unrelated English business articles sit at
	 * 0.35-0.50 on text-embedding-3-small). So defaults are per family, and a
	 * non-zero setting always overrides.
	 *
	 * @return array{duplicate:float,cannibal:float,related:float}
	 */
	public static function thresholds() {
		return match ( self::provider() ) {
			'openai', 'gemini' => array( 'duplicate' => 0.93, 'cannibal' => 0.86, 'related' => 0.62 ),
			'ollama'           => array( 'duplicate' => 0.90, 'cannibal' => 0.82, 'related' => 0.58 ),
			default            => array( 'duplicate' => 0.86, 'cannibal' => 0.72, 'related' => 0.38 ),
		};
	}

	public static function threshold( $which ) {
		$map = array(
			'duplicate' => 'vector_dup_threshold',
			'cannibal'  => 'vector_cannibal_threshold',
			'related'   => 'vector_related_threshold',
		);
		$set = isset( $map[ $which ] ) ? (float) VMSB_Settings::get( $map[ $which ], 0 ) : 0;
		if ( $set > 0 ) {
			return $set;
		}
		return self::thresholds()[ $which ] ?? 0.8;
	}

	/* ---------------------------------------------------------------- providers */

	private static function via_openai( $text ) {
		$key = VMSB_Settings::get( 'openai_key' );
		if ( ! $key ) {
			return self::fail( 'No OpenAI key.' );
		}
		$res = wp_remote_post(
			'https://api.openai.com/v1/embeddings',
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key ),
				'body'    => wp_json_encode( array( 'model' => self::model(), 'input' => $text ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['data'][0]['embedding'] ) ) {
			return self::fail( $data['error']['message'] ?? 'No embedding returned.' );
		}
		return self::ok( $data['data'][0]['embedding'], self::model() );
	}

	private static function via_gemini( $text ) {
		$key = VMSB_Settings::get( 'gemini_key' );
		if ( ! $key ) {
			return self::fail( 'No Gemini key.' );
		}
		$model = self::model();
		$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:embedContent?key=" . rawurlencode( $key );
		$res   = wp_remote_post(
			$url,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'model' => 'models/' . $model, 'content' => array( 'parts' => array( array( 'text' => $text ) ) ) ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['embedding']['values'] ) ) {
			return self::fail( 'No embedding returned.' );
		}
		return self::ok( $data['embedding']['values'], $model );
	}

	private static function via_ollama( $text ) {
		$url = rtrim( (string) VMSB_Settings::get( 'ollama_url' ), '/' );
		if ( ! $url ) {
			return self::fail( 'No Ollama URL.' );
		}
		$res = wp_remote_post(
			$url . '/api/embeddings',
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'model' => self::model(), 'prompt' => $text ) ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::fail( $res->get_error_message() );
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['embedding'] ) ) {
			return self::fail( 'No embedding returned.' );
		}
		return self::ok( $data['embedding'], self::model() );
	}

	/**
	 * Deterministic hashed bag-of-words. No network, no cost. Weaker at
	 * paraphrase than a real model but dramatically better than substring
	 * matching, and it lets the whole layer run before anyone thinks about
	 * token spend.
	 */
	private static function via_local( $text ) {
		$vector = array_fill( 0, self::LOCAL_DIMS, 0.0 );
		$words  = preg_split( '/\s+/', mb_strtolower( preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text ) ), -1, PREG_SPLIT_NO_EMPTY );

		$stop = array_flip( array( 'the', 'and', 'for', 'that', 'with', 'this', 'from', 'you', 'your', 'are', 'was', 'have', 'has', 'not', 'but', 'can', 'will', 'all', 'how', 'what', 'why', 'who', 'when', 'they', 'their', 'our', 'its', 'it', 'a', 'an', 'of', 'to', 'in', 'on', 'is', 'be', 'as', 'at', 'by', 'or', 'we', 'if', 'so' ) );

		$counts = array();
		foreach ( $words as $word ) {
			if ( isset( $stop[ $word ] ) || mb_strlen( $word ) < 3 ) {
				continue;
			}
			$counts[ $word ] = ( $counts[ $word ] ?? 0 ) + 1;
		}
		if ( ! $counts ) {
			return self::fail( 'No usable terms.' );
		}
		foreach ( $counts as $word => $n ) {
			$bucket             = abs( crc32( $word ) ) % self::LOCAL_DIMS;
			$vector[ $bucket ] += 1 + log( $n );
		}
		return self::ok( $vector, 'local-hash-v1' );
	}

	/* ---------------------------------------------------------------- helpers */

	private static function prepare( $text ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( mb_substr( $text, 0, self::MAX_CHARS ) );
	}

	private static function normalise( array $v ) {
		$sum = 0.0;
		foreach ( $v as $x ) {
			$sum += $x * $x;
		}
		if ( $sum <= 0 ) {
			return $v;
		}
		$mag = sqrt( $sum );
		foreach ( $v as $i => $x ) {
			$v[ $i ] = $x / $mag;
		}
		return $v;
	}

	private static function ok( array $vector, $model ) {
		$vector = self::normalise( array_map( 'floatval', $vector ) );
		return array( 'ok' => true, 'vector' => $vector, 'model' => $model, 'dims' => count( $vector ), 'error' => '' );
	}

	private static function fail( $message ) {
		return array( 'ok' => false, 'vector' => array(), 'model' => '', 'dims' => 0, 'error' => $message );
	}
}
