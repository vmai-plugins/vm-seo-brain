<?php
defined( 'ABSPATH' ) || exit;

/**
 * Single option blob. Credentials are encrypted at rest with AUTH_KEY-derived material.
 */
class VMSB_Settings {

	const OPTION = 'vmsb_settings';

	private static $cache = null;

	/**
	 * Every credential this plugin stores. One list, deliberately public, so
	 * the save handler, the encryption in update(), the decryption in all()
	 * and the redaction in masked() cannot drift apart.
	 *
	 * They had drifted: the admin form treated ten fields as secrets and this
	 * list named nine different ones, so huggingface_key and
	 * cloudflare_api_token were written to the options table in cleartext AND
	 * skipped by masked() - which meant the settings screen rendered the live
	 * key into the value attribute of the input. type="password" hides that
	 * from the screen, not from view-source.
	 */
	public static $secret_keys = array(
		'aipuffer_key',
		'aiengine_key',
		'openai_key',
		'gemini_key',
		'openrouter_key',
		'pexels_key',
		'comfy_key',
		'google_client_secret',
		'google_refresh_token',
		'banana_key',
		'huggingface_key',
		'cloudflare_api_token',
		'semrush_key',
		'ahrefs_token',
		'keyword_planner_dev_token',
	);

	/** The bullet run masked() substitutes for a stored credential. */
	const MASK = "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}";

	public static function defaults() {
		return array(
			// Business identity — the brain's anchor.
			'business_name'        => get_bloginfo( 'name' ),
			'business_type'        => '',
			'business_description' => get_bloginfo( 'description' ),
			'primary_locations'    => '',
			'services'             => '',
			'audience'             => '',
			'tone'                 => 'expert, plain-spoken, no fluff',
			'language'             => 'en',
			'country'              => 'IN',
			'currency'             => 'INR',
			'competitors'          => '',
			'profile_locked'       => 0,

			// AI chain.
			'ai_primary'      => 'aipuffer',
			'ai_fallbacks'    => array( 'gemini', 'openrouter', 'ollama' ),
			'aipuffer_url'    => '',
			'aipuffer_key'    => '',
			'aipuffer_bot_id' => '',
			'aipuffer_kb_id'  => '',
			'openai_key'      => '',
			'openai_model'    => 'gpt-4o-mini',
			'gemini_key'      => '',
			'gemini_model'    => 'gemini-2.0-flash',
			'openrouter_key'  => '',
			'openrouter_model'=> 'anthropic/claude-3.5-sonnet',
			'ollama_url'      => 'http://127.0.0.1:11434',
			'ollama_model'    => 'llama3.1',

			// Image chain.
			'image_chain'     => array( 'aipuffer', 'google', 'banana', 'pollinations', 'huggingface', 'cloudflare', 'pexels' ),
			'pollinations_url'=> 'https://image.pollinations.ai/prompt/',
			'pollinations_model' => 'flux',
			'huggingface_key' => '',
			'huggingface_model' => 'black-forest-labs/FLUX.1-dev',
			'cloudflare_account_id' => '',
			'cloudflare_api_token'  => '',
			'cloudflare_model'      => '@cf/bytedance/stable-diffusion-xl-lightning',
			'banana_key'      => '',
			'banana_model'    => 'flux-1-schnell',
			'comfy_url'       => '',
			'comfy_key'       => '',
			'comfy_workflow'  => '',
			'pexels_key'      => '',
			'aipuffer_image_provider' => 'openai', // openai|google|azure|replicate
			'aipuffer_image_model'    => 'dall-e-3',
			'google_imagen_model'     => 'imagen-3',
			'image_width'     => 1200,
			'image_height'    => 675,
			'image_style'     => 'premium professional business photography, editorial commercial style, corporate aesthetic, high-end studio lighting, sharp focus, clean and professional, no text',

			// Google.
			'google_client_id'     => '',
			'google_client_secret' => '',
			'google_refresh_token' => '',
			'gsc_property'         => '',
			'ga4_property_id'      => '',
			'sheet_id'             => '',
			'sheet_tab'            => 'Pipeline',
			// Deliberately a separate tab in the same spreadsheet from
			// 'sheet_tab' above - lets a different automation (e.g. AI
			// Puffer's own Sheets feature) drop raw topic ideas here without
			// ever touching the columns the main Content Plan sync depends on.
			'bulk_topics_tab'      => 'Bulk Topics',

			// Autonomy.
			'god_mode'          => 0,
			'god_mode_scope'    => array( 'meta', 'alt', 'schema', 'internal_links', 'taxonomy' ),
			'max_god_fixes_day' => 50,
			'auto_publish'      => 0,
			'posts_per_day'     => 3,
			'max_ai_calls_day'  => 200,
			'require_review'    => 1,
			'growth_target'     => 50000,
			'growth_window'     => 50,
			'auto_growth_mode'  => 0,

			// Goal ladder (VMSB_Goal). growth_target/growth_window above seed
			// phase 1; every phase after that is sized from measured rate.
			// The controller only ever moves posts_per_day, and only between
			// these bounds - it never touches auto_publish or require_review.
			'goal_autopilot'    => 1,
			'goal_min_posts'    => 1,
			'goal_max_posts'    => 12,
			'staleness_threshold_days' => 365,

			// Vector memory. Thresholds at 0 auto-calibrate to the embedding model.
			'vector_enabled'         => 1,
			'embedding_provider'     => 'auto', // auto|openai|gemini|ollama|local
			'embedding_model'        => '',
			'vector_dup_threshold'   => 0,
			'vector_cannibal_threshold' => 0,
			'vector_related_threshold'  => 0,

			// Quality gate. Nothing generated reaches a URL without passing.
			'quality_gate'          => 1,
			'quality_min_score'     => 70,
			'quality_min_alignment' => 60,
			// Not third-party plagiarism detection (needs a paid API this
			// plugin has no credentials for) - this is a same-call heuristic
			// for generic, could-be-anyone AI filler. See VMSB_Quality_Gate.
			'quality_min_originality' => 50,
			'quality_min_words'     => 700,
			'quality_dup_block'     => 1,

			// Learning. Measure whether actions actually moved anything.
			'learning_enabled'      => 1,

			// Competitor intelligence.
			'competitor_enabled'    => 1,
			'thief_auto_plan'       => 0,

			// Backlinks. Off by default - outreach sends real email under your name.
			'backlink_enabled'      => 0,
			'backlink_daily_limit'  => 5,
			'outreach_from_name'    => '',
			'outreach_from_email'   => '',
			'outreach_tone'         => 'brief, human, no hype',

			// AEO / answer-engine optimisation.
			'aeo_enabled'           => 1,

			// Entity + semantic authority.
			'entity_enabled'        => 1,

			// Programmatic SEO. Off by default - this is the highest scaled-content risk in the plugin.
			'programmatic_enabled'      => 0,
			'programmatic_daily_cap'    => 5,

			// ROI + conversion.
			'roi_enabled'           => 1,
			'conversion_goal'       => '', // free text: what counts as a conversion
			'cta_style'             => 'direct, one clear action, no pressure tactics',

			// CTR testing.
			'ctr_test_enabled'      => 1,
			'ctr_test_days'         => 14,
			'ctr_test_min_impressions' => 200,

			// News / trend hijacking.
			'news_enabled'          => 0,

			// The Video Pipeline agent was gated on 'content_enabled', which
			// is not a setting and never has been - it appeared exactly once
			// in the codebase, in the toggle map itself. Settings::get()
			// returned null, (int) null is 0, so the agent was permanently
			// switched off: fully implemented (VMSB_Video_Agent::sweep) and
			// unreachable. Off by default like the other agents that spend AI
			// calls on an optional output, but reachable now.
			'video_enabled'         => 0,

			// Global expansion.
			'global_locations'      => '', // comma-separated cities/regions

			// Tier 3: external SEO data (all optional - the plugin degrades to
			// model-estimated numbers when a key is absent).
			'semrush_key'           => '',
			'ahrefs_token'          => '',
			'keyword_planner_customer_id' => '',
			'keyword_planner_dev_token'   => '',

			// Site Kit / Elementor integration awareness.
			'sitekit_prefer'        => 1, // prefer Site Kit's GA4/GSC connection when present
			'elementor_safe_mode'   => 1, // route edits through the safe bridge on Elementor pages
			'theme_mode'            => 'dark', // dark|lite
			'insecure_ssl'          => 0, // skip SSL verification (for local/dev)
			'safe_post_types'       => self::detect_safe_defaults(),

			// Outbound webhooks (Zapier/Make/custom scripts).
			'webhook_enabled' => 0,
			'webhook_url'     => '',
			'webhook_events'  => array( 'content_published' ),

			// Feature Toggles (Granular Control)
			'feature_aeo'        => 1,
			'feature_entity'     => 1,
			'feature_silo'       => 1,
			'feature_images'     => 1,
			'feature_taxonomy'   => 1,
			'feature_production' => 1,
			'feature_maintenance' => 1,
			'feature_schema'     => 1,
		);
	}

	private static function detect_safe_defaults() {
		$types = array( 'post' );
		if ( post_type_exists( 'destination' ) ) $types[] = 'destination';
		if ( post_type_exists( 'event' ) )       $types[] = 'event';
		if ( post_type_exists( 'product' ) )     $types[] = 'product';
		return $types;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved      = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
			foreach ( self::$secret_keys as $k ) {
				if ( ! empty( self::$cache[ $k ] ) ) {
					self::$cache[ $k ] = self::decrypt( self::$cache[ $k ] );
				}
			}
		}
		return self::$cache;
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		$val = array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;

		// License Enforcement
		if ( 'posts_per_day' === $key && class_exists('VMSB_License') ) {
			$limits = VMSB_License::limits();
			return min( (int)$val, (int)$limits['posts_per_day'] );
		}

		if ( 'vector_enabled' === $key && class_exists('VMSB_License') ) {
			return VMSB_License::at_least('elite') ? (int)$val : 0;
		}

		return $val;
	}

	public static function update( array $changes ) {
		$all = self::all();
		$new = array_merge( $all, $changes );
		$store = $new;
		foreach ( self::$secret_keys as $k ) {
			if ( ! empty( $store[ $k ] ) ) {
				$store[ $k ] = self::encrypt( $store[ $k ] );
			}
		}
		update_option( self::OPTION, $store, 'yes' );
		delete_option( 'vmsb_decryption_failed' );
		self::$cache = $new;
		return $new;
	}

	private static function key_material() {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'vmsb-fallback';
		return hash( 'sha256', $salt . '|vmsb', true );
	}

	public static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $plain );
		}
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plain, 'aes-256-cbc', self::key_material(), OPENSSL_RAW_DATA, $iv );
		return 'v1:' . base64_encode( $iv . $ct );
	}

	public static function decrypt( $stored ) {
		if ( 0 !== strpos( (string) $stored, 'v1:' ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, 3 ) );
		if ( ! $raw || strlen( $raw ) < 17 || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		$out = openssl_decrypt( $ct, 'aes-256-cbc', self::key_material(), OPENSSL_RAW_DATA, $iv );
		if ( false === $out ) {
			// Almost always means AUTH_KEY changed since this value was encrypted
			// (a wp-config secret rotation/migration) - every stored API key goes
			// silently blank otherwise, and every AI call starts failing with a
			// confusing "not configured" error instead of pointing at the cause.
			if ( ! get_option( 'vmsb_decryption_failed' ) ) {
				update_option( 'vmsb_decryption_failed', time(), false );
			}
			if ( class_exists( 'VMSB_Logger' ) ) {
				( new VMSB_Logger() )->warn( 'settings', 'Could not decrypt a stored secret - AUTH_KEY may have changed since it was saved. Re-enter API keys in Settings.' );
			}
			return '';
		}
		return $out;
	}

	/** Redacted copy for rendering forms. Never hand a raw secret to a view. */
	public static function masked() {
		$all = self::all();
		foreach ( self::$secret_keys as $k ) {
			if ( ! empty( $all[ $k ] ) ) {
				$all[ $k ] = self::MASK;
			}
		}
		return $all;
	}

	/**
	 * Is this submitted value the mask the form was rendered with, rather than
	 * a key the operator actually typed? Saving the mask would overwrite the
	 * real credential with a row of bullets.
	 */
	public static function is_masked( $value ) {
		return is_string( $value ) && false !== strpos( $value, "\u{2022}" );
	}

	/**
	 * Encrypt any credential that is still sitting in the options table in
	 * cleartext, left there by the mismatch documented on $secret_keys.
	 *
	 * Safe to run repeatedly: encrypt() stamps its output with a 'v1:' prefix
	 * and decrypt() returns anything without that prefix untouched, so an
	 * already-encrypted value is skipped rather than double-wrapped.
	 *
	 * @return int Number of credentials migrated.
	 */
	public static function encrypt_legacy_secrets() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! $stored ) {
			return 0;
		}

		$migrated = 0;
		foreach ( self::$secret_keys as $key ) {
			if ( empty( $stored[ $key ] ) || ! is_string( $stored[ $key ] ) ) {
				continue;
			}
			if ( 0 === strpos( $stored[ $key ], 'v1:' ) ) {
				continue; // Already encrypted.
			}
			$stored[ $key ] = self::encrypt( $stored[ $key ] );
			$migrated++;
		}

		if ( $migrated ) {
			update_option( self::OPTION, $stored, 'yes' );
			self::$cache = null;
			if ( class_exists( 'VMSB_Logger' ) ) {
				( new VMSB_Logger() )->info( 'settings', "Encrypted {$migrated} credential(s) that were stored in plain text." );
			}
		}

		return $migrated;
	}
}
