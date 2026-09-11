<?php
defined( 'ABSPATH' ) || exit;

/**
 * Model Sync Engine.
 * Fetches available models from configured providers.
 */
class VMSB_Model_Sync {

	public static function sync_all() {
		$providers = array( 'openai', 'gemini', 'openrouter', 'aipuffer', 'ollama', 'omniroute' );
		$all_models = array();
		$fallbacks = (array) VMSB_Settings::get( 'ai_fallbacks', array() );
		$primary = VMSB_Settings::get( 'ai_primary' );

		foreach ( $providers as $provider ) {
			// OmniRoute is also the image provider, and it takes priority for
			// images the moment a URL is set - independently of the text chain
			// (VMSB_Image_Engine::create() unshifts it onto the image chain).
			// Gating its model list on the text chain would leave the image
			// model field with nothing to offer on exactly the setups that use
			// it most.
			if ( 'omniroute' === $provider && VMSB_Settings::get( 'omniroute_url' ) ) {
				$models = self::sync_provider( $provider );
				if ( is_array( $models ) ) {
					$all_models[ $provider ] = $models;
				}
				continue;
			}

			// Only sync if configured as primary or enabled in fallbacks
			if ( $provider !== $primary && ! in_array( $provider, $fallbacks ) ) {
				continue;
			}

			$models = self::sync_provider( $provider );
			if ( is_array( $models ) ) {
				$all_models[ $provider ] = $models;
			}
		}

		return $all_models;
	}

	public static function sync_provider( $provider ) {
		try {
			switch ( $provider ) {
				case 'openai':
					return self::sync_openai();
				case 'gemini':
					return self::sync_gemini();
				case 'openrouter':
					return self::sync_openrouter();
				case 'aipuffer':
					return self::sync_aipuffer();
				case 'ollama':
					return self::sync_ollama();
				case 'omniroute':
					return self::sync_omniroute();
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'sync_error', $e->getMessage() );
		}
		return new WP_Error( 'invalid_provider', 'Unknown provider' );
	}

	private static function sync_openai() {
		$key = VMSB_Settings::get( 'openai_key' );
		if ( ! $key ) return new WP_Error( 'no_key', 'OpenAI key missing' );

		$res = wp_remote_get( 'https://api.openai.com/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key )
		) );

		if ( is_wp_error( $res ) ) return $res;
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$models = array();
		if ( ! empty( $body['data'] ) ) {
			foreach ( $body['data'] as $m ) {
				if ( preg_match( '/^(gpt|o1|o3)/', $m['id'] ) ) {
					$models[] = array( 'id' => $m['id'], 'name' => $m['id'] );
				}
			}
		}
		update_option( 'vmsb_models_openai', $models );
		return $models;
	}

	private static function sync_gemini() {
		$key = VMSB_Settings::get( 'gemini_key' );
		if ( ! $key ) return new WP_Error( 'no_key', 'Gemini key missing' );

		$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . rawurlencode( $key );
		$res = wp_remote_get( $url );

		if ( is_wp_error( $res ) ) return $res;
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$models = array();
		if ( ! empty( $body['models'] ) ) {
			foreach ( $body['models'] as $m ) {
				$id = str_replace( 'models/', '', $m['name'] );

				// Categorize for UI
				$type = ( strpos($id, 'imagen') !== false ) ? 'Image' : 'Text';
				$models[] = array(
					'id'   => $id,
					'name' => "{$type}: " . ($m['displayName'] ?? $id),
					'type' => strtolower($type)
				);
			}
		}
		update_option( 'vmsb_models_gemini', $models );
		return $models;
	}

	private static function sync_openrouter() {
		$key = VMSB_Settings::get( 'openrouter_key' );
		if ( ! $key ) return new WP_Error( 'no_key', 'OpenRouter key missing' );

		$res = wp_remote_get( 'https://openrouter.ai/api/v1/models', array(
			'headers' => array( 'Authorization' => 'Bearer ' . $key )
		) );

		if ( is_wp_error( $res ) ) return $res;
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$models = array();
		if ( ! empty( $body['data'] ) ) {
			foreach ( $body['data'] as $m ) {
				$models[] = array( 'id' => $m['id'], 'name' => $m['name'] ?? $m['id'] );
			}
		}
		update_option( 'vmsb_models_openrouter', $models );
		return $models;
	}

	/**
	 * OmniRoute exposes the OpenAI-compatible /v1/models endpoint, so this is
	 * the same shape as sync_openrouter() above - only the host differs.
	 *
	 * Two things are specific to a gateway rather than a single vendor. It
	 * fronts hundreds of upstream providers, so the list is long and the
	 * provider prefix ("openai/gpt-image-2") is the useful part of the label;
	 * and it can be reached without a key when the instance allows it, so a
	 * missing key is not treated as a hard failure the way it is for a vendor
	 * API. The image-capable models are split out separately: the Settings
	 * screen needs to offer those for the image model field, and an image
	 * endpoint given a chat model fails at generation time with an error that
	 * says nothing useful about why.
	 */
	private static function sync_omniroute() {
		$base = VMSB_Settings::omniroute_base();
		if ( ! $base ) {
			return new WP_Error( 'no_url', 'OmniRoute URL is not set.' );
		}

		$key     = VMSB_Settings::get( 'omniroute_key' );
		$headers = array( 'Content-Type' => 'application/json' );
		if ( $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$res = wp_remote_get( $base . '/v1/models', array(
			'headers'    => $headers,
			'timeout'    => 20,
			'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . '; ' . home_url(),
			'sslverify'  => ! VMSB_Settings::get( 'insecure_ssl' ),
		) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'omniroute_http', $body['error']['message'] ?? ( 'OmniRoute returned HTTP ' . $code ) );
		}

		// OpenAI shape is {"data":[{"id":...}]}; accept a bare list too, since a
		// self-hosted gateway is free to answer with either.
		$list = $body['data'] ?? ( is_array( $body ) ? $body : array() );

		$models = array();
		$images = array();

		foreach ( (array) $list as $m ) {
			$id = is_array( $m ) ? ( $m['id'] ?? '' ) : (string) $m;
			if ( '' === $id ) {
				continue;
			}

			$name  = is_array( $m ) && ! empty( $m['name'] ) ? $m['name'] : $id;
			$entry = array( 'id' => $id, 'name' => $name );

			$models[] = $entry;

			if ( self::is_image_model( $id, $m ) ) {
				$images[] = $entry;
			}
		}

		update_option( 'vmsb_models_omniroute', $models, false );
		update_option( 'vmsb_models_omniroute_image', $images, false );

		( new VMSB_Logger() )->info( 'model_sync', sprintf(
			'OmniRoute: %d models (%d image-capable).', count( $models ), count( $images )
		) );

		return $models;
	}

	/**
	 * Is this gateway model an image generator?
	 *
	 * A gateway does not reliably tag modality, so this leans on the naming
	 * conventions the image families actually ship with, plus any explicit
	 * modality/type field when the instance provides one. Kept deliberately
	 * conservative - a chat model wrongly offered in the image list produces a
	 * confusing runtime failure, while an image model missed here can still be
	 * typed in by hand.
	 */
	private static function is_image_model( $id, $meta = null ) {
		if ( is_array( $meta ) ) {
			foreach ( array( 'type', 'modality', 'output_modality', 'category' ) as $field ) {
				if ( ! empty( $meta[ $field ] ) && false !== stripos( (string) $meta[ $field ], 'image' ) ) {
					return true;
				}
			}
		}

		$needles = array(
			'flux', 'dall-e', 'dalle', 'gpt-image', 'stable-diffusion', 'sdxl',
			'imagen', 'firefly', 'midjourney', 'grok-imagine', 'freepik',
			'playground', 'kandinsky', 'ideogram', 'recraft', 'seedream',
		);

		$haystack = strtolower( (string) $id );
		foreach ( $needles as $needle ) {
			if ( false !== strpos( $haystack, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	private static function sync_aipuffer() {
		$all_models = array();
		$base = untrailingslashit( VMSB_Settings::get( 'aipuffer_url' ) );
		$key  = VMSB_Settings::get( 'aipuffer_key' );

		$is_local = empty($base);
		if ( ! $is_local ) {
			$base_normalized = preg_replace( '/^https?:\/\//', '', strtolower( $base ) );
			$home_normalized = preg_replace( '/^https?:\/\//', '', strtolower( untrailingslashit( home_url() ) ) );
			if ( $base_normalized === $home_normalized ) {
				$is_local = true;
			}
		}

		// 1. Remote Sync
		if ( ! $is_local && $base ) {
			// Try AI Power's internal model list if available via REST
			$url = $base . '/wp-json/aipkit/v1/models';
			$res = wp_remote_get( add_query_arg('aipkit_api_key', $key, $url), array(
				'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . '; ' . home_url(),
				'timeout'    => 15
			) );

			if ( ! is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200 ) {
				$body = json_decode( wp_remote_retrieve_body($res), true );
				if ( is_array($body) ) {
					foreach ( $body as $m ) {
						if ( is_array($m) && isset($m['id']) ) {
							$all_models[] = array( 'id' => $m['id'], 'name' => "Remote Puffer: " . ($m['name'] ?? $m['id']) );
						}
					}
				}
			}
		}

		// 2. Local AI Power (AIPKit)
		if ( class_exists( '\WPAICG\Core\AIPKit_Models_API' ) ) {
			$aip_providers = array( 'OpenAI', 'Google', 'Claude', 'OpenRouter' );
			foreach ( $aip_providers as $ap ) {
				// Per-provider, not around the whole loop. AI Power throws a
				// TypeError out of get_models() for any provider it has no key
				// for ("get_api_headers(): Argument #1 ($api_key) must be of
				// type string, null given"). With one catch outside the loop
				// that first unconfigured provider aborted the remaining ones,
				// so a site with only a Google key in AI Power synced nothing
				// at all - OpenAI is checked first and threw before Google was
				// ever reached.
				try {
					$models = \WPAICG\Core\AIPKit_Models_API::get_models( $ap );
					if ( ! is_wp_error( $models ) && is_array( $models ) ) {
						foreach ( $models as $m ) {
							$all_models[] = array( 'id' => $m['id'], 'name' => "AI Power ({$ap}): " . ($m['name'] ?? $m['id']) );
						}
					}
				} catch ( \Throwable $e ) {
					// A provider the user simply has not configured is the
					// normal case, not an error worth a red log row.
					( new VMSB_Logger() )->warn( 'model_sync', "AI Power model sync skipped {$ap}: " . $e->getMessage() );
				}
			}
		}

		// Also check for Meow Apps AI Engine models
		if ( function_exists( 'mwai_get_models' ) ) {
			$mwai_models = mwai_get_models();
			foreach ( $mwai_models as $m ) {
				$all_models[] = array( 'id' => $m['id'], 'name' => "AI Engine/Puffer: " . $m['name'] );
			}
		}

		update_option( 'vmsb_models_aipuffer', $all_models );
		return $all_models;
	}

	private static function sync_ollama() {
		$url = VMSB_Settings::get( 'ollama_url' );
		if ( ! $url ) return new WP_Error( 'no_url', 'Ollama URL missing' );

		$res = wp_remote_get( rtrim( $url, '/' ) . '/api/tags' );
		if ( is_wp_error( $res ) ) return $res;

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$models = array();
		if ( ! empty( $body['models'] ) ) {
			foreach ( $body['models'] as $m ) {
				$models[] = array( 'id' => $m['name'], 'name' => $m['name'] );
			}
		}
		update_option( 'vmsb_models_ollama', $models );
		return $models;
	}

	public static function get_models( $provider ) {
		return get_option( 'vmsb_models_' . $provider, array() );
	}
}
