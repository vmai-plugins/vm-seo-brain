<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sentient Commander.
 * Ported from VMAI SEO: Provides conversational command handling for the chat console.
 */
class VMSB_Commander {

	public function execute( $input ) {
		$input   = trim( $input );
		$command = strtolower( explode( ' ', $input )[0] );
		$args    = array_filter( explode( ' ', substr( $input, strlen( $command ) ) ) );

		// Maintain Session History
		$history = get_transient( 'vmsb_chat_history_' . get_current_user_id() ) ?: array();

		$reply = '';
		switch ( $command ) {
			case '/scan':
				$reply = $this->scan();
				break;
			case '/report':
				$reply = $this->report();
				break;
			case '/status':
				$reply = $this->status();
				break;
			case '/learn':
				$reply = $this->learn();
				break;
			case '/blog':
				$reply = $this->blog( implode( ' ', $args ), 'post' );
				break;
			case '/destination':
				$reply = $this->blog( implode( ' ', $args ), 'destinations' );
				break;
			case '/event':
				$reply = $this->blog( implode( ' ', $args ), 'events' );
				break;
			case '/move':
				$reply = $this->move( implode( ' ', $args ) );
				break;
			case '/fix':
				$reply = $this->fix( (int)($args[0] ?? 0) );
				break;
			default:
				$reply = $this->ai_chat( $input, $history );
				break;
		}

		// Update History
		$history[] = array( 'role' => 'user', 'content' => $input );
		$history[] = array( 'role' => 'assistant', 'content' => $reply );
		if ( count($history) > 10 ) $history = array_slice($history, -10);
		set_transient( 'vmsb_chat_history_' . get_current_user_id(), $history, HOUR_IN_SECONDS );

		return $reply;
	}

	private function move( $niche ) {
		if ( ! $niche ) {
			return "Please provide a niche or location. Example: /move Madhya Pradesh travel";
		}
		$keywords = new VMSB_Keywords();
		$found = $keywords->research( 40, $niche );
		return "Pivot initiated. Researched {$found} new keywords for '{$niche}'. Strategic roadmap updated.";
	}

	private function scan() {
		$fixer = new VMSB_Fixer();
		$found = $fixer->scan( 100 );
		return "Audit complete. Found {$found} issues across the site. Use /report for details.";
	}

	private function report() {
		$keywords = new VMSB_Keywords();
		$gaps     = $keywords->content_gaps( 5 );
		$striking = $keywords->striking_distance( 5 );

		$msg = "Strategic Gap Analysis:\n\n";
		$msg .= "Top Content Gaps:\n";
		foreach ( $gaps as $g ) {
			$msg .= "- {$g->keyword} (Opp Score: {$g->opportunity})\n";
		}
		$msg .= "\nQuick Win Opportunities:\n";
		foreach ( $striking as $s ) {
			$msg .= "- {$s->keyword} (Current Pos: {$s->position})\n";
		}
		return $msg;
	}

	private function status() {
		$health = ( new VMSB_Health() )->check();
		$msg    = "System Status:\n";
		foreach ( $health as $key => $check ) {
			$icon = $check['ok'] ? '✅' : '❌';
			$msg .= "{$icon} {$check['label']}: {$check['detail']}\n";
		}
		return $msg;
	}

	private function learn() {
		$market = new VMSB_Market();
		$res    = $market->assess();
		return "Learning complete. Market saturation is at " . ( $res['saturation'] ?? 'unknown' ) . ". Insights updated in the business DNA.";
	}

	private function fix( $issue_id ) {
		if ( ! $issue_id ) return "Please provide an issue ID. Example: /fix 123";
		$fixer = new VMSB_Fixer();
		global $wpdb;
		$issue = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}vmsb_issues WHERE id = %d", $issue_id ) );
		if ( ! $issue ) return "Issue #{$issue_id} not found.";

		$res = $fixer->fix_issue( $issue );
		if ( is_wp_error($res) ) return "Failed to fix: " . $res->get_error_message();
		return "Issue #{$issue_id} fixed successfully. Revert is available in the Issues screen.";
	}

	private function blog( $topic, $post_type = 'post' ) {
		if ( ! $topic ) {
			return "Please provide a topic. Example: /blog healthy vegan recipes";
		}
		$content = new VMSB_Content();

		// Map travel CPTs
		if ( $post_type === 'destinations' && ! post_type_exists('destinations') ) $post_type = 'post';
		if ( $post_type === 'events' && ! post_type_exists('events') ) $post_type = 'post';

		$id = $content->produce_by_topic( $topic );
		if ( is_wp_error( $id ) ) {
			return "Failed to queue post: " . $id->get_error_message();
		}
		return "Post ({$post_type}) queued and generating for topic: '{$topic}'. Post ID: {$id}";
	}

	private function ai_chat( $input, $history = array() ) {
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();

		// Inject history into the prompt
		$history_context = "";
		if ( ! empty($history) ) {
			$history_context = "\nRECENT CONVERSATION:\n";
			foreach ( $history as $msg ) {
				$history_context .= strtoupper($msg['role']) . ": " . $msg['content'] . "\n";
			}
		}

		$system = $brain->context_prompt()
			. "\nYou are the Sentient SEO Commander."
			. $history_context
			. "\nBe brief, expert, and actionable. You can suggest commands like /scan, /blog, /destination, or /report.";

		$res = $ai->generate( $input, array( 'system' => $system ) );
		return $res['ok'] ? $res['text'] : "Error: " . $res['error'];
	}
}
