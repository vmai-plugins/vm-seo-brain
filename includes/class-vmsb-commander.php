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

		switch ( $command ) {
			case '/scan':
				return $this->scan();
			case '/report':
				return $this->report();
			case '/status':
				return $this->status();
			case '/learn':
				return $this->learn();
			case '/blog':
				return $this->blog( implode( ' ', $args ) );
			default:
				return $this->ai_chat( $input );
		}
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

	private function blog( $topic ) {
		if ( ! $topic ) {
			return "Please provide a topic. Example: /blog healthy vegan recipes";
		}
		$content = new VMSB_Content();
		$id      = $content->produce_by_topic( $topic );
		if ( is_wp_error( $id ) ) {
			return "Failed to queue post: " . $id->get_error_message();
		}
		return "Post queued and generating for topic: '{$topic}'. Post ID: {$id}";
	}

	private function ai_chat( $input ) {
		$ai    = new VMSB_AI_Router();
		$brain = new VMSB_Brain();
		$res   = $ai->generate( $input, array( 'system' => $brain->context_prompt() . "\nYou are the Sentient SEO Commander. Be brief, expert, and actionable." ) );
		return $res['ok'] ? $res['text'] : "Error: " . $res['error'];
	}
}
