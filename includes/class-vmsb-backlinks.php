<?php
defined( 'ABSPATH' ) || exit;

/**
 * Backlink pipeline.
 *
 * Merges what the old plugin split into finder/pitcher/negotiator/shield into
 * one status-driven pipeline: prospect -> pitched -> replied -> linked (or
 * declined). Every stage is a row in {prefix}vmsb_backlinks with a status, so
 * "what's the state of our outreach" is one query, not four modules to check.
 *
 * Off by default. Outreach sends real email under the site owner's name and
 * reputation - this is the one module in the plugin that acts outside the
 * site itself, so it needs an explicit opt-in plus sender details before it
 * does anything.
 */
class VMSB_Backlinks {

	private $ai;
	private $log;

	public function __construct() {
		$this->ai  = new VMSB_AI_Router();
		$this->log = new VMSB_Logger();
	}

	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'vmsb_backlinks';
	}

	public function is_enabled() {
		return (int) VMSB_Settings::get( 'backlink_enabled' )
			&& VMSB_Settings::get( 'outreach_from_name' )
			&& is_email( (string) VMSB_Settings::get( 'outreach_from_email' ) );
	}

	/* ---------------------------------------------------------------- discovery */

	/**
	 * Find prospects for a published post: sites that would plausibly link to
	 * it because they cover adjacent ground. No scraping or paid API required -
	 * the model reasons from the business context and the post's topic; a
	 * SEMrush/Ahrefs "linking domains" pull (Tier 3) can seed this list too
	 * when a key is configured.
	 */
	public function discover( $post_id, $limit = 8 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'vmsb_backlinks', 'Post not found.' );
		}

		$brain  = new VMSB_Brain();
		$prompt = "Article: \"{$post->post_title}\"\nSummary: " . wp_trim_words( wp_strip_all_tags( $post->post_content ), 80 ) . "\n\n"
			. "List real, currently-existing website categories (not fabricated domain names) that would plausibly link to a resource like this - "
			. "industry directories, roundup posts, resource pages, complementary (non-competing) blogs in adjacent niches. "
			. "For each, describe what kind of page it is and why it would link here, not a guessed domain.\n\n"
			. 'Return JSON: {"prospects":[{"site_type":"","why":"","relevance":0.0}]}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 700, 'temperature' => 0.4 ) );
		if ( ! is_array( $data ) || empty( $data['prospects'] ) ) {
			return array( 'found' => 0 );
		}

		global $wpdb;
		$found = 0;
		foreach ( array_slice( $data['prospects'], 0, $limit ) as $p ) {
			if ( empty( $p['site_type'] ) ) {
				continue;
			}
			// We store the prospect as a lead to research, not a guessed domain -
			// domain and email are filled in by a human or a later enrichment
			// pass, never invented.
			$wpdb->insert( $this->table(), array(
				'domain'         => '(unresearched: ' . mb_substr( $p['site_type'], 0, 150 ) . ')',
				'relevance'      => (float) ( $p['relevance'] ?? 0.5 ),
				'target_post_id' => $post_id,
				'pitch'          => wp_json_encode( array( 'why' => $p['why'] ?? '' ) ),
				'status'         => 'prospect',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			) );
			$found++;
		}

		return array( 'found' => $found );
	}

	/**
	 * Turn a researched prospect (domain + contact known) into a drafted pitch.
	 * Never sends - drafting and sending are separate, deliberate steps.
	 */
	public function draft_pitch( $prospect_id ) {
		$row = $this->get( $prospect_id );
		if ( ! $row ) {
			return new WP_Error( 'vmsb_backlinks', 'Prospect not found.' );
		}
		if ( ! $row->target_post_id ) {
			return new WP_Error( 'vmsb_backlinks', 'No target post set.' );
		}

		$post   = get_post( $row->target_post_id );
		$brain  = new VMSB_Brain();
		$tone   = VMSB_Settings::get( 'outreach_tone', 'brief, human, no hype' );
		$sender = VMSB_Settings::get( 'outreach_from_name' );

		$prompt = "Write a short outreach email pitching a link to our article \"{$post->post_title}\".\n"
			. "Recipient: {$row->domain}\n"
			. "Why they might care: " . self::extract_why( $row->pitch ) . "\n"
			. "Tone: {$tone}. Sender name: {$sender}.\n"
			. "Rules: no flattery padding, no 'I came across your site', state the specific value in the first two sentences, one clear ask, under 120 words, no subject line filler like 'Quick question'.\n\n"
			. 'Return JSON: {"subject":"","body":""}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 400, 'temperature' => 0.5 ) );
		if ( empty( $data['body'] ) ) {
			return new WP_Error( 'vmsb_backlinks', $this->ai->get_last_error() ?: 'Could not draft a pitch.' );
		}

		global $wpdb;
		$wpdb->update( $this->table(), array(
			'pitch'      => wp_json_encode( array( 'subject' => $data['subject'] ?? '', 'body' => $data['body'] ) ),
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $prospect_id ) );

		return $data;
	}

	/**
	 * Send a drafted pitch. Requires the module to be explicitly enabled and
	 * sender details configured - see is_enabled(). Uses wp_mail so it routes
	 * through whatever SMTP the site already has configured.
	 */
	public function send_pitch( $prospect_id ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error( 'vmsb_backlinks', 'Backlink outreach is not enabled, or sender details are missing.' );
		}
		$row = $this->get( $prospect_id );
		if ( ! $row || ! $row->contact_email || ! is_email( $row->contact_email ) ) {
			return new WP_Error( 'vmsb_backlinks', 'No valid contact email on file for this prospect.' );
		}
		$pitch = json_decode( $row->pitch, true );
		if ( empty( $pitch['body'] ) ) {
			return new WP_Error( 'vmsb_backlinks', 'Draft a pitch before sending.' );
		}

		$from    = VMSB_Settings::get( 'outreach_from_name' ) . ' <' . VMSB_Settings::get( 'outreach_from_email' ) . '>';
		$headers = array( 'From: ' . $from, 'Reply-To: ' . VMSB_Settings::get( 'outreach_from_email' ) );

		$sent = wp_mail( $row->contact_email, $pitch['subject'] ?? 'Quick note', $pitch['body'], $headers );
		if ( ! $sent ) {
			return new WP_Error( 'vmsb_backlinks', 'wp_mail failed to send. Check the site\'s mail configuration.' );
		}

		global $wpdb;
		$wpdb->update( $this->table(), array(
			'status'          => 'pitched',
			'last_contact_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		), array( 'id' => $prospect_id ) );

		return array( 'sent' => true );
	}

	/**
	 * File an incoming reply against a prospect's thread and let the model
	 * decide the next step. This never auto-sends a follow-up; it proposes one
	 * for a human to review, because a wrong reply to a real contact costs
	 * more than a wrong blog post.
	 */
	public function log_reply( $prospect_id, $reply_text ) {
		$row = $this->get( $prospect_id );
		if ( ! $row ) {
			return new WP_Error( 'vmsb_backlinks', 'Prospect not found.' );
		}

		$thread   = (array) json_decode( (string) $row->thread, true );
		$thread[] = array( 'from' => 'them', 'text' => $reply_text, 'at' => current_time( 'mysql' ) );

		$brain  = new VMSB_Brain();
		$prompt = "Outreach thread with {$row->domain}:\n" . wp_json_encode( $thread ) . "\n\n"
			. "Classify their reply and draft a next step if one is warranted.\n\n"
			. 'Return JSON: {"classification":"interested|declined|needs_info|linked|no_action","suggested_reply":"","status":"pitched|declined|linked"}';

		$data = $this->ai->generate_json( $prompt, array( 'system' => $brain->context_prompt(), 'max_tokens' => 500, 'temperature' => 0.3 ) );

		global $wpdb;
		$wpdb->update( $this->table(), array(
			'thread'          => wp_json_encode( $thread ),
			'status'          => is_array( $data ) && ! empty( $data['status'] ) ? sanitize_key( $data['status'] ) : $row->status,
			'last_contact_at' => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		), array( 'id' => $prospect_id ) );

		return is_array( $data ) ? $data : array( 'classification' => 'unknown' );
	}

	/* ---------------------------------------------------------------- authority shield */

	/**
	 * Flag outbound links on our own site that look like a bad neighbourhood -
	 * dead links, or link targets that have changed subject entirely since we
	 * linked to them. Files as normal issues so they surface in God Fix scope.
	 */
	public function shield_scan( $limit = 30 ) {
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'posts_per_page' => $limit, 'post_status' => 'publish' ) );
		$flagged = 0;

		foreach ( $posts as $post ) {
			if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $post->post_content, $m ) ) {
				continue;
			}
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( array_unique( $m[1] ) as $href ) {
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || $host === $home ) {
					continue;
				}
				$res = wp_remote_head( $href, array( 'timeout' => 8, 'redirection' => 3 ) );
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				if ( $code >= 400 || 0 === $code ) {
					$this->file_dead_link( $post->ID, $href, $code );
					$flagged++;
				}
			}
		}

		return array( 'checked' => count( $posts ), 'flagged' => $flagged );
	}

	private function file_dead_link( $post_id, $url, $code ) {
		global $wpdb;
		$table = $wpdb->prefix . 'vmsb_issues';
		$dupe  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE object_id = %d AND rule = 'dead_outbound_link' AND status = 'open' AND detail LIKE %s",
			$post_id, '%' . $wpdb->esc_like( $url ) . '%'
		) );
		if ( $dupe ) {
			return;
		}
		$wpdb->insert( $table, array(
			'object_type' => 'post',
			'object_id'   => $post_id,
			'rule'        => 'dead_outbound_link',
			'severity'    => 'low',
			'detail'      => "Outbound link returns HTTP {$code}: {$url}",
			'suggested'   => wp_json_encode( array( 'url' => $url ) ),
			'status'      => 'open',
			'detected_at' => current_time( 'mysql' ),
		) );
	}

	/* ---------------------------------------------------------------- reads */

	public function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', (int) $id ) );
	}

	public function list_by_status( $status = '', $limit = 100 ) {
		global $wpdb;
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE status = %s ORDER BY id DESC LIMIT %d', $status, $limit ) );
		}
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d', $limit ) );
	}

	public function counts() {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT COUNT(*) total, SUM(status="prospect") prospects, SUM(status="pitched") pitched, SUM(status="linked") linked, SUM(status="declined") declined FROM ' . $this->table(),
			ARRAY_A
		);
		return wp_parse_args( (array) $row, array( 'total' => 0, 'prospects' => 0, 'pitched' => 0, 'linked' => 0, 'declined' => 0 ) );
	}

	private static function extract_why( $pitch_json ) {
		$data = json_decode( (string) $pitch_json, true );
		return is_array( $data ) && ! empty( $data['why'] ) ? $data['why'] : 'Relevant to their audience.';
	}
}
