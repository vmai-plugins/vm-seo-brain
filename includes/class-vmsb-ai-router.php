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
	 * KSES wrapper that preserves Gutenberg block comments.
	 */
	public static function safe_html( $html ) {
		if ( empty( $html ) || ! is_string( $html ) ) {
			return (string) $html;
		}

		// Preserve Gutenberg block comments
		$blocks = array();
		$html = preg_replace_callback( '/<!--\s*\/?wp:.*?-->/s', function( $m ) use ( &$blocks ) {
			$id = '[[VMSB_BLOCK_' . count( $blocks ) . ']]';
			$blocks[ $id ] = $m[0];
			return $id;
		}, $html );

		$html = wp_kses_post( $html );

		if ( ! empty( $blocks ) ) {
			$html = str_replace( array_keys( $blocks ), array_values( $blocks ), $html );
		}

		return $html;
	}

	/**
	 * @param string $prompt   User prompt.
	 * @param array  $args     system, json, temperature, max_tokens, cache_ttl, kb (bool), provider, complexity (cheap|standard|premium), persona (strategist|wordsmith|auditor|thief), attempt (int), action (string)
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
				'action'      => '',
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

		// Token Efficiency: Normalize spaces but preserve structure-defining newlines.
		$prompt = trim( preg_replace( '/[ \t]+/', ' ', $prompt ) );
		$prompt = preg_replace( '/\n{3,}/', "\n\n", $prompt );

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

		// World-Class Optimization: Mistakes & Lessons Memory
		$brain = new VMSB_Brain();
		if ( $args['persona'] === 'wordsmith' ) {
			$mistakes = $brain->recall( 'ai_training', 'recent_mistakes' );
			if ( $mistakes ) {
				$args['system'] .= "\n\nCRITICAL: In previous attempts, you made these mistakes. DO NOT REPEAT THEM:\n- " . implode("\n- ", (array)$mistakes);
			}
		}

		// Apply lessons from the Healer (Post-Mortem Analysis)
		if ( ! empty($args['action']) ) {
			$lesson = $brain->recall( 'healer', "lesson_{$args['action']}" );
			if ( $lesson ) {
				$args['system'] .= "\n\nCRITICAL STRATEGIC LESSON: {$lesson}";
			}
		}

		// Apply Strategic Pivot Directives
		$pivot = $brain->recall( 'intelligence', 'current_strategy_pivot' );
		if ( $pivot && ! empty($pivot['new_directives']) ) {
			$args['system'] .= "\n\nCURRENT STRATEGIC PIVOT: {$pivot['pivot_name']}\nDIRECTIVES: " . implode(' | ', (array)$pivot['new_directives']);
		}

		// Sentient Heartbeat: Notify logs that the Brain is reasoning
		$this->log->info( 'brain', "AI Generation Started: Persona '{$args['persona']}' processing task.", array( 'tokens_est' => $args['max_tokens'] ) );

		// get_chain() already filters and dedupes with SORT_REGULAR, which
		// correctly compares its array-shaped [provider, model] entries
		// element-by-element. Re-deduping here with array_unique()'s default
		// SORT_STRING cast every one of those entries to a string, throwing
		// "Array to string conversion" and corrupting the REST response body
		// (the warning gets echoed before the JSON, so res.json() fails
		// client-side) on every premium/cheap-complexity call.
		$chain = $this->get_chain( $args );

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

			// World-Class Resilience: Individual Provider Retry with Backoff
			$provider_attempts = 0;
			$max_provider_attempts = 2;
			$result = array('ok' => false);

			while ( $provider_attempts < $max_provider_attempts ) {
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
					break; // Exit retry loop on success
				}

				$provider_attempts++;
				if ( $provider_attempts < $max_provider_attempts ) {
					// Only wait when nothing is holding the connection open.
					// With the default four-provider chain a bad afternoon
					// upstream cost eight seconds of pure sleep on top of four
					// HTTP timeouts, inside a REST call an admin was watching -
					// and behind the same proxy read timeout the task runner's
					// budget is carefully written to stay under. Unattended,
					// the wait is worth it; attended, the circuit breaker is
					// the right tool for a provider that is genuinely down.
					$unattended = ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || 'cli' === PHP_SAPI;
					$sleep_sec  = pow( 2, $provider_attempts ); // 2s, 4s backoff
					$this->log->warn( 'ai', "Provider {$provider} failed. Retrying" . ( $unattended ? " in {$sleep_sec}s" : ' immediately' ) . '... (' . ( $result['error'] ?? 'timeout' ) . ')' );
					if ( $unattended ) {
						sleep( $sleep_sec );
					}
				}
			}

			if ( ! empty( $result['ok'] ) ) {
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

				// World-Class Peer Review Loop (Ported from Autopilot ASP)
				if ( $args['persona'] === 'wordsmith' && $args['max_tokens'] > 2000 && $args['attempt'] < 2 ) {

					// 1. Auditor Pass: Critique against elite standards
					$audit_prompt = "Act as a Senior SEO Editor. Critique this draft against our Elite Standards:\n"
						. "1. INFORMATION GAIN: Does it offer unique insights or data that Top 3 winners miss?\n"
						. "2. EEAT: Is the expert tone credible and specific to the brand?\n"
						. "3. SGE READINESS: Are there clear, quotable blocks for AI search engines?\n"
						. "4. RANK MATH: Are keyword placements surgical and density natural?\n\n"
						. "DRAFT CONTENT:\n" . wp_trim_words($result['text'], 1000) . "\n\n"
						. "TASK: Provide a Grade (0-100) and if < 85, list 3 'Rewrite Directives'. Otherwise reply 'PASS'.";

					$quality_check = $this->generate( $audit_prompt, array('max_tokens' => 500, 'persona' => 'auditor', 'complexity' => 'premium') );

					$verdict = ! empty( $quality_check['ok'] ) ? trim( $quality_check['text'] ) : '';

					if ( ! empty($verdict) && 0 !== stripos( $verdict, 'PASS' ) && $args['attempt'] < 2 ) {
						( new VMSB_Logger() )->warn( 'ai', 'Peer Review Loop: Auditor identified gaps. Initiating recursive refinement pass.' );

						$args['attempt']++;
						$args['system'] = $original_system; // Reset system prompt

						$refinement_prompt = $prompt . "\n\n"
							. "CRITICAL PEER REVIEW FEEDBACK:\n"
							. "{$verdict}\n\n"
							. "STRICT INSTRUCTION: Refine the content to fix these gaps. Ensure 100% brand alignment and high information gain.";

						return $this->generate( $refinement_prompt, $args );
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
		$args['system'] = trim( ( isset( $args['system'] ) ? $args['system'] : '' ) . "\nReturn only raw JSON. No markdown fences, no commentary. Use only the specified keys." );

		$res = $this->generate( $prompt, $args );
		if ( empty( $res['ok'] ) ) {
			return null;
		}
		$parsed = self::parse_json( $res['text'] );

		// Everything parse_json() can repair on its own - including the
		// Gutenberg-attribute-escaping failure this was rewritten for - is
		// already fixed by the time we get here, so this retry no longer
		// fires for that whole class of failure. What is left is genuinely
		// unsalvageable text, and it comes in a few different shapes that
		// each need a different instruction back to the model: a reply cut
		// off mid-object needs more room, not a scolding about commentary; a
		// reply that asked a clarifying question needs telling to just use
		// the context it already has; and it is misleading to tell the model
		// it added commentary when it did not. One forceful, targeted retry -
		// not a loop, capped by _json_retried.
		if ( null === $parsed && empty( $args['_json_retried'] ) ) {
			$retry_args                  = $args;
			$retry_args['_json_retried'] = true;

			$cause = self::diagnose_json_failure( $res['text'] );
			switch ( $cause ) {
				case 'truncated':
					// Retrying with the same budget just reproduces the same
					// cutoff, so give this attempt more room - capped so one
					// bad prompt can't silently double every call's cost.
					if ( ! empty( $retry_args['max_tokens'] ) ) {
						$retry_args['max_tokens'] = min( (int) $retry_args['max_tokens'] * 2, 16000 );
					}
					$hint = "Your previous reply was cut off before it finished - an object or array you opened was never closed. "
						. "Either write more concisely so the complete object fits, or, if the content genuinely needs the space, prioritise finishing every field over adding more detail to any single one. "
						. "Output ONLY the complete, raw JSON object now.";
					break;

				case 'no_json_attempted':
				case 'commentary':
					$hint = "Your previous reply was not valid JSON - it contained a question, a refusal, or commentary instead of pure data. "
						. "You already have every piece of context you need to complete this task; do not ask for more. Do not explain, do not add anything before or after the object. "
						. "Output ONLY the raw JSON object now.";
					break;

				default: // 'malformed' - structurally complete but still would not parse.
					$hint = "Your previous reply was not valid JSON. The most common cause is an unescaped double-quote inside a string value - "
						. "check every quote, especially inside any embedded markup or code, is backslash-escaped for the OUTER JSON. "
						. "Output ONLY the raw JSON object now.";
			}

			$retry_args['system'] = trim( $args['system'] . "\n\n{$hint}" );

			$retry_res = $this->generate( $prompt, $retry_args );
			if ( ! empty( $retry_res['ok'] ) ) {
				$res    = $retry_res;
				$parsed = self::parse_json( $res['text'] );
			}
		}

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

	/**
	 * Work out WHY a reply failed to parse as JSON, so the one retry
	 * generate_json() spends can give the model an instruction that
	 * actually matches what went wrong, instead of a fixed guess.
	 *
	 * Three checks, cheap and in order of how conclusive they are:
	 *
	 * 1. No { or [ anywhere - the model answered in prose, not JSON.
	 * 2. Brace/bracket count imbalance - a genuinely complete reply always
	 *    balances, even when it has an unrelated quoting defect somewhere
	 *    in the middle (a Gutenberg attribute pair like {"level":2} adds
	 *    one open and one close, so it never causes an imbalance on its
	 *    own). An imbalance is close to conclusive evidence the model was
	 *    still writing when it ran out of tokens.
	 * 3. A question mark or a refusal/clarifying phrase before the JSON
	 *    starts (or in place of it) - a request for more information
	 *    instead of using the context already given.
	 *
	 * Anything that clears all three is 'malformed': structurally complete,
	 * not a refusal, but still broken - most often a stray unescaped quote
	 * parse_json()'s targeted Gutenberg fix did not cover.
	 *
	 * @param string $raw_text The model's raw reply, before any salvage step.
	 * @return string One of: no_json_attempted, truncated, commentary, malformed.
	 */
	private static function diagnose_json_failure( $raw_text ) {
		$text = trim( (string) $raw_text );

		if ( ! preg_match( '/[\{\[]/', $text ) ) {
			return 'no_json_attempted';
		}

		$opens  = substr_count( $text, '{' ) + substr_count( $text, '[' );
		$closes = substr_count( $text, '}' ) + substr_count( $text, ']' );
		if ( $opens > $closes ) {
			return 'truncated';
		}

		$before_json = trim( preg_replace( '/[\{\[].*$/s', '', $text ) );
		if ( '' !== $before_json && ( false !== strpos( $before_json, '?' )
			|| preg_match( '/\b(please provide|please upload|could you|i need|i cannot|i don\'t have|as an ai)\b/i', $before_json ) ) ) {
			return 'commentary';
		}

		return 'malformed';
	}

	public static function parse_json( $text ) {
		$text = trim( (string) $text );

		// Salvage Step 0: Aggressive Markdown & Commentary Removal
		// Some models (especially on local hosts) wrap JSON in markdown blocks or add "Here is the JSON:" preamble.
		$text = preg_replace( '/^.*?({|\[)/s', '$1', $text ); // Strip everything before first { or [
		$text = preg_replace( '/(}|\])[^}\]]*$/s', '$1', $text ); // Strip everything after last } or ]
		$text = trim( $text );

		// Salvage Step 0.5: Gutenberg block attributes embedded unescaped.
		//
		// Every content-writing prompt in this plugin asks the model to put
		// real block markup - <!-- wp:heading {"level":2} -->,
		// <!-- wp:image {"id":42,"sizeSlug":"large"} --> - inside a JSON
		// string value. That is JSON nested inside JSON: every quote in the
		// block's own {...} attributes has to come back out as \" for the
		// OUTER json_decode() to succeed. A model reliably forgets this at
		// least once in any article with more than a couple of headings or
		// images, and it only takes one miss anywhere in a few thousand
		// words to break the entire payload - invisibly, because the parts
		// before and after the miss still look perfectly formed.
		//
		// Run this before the first decode attempt, unconditionally: it is a
		// no-op on text with no such block comments, and on already-correctly-
		// escaped ones (the negative lookbehind below skips a \" that is
		// already escaped, so it never double-escapes a model that got it
		// right). Fixing this here means the common case reaches
		// json_decode() clean and never touches the blunter salvage regexes
		// below at all - those are tuned for different failure shapes and
		// have no business running against content that only needed this.
		$text = self::escape_gutenberg_attrs( $text );

		// Salvage Step 0.6: Any other unescaped HTML attribute quote.
		//
		// Step 0.5 only covers a block comment's own {"...":...} attribute
		// JSON. It does nothing for a plain HTML attribute like
		// <a href="...">, which several prompts explicitly ask the model to
		// insert (internal links, image alt text) - a different HTML
		// construct producing the exact same failure class: an unescaped "
		// inside a JSON string value. This targets that shape specifically -
		// name="value" - which essentially never occurs in ordinary prose,
		// so unlike a generic "repair any stray quote" pass it cannot
		// misfire on real dialogue or quoted terms in the article text (see
		// tests/test-parse-json.php's "quote in plain prose" case, and the
		// docblock on escape_html_attrs() below).
		$text = self::escape_html_attrs( $text );

		// Initial Attempt: Pure Standard Parse
		$data = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $data;
		}

		// --- Start Cumulative Salvage Flow ---
		// If standard parsing failed, apply multiple fixes sequentially.

		// Salvage 1: Fix "Smart Quotes" (common when AI tries to be 'fancy')
		$text = str_replace( array( "\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}" ), array( '"', '"', "'", "'" ), $text );

		// Salvage 2: Handle raw newlines inside JSON strings
		// json_decode() fails if there are literal newlines within a double-quoted string.
		$text = preg_replace_callback( '/"((?:[^"\\\\]|\\\\.)*)"/s', function( $matches ) {
			return '"' . str_replace( array( "\n", "\r" ), array( "\\n", "\\r" ), $matches[1] ) . '"';
		}, $text );

		// Salvage 3: Handle early closure hallucinations (e.g. "Value" } ], "next_key":)
		// This happens when the model thinks it's closing an array/object mid-flow.
		// Pattern: closing quote, then junk (spaces, braces, brackets), then optional comma, then opening quote.
		$text = preg_replace( '/"(\s*)[\}\s\]]+,?\s*"/s', '"$1, "', $text );

		// Salvage 4: Fix trailing commas (common LLM mistake)
		$text = preg_replace( '/,\s*([\]\}])/', '$1', $text );

		// Final Attempt: Parse the salvaged text
		$data = json_decode( $text, true );
		if ( JSON_ERROR_NONE === json_last_error() ) {
			return $data;
		}

		return null;
	}

	/**
	 * Re-escape the double quotes inside a Gutenberg block comment's own
	 * {...} attribute JSON, without touching quotes anywhere else in the
	 * text.
	 *
	 * Deliberately narrow: this only matches inside
	 * <!-- wp:name {...} --> / <!-- /wp:name --> comments, specifically
	 * because that is the one place this codebase's own prompts ask the
	 * model to embed a second layer of JSON. A generic "fix any unescaped
	 * quote anywhere" pass would be far more likely to mangle a genuine
	 * quoted word in the article's prose than to help - see
	 * tests/test-parse-json.php's "quote in plain prose" case, which must
	 * keep failing rather than being silently (and probably wrongly)
	 * repaired.
	 *
	 * The attribute capture is non-greedy up to the next " -->", not a
	 * brace-balanced match - regex cannot balance arbitrarily nested braces
	 * in general, and does not need to here: a Gutenberg block comment's
	 * attribute JSON is always immediately followed by " -->" with nothing
	 * else after it, including when the attributes are themselves nested
	 * (style.spacing.margin.top and similar). Only a value inside the
	 * attributes that happened to contain the literal substring " -->"
	 * could fool this, which is not a realistic block attribute.
	 *
	 * The space before the opening brace is optional (\s*, not \s+):
	 * WordPress's own serializer (get_comment_delimited_block_content() in
	 * wp-includes/blocks.php) always includes it, but this is repairing a
	 * model's imitation of that format, not the real serializer, so a
	 * model that drops the space still gets fixed rather than skipped.
	 *
	 * @param string $text Raw model output, still containing literal
	 *                     newlines/whitespace exactly as received - this
	 *                     runs before any other normalisation.
	 * @return string
	 */
	private static function escape_gutenberg_attrs( $text ) {
		return preg_replace_callback(
			'/<!--\s*(\/?wp:[a-zA-Z0-9\/_-]+)\s*(\{.*?\})\s*-->/',
			static function ( $m ) {
				// Escape every quote not already escaped, so a block the
				// model DID get right passes through unchanged instead of
				// being double-escaped into "\\\"level\\\"".
				$fixed_attrs = preg_replace( '/(?<!\\\\)"/', '\\\\"', $m[2] );
				return '<!-- ' . $m[1] . ' ' . $fixed_attrs . ' -->';
			},
			$text
		);
	}

	/**
	 * Re-escape the quotes that delimit an HTML attribute value -
	 * name="value" - anywhere they appear unescaped inside the text, without
	 * touching quotes anywhere else.
	 *
	 * This is the sibling of escape_gutenberg_attrs() above, for a different
	 * source of the same failure class: several prompts explicitly instruct
	 * the model to insert real HTML attributes into content_html - internal
	 * links (<a href="...">), image alt text, occasionally title/target/
	 * class. Each one is an unescaped-quote landmine for the outer JSON the
	 * exact same way a Gutenberg block's {"level":2} is, but the earlier
	 * fix's regex is scoped to <!-- wp:name {...} --> and does not match
	 * this shape at all.
	 *
	 * A general "repair any unescaped quote anywhere" tokenizer was
	 * prototyped and rejected: it cannot reliably tell a real JSON string
	 * boundary apart from ordinary prose punctuation that happens to look
	 * like one (quoted dialogue or terms separated by commas - plausible in
	 * this site's Sanskrit/Hindi pilgrimage content), and a wrong guess
	 * there can decode to syntactically valid but silently truncated JSON,
	 * which is worse than a clean failure. name="value" carries no such
	 * ambiguity - that exact shape does not occur in natural prose - so this
	 * stays a narrow, pattern-based fix like its sibling, not a guess.
	 *
	 * @param string $text
	 * @return string
	 */
	private static function escape_html_attrs( $text ) {
		return preg_replace_callback(
			'/([a-zA-Z_:][a-zA-Z0-9_:.-]*)="([^"]*)"/',
			static function ( $m ) {
				// The unescaped quotes are the attribute's own delimiters -
				// escape those, plus any stray quote already inside the
				// value (the negative lookbehind keeps this idempotent on a
				// value the model already escaped correctly).
				$fixed_val = preg_replace( '/(?<!\\\\)"/', '\\\\"', $m[2] );
				return $m[1] . '=\\"' . $fixed_val . '\\"';
			},
			$text
		);
	}

	/* ---------------------------------------------------------------- providers */

	private function call_aipuffer( $prompt, $args ) {
		$base   = untrailingslashit( (string) VMSB_Settings::get( 'aipuffer_url' ) );
		$key    = VMSB_Settings::get( 'aipuffer_key' );
		$bot_id = VMSB_Settings::get( 'aipuffer_bot_id' );

		// Intelligence: If URL is empty or using the placeholder, assume Local Mode (AI Power/Engine installed here)
		$is_local = empty($base) || strpos( $base, 'your-aipuffer-host' ) !== false;
		if ( ! $is_local ) {
			$base_normalized = preg_replace( '/^https?:\/\//', '', untrailingslashit( strtolower( $base ) ) );
			$home_normalized = preg_replace( '/^https?:\/\//', '', untrailingslashit( strtolower( home_url() ) ) );
			if ( $base_normalized === $home_normalized ) {
				$is_local = true;
			}
		}

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
		$namespaces = array( 'aipkit/v1', 'mwai/v1', 'wpaicg/v1', 'aipuffer/v1' );
		$last_error = 'Unknown error';

		if ( $is_local ) {
			// 100% Compatibility: Try Direct Class Bridge first to bypass REST/HTTP issues.
			$direct = $this->call_aipkit_direct( $prompt, $args, $bot_id );
			if ( $direct && ! empty($direct['ok']) ) {
				return $direct;
			}

			// Early diagnostic: If in local mode, we must have a compatible bridge plugin.
			$has_power  = class_exists( '\WPAICG\Chat\Storage\BotStorage' ) || post_type_exists( 'wpaicg_chatbots' );
			$has_engine = get_option( 'mwai_options' ) || class_exists( 'Meow_MWAI_Core' );

			if ( ! $has_power && ! $has_engine ) {
				return $this->fail( 'AI Puffer is in Local Mode, but no compatible bridge (AI Power or AI Engine) was detected on this site. Please install one of these to use the local Puffer bridge.' );
			}
		}

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
			} elseif ( $ns === 'aipuffer/v1' ) {
				$rel_suffix = '/chat/message';
				$url = rtrim( $ns_url, '/' ) . $rel_suffix;
				$request_body = array_merge( $body, array(
					'message' => $prompt,
					'context' => $context,
					'aipkit_api_key' => $key
				) );
			} elseif ( $ns === 'aipkit/v1' || $ns === 'wpaicg/v1' ) {
				if ( $bot_id ) {
					// Hardening: AI Power often expects /chat/message or /chat/{id}/message
					$rel_suffix = "/chat/{$bot_id}/message";
					$url = rtrim( $ns_url, '/' ) . $rel_suffix;

					// If the endpoint doesn't support 'system' roles, we must
					// prepend the context to the user prompt to maintain 'sentience'.
					$full_prompt = $prompt;
					if ( ! empty($args['system']) ) {
						$full_prompt = "CONTEXT:\n" . $args['system'] . "\n\nTASK:\n" . $prompt;
					}

					$request_body = array_merge( $body, array(
						'messages' => array( array( 'role' => 'user', 'content' => $full_prompt ) ),
						'context' => $context,
						'aipkit_api_key' => $key,
						'message' => $full_prompt
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

				$data = method_exists($response, 'get_data') ? $response->get_data() : array();
				$last_error = is_wp_error( $response ) ? $response->get_error_message() : ( isset($data['message']) ? $data['message'] : wp_json_encode($data) );
				$this->log->warn( 'aipuffer', "Local REST bridge failed on {$ns}: " . $last_error );

				// Diagnostic: If internal route is missing, skip the external post to same site.
				if ( isset($data['code']) && $data['code'] === 'rest_no_route' ) {
					continue;
				}
			}

			$res = $this->post( $url, $request_body, array( 'Authorization' => 'Bearer ' . $key, 'X-API-KEY' => $key ), 120 );

			if ( ! empty( $res['ok'] ) ) {
				return $this->extract_aipuffer_reply( $res['data'], $args['forced_model'] ?? 'aipuffer' );
			}
			$last_error = $res['error'] . ' (' . $url . ')';
		}

		if ( strpos( $last_error, 'No route was found' ) !== false ) {
			return $this->fail( 'AI Puffer Connection Failed: No compatible REST route was found. Ensure AI Power or AI Engine is installed and its REST API is enabled.' );
		}

		return $this->fail( $last_error );
	}

	/**
	 * Direct Zero-Distance Bridge for AI Power / AIPKit.
	 * Bypasses HTTP/REST for 100% compatibility when on the same server.
	 */
	private function call_aipkit_direct( $prompt, $args, $bot_id ) {
		if ( ! class_exists('\WPAICG\Core\AIPKit_AI_Caller') ) return null;

		try {
			// Scenario A: Chatbot-specific call
			if ( $bot_id && class_exists('\WPAICG\Chat\Core\AIService') && class_exists('\WPAICG\Chat\Storage\BotStorage') ) {
				$bot_storage = new \WPAICG\Chat\Storage\BotStorage();
				$bot_settings = $bot_storage->get_chatbot_settings($bot_id);
				if ( $bot_settings ) {
					$service = new \WPAICG\Chat\Core\AIService();
					$full_prompt = $prompt;
					if ( ! empty($args['system']) ) {
						$full_prompt = "CONTEXT:\n" . $args['system'] . "\n\nTASK:\n" . $prompt;
					}

					$ai_result = $service->generate_response($full_prompt, $bot_settings, []);
					if ( ! is_wp_error($ai_result) ) {
						$this->log->info( 'aipuffer', "AI Power Direct Bridge (Chat) success.", array('bot' => $bot_id) );
						return $this->ok($ai_result['content'] ?? '', $bot_settings['model'] ?? 'unknown', $ai_result['usage'] ?? []);
					}
					return $this->fail( $ai_result->get_error_message() );
				}
			}

			// Scenario B: Generic generation (no bot)
			if ( ! $bot_id ) {
				$caller = new \WPAICG\Core\AIPKit_AI_Caller();
				$provider_label = \WPAICG\AIPKit_Providers::normalize_provider_label($args['provider'] ?: 'openai');
				$model = $args['forced_model'] ?: 'gpt-4o-mini';

				$ai_result = $caller->make_standard_call(
					$provider_label,
					$model,
					$this->messages($prompt, $args),
					array(
						'temperature' => (float)$args['temperature'],
						'max_completion_tokens' => (int)$args['max_tokens']
					)
				);

				if ( ! is_wp_error($ai_result) ) {
					$this->log->info( 'aipuffer', "AI Power Direct Bridge (Text) success.", array('model' => $model) );
					return $this->ok($ai_result['content'] ?? '', $model, $ai_result['usage'] ?? []);
				}
				return $this->fail( $ai_result->get_error_message() );
			}
		} catch ( \Throwable $e ) {
			$this->log->error( 'aipuffer', 'AI Power direct bridge exception: ' . $e->getMessage() );
		}

		return null;
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
			'timeout'    => $timeout,
			'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . '; ' . home_url(),
			'headers'    => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'       => wp_json_encode( $body ),
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
			// 'creative' was requested in four places - CTA copy, CTR title
			// variants, video scripts - and 'writer' in one, but neither was
			// ever defined here. Both silently fell through to the strategist
			// below, so short persuasive copy was being written in the voice
			// of a strategy director, and at the strategist's low temperature.
			'creative'   => 'Act as a Senior Direct-Response Copywriter. You write short, vivid, specific copy that earns a click or an action. No corporate filler, no hedging.',
			'writer'     => 'Act as an Elite SEO Content Writer. Your goal is to write deep-authority articles that users love and Google rewards.',
		);
		return isset( $personas[ $persona ] ) ? $personas[ $persona ] : $personas['strategist'];
	}

	private function get_persona_temperature( $persona, $default ) {
		$map = array(
			'strategist' => 0.4,
			'wordsmith'  => 0.8, // More creative for writing
			'auditor'    => 0.1, // High precision for audits
			'thief'      => 0.6,
			'creative'   => 0.9, // Headlines, CTAs and scripts need range
			'writer'     => 0.8,
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
