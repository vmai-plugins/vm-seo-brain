<?php
defined( 'ABSPATH' ) || exit;

/**
 * One entry point for every text generation in the plugin.
 * AI Puffer is primary; the chain falls through on failure, quota, or timeout.
 * Every call is budgeted, cached, and logged so an autonomous run can't burn a wallet.
 */
class VMSB_AI_Router {

	const BUDGET_KEY = 'vmsb_ai_calls_';

	private $log;
	private $last_error = '';

	public function __construct() {
		$this->log = new VMSB_Logger();
	}

	/**
	 * The specific reason the most recent generate()/generate_json() call
	 * failed. generate_json() only ever returns null on failure, discarding
	 * the real provider error - callers that need to show the operator why
	 * (e.g. writing it to a plan row's last_error) should read this right
	 * after a null/false result instead of falling back to a generic string.
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * @param string $prompt   User prompt.
	 * @param array  $args     system, json, temperature, max_tokens, cache_ttl, kb (bool), provider, complexity (cheap|standard|premium), persona (strategist|wordsmith|auditor|thief), attempt (int)
	 * @return array{ok:bool,text:string,provider:string,model:string,error:string,usage:array}
	 */
	public function generate( $prompt, array $args = array() ) {
		$explicit_temperature = array_key_exists( 'temperature', $args );
		$args = wp_parse_args(
			$args,
			array(
				'system'      => '',
				'json'        => false,
				'temperature' => 0.6,
				'max_tokens'  => 2400,
				'cache_ttl'   => 0,
				'kb'          => true,
				'provider'    => '',
				'complexity'  => 'standard',
				'persona'     => 'strategist',
				'attempt'     => 1,
			)
		);

		// Captured before any mutation below, so a recursive quality-gate
		// retry (further down) can rebuild the system prompt from a clean
		// baseline instead of re-prepending/re-appending the persona
		// instruction and mistakes-memory block on top of an already
		// mutated string every retry.
		$original_system = $args['system'];

		// Prepend Persona Instruction (from Autopilot)
		$persona_instruction = $this->get_persona_instruction( $args['persona'] );
		if ( $persona_instruction ) {
			$args['system'] = trim( $persona_instruction . "\n" . $args['system'] );
		}

		// World-Class Optimization: Model-Specific Prompt Tuning - only relevant
		// for JSON calls; injecting JSON-formatting instructions into a plain-text
		// generation (e.g. a wordsmith blog draft) confuses the model's role.
		if ( ! empty( $args['json'] ) ) {
			$provider_primary = VMSB_Settings::get( 'ai_primary' );
			if ( $provider_primary === 'gemini' ) {
				$args['system'] .= "\nIMPORTANT: Ensure JSON keys are exactly as requested. Do not include markdown code blocks.";
			} elseif ( $provider_primary === 'openrouter' && strpos(VMSB_Settings::get('openrouter_model'), 'claude') !== false ) {
				$args['system'] .= "\nIMPORTANT: You are an expert strategist. Be concise. Start your response directly with the requested JSON or HTML.";
			}
		}

		// World-Class Optimization: Dynamic Temperature based on Persona -
		// only when the caller didn't explicitly ask for a specific temperature.
		if ( ! $explicit_temperature ) {
			$args['temperature'] = $this->get_persona_temperature( $args['persona'], $args['temperature'] );
		}

		// Loop protection for recursive quality checks
		if ( $args['attempt'] > 2 ) {
			return $this->fail( 'Maximum AI recursive attempts reached.' );
		}

		// Sync to Memory (MWAI Knowledge Base) if enabled
		if ( ! empty($args['kb']) && class_exists('VMSB_AIPuffer') ) {
			// Optimization: Only sync unique prompts to memory to save KB space/tokens
			VMSB_AIPuffer::push_to_memory( "Prompt History", $prompt, array( 'persona' => $args['persona'] ) );
		}

		// Token Efficiency: Strip excessive whitespace and Normalize
		$prompt = trim( preg_replace( '/\s+/', ' ', $prompt ) );

		$cache_key = 'vmsb_ai_' . md5( $prompt . wp_json_encode( $args ) );
		if ( $args['cache_ttl'] > 0 ) {
			$hit = get_transient( $cache_key );
			if ( false !== $hit ) {
				return $hit;
			}
		}

		if ( ! $this->budget_available() ) {
			return $this->fail( 'Daily AI call budget reached. Raise it in Settings > Autonomy or wait for the reset.' );
		}

		// World-Class Optimization: Mistakes Memory (Learning from Rejections)
		if ( $args['persona'] === 'wordsmith' ) {
			$mistakes = ( new VMSB_Brain() )->recall( 'ai_training', 'recent_mistakes' );
			if ( $mistakes ) {
				$args['system'] .= "\n\nCRITICAL: In previous attempts, you made these mistakes. DO NOT REPEAT THEM:\n- " . implode("\n- ", (array)$mistakes);
			}
		}

		// Sentient Heartbeat: Notify logs that the Brain is reasoning
		$this->log->info( 'brain', "AI Generation Started: Persona '{$args['persona']}' processing task.", array( 'tokens_est' => $args['max_tokens'] ) );

		$chain = $this->get_chain( $args );
		$chain = array_values( array_unique( array_filter( $chain ) ) );

		$errors = array();
		foreach ( $chain as $provider_config ) {
			// provider_config can be a string (provider name) or an array [provider, model]
			if ( is_array( $provider_config ) ) {
				$provider = $provider_config[0];
				$args['forced_model'] = $provider_config[1];
			} else {
				$provider = $provider_config;
				unset( $args['forced_model'] );
			}

			// 2026 Resilience: Skip blocked providers (Bypass allowed for manual tests)
			if ( empty($args['bypass_circuit']) && ! VMSB_AI_Circuit::is_available( $provider ) ) {
				$errors[ $provider ] = 'Blocked by circuit breaker (too many failures).';
				continue;
			}

			$method = 'call_' . $provider;
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}

			$start_time = microtime(true);
			$result = $this->$method( $prompt, $args );
			$latency = microtime(true) - $start_time;

			if ( ! empty( $result['ok'] ) && '' !== trim( $result['text'] ) ) {
				$this->count_call();
				$this->record_latency( $provider, $latency );
				VMSB_AI_Circuit::success( $provider ); // RESET provider circuit
				VMSB_Health::reset_failures();
				$result['provider'] = $provider;
				$result['model']    = $result['model'] ?? ($args['forced_model'] ?? 'unknown');

				// Record Usage
				if ( class_exists('VMSB_Usage') ) {
					VMSB_Usage::record( array(
						'provider'   => $provider,
						'model'      => $result['model'],
						'persona'    => $args['persona'],
						'tokens_in'  => $result['usage']['prompt_tokens'] ?? 0,
						'tokens_out' => $result['usage']['completion_tokens'] ?? 0,
					) );
				}

				if ( $args['cache_ttl'] > 0 ) {
					set_transient( $cache_key, $result, (int) $args['cache_ttl'] );
				}

				// Quality Recursive Correction (from Autopilot)
				if ( $args['persona'] === 'wordsmith' && $args['max_tokens'] > 2000 && $args['attempt'] < 2 ) {
					// World-Class Critque Logic: EEAT, Specificity, and Human-Like Flow.
					// This is a plain "REVISE: ..." / "PASS" verdict, not JSON - use
					// generate() directly, generate_json() forces a JSON-only system
					// prompt that contradicts the format asked for here and made the
					// verdict fail to parse on every call.
					$quality_check = $this->generate(
						"Act as a Senior Editor. Review this generated content for EEAT and High-End quality. "
						. "Criteria: 1. Is it specific to the brand? 2. Does it avoid AI filler? 3. Is the tone truly expert? "
						. "If score < 85, reply with 'REVISE: [Specific technical critique]'. Otherwise reply 'PASS'.\n\n"
						. "CONTENT: " . wp_trim_words($result['text'], 600),
						array('max_tokens' => 200, 'persona' => 'auditor')
					);

					$verdict = ! empty( $quality_check['ok'] ) ? trim( $quality_check['text'] ) : '';
					if ( 0 === stripos( $verdict, 'REVISE' ) ) {
						$critique = trim( preg_replace( '/^REVISE:\s*/i', '', $verdict ) ) ?: 'Content lacks sufficient expert depth and brand alignment.';
						( new VMSB_Logger() )->warn( 'ai', 'Content Quality Refiner: Re-writing with technical critique: ' . $critique );
						$args['attempt']++;
						// Reset to the caller's original system prompt before retrying -
						// $args['system'] has already had the persona instruction and
						// mistakes-memory block folded in once; recursing with it as-is
						// would fold them in a second time on top of themselves.
						$args['system'] = $original_system;
						return $this->generate( $prompt . "\n\nCRITICAL EDITOR FEEDBACK: {$critique}\nFocus on providing more technical specifics and brand-first expertise.", $args );
					}
				}

				return $result;
			}

			$error_msg = isset( $result['error'] ) ? $result['error'] : 'no response';
			$errors[ $provider ] = $error_msg;
			VMSB_AI_Circuit::failure( $provider, $error_msg );
		}

		VMSB_Health::record_failure(); // Circuit Breaker
		$this->log->error( 'ai', 'Every provider in the chain failed.', $errors );

		$primary = VMSB_Settings::get('ai_primary');
		$error_detail = isset($errors[$primary]) ? $errors[$primary] : 'Unknown error';

		return $this->fail( "Connection Failed. Primary provider ({$primary}) reported: {$error_detail}. Ensure your API keys are correct and you have saved settings." );
	}

	private function get_chain( $args ) {
		if ( ! empty( $args['provider'] ) ) {
			return array( $args['provider'] );
		}

		$complexity = isset( $args['complexity'] ) ? $args['complexity'] : 'standard';
		$primary    = VMSB_Settings::get( 'ai_primary' );
		$fallbacks  = (array) VMSB_Settings::get( 'ai_fallbacks' );
		$full_chain = array_merge( array( $primary ), $fallbacks );

		// Advanced 2026 Routing: Efficiency-First
		if ( 'cheap' === $complexity ) {
			// Fast, high-throughput models for simple audits
			if ( VMSB_Settings::get( 'gemini_key' ) ) {
				$full_chain = array_merge( array( array( 'gemini', 'gemini-1.5-flash' ) ), $full_chain );
			} elseif ( VMSB_Settings::get( 'openai_key' ) ) {
				$full_chain = array_merge( array( array( 'openai', 'gpt-4o-mini' ) ), $full_chain );
			}
		}

		if ( 'premium' === $complexity ) {
			// Deep reasoning models for long-form content
			if ( VMSB_Settings::get( 'openrouter_key' ) ) {
				$full_chain = array_merge( array( array( 'openrouter', 'anthropic/claude-3.5-sonnet' ) ), $full_chain );
			} elseif ( VMSB_Settings::get( 'gemini_key' ) ) {
				$full_chain = array_merge( array( array( 'gemini', 'gemini-2.0-flash' ) ), $full_chain );
			}
		}

		return array_values( array_unique( array_filter( $full_chain ), SORT_REGULAR ) );
	}

	/**
	 * Generate and decode JSON. Returns array or null.
	 */
	public function generate_json( $prompt, array $args = array() ) {
		$args['json']   = true;
		$args['system'] = trim( ( isset( $args['system'] ) ? $args['system'] : '' ) . "\nReturn only raw JSON. No markdown fences, no commentary." );

		$res = $this->generate( $prompt, $args );
		if ( empty( $res['ok'] ) ) {
			return null;
		}
		$parsed = self::parse_json( $res['text'] );
		if ( null === $parsed ) {
			// The call succeeded but the reply wasn't valid/salvageable JSON -
			// this is a different failure than a provider error, so don't leave
			// get_last_error() pointing at a stale or empty message.
			// Show both ends of the reply, not just the head: a head-only
			// snippet can't distinguish "the model produced garbage" from
			// "the model hit its token limit mid-article and got cut off
			// inside a string" - which look identical from the first 200
			// characters but need completely different fixes (one's a
			// prompt/parsing problem, the other's a max_tokens problem).
			$full    = (string) $res['text'];
			$snippet = mb_strlen( $full ) > 400
				? mb_substr( $full, 0, 200 ) . ' … ' . mb_substr( $full, -200 )
				: $full;
			$this->last_error = 'Model reply was not valid JSON: ' . $snippet;
		}
		return $parsed;
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		$text = preg_replace( '/^```(?:json)?|```$/m', '', $text );
		$text = trim( $text );

		// Salvage Step 1: Standard Parse
		$data = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $data;
		}

		// Salvage Step 2: Fix trailing commas (common LLM mistake)
		$fixed = preg_replace( '/,\s*([\]\}])/', '$1', $text );
		$data  = json_decode( $fixed, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $data;
		}

		// Salvage Step 3: Outermost object/array extraction
		if ( preg_match( '/(\{.*\}|\[.*\])/s', $text, $m ) ) {
			$inner = $m[1];
			$data = json_decode( $inner, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $data;
			}

			// Try fixing trailing commas in the extracted part too
			$inner_fixed = preg_replace( '/,\s*([\]\}])/', '$1', $inner );
			$data = json_decode( $inner_fixed, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return $data;
			}
		}

		return null;
	}

	/* ---------------------------------------------------------------- providers */

	private function call_aipuffer( $prompt, $args ) {
		$base   = untrailingslashit( (string) VMSB_Settings::get( 'aipuffer_url' ) );
		$key    = VMSB_Settings::get( 'aipuffer_key' );
		$bot_id = VMSB_Settings::get( 'aipuffer_bot_id' );

		// Intelligence: If URL is empty or using the placeholder, assume Local Mode (AI Power/Engine installed here)
		$is_local = empty($base) || strpos( $base, 'your-aipuffer-host' ) !== false || ( untrailingslashit($base) === untrailingslashit(home_url()) );

		if ( ! $is_local && ! $key ) {
			return $this->fail( 'AI Puffer is not configured.' );
		}

		if ( $is_local && ! $key ) {
			$opts = get_option('aipkit_options', []);
			$key = $opts['api_keys']['public_api_key'] ?? '';
		}

		$body = array(
			'messages'    => $this->messages( $prompt, $args ),
			'temperature' => (float) $args['temperature'],
			'max_tokens'  => (int) $args['max_tokens'],
			'stream'      => false,
		);

		if ( $bot_id ) {
			$body['botId'] = $bot_id;
			$body['bot_id'] = $bot_id;
		}

		$kb = VMSB_Settings::get( 'aipuffer_kb_id' );
		if ( $args['kb'] && $kb ) {
			$body['knowledge_base_id'] = $kb;
			$body['use_knowledge']     = true;
		}

		// Try different namespaces for AI Puffer / AI Power / AI Engine
		$namespaces = array( 'aipkit/v1', 'mwai/v1', 'wpaicg/v1' );
		$last_error = 'Unknown error';

		$context = array(
			'site_dna' => VMSB_Settings::get( 'business_description' ),
			'niche'    => VMSB_Settings::get( 'business_type' ),
			'url'      => home_url()
		);

		if ( class_exists('VMSB_Graph') ) {
			global $wpdb;
			$entities = $wpdb->get_col( "SELECT DISTINCT object_id FROM {$wpdb->prefix}vmsb_graph WHERE predicate = 'covers_entity' LIMIT 20" );
			if ( $entities ) {
				$context['topical_entities'] = $entities;
			}
		}

		foreach ( $namespaces as $ns ) {
			$ns_url = $is_local ? get_rest_url( null, $ns ) : untrailingslashit( $base ) . '/wp-json/' . $ns;

			if ( $ns === 'mwai/v1' ) {
				$rel_suffix = '/simpleChatbotQuery';
				$url = rtrim( $ns_url, '/' ) . $rel_suffix;
				$request_body = array_merge( $body, array(
					'message' => $prompt,
					'newChat' => true,
					'context' => $context
				) );
			} elseif ( $ns === 'aipkit/v1' || $ns === 'wpaicg/v1' ) {
				if ( $bot_id ) {
					// Hardening: AI Power often expects /chat/message or /chat/{id}/message
					$rel_suffix = "/chat/{$bot_id}/message";
					$url = rtrim( $ns_url, '/' ) . $rel_suffix;
					// AI Power's chat-bot endpoint only accepts 'user'/'assistant'
					// roles in its messages schema (a bot's system instructions live
					// on the bot itself, configured in its own settings) - sending
					// our usual 'system' role entry fails REST schema validation
					// with a 400 on every single call.
					$request_body = array_merge( $body, array(
						'messages' => array( array( 'role' => 'user', 'content' => $prompt ) ),
						'context' => $context,
						'aipkit_api_key' => $key,
						'message' => $prompt // Ensure single message string is sent
					) );
				} else {
					$rel_suffix = '/generate';
					$url = rtrim( $ns_url, '/' ) . $rel_suffix;
					$request_body = array(
						'provider' => strtolower($args['provider'] ?: 'openai'),
						'model'    => $args['forced_model'] ?: 'gpt-4o-mini',
						'messages' => $this->messages( $prompt, $args ),
						'ai_params'=> array(
							'temperature' => $args['temperature'],
							'max_completion_tokens' => $args['max_tokens']
						),
						'aipkit_api_key' => $key
					);
				}
			} else {
				$rel_suffix = $bot_id ? "/chat/{$bot_id}/message" : '/chat/completions';
				$url = rtrim( $ns_url, '/' ) . $rel_suffix;
				$request_body = array_merge( $body, array(
					'context' => $context,
					'aipkit_api_key' => $key
				) );
			}

			// Local mode diagnostic check: Verify bot existence
			if ( $is_local && $bot_id && (int)$bot_id > 0 ) {
				$bot_post = get_post($bot_id);
				if ( ! $bot_post ) {
					$last_error = "Local Bot ID {$bot_id} does not exist. Check your Bot ID.";
					continue;
				}
			}

			if ( $is_local ) {
				// For local, use the internal path. Built directly from the known
				// namespace + suffix rather than reverse-parsing $url - stripping
				// "wp-json/" out of $url silently produced a bogus route whenever
				// pretty permalinks are off, since get_rest_url() then returns a
				// "?rest_route=" query string with no "wp-json/" segment to strip.
				$relative_path = $ns . $rel_suffix;

				$request = new WP_REST_Request( 'POST', '/' . ltrim( $relative_path, '/' ) );
				$request->set_body_params( $request_body );
				if ( $key ) {
					$request->set_header( 'Authorization', 'Bearer ' . $key );
					$request->set_header( 'X-API-KEY', $key );
				}

				// Standardize request for internal REST
				$request->set_param( 'aipkit_api_key', $key );
				$request->set_param( 'message', $prompt );
				$request->set_param( 'bot_id', $bot_id );

				$response = rest_do_request( $request );
				if ( ! is_wp_error( $response ) && ! $response->is_error() ) {
					$data = $response->get_data();
					// Internal bridge debug logging
					$this->log->info( 'aipuffer', "Local REST bridge success on {$ns}.", array('bot' => $bot_id) );
					return $this->extract_aipuffer_reply( $data, $args['forced_model'] ?? 'aipuffer' );
				}
				$last_error = is_wp_error( $response ) ? $response->get_error_message() : ( method_exists($response, 'get_data') ? wp_json_encode($response->get_data()) : 'REST Internal Error' );
				$this->log->warn( 'aipuffer', "Local REST bridge failed on {$ns}: " . $last_error );
			}

			$res = $this->post( $url, $request_body, array( 'Authorization' => 'Bearer ' . $key, 'X-API-KEY' => $key ), 120 );

			if ( ! empty( $res['ok'] ) ) {
				return $this->extract_aipuffer_reply( $res['data'], $args['forced_model'] ?? 'aipuffer' );
			}
			$last_error = $res['error'] . ' (' . $url . ')';
		}

		return $this->fail( $last_error );
	}

	private function extract_aipuffer_reply( $data, $model ) {
		$reply = '';
		$search_targets = array();
		if ( isset($data['data']) ) $search_targets[] = $data['data'];
		$search_targets[] = $data;

		foreach ( $search_targets as $target ) {
			if ( is_string($target) && ! empty($target) && ! is_numeric($target) ) {
				$reply = $target;
				break;
			}
			if ( is_array($target) ) {
				foreach ( array( 'reply', 'content', 'response', 'text', 'output', 'result', 'answer', 'message' ) as $field ) {
					if ( ! empty( $target[ $field ] ) && is_string( $target[ $field ] ) ) {
						$reply = $target[ $field ];
						break 2;
					}
				}
				if ( isset($target['choices'][0]['message']['content']) ) {
					$reply = $target['choices'][0]['message']['content'];
					break;
				}
			}
		}

		// Hardened Usage Extraction
		$u_source = $data['usage'] ?? ( $data['data']['usage'] ?? array() );
		$usage = array(
			'prompt_tokens'     => $u_source['prompt_tokens'] ?? ( $u_source['input_tokens'] ?? 0 ),
			'completion_tokens' => $u_source['completion_tokens'] ?? ( $u_source['output_tokens'] ?? 0 ),
		);

		return $reply ? $this->ok( $reply, $model, $usage ) : $this->fail( 'Empty reply from AI Puffer.' );
	}

	private function call_openai( $prompt, $args ) {
		$key = VMSB_Settings::get( 'openai_key' );
		if ( ! $key ) {
			return $this->fail( 'No OpenAI key.' );
		}
		$model = isset( $args['forced_model'] ) ? $args['forced_model'] : VMSB_Settings::get( 'openai_model' );
		$body = array(
			'model'       => $model,
			'messages'    => $this->messages( $prompt, $args ),
			'temperature' => (float) $args['temperature'],
			'max_tokens'  => (int) $args['max_tokens'],
		);
		if ( $args['json'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}
		$res = $this->post( 'https://api.openai.com/v1/chat/completions', $body, array( 'Authorization' => 'Bearer ' . $key ) );
		return $this->from_openai_shape( $res, $model );
	}

	private function call_openrouter( $prompt, $args ) {
		$key = VMSB_Settings::get( 'openrouter_key' );
		if ( ! $key ) {
			return $this->fail( 'No OpenRouter key.' );
		}
		$model = isset( $args['forced_model'] ) ? $args['forced_model'] : VMSB_Settings::get( 'openrouter_model' );
		$body = array(
			'model'       => $model,
			'messages'    => $this->messages( $prompt, $args ),
			'temperature' => (float) $args['temperature'],
			'max_tokens'  => (int) $args['max_tokens'],
		);
		$res = $this->post(
			'https://openrouter.ai/api/v1/chat/completions',
			$body,
			array(
				'Authorization' => 'Bearer ' . $key,
				'HTTP-Referer'  => home_url(),
				'X-Title'       => 'VM SEO Brain',
			)
		);
		return $this->from_openai_shape( $res, $model );
	}

	private function call_gemini( $prompt, $args ) {
		$key = VMSB_Settings::get( 'gemini_key' );
		if ( ! $key ) {
			return $this->fail( 'No Gemini key.' );
		}
		$model = isset( $args['forced_model'] ) ? $args['forced_model'] : VMSB_Settings::get( 'gemini_model' );
		$url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $key );
		$body  = array(
			'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
			'generationConfig' => array(
				'temperature'     => (float) $args['temperature'],
				'maxOutputTokens' => (int) $args['max_tokens'],
			),
		);
		if ( $args['system'] ) {
			$body['systemInstruction'] = array( 'parts' => array( array( 'text' => $args['system'] ) ) );
		}
		if ( $args['json'] ) {
			$body['generationConfig']['responseMimeType'] = 'application/json';
		}
		$res = $this->post( $url, $body );
		if ( empty( $res['ok'] ) ) {
			return $res;
		}

		$text  = isset( $res['data']['candidates'][0]['content']['parts'][0]['text'] ) ? $res['data']['candidates'][0]['content']['parts'][0]['text'] : '';
		$usage = array(
			'prompt_tokens'     => $res['data']['usageMetadata']['promptTokenCount'] ?? 0,
			'completion_tokens' => $res['data']['usageMetadata']['candidatesTokenCount'] ?? 0,
		);

		return $text ? $this->ok( $text, $model, $usage ) : $this->fail( 'Gemini returned no candidates.' );
	}

	private function call_ollama( $prompt, $args ) {
		$url = untrailingslashit( (string) VMSB_Settings::get( 'ollama_url' ) );
		if ( ! $url ) {
			return $this->fail( 'No Ollama URL.' );
		}
		$model = VMSB_Settings::get( 'ollama_model' );
		$body = array(
			'model'    => $model,
			'messages' => $this->messages( $prompt, $args ),
			'stream'   => false,
			'options'  => array( 'temperature' => (float) $args['temperature'] ),
		);
		$res = $this->post( $url . '/api/chat', $body, array(), 120 );
		if ( empty( $res['ok'] ) ) {
			return $res;
		}
		$text = isset( $res['data']['message']['content'] ) ? $res['data']['message']['content'] : '';

		$usage = array(
			'prompt_tokens'     => $res['data']['prompt_eval_count'] ?? 0,
			'completion_tokens' => $res['data']['eval_count'] ?? 0,
		);

		return $text ? $this->ok( $text, $model, $usage ) : $this->fail( 'Ollama returned nothing.' );
	}

	/* ---------------------------------------------------------------- plumbing */

	private function messages( $prompt, $args ) {
		$messages = array();
		if ( $args['system'] ) {
			$messages[] = array( 'role' => 'system', 'content' => $args['system'] );
		}
		$messages[] = array( 'role' => 'user', 'content' => $prompt );
		return $messages;
	}

	private function post( $url, $body, $headers = array(), $timeout = 90 ) {
		$args = array(
			'timeout' => $timeout,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
		);

		// Intelligence: Auto-disable SSL verify for .local or placeholder sites
		if ( VMSB_Settings::get( 'insecure_ssl' ) || strpos($url, '.local') !== false || strpos($url, 'your-aipuffer-host') !== false ) {
			$args['sslverify'] = false;
		}

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			// OpenAI-shaped error first, then WordPress's own REST error shape
			// (used by AI Puffer/AI Power's WP_Error responses) - without this,
			// the specific, actionable reason (e.g. "REST API access is
			// disabled.") gets swallowed into an unhelpful "HTTP 403".
			$msg = $data['error']['message'] ?? ( $data['message'] ?? 'HTTP ' . $code );
			return $this->fail( $msg );
		}

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->fail( 'Invalid JSON response from API.' );
		}

		return array( 'ok' => true, 'data' => $data, 'text' => '', 'error' => '', 'provider' => '' );
	}

	private function from_openai_shape( $res, $model ) {
		if ( empty( $res['ok'] ) ) {
			return $res;
		}
		$text = isset( $res['data']['choices'][0]['message']['content'] ) ? $res['data']['choices'][0]['message']['content'] : '';
		$usage = $res['data']['usage'] ?? array();
		return $text ? $this->ok( $text, $model, $usage ) : $this->fail( 'Empty completion.' );
	}

	private function ok( $text, $model = 'unknown', $usage = array() ) {
		return array( 'ok' => true, 'text' => (string) $text, 'model' => $model, 'usage' => $usage, 'error' => '', 'provider' => '' );
	}

	private function fail( $msg ) {
		$this->last_error = (string) $msg;
		return array( 'ok' => false, 'text' => '', 'model' => 'unknown', 'usage' => array(), 'error' => (string) $msg, 'provider' => '' );
	}

	private function get_persona_instruction( $persona ) {
		$personas = array(
			'strategist' => 'Act as a Senior SEO Strategy Director. You focus on data, ROI, and long-term authority.',
			'wordsmith'  => 'Act as an Elite SEO Content Writer. Your goal is to write deep-authority articles that users love and Google rewards.',
			'auditor'    => 'Act as a Strict SEO Auditor. You identify technical errors, missing entities, and structural weaknesses with precision.',
			'thief'      => 'Act as a Competitive Intelligence Analyst. You analyze competitors to find their weaknesses and steal their traffic.',
		);
		return isset( $personas[ $persona ] ) ? $personas[ $persona ] : $personas['strategist'];
	}

	private function get_persona_temperature( $persona, $default ) {
		$map = array(
			'strategist' => 0.4,
			'wordsmith'  => 0.8, // More creative for writing
			'auditor'    => 0.1, // High precision for audits
			'thief'      => 0.6,
		);
		return isset( $map[ $persona ] ) ? $map[ $persona ] : $default;
	}

	private function budget_key() {
		return self::BUDGET_KEY . gmdate( 'Ymd' );
	}

	public function calls_today() {
		return (int) get_option( $this->budget_key(), 0 );
	}

	private function budget_available() {
		return $this->calls_today() < (int) VMSB_Settings::get( 'max_ai_calls_day' );
	}

	private function count_call() {
		update_option( $this->budget_key(), $this->calls_today() + 1, false );
	}

	private function record_latency( $provider, $latency ) {
		$stats = get_transient( 'vmsb_ai_latency' ) ?: array();
		if ( ! isset($stats[$provider]) ) $stats[$provider] = array();
		$stats[$provider][] = $latency;
		if ( count($stats[$provider]) > 10 ) array_shift($stats[$provider]);
		set_transient( 'vmsb_ai_latency', $stats, HOUR_IN_SECONDS );
	}
}
