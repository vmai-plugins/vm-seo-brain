<?php
defined( 'ABSPATH' ) || exit;

/**
 * Image sourcing with a fallback chain: Pollinations -> ComfyUI -> AI Puffer -> Pexels.
 * Everything lands in the media library with SEO filename, alt, title, caption.
 */
class VMSB_Image_Engine {

	private $log;

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * @param string $subject   What the image should show.
	 * @param array  $meta      keyword, post_id, alt, filename_hint, orientation
	 * @return array{ok:bool,attachment_id:int,url:string,provider:string,error:string}
	 */
	public function create( $subject, array $meta = array() ) {
		if ( ! (int) VMSB_Settings::get( 'feature_images', 1 ) ) {
			return array( 'ok' => false, 'attachment_id' => 0, 'url' => '', 'provider' => '', 'error' => 'Visual Engine is disabled.' );
		}

		// Professional Subject Refinement: Turn simple keywords into high-end descriptions
		$subject = $this->refine_subject( $subject );

		// Compatibility: Use VM Image AI if active and configured.
		if ( class_exists( 'VMIA_Plugin' ) && function_exists( 'vmia' ) ) {
			$router = new VMIA_Image_Router();
			$order  = (array) VMIA_Settings::get( 'image_order' );
			if ( ! empty( $order ) ) {
				$res = $router->generate_to_library(
					$subject,
					array(
						'post_id'      => $meta['post_id'] ?? 0,
						'set_featured' => true,
						'width'        => VMSB_Settings::get( 'image_width', 1200 ),
						'height'       => VMSB_Settings::get( 'image_height', 675 ),
						'style'        => VMSB_Settings::get( 'image_style' ),
					)
				);
				if ( ! empty( $res['ok'] ) ) {
					return array(
						'ok'            => true,
						'attachment_id' => $res['attach_id'],
						'url'           => $res['url'],
						'provider'      => $res['provider'],
						'error'         => '',
					);
				}
			}
		}

		$meta = wp_parse_args(
			$meta,
			array(
				'keyword'       => '',
				'post_id'       => 0,
				'alt'           => '',
				'filename_hint' => '',
				'orientation'   => 'landscape',
			)
		);

		$prompt = $this->build_prompt( $subject, $meta );
		$chain  = (array) VMSB_Settings::get( 'image_chain' );

		// 2026 Optimization: OmniRoute takes priority for Elite/Self-Hosted setups
		if ( VMSB_Settings::get( 'omniroute_url' ) && ! in_array( 'omniroute', $chain, true ) ) {
			array_unshift( $chain, 'omniroute' );
		}

		$errors = array();

		foreach ( $chain as $provider ) {
			$method = 'from_' . $provider;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			$bytes = $this->$method( $prompt, $subject, $meta );
			if ( is_wp_error( $bytes ) ) {
				$errors[ $provider ] = $bytes->get_error_message();
				continue;
			}
			if ( ! $bytes ) {
				$errors[ $provider ] = 'empty response';
				continue;
			}
			$attachment = $this->sideload( $bytes, $subject, $meta, $provider );
			if ( is_wp_error( $attachment ) ) {
				$errors[ $provider ] = $attachment->get_error_message();
				continue;
			}
			return array(
				'ok'            => true,
				'attachment_id' => $attachment,
				'url'           => wp_get_attachment_url( $attachment ),
				'provider'      => $provider,
				'error'         => '',
			);
		}

		$this->log->error( 'images', 'Image chain exhausted for: ' . $subject, $errors );
		return array( 'ok' => false, 'attachment_id' => 0, 'url' => '', 'provider' => '', 'error' => implode( ' | ', $errors ) );
	}

	private function build_prompt( $subject, $meta ) {
		$style = VMSB_Settings::get( 'image_style' );
		$biz   = VMSB_Settings::get( 'business_type' );

		// High-end professional framing - kept subject-agnostic. It used to
		// hardcode "corporate editorial style", which reads wrong on a food,
		// product, landscape, or interior shot (most images this generates).
		$quality = "ultra-realistic professional photography, shot on a 35mm lens at f/1.8, award-winning editorial style, commercial grade, lighting matched to the scene, sharp focus on subject, clean composition, 8k resolution";

		// Business-relevant context, not assumed-corporate.
		$context = $biz ? "context: a real {$biz} setting" : "context: a real, professional setting relevant to the subject";

		// Anti-AI artifacts & slop (positive reinforcement). Human-specific
		// constraints ("natural hands and faces", "business casual attire")
		// used to apply to every single image, including product shots, food
		// photography, and empty interiors/landscapes that have no person in
		// them at all - diluting the prompt and risking the model inserting
		// an unwanted person to satisfy the attire/face wording. Only ask
		// for those when the subject actually suggests people are in frame.
		$has_people = (bool) preg_match( '/\b(person|people|team|staff|employee|employer|owner|customer|client|worker|man|woman|men|women|hands?|face|faces|portrait|smiling|couple|family|crowd|barber|chef|doctor|nurse|technician)\b/i', $subject );
		$integrity  = $has_people
			? "natural human features, realistic hands and faces, authentic skin texture, natural unposed expression, attire appropriate to the setting, no text, no watermarks, no logos, no distorted features"
			: "authentic natural textures and materials, professional color grading, neutral color palette, no text, no watermarks, no logos, no visual artifacts";

		$parts = array_filter( array(
			$subject,
			$context,
			$style,
			$quality,
			$integrity
		) );

		return implode( ', ', $parts );
	}

	/**
	 * Turn a simple subject or keyword into a high-end professional description.
	 */
	private function refine_subject( $subject ) {
		// If subject is already long/descriptive, don't mess with it too much locally
		if ( str_word_count( $subject ) > 15 ) {
			return $subject;
		}

		$ai    = new VMSB_AI_Router();
		$biz   = VMSB_Settings::get( 'business_type' );

		$prompt = "Act as a high-end commercial photographer. Transform this simple keyword into a detailed, professional, and business-friendly visual description for an image generator (like Flux or DALL-E 3).\n\n"
			. "Keyword: \"{$subject}\"\n"
			. ($biz ? "Business Context: \"{$biz}\"\n" : "")
			. "\nFocus on: realism, professional atmosphere, minimalist aesthetic, authentic lighting.\n"
			. "CRITICAL RULES:\n"
			. "- Do not include any text, signs, logos, or brand names.\n"
			. "- Avoid generic stock-photo clichés (e.g. people high-fiving).\n"
			. "- Ensure human hands and faces are described as natural and anatomically correct.\n"
			. "Return ONLY the expanded description, no commentary.";

		// Without an explicit persona this silently defaulted to 'strategist'
		// ("Act as a Senior SEO Strategy Director"), which the AI router
		// prepends to the system prompt ahead of - and in direct conflict
		// with - the "Act as a high-end commercial photographer" instruction
		// already in the prompt above.
		$res = $ai->generate( $prompt, array( 'max_tokens' => 150, 'temperature' => 0.7, 'cache_ttl' => MONTH_IN_SECONDS, 'persona' => 'wordsmith' ) );

		if ( ! empty( $res['ok'] ) && ! empty( $res['text'] ) ) {
			return trim( $res['text'] );
		}

		return $subject;
	}

	/* ---------------------------------------------------------------- providers */

	private function from_pollinations( $prompt, $subject, $meta ) {
		$base = rtrim( (string) VMSB_Settings::get( 'pollinations_url' ), '/' ) . '/';
		$url  = $base . rawurlencode( $prompt ) . '?' . http_build_query(
			array(
				'width'    => (int) VMSB_Settings::get( 'image_width' ),
				'height'   => (int) VMSB_Settings::get( 'image_height' ),
				'model'    => VMSB_Settings::get( 'pollinations_model' ),
				'nologo'   => 'true',
				'enhance'  => 'true',
				'seed'     => wp_rand( 1, 999999 ),
			)
		);
		return $this->fetch_bytes( $url, 120 );
	}

	private function from_google( $prompt, $subject, $meta ) {
		$key = VMSB_Settings::get( 'gemini_key' );
		if ( ! $key ) {
			return new WP_Error( 'vmsb_google', 'Gemini/Google key missing.' );
		}

		$model = VMSB_Settings::get( 'google_imagen_model', 'imagen-3' );

		// Imagen 3 via Vertex AI/Gemini API (2026 Beta Endpoint)
		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:predict?key=" . rawurlencode( $key );
		$body = array(
			'instances' => array(
				array( 'prompt' => $prompt )
			),
			'parameters' => array(
				'sampleCount' => 1,
				'aspectRatio' => '16:9',
			)
		);

		$res = wp_remote_post( $url, array(
			'timeout' => 90,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $res ) ) return $res;
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! empty( $data['predictions'][0]['bytesBase64Encoded'] ) ) {
			return base64_decode( $data['predictions'][0]['bytesBase64Encoded'] );
		}

		return new WP_Error( 'vmsb_google', 'Google Imagen returned no image data.' );
	}

	private function from_banana( $prompt, $subject, $meta ) {
		$key = VMSB_Settings::get( 'banana_key' );
		if ( ! $key ) {
			return new WP_Error( 'vmsb_banana', 'Banana.dev API key missing.' );
		}

		// Banana.dev Serverless Inference (2026 High-Speed Node)
		$url = "https://api.banana.dev/start/v1/";
		$body = array(
			'apiKey'      => $key,
			'modelKey'    => VMSB_Settings::get( 'banana_model', 'flux-nano-banana' ),
			'modelInputs' => array( 'prompt' => $prompt, 'width' => 1024, 'height' => 768 )
		);

		$res = wp_remote_post( $url, array(
			'timeout' => 90,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $res ) ) return $res;
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( ! empty( $data['modelOutputs'][0]['image_url'] ) ) {
			return $this->fetch_bytes( $data['modelOutputs'][0]['image_url'], 60 );
		}

		return new WP_Error( 'vmsb_banana', 'Banana.dev returned no image.' );
	}

	private function from_huggingface( $prompt, $subject, $meta ) {
		$key   = VMSB_Settings::get( 'huggingface_key' );
		$model = VMSB_Settings::get( 'huggingface_model', 'black-forest-labs/FLUX.1-dev' );
		if ( ! $key ) {
			return new WP_Error( 'vmsb_hf', 'Hugging Face key missing.' );
		}

		$url = "https://api-inference.huggingface.co/models/{$model}";
		$res = wp_remote_post( $url, array(
			'timeout' => 90,
			'headers' => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'inputs' => $prompt ) ),
		) );

		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'vmsb_hf', 'Hugging Face HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
		}
		$body = wp_remote_retrieve_body( $res );
		return $body ? $body : new WP_Error( 'vmsb_hf', 'Empty image body from Hugging Face.' );
	}

	private function from_cloudflare( $prompt, $subject, $meta ) {
		$acc   = VMSB_Settings::get( 'cloudflare_account_id' );
		$token = VMSB_Settings::get( 'cloudflare_api_token' );
		$model = VMSB_Settings::get( 'cloudflare_model', '@cf/bytedance/stable-diffusion-xl-lightning' );

		if ( ! $acc || ! $token ) {
			return new WP_Error( 'vmsb_cf', 'Cloudflare Workers AI not configured.' );
		}

		$url = "https://api.cloudflare.com/client/v4/accounts/{$acc}/ai/run/{$model}";
		$res = wp_remote_post( $url, array(
			'timeout' => 60,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'prompt' => $prompt ) ),
		) );

		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'vmsb_cf', 'Cloudflare HTTP ' . $code . ': ' . wp_remote_retrieve_body( $res ) );
		}
		$body = wp_remote_retrieve_body( $res );
		return $body ? $body : new WP_Error( 'vmsb_cf', 'Empty image body from Cloudflare.' );
	}

	private function from_aipuffer( $prompt, $subject, $meta ) {
		$base = rtrim( (string) VMSB_Settings::get( 'aipuffer_url' ), '/' );
		$key  = VMSB_Settings::get( 'aipuffer_key' );

		if ( ! $base && ! $key ) {
			return new WP_Error( 'vmsb_aipuffer', 'AI Puffer is not configured.' );
		}

		$is_local = empty($base) || ( untrailingslashit($base) === untrailingslashit(home_url()) );
		if ( $is_local && ! $key ) {
			$opts = get_option('aipkit_options', []);
			$key = $opts['api_keys']['public_api_key'] ?? '';
		}

		$provider = VMSB_Settings::get( 'aipuffer_image_provider', 'openai' );
		$model    = VMSB_Settings::get( 'aipuffer_image_model', 'dall-e-3' );
		$size     = (int) VMSB_Settings::get( 'image_width' ) . 'x' . (int) VMSB_Settings::get( 'image_height' );

		if ( $is_local ) {
			$direct = $this->call_aipkit_image_direct( $prompt, $provider, $model, $size );
			if ( $direct && ! is_wp_error($direct) ) {
				return $direct;
			}
		}

		$body = array(
			'prompt'          => $prompt,
			'provider'        => $provider,
			'model'           => $model,
			'size'            => $size,
			'n'               => 1,
			'response_format' => 'url',
		);

		// Try different namespaces
		$namespaces = array( 'aipkit/v1', 'wpaicg/v1', 'mwai/v1' );
		$last_error = 'Unknown error';

		foreach ( $namespaces as $ns ) {
			$ns_url = $is_local ? get_rest_url( null, $ns ) : untrailingslashit( $base ) . '/wp-json/' . $ns;

			if ( $ns === 'mwai/v1' ) {
				$url = rtrim( $ns_url, '/' ) . '/images/generations';
			} else {
				$url = rtrim( $ns_url, '/' ) . '/images/generate';
			}

			// Local mode specific: try rest_do_request first
			if ( $is_local ) {
				$relative_path = str_replace( get_rest_url( null, '' ), '', $url );
				$request = new WP_REST_Request( 'POST', '/' . ltrim( $relative_path, '/' ) );
				$request->set_body_params( $body );
				if ( $key ) {
					$request->set_header( 'Authorization', 'Bearer ' . $key );
				}
				$response = rest_do_request( $request );
				if ( ! is_wp_error( $response ) && ! $response->is_error() ) {
					$data = $response->get_data();
					return $this->extract_image_from_aipuffer_data( $data );
				}
			}

			// Add API Key as query param for remote sites
			if ( ! $is_local && $key ) {
				$url = add_query_arg( 'aipkit_api_key', $key, $url );
			}

			// Remote or Local Loopback fallback
			$res = wp_remote_post( $url, array(
				'timeout' => 120,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
					'X-API-KEY'     => $key
				),
				'body' => wp_json_encode( $body ),
			) );

			if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
				$data = json_decode( wp_remote_retrieve_body( $res ), true );
				return $this->extract_image_from_aipuffer_data( $data );
			}
			if ( is_wp_error( $res ) ) {
				$last_error = $res->get_error_message();
			} else {
				$last_error = 'HTTP ' . wp_remote_retrieve_response_code( $res );
			}
		}

		return new WP_Error( 'vmsb_aipuffer', $last_error );
	}

	/**
	 * OmniRoute (self-hosted images + video) - see call_aipkit_image_direct()
	 * below for the actual AI Power/AIPKit direct bridge; this docblock was
	 * previously attached here instead, describing the wrong method.
	 */
	private function from_omniroute( $prompt, $subject, $meta ) {
		$url = VMSB_Settings::omniroute_base();
		$key = VMSB_Settings::get( 'omniroute_key' );

		if ( ! $url || ! $key ) {
			return new WP_Error( 'vmsb_omniroute', 'OmniRoute is not configured.' );
		}

		$model = VMSB_Settings::get( 'omniroute_image_model', 'flux' );
		$size  = (int) VMSB_Settings::get( 'image_width', 1200 ) . 'x' . (int) VMSB_Settings::get( 'image_height', 675 );

		$body = array(
			'prompt'          => $prompt,
			'model'           => $model,
			'size'            => $size,
			'response_format' => 'b64_json',
		);

		$res = wp_remote_post( $url . '/v1/images/generations', array(
			'timeout' => 120,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $key,
			),
			'body' => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code !== 200 ) {
			return new WP_Error( 'vmsb_omniroute', $data['error']['message'] ?? 'HTTP ' . $code );
		}

		if ( ! empty( $data['data'][0]['b64_json'] ) ) {
			return base64_decode( $data['data'][0]['b64_json'] );
		}

		if ( ! empty( $data['data'][0]['url'] ) ) {
			return $this->fetch_bytes( $data['data'][0]['url'] );
		}

		return new WP_Error( 'vmsb_omniroute', 'No image data in OmniRoute response.' );
	}

	/**
	 * Direct Zero-Distance Bridge for AI Power / AIPKit (Images).
	 * Bypasses HTTP/REST for 100% compatibility when on the same server -
	 * signature verified against the installed AIPKit_Image_Manager.
	 */
	private function call_aipkit_image_direct( $prompt, $provider, $model, $size ) {
		if ( ! class_exists('\WPAICG\Images\AIPKit_Image_Manager') ) return null;

		try {
			$img_manager = new \WPAICG\Images\AIPKit_Image_Manager();
			$options = array(
				'provider' => $provider,
				'model'    => $model,
				'size'     => $size,
				'n'        => 1,
			);
			$res = $img_manager->generate_image( $prompt, $options );

			if ( ! is_wp_error($res) ) {
				return $this->extract_image_from_aipuffer_data( $res );
			}
			return $res;
		} catch ( \Throwable $e ) {
			( new VMSB_Logger() )->error( 'aipuffer', 'AI Power image direct bridge exception: ' . $e->getMessage() );
		}

		return null;
	}

	private function extract_image_from_aipuffer_data( $data ) {
		// Handle both standard OpenAI shape and premium AIPKit shape
		if ( ! empty( $data['images'][0]['url'] ) ) {
			return $this->fetch_bytes( $data['images'][0]['url'], 60 );
		}
		if ( ! empty( $data['images'][0]['b64_json'] ) ) {
			return base64_decode( $data['images'][0]['b64_json'] );
		}
		if ( ! empty( $data['data'][0]['url'] ) ) {
			return $this->fetch_bytes( $data['data'][0]['url'], 60 );
		}
		if ( ! empty( $data['data'][0]['b64_json'] ) ) {
			return base64_decode( $data['data'][0]['b64_json'] );
		}
		return new WP_Error( 'vmsb_aipuffer', 'No image in AI Puffer response.' );
	}

	private function from_pexels( $prompt, $subject, $meta ) {
		$key = VMSB_Settings::get( 'pexels_key' );
		if ( ! $key ) {
			return new WP_Error( 'vmsb_pexels', 'No Pexels key.' );
		}
		$query = $meta['keyword'] ? $meta['keyword'] : $subject;
		$res   = wp_remote_get(
			'https://api.pexels.com/v1/search?' . http_build_query(
				array(
					'query'       => $query,
					'per_page'    => 5,
					'orientation' => $meta['orientation'],
				)
			),
			array( 'timeout' => 30, 'headers' => array( 'Authorization' => $key ) )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['photos'] ) ) {
			return new WP_Error( 'vmsb_pexels', 'No stock match for: ' . $query );
		}
		$photo = $data['photos'][ array_rand( $data['photos'] ) ];
		$bytes = $this->fetch_bytes( $photo['src']['large2x'], 60 );
		if ( ! is_wp_error( $bytes ) && $bytes ) {
			// Remember attribution for the caption.
			$this->last_credit = sprintf( 'Photo by %s on Pexels', $photo['photographer'] );
		}
		return $bytes;
	}

	public $last_credit = '';

	/* ---------------------------------------------------------------- helpers */

	private function fetch_bytes( $url, $timeout = 60 ) {
		$res = wp_remote_get( $url, array( 'timeout' => $timeout ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'vmsb_fetch', 'HTTP ' . $code . ' from ' . wp_parse_url( $url, PHP_URL_HOST ) );
		}
		$body = wp_remote_retrieve_body( $res );
		return $body ? $body : new WP_Error( 'vmsb_fetch', 'Empty image body.' );
	}

	/**
	 * Write bytes into the media library with SEO-clean naming and alt text.
	 */
	/**
	 * Resize to the configured dimensions and re-encode at a sane weight.
	 *
	 * Deliberately conservative in three ways. It never upscales - enlarging a
	 * small render just adds bytes to a soft image. It keeps PNG as PNG when
	 * the source has transparency, because flattening an alpha channel onto
	 * white is a visible, unrecoverable change. And it returns null rather
	 * than an error whenever anything is unavailable or the result comes out
	 * larger than the original, so the caller simply keeps the bytes it had.
	 *
	 * @return array{bytes:string,mime:string}|null
	 */
	private function optimise( $bytes, $mime ) {
		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			return null;
		}

		$max_w   = max( 320, (int) VMSB_Settings::get( 'image_width', 1200 ) );
		$max_h   = max( 320, (int) VMSB_Settings::get( 'image_height', 675 ) );
		$quality = min( 100, max( 40, (int) VMSB_Settings::get( 'image_quality', 82 ) ) );

		// wp_get_image_editor() needs a file, not a string.
		$tmp = wp_tempnam( 'vmsb-img' );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) {
			return null;
		}

		$editor = wp_get_image_editor( $tmp );
		if ( is_wp_error( $editor ) ) {
			@unlink( $tmp );
			return null;
		}

		$size = $editor->get_size();
		$editor->set_quality( $quality );

		// Only shrink, and only when the source actually exceeds the target.
		if ( ! empty( $size['width'] ) && ! empty( $size['height'] )
			&& ( $size['width'] > $max_w || $size['height'] > $max_h ) ) {
			$editor->resize( $max_w, $max_h, false ); // false = fit inside, keep aspect
		}

		// A PNG carrying transparency stays a PNG. Anything else becomes JPEG,
		// which is universally supported and far lighter for the photographic
		// output these models produce. WebP is deliberately not forced: support
		// depends on the host's GD/Imagick build, and a silent failure here
		// would cost the image entirely.
		$target = ( 'image/png' === $mime && $this->has_alpha( $bytes ) ) ? 'image/png' : 'image/jpeg';

		$saved = $editor->save( null, $target );
		@unlink( $tmp );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return null;
		}

		$out = file_get_contents( $saved['path'] );
		@unlink( $saved['path'] );

		if ( false === $out || '' === $out || strlen( $out ) >= strlen( $bytes ) ) {
			return null; // no saving to be had - keep the original
		}

		return array(
			'bytes' => $out,
			'mime'  => ! empty( $saved['mime-type'] ) ? $saved['mime-type'] : $target,
		);
	}

	/**
	 * Does this PNG use its alpha channel?
	 *
	 * Byte 25 of the IHDR chunk is the colour type; 4 (greyscale+alpha) and 6
	 * (RGB+alpha) carry transparency, and 3 (palette) may via a tRNS chunk.
	 * Read from the header rather than by decoding the whole image, which for
	 * a 3 MB render is a needless allocation just to answer a yes/no.
	 */
	private function has_alpha( $bytes ) {
		if ( strlen( $bytes ) < 26 || "\x89PNG" !== substr( $bytes, 0, 4 ) ) {
			return false;
		}

		$colour_type = ord( $bytes[25] );
		if ( in_array( $colour_type, array( 4, 6 ), true ) ) {
			return true;
		}

		return 3 === $colour_type && false !== strpos( substr( $bytes, 0, 4096 ), 'tRNS' );
	}

	private function sideload( $bytes, $subject, $meta, $provider ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$slug_source = $meta['filename_hint'] ? $meta['filename_hint'] : ( $meta['keyword'] ? $meta['keyword'] : $subject );

		// World-Class SEO: Semantic Filenames
		$clean_slug = sanitize_title( $slug_source );
		if ( strlen($clean_slug) < 5 ) {
			$clean_slug = sanitize_title( $subject );
		}

		// Detect the real image format instead of assuming JPEG - providers like
		// Cloudflare/HuggingFace commonly return PNG/WEBP, and this also catches
		// providers that returned an error page body instead of image bytes.
		$mime_ext_map = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif' );
		$info = @getimagesizefromstring( $bytes );
		if ( ! $info || empty( $info['mime'] ) || ! isset( $mime_ext_map[ $info['mime'] ] ) ) {
			return new WP_Error( 'vmsb_upload', 'Provider returned data that is not a valid, supported image.' );
		}
		$mime = $info['mime'];
		$ext  = $mime_ext_map[ $mime ];

		// Nothing optimised these bytes before now: whatever the provider
		// returned went into the library as-is. That matters more here than in
		// a normal media upload, because these are machine-generated at volume
		// - image models answer at their own native size and format, commonly
		// a 1024x1024 or 1792x1024 PNG weighing 1.5-3 MB, and a PNG of a
		// photographic scene is several times the size of the same image as
		// JPEG or WebP. Published three posts a day with a featured image and
		// inline images each, that is the plugin steadily filling the media
		// library with oversized files and dragging down the LCP of the very
		// pages it exists to rank.
		//
		// wp_get_image_editor() uses whichever backend the host actually has
		// (Imagick, then GD) and returns WP_Error when neither can help, so a
		// host without image support degrades to the original bytes rather
		// than failing the upload.
		$optimised = $this->optimise( $bytes, $mime );
		if ( $optimised ) {
			$bytes = $optimised['bytes'];
			$mime  = $optimised['mime'];
			$ext   = $mime_ext_map[ $mime ] ?? $ext;
		}

		$filename = mb_substr($clean_slug, 0, 50) . '-' . wp_rand( 100, 999 ) . '.' . $ext;

		$upload = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'vmsb_upload', $upload['error'] );
		}

		$filetype = wp_check_filetype( $upload['file'], null );
		$alt      = $meta['alt'] ? $meta['alt'] : $subject;

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : $mime,
				'post_title'     => wp_strip_all_tags( $subject ),
				'post_content'   => '',
				'post_excerpt'   => $this->last_credit,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $meta['post_id']
		);

		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			@unlink( $upload['file'] ); // Cleanup orphan file
			return new WP_Error( 'vmsb_attach', 'Could not create the attachment.' );
		}

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		update_post_meta( $attach_id, '_vmsb_provider', $provider );
		$this->last_credit = '';

		return (int) $attach_id;
	}

	/**
	 * Repair alt text across the library using AI + surrounding post context.
	 */
	public function backfill_alt_text( $limit = 25 ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'posts_per_page' => (int) $limit,
				'meta_query'     => array(
					'relation' => 'OR',
					array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
				),
			)
		);

		if ( ! $attachments ) {
			return 0;
		}

		$ai    = new VMSB_AI_Router();
		$items = array();
		foreach ( $attachments as $img ) {
			$parent  = $img->post_parent ? get_post( $img->post_parent ) : null;
			$context = $parent ? $parent->post_title : get_bloginfo( 'name' );
			$path    = get_attached_file( $img->ID );
			$name    = $path ? str_replace( array( '-', '_' ), ' ', pathinfo( $path, PATHINFO_FILENAME ) ) : 'image';
			$items[] = array(
				'id'      => $img->ID,
				'name'    => $name,
				'context' => $context,
			);
		}

		// Batch generate ALTs to avoid synchronous AI loops and timeouts.
		$prompt = "Write concise SEO alt text (under 125 chars) for these images. Describe what a reader would see. No 'image of'.\n\n";
		foreach ( $items as $i => $item ) {
			$prompt .= ( $i + 1 ) . ". Filename hint: \"{$item['name']}\", Context: \"{$item['context']}\"\n";
		}
		$prompt .= "\nReturn JSON: {\"alts\": []}";

		$data = $ai->generate_json( $prompt, array( 'max_tokens' => 1000, 'temperature' => 0.4, 'persona' => 'wordsmith' ) );
		$alts = isset( $data['alts'] ) && is_array( $data['alts'] ) ? $data['alts'] : array();

		$fixed = 0;
		foreach ( $items as $i => $item ) {
			$alt = isset( $alts[ $i ] ) ? trim( wp_strip_all_tags( $alts[ $i ] ), " \"'\n" ) : ucfirst( $item['name'] );
			update_post_meta( $item['id'], '_wp_attachment_image_alt', mb_substr( $alt, 0, 125 ) );
			$fixed++;
		}

		return $fixed;
	}
}
