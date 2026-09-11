<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Puffer Bridge for Bot Discovery and Syncing.
 */
class VMSB_AIPuffer {

	public static function discover_bots() {
		$url = VMSB_Settings::get( 'aipuffer_url' );
		$key = VMSB_Settings::get( 'aipuffer_key' );

		$local_bots = self::discover_local();
		$remote_bots = array();
		$errors = array();

		// Remote discovery only if URL is set and NOT the placeholder
		$is_placeholder = ( strpos( (string)$url, 'your-aipuffer-host' ) !== false );
		if ( $url && ! $is_placeholder && untrailingslashit($url) !== untrailingslashit(home_url()) ) {
			$remote = self::discover_remote_detailed( $url, $key );
			$remote_bots = $remote['bots'];
			$errors = $remote['errors'];
		}

		$all = array_merge( $local_bots, $remote_bots );

		// Deduplicate by ID
		$unique = array();
		foreach ( $all as $bot ) {
			$unique[ $bot['id'] ] = $bot;
		}

		return array(
			'bots'   => array_values( $unique ),
			'errors' => $errors
		);
	}

	private static function discover_local() {
		$bots = array();
		$log = new VMSB_Logger();

		// AI Power (AIPKit / WPAICG)
		if ( class_exists( '\WPAICG\Chat\Storage\BotStorage' ) ) {
			try {
				$storage = new \WPAICG\Chat\Storage\BotStorage();
				$all_bots = method_exists($storage, 'get_chatbots') ? $storage->get_chatbots(false) : (method_exists($storage, 'get_bots') ? $storage->get_bots() : array());
				if ($all_bots) {
					foreach ( $all_bots as $bot ) {
						$bot_id = $bot->ID ?? ($bot['id'] ?? 0);
						$bots[] = array( 'id' => $bot_id, 'name' => ( $bot->post_title ?? ($bot['name'] ?? 'Bot ' . $bot_id) ) . ' (Local AI Power)' );
					}
				}
			} catch (\Throwable $e) { $log->error('aipuffer', 'Local discovery storage fail: ' . $e->getMessage()); }
		}

		// AI Power Fallback: Direct DB check if storage class fails or is empty.
		// Post type is 'aipkit_chatbot' (AdminSetup::POST_TYPE in the installed
		// AI Power/AIPKit plugin) - the older 'wpaicg_chatbots' name this used
		// to check never matches current installs, so this fallback silently
		// never found anything.
		if ( empty($bots) ) {
			$raw_bots = get_posts( array( 'post_type' => 'aipkit_chatbot', 'posts_per_page' => 50, 'post_status' => 'any' ) );
			foreach ( $raw_bots as $rb ) {
				$bots[] = array( 'id' => $rb->ID, 'name' => $rb->post_title . ' (Local AI Power)' );
			}
		}

		// Meow Apps (MWAI)
		$mwai_opts = get_option( 'mwai_options' );
		if ( $mwai_opts && ! empty( $mwai_opts['chatbots'] ) ) {
			foreach ( $mwai_opts['chatbots'] as $bot ) {
				$bots[] = array( 'id' => $bot['id'], 'name' => ( $bot['name'] ?? $bot['id'] ) . ' (Local AI Engine)' );
			}
		}

		$log->info('aipuffer', 'Local bot discovery complete.', array('count' => count($bots), 'bot_ids' => wp_list_pluck($bots, 'id')));
		return $bots;
	}

	private static function discover_remote_detailed( $url, $key ) {
		$endpoints = array(
			'/wp-json/aipkit/v1/chat/list',
			'/wp-json/wpaicg/v1/chat/list',
			'/wp-json/mwai/v1/bots'
		);

		$url = untrailingslashit( $url );
		$errors = array();
		$found_bots = array();

		foreach ( $endpoints as $path ) {
			$endpoint = $url . $path;
			$headers = array( 'Content-Type' => 'application/json' );
			if ( $key ) {
				$endpoint = add_query_arg( 'aipkit_api_key', $key, $endpoint );
				$headers['Authorization'] = 'Bearer ' . $key;
				$headers['X-API-KEY'] = $key;
			}

			$response = wp_remote_get( $endpoint, array(
				'headers'    => $headers,
				'timeout'    => 15,
				'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . '; ' . home_url(),
				'sslverify'  => ! VMSB_Settings::get('insecure_ssl')
			) );

			if ( is_wp_error( $response ) ) {
				$errors[] = $path . ": " . $response->get_error_message();
				continue;
			}

			if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
				$errors[] = $path . ": HTTP " . wp_remote_retrieve_response_code( $response );
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			$list = $data['bots'] ?? $data['data'] ?? ( $data['chatbots'] ?? array() );

			if ( is_array( $list ) ) {
				foreach ( $list as $bot ) {
					if ( isset($bot['id']) ) {
						$found_bots[] = array( 'id' => $bot['id'], 'name' => ( $bot['name'] ?? $bot['id'] ) . ' (Remote)' );
					}
				}
				if ( ! empty($found_bots) ) break; // Stop at first successful provider
			}
		}

		return array( 'bots' => $found_bots, 'errors' => $errors );
	}

	/**
	 * Pushes content to AI Puffer's Knowledge Base (Vector Store).
	 */
	public static function push_to_memory( $title, $content, $metadata = array() ) {
		$url   = VMSB_Settings::get( 'aipuffer_url' );
		$key   = VMSB_Settings::get( 'aipuffer_key' );
		$kb_id = VMSB_Settings::get( 'aipuffer_kb_id' );

		if ( ! $kb_id ) return;

		$is_local = empty($url) || ( untrailingslashit($url) === untrailingslashit(home_url()) );

		if ( $is_local ) {
			// Try Meow Apps (MWAI)
			if ( class_exists( 'Meow_MWAI_Core' ) ) {
				do_action( 'mwai_kb_upsert', $kb_id, array(
					'title'   => $title,
					'content' => wp_strip_all_tags( $content ),
					'metadata'=> $metadata
				) );
			}
			// Try AI Power (AIPKit)
			if ( class_exists( '\WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service' ) ) {
				try {
					$v_service = new \WPAICG\Vector\AIPKit_Vector_Text_Ingestion_Service(
						new \WPAICG\Vector\AIPKit_Vector_Store_Manager(),
						new \WPAICG\Core\AIPKit_AI_Caller()
					);

					// AI Power's internal settings for embeddings
					$opts = get_option('aipkit_options', []);
					$e_provider = $opts['embeddings']['provider'] ?? 'openai';
					$e_model    = $opts['embeddings']['model'] ?? 'text-embedding-3-small';
					$v_provider = $opts['vector_store']['provider'] ?? ''; // e.g. pinecone

					if ( $v_provider ) {
						$v_service->ingest_text(
							$v_provider,
							$kb_id, // target_id
							wp_strip_all_tags( $content ),
							$e_provider,
							$e_model,
							array_merge( $metadata, array( 'title' => $title ) ),
							\WPAICG\AIPKit_Providers::get_provider_data($v_provider)
						);
					}
				} catch ( \Throwable $e ) {
					( new VMSB_Logger() )->error( 'aipuffer', 'AI Power knowledge-base push failed: ' . $e->getMessage() );
				}
			} elseif ( class_exists( '\WPAICG\Vector\AIPKit_Vector_Store_Manager' ) ) {
				// Fallback for older versions or if ingestion service is missing
				try {
					$v_manager = new \WPAICG\Vector\AIPKit_Vector_Store_Manager();
					if ( method_exists($v_manager, 'upsert_item') ) {
						$v_manager->upsert_item( $kb_id, array(
							'title'   => $title,
							'content' => wp_strip_all_tags( $content ),
							'metadata'=> $metadata
						) );
					}
				} catch ( \Throwable $e ) {
					( new VMSB_Logger() )->error( 'aipuffer', 'AI Power knowledge-base push failed: ' . $e->getMessage() );
				}
			}
		} else {
			// Remote Sync. Fire-and-forget: this runs on every single AI
			// generation call in the plugin (VMSB_AI_Router::generate()
			// doesn't use the return value, and its own internal auditor/
			// refine recursion can call it 2-3x in one content cycle), so
			// two sequential blocking 15s requests here used to add up to
			// 30s of pure added latency in front of the real AI call every
			// time the remote KB was slow or unreachable, with nothing
			// anywhere to attribute the slowdown to. 'blocking' => false
			// still sends the request but returns immediately without
			// waiting for a response - correct for a background sync
			// nothing downstream reads the result of. The tradeoff is both
			// endpoints fire unconditionally instead of stopping at the
			// first 200 (blocking=false gives no real status to check
			// against), which costs nothing now that neither call blocks.
			$endpoints = array( '/wp-json/mwai/v1/kb/upsert', '/wp-json/aipkit/v1/vector-stores/upsert' );
			foreach ( $endpoints as $path ) {
				wp_remote_post( rtrim( $url, '/' ) . $path, array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $key,
						'X-API-KEY'     => $key,
						'Content-Type'  => 'application/json'
					),
					'body' => wp_json_encode( array(
						'kbId'    => $kb_id,
						'bot_id'  => $kb_id,
						'item'    => array(
							'title'   => $title,
							'content' => wp_strip_all_tags( $content ),
							'metadata'=> $metadata
						)
					) ),
					'timeout'    => 15,
					'blocking'   => false,
					'user-agent' => 'VM-SEO-Brain/' . VMSB_VERSION . '; ' . home_url(),
				) );
			}
		}
	}
}
