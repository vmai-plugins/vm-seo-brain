<?php
defined( 'ABSPATH' ) || exit;

/**
 * Health monitor.
 *
 * The brain depends on a lot of moving parts - Google OAuth, an AI provider,
 * WP cron actually firing, the AI daily call budget. When one of these is
 * silently broken, everything downstream fails quietly (an empty keyword
 * list looks the same whether there are no good keywords or GSC is
 * disconnected). This gives one place that says which is which.
 */
class VMSB_Health {

	/**
	 * @return array<string,array{ok:bool,label:string,detail:string}>
	 */
	public function check() {
		$checks = array();

		// Circuit Breaker check.
		$failures = (int) get_option( 'vmsb_consecutive_failures', 0 );
		$is_paused = $failures >= 5;
		if ( $is_paused ) {
			$checks['circuit_breaker'] = array(
				'label'  => 'Circuit Breaker',
				'ok'     => false,
				'detail' => "Automation paused after {$failures} consecutive API failures. Check your keys and click 'Reset' to resume.",
			);
		}

		// Google connection.
		$google = new VMSB_Google();
		$checks['google'] = array(
			'label'  => 'Google (Search Console / GA4 / Sheets)',
			'ok'     => $google->is_connected(),
			'detail' => $google->is_connected() ? 'Connected.' : 'Not connected - keyword research, GSC-based fixes, and outcome measurement are all degraded.',
		);

		// AI provider.
		$ai  = new VMSB_AI_Router();
		$res = $ai->generate( 'Reply with exactly: OK', array( 'max_tokens' => 5 ) );
		$checks['ai'] = array(
			'label'  => 'AI provider',
			'ok'     => ! empty( $res['ok'] ),
			'detail' => ! empty( $res['ok'] ) ? 'Responding.' : ( $res['error'] ?? 'No response.' ),
		);

		// Daily AI budget - a maxed-out budget looks like "nothing happened
		// today" unless this is surfaced explicitly.
		$calls_today = $ai->calls_today();
		$cap         = (int) VMSB_Settings::get( 'max_ai_calls_day', 200 );
		$checks['ai_budget'] = array(
			'label'  => 'AI call budget',
			'ok'     => $calls_today < $cap,
			'detail' => "{$calls_today} / {$cap} calls used today.",
		);

		// Cron actually firing - if the last daily/weekly run is stale, God
		// Mode looks idle even though it's "enabled".
		$last_daily = (int) get_option( 'vmsb_last_daily_run', 0 );
		$stale      = $last_daily && ( time() - $last_daily ) > 2 * DAY_IN_SECONDS;
		$checks['cron'] = array(
			'label'  => 'Autonomous cycle',
			'ok'     => ! $stale && $last_daily > 0,
			'detail' => $last_daily
				? sprintf( 'Last ran %s.', human_time_diff( $last_daily ) . ' ago' )
				: 'Has not run yet - WP-Cron may need a visit trigger or a real cron trigger.',
		);

		// Vector index freshness.
		if ( class_exists( 'VMSB_Vector_Store' ) ) {
			$stats = VMSB_Vector_Store::stats();
			$checks['vectors'] = array(
				'label'  => 'Semantic index',
				'ok'     => $stats['pending'] < 20,
				'detail' => "{$stats['pending']} pages waiting to index.",
			);
		}

		// Rank Math presence - most fixes assume it.
		$checks['rankmath'] = array(
			'label'  => 'Rank Math',
			'ok'     => class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ),
			'detail' => ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) ? 'Active.' : 'Not detected - meta/schema fixes fall back to plain post meta.',
		);

		// Environment Health
		$decrypt_failed = get_option( 'vmsb_decryption_failed' );
		$checks['openssl'] = array(
			'label'  => 'Encryption Support (OpenSSL)',
			'ok'     => function_exists('openssl_encrypt') && ! $decrypt_failed,
			'detail' => ! function_exists('openssl_encrypt')
				? 'Missing - API keys cannot be safely decrypted. Please enable OpenSSL on your server.'
				: ( $decrypt_failed ? 'Decryption failed - AUTH_KEY mismatch. Re-save your API keys.' : 'Available.' ),
		);

		// Indexing API health
		$checks['indexing'] = array(
			'label'  => 'Google Indexing API',
			'ok'     => $google->is_connected() && strpos( VMSB_Google::SCOPES, 'indexing' ) !== false,
			'detail' => ( $google->is_connected() && strpos( VMSB_Google::SCOPES, 'indexing' ) !== false )
				? 'Ready to ping Google on publish.'
				: 'Indexing scope missing. Please reconnect Google in Settings.',
		);

		// Knowledge Graph health
		if ( class_exists( 'VMSB_Graph' ) ) {
			global $wpdb;
			$graph_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_graph" );

			// 2026 Auto-Heal: Build twin if empty and site has content
			if ( $graph_count === 0 && (int) wp_count_posts('post')->publish > 0 ) {
				VMSB_Graph::build_twin( 50 ); // Small batch auto-heal
				$graph_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_graph" );
			}

			$checks['graph'] = array(
				'label'  => 'Knowledge Graph (Digital Twin)',
				'ok'     => $graph_count > 0,
				'detail' => $graph_count > 0
					? "{$graph_count} semantic relationships mapped."
					: 'Graph is empty. Run /scan in Command to build your Digital Twin.',
			);
		}

		// GA4 Intelligence Health
		$ga4 = new VMSB_GA4();
		if ( $ga4->is_connected() ) {
			$ga4_audit = $ga4->audit_integrity();
			$checks['ga4'] = array(
				'label'  => 'Google Analytics 4',
				'ok'     => $ga4_audit['ok'],
				'detail' => $ga4_audit['ok'] ? "Healthy. {$ga4_audit['events_detected']} events tracked." : "Tracking Issues: " . ($ga4_audit['message'] ?? 'Critical events missing.'),
			);
		}

		// AI Link Genius Pro Integration
		if ( class_exists( 'AILG_Core' ) ) {
			global $wpdb;
			$broken_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_broken_links WHERE status = 'broken'" );
			$pending_links = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}ailg_suggestions WHERE status = 'pending'" );
			$checks['link_genius'] = array(
				'label'  => 'AI Link Genius Pro',
				'ok'     => $broken_links === 0,
				'detail' => "Active. {$pending_links} pending suggestions. " . ( $broken_links > 0 ? "{$broken_links} broken links detected!" : "Internal linking is healthy." ),
			);
		}

		update_option( 'vmsb_health_last_check', array( 'checks' => $checks, 'at' => current_time( 'mysql' ) ), false );
		return $checks;
	}

	public function overall_ok() {
		$checks = get_option( 'vmsb_health_last_check' );
		if ( ! $checks || empty( $checks['checks'] ) ) {
			return null;
		}
		foreach ( $checks['checks'] as $c ) {
			if ( empty( $c['ok'] ) ) {
				return false;
			}
		}
		return true;
	}

	public function latest() {
		return get_option( 'vmsb_health_last_check', null );
	}

	/**
	 * Run a multi-provider AI connectivity audit.
	 */
	public function verify_ai_chain() {
		$ai = new VMSB_AI_Router();
		$providers = array( 'aipuffer', 'gemini', 'openrouter', 'openai', 'ollama' );
		$report = array();

		foreach ( $providers as $p ) {
			$res = $ai->generate( 'Reply with exactly: OK', array( 'provider' => $p, 'max_tokens' => 10 ) );
			$report[ $p ] = array(
				'ok'      => ! empty( $res['ok'] ),
				'message' => ! empty( $res['ok'] ) ? 'Connected' : ( $res['error'] ?? 'No response' ),
				'details' => $res['text'] ?? ''
			);
		}

		return $report;
	}

	public static function record_failure() {
		$f = (int) get_option( 'vmsb_consecutive_failures', 0 ) + 1;
		update_option( 'vmsb_consecutive_failures', $f );
		if ( $f >= 5 ) {
			VMSB_Settings::update( array( 'god_mode' => 0 ) );
			( new VMSB_Logger() )->error( 'health', 'Circuit Breaker triggered: God Mode disabled due to 5 consecutive failures.' );
		}
	}

	public static function reset_failures() {
		update_option( 'vmsb_consecutive_failures', 0 );
		if ( class_exists('VMSB_AI_Circuit') ) {
			VMSB_AI_Circuit::reset_all();
		}
	}
}
