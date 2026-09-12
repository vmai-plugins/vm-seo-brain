<?php
defined( 'ABSPATH' ) || exit;

class VMSB_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'handle_forms' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_action( 'admin_footer', array( $this, 'render_popup_chatbot' ) );
	}

	public function render_popup_chatbot() {
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'vmsb' ) === false ) {
			return;
		}

		$brain = new VMSB_Brain();
		$profile = $brain->profile();
		?>
		<div class="vmsb-commander-wrapper" id="vmsb-commander-root">
			<!-- Floating Toggle with Sentient Pulse -->
			<div class="vmsb-commander-toggle" id="vmsb-commander-trigger">
				<div class="vmsb-brain-inner">🧠</div>
				<div class="vmsb-pulse-ring"></div>
			</div>

			<!-- Modern Popup Chat -->
			<div class="vmsb-commander-popup" id="vmsb-commander-popup">
				<div class="vmsb-popup-header">
					<div class="vmsb-header-info">
						<div class="vmsb-status-indicator">
							<div class="vmsb-status-dot"></div>
							<div class="vmsb-status-pulse"></div>
						</div>
						<div class="vmsb-header-text">
							<span class="vmsb-name">Master Commander</span>
							<span class="vmsb-status">Online & Sentient</span>
						</div>
					</div>
					<div class="vmsb-header-actions">
						<button class="vmsb-icon-btn" id="vmsb-chat-clear" title="Clear conversation">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
						</button>
						<button class="vmsb-popup-close" id="vmsb-commander-close">
							<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
						</button>
					</div>
				</div>

				<div class="vmsb-popup-history" id="vmsb-popup-chat-history">
					<?php
					$history = get_transient( 'vmsb_chat_history_' . get_current_user_id() ) ?: array();
					if ( empty($history) ) : ?>
						<div class="vmsb-chat-msg vmsb-msg-ai">
							<div class="vmsb-chat-bubble">
								Greetings. I am your <strong>Strategic Commander</strong>. I have analyzed <strong><?php echo esc_html($profile['name'] ?: 'your business'); ?></strong> and identified several growth levers.
								<br><br>
								What should we optimize first?
							</div>
						</div>
					<?php else :
						foreach ( $history as $msg ) :
							$type = ( $msg['role'] === 'user' ) ? 'user' : 'ai';
							?>
							<div class="vmsb-chat-msg vmsb-msg-<?php echo $type; ?>">
								<div class="vmsb-chat-bubble"><?php echo $type === 'ai' ? wp_kses_post($msg['content']) : esc_html($msg['content']); ?></div>
							</div>
						<?php endforeach;
					endif; ?>
				</div>

				<div class="vmsb-popup-footer">
					<div class="vmsb-quick-actions">
						<button data-cmd="/report">📈 Strategic Report</button>
						<button data-cmd="/scan">🔍 Deep Site Audit</button>
						<button data-cmd="/status">⚡ System Health</button>
						<button data-cmd="/learn">🧠 Market Intel</button>
					</div>
					<div class="vmsb-input-wrapper">
						<input type="text" id="vmsb-popup-chat-input" placeholder="Give a command..." autocomplete="off">
						<button id="vmsb-popup-chat-send">
							<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
						</button>
					</div>
				</div>
			</div>
		</div>

		<style>
		:root {
			--vmsb-primary: #c9a227;
			--vmsb-bg: #0e0e11;
			--vmsb-panel: #16161b;
			--vmsb-glass: rgba(22, 22, 27, 0.98);
			--vmsb-border: rgba(255, 255, 255, 0.08);
			--vmsb-text: #edeae3;
			--vmsb-muted: #8b877e;
		}

		.vmsb-commander-wrapper {
			position: fixed;
			bottom: 30px;
			right: 30px;
			left: auto !important;
			z-index: 10000;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
		}

		/* --- Sentient Toggle --- */
		.vmsb-commander-toggle {
			width: 48px;
			height: 48px;
			background: var(--vmsb-primary);
			border-radius: 50%;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			box-shadow: 0 8px 24px rgba(201, 162, 39, 0.35);
			transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
			position: fixed;
			bottom: 30px;
			right: 30px !important;
			left: auto !important;
			z-index: 10002;
			animation: vmsb-float 3s ease-in-out infinite;
		}
		@keyframes vmsb-float {
			0%, 100% { transform: translateY(0) rotate(0deg); }
			50% { transform: translateY(-5px) rotate(2deg); }
		}
		.vmsb-commander-toggle:hover { transform: scale(1.05) rotate(3deg); }
		.vmsb-brain-inner { font-size: 24px; z-index: 2; }

		.vmsb-pulse-ring {
			position: absolute;
			width: 100%;
			height: 100%;
			border: 2px solid var(--vmsb-primary);
			border-radius: 50%;
			opacity: 0;
			animation: vmsb-pulse-ring 3s infinite;
		}
		@keyframes vmsb-pulse-ring {
			0% { transform: scale(0.95); opacity: 0.6; }
			100% { transform: scale(1.6); opacity: 0; }
		}

		/* --- Advanced Popup Window --- */
		.vmsb-commander-popup {
			position: absolute;
			bottom: 80px;
			right: 0;
			width: 400px;
			height: 600px;
			background: var(--vmsb-glass);
			backdrop-filter: blur(20px);
			border: 1px solid var(--vmsb-border);
			border-radius: 24px;
			display: none;
			flex-direction: column;
			overflow: hidden;
			box-shadow: 0 25px 80px rgba(0,0,0,0.6);
			transform-origin: bottom right;
			animation: vmsb-slide-in 0.5s cubic-bezier(0.165, 0.84, 0.44, 1);
		}
		@keyframes vmsb-slide-in {
			from { opacity: 0; transform: scale(0.7) translateY(40px); filter: blur(10px); }
			to { opacity: 1; transform: scale(1) translateY(0); filter: blur(0); }
		}

		.vmsb-popup-header {
			padding: 22px 28px;
			background: rgba(255,255,255,0.02);
			border-bottom: 1px solid var(--vmsb-border);
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.vmsb-header-info { display: flex; align-items: center; gap: 14px; }
		.vmsb-status-indicator { position: relative; width: 10px; height: 10px; }
		.vmsb-status-dot { width: 100%; height: 100%; background: #5fa778; border-radius: 50%; }
		.vmsb-status-pulse {
			position: absolute; top: 0; left: 0; width: 100%; height: 100%;
			background: #5fa778; border-radius: 50%; opacity: 0;
			animation: vmsb-dot-pulse 2s infinite;
		}
		@keyframes vmsb-dot-pulse { 0% { transform: scale(1); opacity: 0.5; } 100% { transform: scale(2.5); opacity: 0; } }

		.vmsb-header-text { display: flex; flex-direction: column; }
		.vmsb-name { color: #fff; font-weight: 700; font-size: 15px; letter-spacing: -0.2px; }
		.vmsb-status { color: var(--vmsb-primary); font-size: 10px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 600; margin-top: 2px; }

		.vmsb-header-actions { display: flex; gap: 12px; align-items: center; }
		.vmsb-icon-btn { background: none; border: none; font-size: 16px; cursor: pointer; opacity: 0.5; transition: opacity 0.2s; }
		.vmsb-icon-btn:hover { opacity: 1; }
		.vmsb-popup-close { background: none; border: none; color: #fff; cursor: pointer; font-size: 18px; padding: 4px; line-height: 1; opacity: 0.6; transition: 0.2s; }
		.vmsb-popup-close:hover { opacity: 1; transform: scale(1.1); }

		/* --- Chat History Flow --- */
		.vmsb-popup-history {
			flex: 1;
			padding: 28px;
			overflow-y: auto;
			display: flex;
			flex-direction: column;
			gap: 24px;
			scrollbar-width: none;
		}
		.vmsb-popup-history::-webkit-scrollbar { display: none; }

		.vmsb-chat-msg { max-width: 88%; display: flex; flex-direction: column; animation: vmsb-msg-appear 0.4s ease-out both; }
		@keyframes vmsb-msg-appear { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

		.vmsb-msg-ai { align-self: flex-start; }
		.vmsb-msg-user { align-self: flex-end; }

		.vmsb-chat-bubble {
			padding: 16px 20px;
			font-size: 14px;
			line-height: 1.6;
			border-radius: 4px 20px 20px 20px;
			background: var(--vmsb-panel);
			color: var(--vmsb-text);
			border: 1px solid rgba(255,255,255,0.04);
			box-shadow: 0 4px 15px rgba(0,0,0,0.1);
		}
		.vmsb-chat-bubble > *:first-child { margin-top: 0; }
		.vmsb-chat-bubble > *:last-child { margin-bottom: 0; }
		.vmsb-chat-bubble p { margin: 0 0 10px; }
		.vmsb-chat-bubble h3, .vmsb-chat-bubble h4,
		.vmsb-chat-bubble h5, .vmsb-chat-bubble h6 {
			margin: 16px 0 8px;
			font-size: 14px;
			font-weight: 700;
			color: var(--vmsb-primary);
		}
		.vmsb-chat-bubble ul { margin: 0 0 10px; padding-left: 20px; }
		.vmsb-chat-bubble li { margin-bottom: 6px; }
		.vmsb-chat-bubble code {
			background: rgba(255,255,255,0.07);
			padding: 2px 6px;
			border-radius: 4px;
			font-size: 12px;
		}
		.vmsb-msg-user .vmsb-chat-bubble {
			background: var(--vmsb-primary);
			color: #000;
			font-weight: 500;
			border-radius: 20px 20px 4px 20px;
			border: none;
			box-shadow: 0 8px 20px rgba(201,162,39,0.2);
		}

		/* --- Smart Typing Animation --- */
		.vmsb-typing { display: flex; gap: 4px; padding: 12px 16px; background: var(--vmsb-panel); border-radius: 20px; align-self: flex-start; margin-bottom: 10px; }
		.vmsb-typing span { width: 6px; height: 6px; background: var(--vmsb-primary); border-radius: 50%; animation: vmsb-typing 1.4s infinite; opacity: 0.4; }
		.vmsb-typing span:nth-child(2) { animation-delay: 0.2s; }
		.vmsb-typing span:nth-child(3) { animation-delay: 0.4s; }
		@keyframes vmsb-typing { 0%, 100% { transform: translateY(0); opacity: 0.4; } 50% { transform: translateY(-4px); opacity: 1; } }

		/* --- Footer Design --- */
		.vmsb-popup-footer {
			padding: 24px 28px 32px;
			background: rgba(0,0,0,0.15);
		}
		.vmsb-quick-actions {
			display: flex;
			gap: 6px;
			margin-bottom: 20px;
			flex-wrap: wrap;
		}
		.vmsb-quick-actions button {
			background: rgba(201,162,39,0.06);
			border: 1.5px solid rgba(201,162,39,0.2);
			color: var(--vmsb-primary);
			padding: 7px 14px;
			border-radius: 12px;
			font-size: 11px;
			font-weight: 700;
			cursor: pointer;
			transition: all 0.2s;
			white-space: nowrap;
		}
		.vmsb-quick-actions button:hover { background: var(--vmsb-primary); color: #000; border-color: var(--vmsb-primary); transform: translateY(-2px); }

		.vmsb-input-wrapper {
			display: flex;
			gap: 12px;
			background: var(--vmsb-bg);
			padding: 8px 8px 8px 20px;
			border-radius: 16px;
			border: 1px solid var(--vmsb-border);
			transition: border-color 0.3s, box-shadow 0.3s;
		}
		.vmsb-input-wrapper:focus-within { border-color: var(--vmsb-primary); box-shadow: 0 0 0 3px rgba(201,162,39,0.15); }

		#vmsb-popup-chat-input {
			flex: 1;
			background: none !important;
			border: none !important;
			padding: 10px 0 !important;
			color: #fff !important;
			font-size: 14px !important;
			box-shadow: none !important;
			outline: none !important;
		}
		#vmsb-popup-chat-send {
			background: var(--vmsb-primary);
			color: #000;
			border: none;
			width: 42px;
			height: 42px;
			border-radius: 12px;
			display: flex;
			align-items: center;
			justify-content: center;
			cursor: pointer;
			transition: all 0.2s;
		}
		#vmsb-popup-chat-send:hover { transform: scale(1.05); }

		/* Mobile Responsive Bottom-Sheet */
		@media (max-width: 782px) {
			.vmsb-commander-wrapper { bottom: 0; right: 0; left: auto; pointer-events: none; }
			.vmsb-commander-toggle {
				bottom: 20px;
				right: 20px !important;
				left: auto !important;
				width: 44px;
				height: 44px;
				pointer-events: auto;
			}
			.vmsb-brain-inner { font-size: 20px; }
			.vmsb-commander-popup {
				position: fixed;
				bottom: 0; right: 0; left: 0;
				width: 100%; height: 75vh;
				border-radius: 24px 24px 0 0;
				border-bottom: none;
				z-index: 100001;
				pointer-events: auto;
			}
			.vmsb-popup-header { padding: 16px 20px; }
			.vmsb-popup-history { padding: 20px; }
			.vmsb-popup-footer { padding: 12px 20px 25px; }
			.vmsb-quick-actions { gap: 6px; margin-bottom: 12px; }
			.vmsb-quick-actions button { padding: 6px 10px; font-size: 10px; flex: 1 1 calc(50% - 6px); }
		}
		</style>
		<?php
	}

	public function body_class( $classes ) {
		if ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'vmsb' ) === 0 ) {
			$mode = VMSB_Settings::get( 'theme_mode', 'dark' );
			$classes .= ' vmsb-mode-' . $mode;
		}
		return $classes;
	}

	public function menu() {
		add_menu_page(
			'VM SEO Brain',
			'SEO Brain',
			VMSB_CAP,
			'vmsb',
			array( $this, 'render_dashboard' ),
			'dashicons-superhero',
			58
		);

		// 5 Clean Job-Oriented Hubs + Setup Wizard
		$hubs = array(
			'vmsb'           => array( 'Overview', 'render_dashboard' ),
			'vmsb-content'   => array( 'Content Engine', 'render_page' ),
			'vmsb-seo'       => array( 'SEO Lab', 'render_page' ),
			'vmsb-analytics' => array( 'Performance & Logs', 'render_page' ),
			'vmsb-settings'  => array( 'Settings', 'render_page' ),
			'vmsb-wizard'    => array( 'Setup Wizard', 'render_page' ),
		);

		foreach ( $hubs as $slug => $config ) {
			add_submenu_page(
				'vmsb',
				'VM SEO Brain — ' . $config[0],
				$config[0],
				VMSB_CAP,
				$slug,
				array( $this, $config[1] )
			);
		}
	}

	/**
	 * Cache-busting version for a bundled asset: its mtime when readable,
	 * the plugin version otherwise (some hosts disable stat on the path).
	 */
	private static function asset_version( $relative ) {
		$path = VMSB_DIR . $relative;
		$time = @filemtime( $path );
		return $time ? (string) $time : VMSB_VERSION;
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'vmsb' ) ) {
			return;
		}
		// Version by file mtime, falling back to the plugin version. Both
		// assets were pinned to VMSB_VERSION alone, so any change shipped
		// without a version bump - every fix between releases - stayed
		// invisible behind the browser cache until a manual hard refresh.
		wp_enqueue_style( 'vmsb-admin', VMSB_URL . 'admin/assets/css/admin.css', array(), self::asset_version( 'admin/assets/css/admin.css' ) );
		wp_enqueue_script( 'vmsb-admin', VMSB_URL . 'admin/assets/js/admin.js', array(), self::asset_version( 'admin/assets/js/admin.js' ), true );
		wp_localize_script(
			'vmsb-admin',
			'VMSB',
			array(
				'root'  => esc_url_raw( rest_url( VMSB_REST::NS . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public function render_dashboard() {
		echo '<div class="wrap vmsb vmsb-theme-wrapper">';
		$this->render_header_utility();
		$this->view( 'dashboard' );
		echo '</div>';
	}

	public function render_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'vmsb';

		$route_map = array(
			'vmsb'               => 'dashboard',
			'vmsb-command'       => 'dashboard',
			'vmsb-content'       => 'production',
			'vmsb-production'    => 'production',
			'vmsb-pipeline'      => 'production',
			'vmsb-growth'        => 'production',
			'vmsb-agents'        => 'production',
			'vmsb-seo'           => 'seo',
			'vmsb-taxonomy'      => 'seo',
			'vmsb-intelligence'  => 'seo',
			'vmsb-analytics'     => 'analytics',
			'vmsb-learning'      => 'analytics',
			'vmsb-observability' => 'analytics',
			'vmsb-logs'          => 'analytics',
			'vmsb-settings'      => 'settings',
			'vmsb-plans'         => 'settings',
			'vmsb-wizard'        => 'wizard',
		);

		$view = isset( $route_map[ $page ] ) ? $route_map[ $page ] : str_replace( 'vmsb-', '', $page );

		echo '<div class="wrap vmsb vmsb-theme-wrapper">';
		$this->render_header_utility();
		$this->view( $view );
		echo '</div>';
	}

	private function render_header_utility() {
		$s = VMSB_Settings::masked();
		$ai_configured = ! empty( $s['ai_primary'] ) && (
			! empty( $s['aipuffer_key'] ) || ! empty( $s['openai_key'] ) ||
			! empty( $s['gemini_key'] ) || ! empty( $s['openrouter_key'] ) ||
			! empty( $s['ollama_url'] ) || ! empty( $s['omniroute_url'] )
		);
		$onboarded = get_option( 'vmsb_onboarded', false );
		$mode = VMSB_Settings::get( 'theme_mode', 'dark' );
		?>
		<div class="vmsb-top-bar">
			<div class="vmsb-top-left">
				<?php self::breadcrumbs(); ?>
			</div>
			<div class="vmsb-top-right">
				<div class="vmsb-status-pill <?php echo $ai_configured ? 'is-live' : 'is-warning'; ?>">
					<span class="vmsb-status-dot"></span>
					<span><?php echo $ai_configured ? esc_html( ucfirst( $s['ai_primary'] ) ) . ' Active' : 'Setup Required'; ?></span>
				</div>
				<?php if ( ! $onboarded || ! $ai_configured ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-wizard' ) ); ?>" class="vmsb-wizard-btn">
						⚡ Setup Wizard
					</a>
				<?php endif; ?>
				<button type="button" class="vmsb-btn-theme-toggle" id="vmsb-theme-toggle" title="Toggle Light/Dark Theme">
					<?php echo $mode === 'lite' ? '🌙' : '☀️'; ?>
				</button>
				<button type="button" class="vmsb-command-btn" id="vmsb-open-commander" title="Open AI Command Assistant (Ctrl+K)">
					<span>🧠 Commander</span>
					<kbd>⌘K</kbd>
				</button>
			</div>
		</div>
		<?php
	}

	private function view( $name ) {
		$file = VMSB_DIR . 'admin/views/' . sanitize_file_name( $name ) . '.php';
		if ( ! is_readable( $file ) ) {
			echo '<p>That screen is missing.</p>';
			return;
		}
		$core = vmsb();
		include $file;
	}

	public function handle_forms() {
		if ( ! current_user_can( VMSB_CAP ) ) {
			return;
		}

		if ( isset( $_GET['vmsb_google'], $_GET['code'] ) && 'callback' === $_GET['vmsb_google'] ) {
			$google = new VMSB_Google();
			if ( ! isset( $_GET['state'] ) || ! $google->verify_oauth_state( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) ) {
				wp_die( esc_html__( 'That authorisation link has expired. Please try connecting again.', 'vm-seo-brain' ) );
			}
			$res = $google->exchange_code( sanitize_text_field( wp_unslash( $_GET['code'] ) ) );
			$key = is_wp_error( $res ) ? 'google_failed' : 'google_connected';
			wp_safe_redirect( admin_url( 'admin.php?page=vmsb-settings&vmsb_msg=' . $key ) );
			exit;
		}

		if ( isset( $_GET['vmsb_action'], $_GET['vmsb_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['vmsb_nonce'] ) ), 'vmsb_update' ) ) {
			$action = sanitize_key( wp_unslash( $_GET['vmsb_action'] ) );
			if ( 'check_update' === $action && class_exists( 'VMSB_GitHub_Updater' ) ) {
				VMSB_GitHub_Updater::check( true );
				wp_safe_redirect( admin_url( 'admin.php?page=vmsb-settings&tab=updates&vmsb_msg=checked' ) );
				exit;
			}
			if ( 'update_from_github' === $action && class_exists( 'VMSB_GitHub_Updater' ) ) {
				$res = VMSB_GitHub_Updater::perform_direct_update();
				$key = is_wp_error( $res ) ? 'update_failed' : 'update_done';
				wp_safe_redirect( admin_url( 'admin.php?page=vmsb-settings&tab=updates&vmsb_msg=' . $key ) );
				exit;
			}
		}

		if ( ! isset( $_POST['vmsb_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['vmsb_settings_nonce'] ) ), 'vmsb_save_settings' ) ) {
			return;
		}

		$fields = wp_unslash( $_POST['vmsb'] ?? array() );
		$clean  = array();

		$textarea_keys = array( 'business_description', 'services' );
		foreach ( $textarea_keys as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( $fields[ $key ] );
			}
		}

		$text_keys = array(
			'business_name', 'business_type', 'primary_locations',
			'audience', 'tone', 'language', 'country', 'currency', 'competitors',
			'ai_primary', 'aipuffer_url', 'aipuffer_kb_id', 'aipuffer_bot_id', 'openai_model', 'gemini_model',
			'openrouter_model', 'ollama_url', 'ollama_model', 'pollinations_url', 'pollinations_model',
			'huggingface_model', 'cloudflare_account_id', 'cloudflare_model',
			'comfy_url', 'image_style', 'google_client_id', 'gsc_property', 'ga4_property_id',
			'sheet_id', 'sheet_tab', 'bulk_topics_tab', 'theme_mode', 'aipuffer_image_provider', 'aipuffer_image_model',
			'google_imagen_model', 'banana_model', 'webhook_url',
			'conversion_goal', 'cta_style',
			'outreach_from_name', 'outreach_tone',
			'omniroute_url', 'omniroute_text_model', 'omniroute_image_model', 'omniroute_video_model',
		);
		foreach ( $text_keys as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = sanitize_text_field( $fields[ $key ] );
			}
		}

		// A malformed value here isn't just cosmetic: VMSB_Backlinks::is_enabled()
		// gates the whole outreach feature on is_email() passing, so this has to
		// go through the same sanitizer WordPress uses for real email fields.
		if ( isset( $fields['outreach_from_email'] ) ) {
			$clean['outreach_from_email'] = sanitize_email( $fields['outreach_from_email'] );
		}

		// One list, held by VMSB_Settings, which is also what encrypts on save
		// and redacts on render. This used to be a second hand-maintained copy
		// and the two had drifted: two keys here were absent there, so they
		// were stored unencrypted and rendered back into the form in the clear.
		foreach ( VMSB_Settings::$secret_keys as $key ) {
			if ( empty( $fields[ $key ] ) || VMSB_Settings::is_masked( $fields[ $key ] ) ) {
				continue; // Field left untouched - keep whatever is stored.
			}
			$clean[ $key ] = trim( $fields[ $key ] );
		}

		$int_keys = array(
			'image_width', 'image_height', 'image_quality', 'posts_per_day', 'max_ai_calls_day', 'growth_target',
			'growth_window', 'max_god_fixes_day', 'staleness_threshold_days',
			// Quality gate thresholds and the programmatic cap. The code has
			// always read these; until now no form offered them, so they were
			// only changeable directly in the database.
			'quality_min_score', 'quality_min_words', 'quality_min_alignment',
			'quality_min_originality', 'programmatic_daily_cap',
			'goal_min_posts', 'goal_max_posts',
		);
		foreach ( $int_keys as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = max( 0, (int) $fields[ $key ] );
			}
		}

		// The ROI benchmarks below are dollar/percentage values, not whole
		// numbers - (int) would truncate a $1.85 CPC down to $1.
		foreach ( array( 'avg_cpc', 'default_aov', 'default_conversion_rate' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$clean[ $key ] = max( 0, (float) $fields[ $key ] );
			}
		}

		foreach ( array(
			'god_mode', 'auto_publish', 'require_review', 'profile_locked', 'insecure_ssl',
			'webhook_enabled', 'auto_growth_mode', 'feature_aeo', 'feature_entity', 'feature_silo',
			'feature_images', 'feature_taxonomy', 'feature_production', 'feature_maintenance', 'feature_schema',
			'feature_llms_txt', 'feature_citability',
			// Quality gate switches and the per-agent toggles, all previously
			// readable by the code but unreachable from any screen.
			'quality_gate', 'quality_dup_block',
			'learning_enabled', 'vector_enabled', 'competitor_enabled',
			'news_enabled', 'programmatic_enabled', 'backlink_enabled',
			'goal_autopilot', 'video_enabled',
		) as $key ) {
			$clean[ $key ] = empty( $fields[ $key ] ) ? 0 : 1;
		}

		if ( isset( $fields['ai_fallbacks'] ) ) {
			$clean['ai_fallbacks'] = array_map( 'sanitize_key', (array) $fields['ai_fallbacks'] );
		}
		if ( isset( $fields['image_chain'] ) ) {
			$clean['image_chain'] = array_map( 'sanitize_key', (array) $fields['image_chain'] );
		}
		if ( isset( $fields['god_mode_scope'] ) ) {
			$clean['god_mode_scope'] = array_map( 'sanitize_key', (array) $fields['god_mode_scope'] );
		}
		if ( isset( $fields['safe_post_types'] ) ) {
			$clean['safe_post_types'] = array_map( 'sanitize_key', (array) $fields['safe_post_types'] );
		}
		// Checkbox group: absent entirely from $_POST when every box is
		// unchecked, same reasoning as ai_fallbacks/image_chain above - only
		// overwrite the saved value when the field was actually on the form.
		if ( isset( $fields['webhook_events'] ) ) {
			$clean['webhook_events'] = array_intersect( array_map( 'sanitize_key', (array) $fields['webhook_events'] ), array_keys( VMSB_Webhooks::EVENTS ) );
		}
		if ( isset( $fields['comfy_workflow'] ) ) {
			$clean['comfy_workflow'] = wp_kses_post( $fields['comfy_workflow'] );
		}

		VMSB_Settings::update( $clean );
		wp_safe_redirect( admin_url( 'admin.php?page=vmsb-settings&vmsb_msg=saved' ) );
		exit;
	}

	public function notices() {
		if ( get_option( 'vmsb_decryption_failed' ) ) {
			echo '<div class="notice notice-error"><p><strong>VM SEO Brain:</strong> Could not decrypt your API keys. This usually happens after a site migration or if <code>AUTH_KEY</code> was changed in <code>wp-config.php</code>. Please re-enter your API keys in <a href="' . esc_url( admin_url('admin.php?page=vmsb-settings') ) . '">Settings</a> to restore functionality.</p></div>';
		}

		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'vmsb' ) !== false && $screen->id !== 'seo-brain_page_vmsb-wizard' ) {
			$onboarded = get_option( 'vmsb_onboarded', false );
			$s = VMSB_Settings::masked();
			$has_ai = ! empty( $s['openai_key'] ) || ! empty( $s['gemini_key'] ) || ! empty( $s['openrouter_key'] ) || ! empty( $s['aipuffer_key'] ) || ! empty( $s['ollama_url'] );

			if ( ! $onboarded || ! $has_ai ) {
				?>
				<div class="notice notice-info vmsb-onboard-notice is-dismissible" style="border-left-color: #d4af37; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between;">
					<div>
						<strong style="font-size: 14px;">🚀 Welcome to VM SEO Brain:</strong>
						<span style="margin-left: 8px;">Your autonomous SEO assistant is ready. Complete the 2-minute setup wizard to connect your AI model and generate your first strategy.</span>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-wizard' ) ); ?>" class="button button-primary" style="background: #d4af37; border-color: #c9a227; color: #000; font-weight: 600; text-shadow: none;">
						Launch Quick Setup →
					</a>
				</div>
				<?php
			}
		}

		if ( empty( $_GET['vmsb_msg'] ) ) {
			return;
		}
		$messages = array(
			'saved'            => array( 'success', 'Settings saved successfully.' ),
			'google_connected' => array( 'success', 'Google Cloud synchronized.' ),
			'google_failed'    => array( 'error', 'Google Cloud authorization failed.' ),
			'import_done'      => array( 'success', 'Topics imported successfully. View them in Content Engine.' ),
			'license_active'   => array( 'success', 'License activated successfully! Your plan is now: ' . strtoupper(VMSB_License::plan()) ),
			'wizard_completed' => array( 'success', 'Setup Wizard completed! Your SEO Brain is now calibrated.' ),
			'checked'          => array( 'success', 'Update check completed with GitHub.' ),
			'update_done'      => array( 'success', 'Successfully updated VM SEO Brain from GitHub!' ),
			'update_failed'    => array( 'error', 'GitHub update failed. Check plugin logs for details.' ),
		);
		$key = sanitize_key( wp_unslash( $_GET['vmsb_msg'] ) );
		if ( ! isset( $messages[ $key ] ) ) {
			return;
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
	}

	public static function breadcrumbs() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'vmsb';

		$labels = array(
			'vmsb'           => 'Overview',
			'vmsb-content'   => 'Content Engine',
			'vmsb-seo'       => 'SEO Lab',
			'vmsb-analytics' => 'Performance & Logs',
			'vmsb-settings'  => 'Settings',
			'vmsb-wizard'    => 'Setup Wizard',
		);

		$label = isset( $labels[ $page ] ) ? $labels[ $page ] : ucwords( str_replace( array( 'vmsb-', '-' ), array( '', ' ' ), $page ) );

		echo '<nav class="vmsb-breadcrumbs">';
		echo '<a href="' . esc_url( admin_url('admin.php?page=vmsb') ) . '" class="vmsb-bc-root">🧠 SEO Brain</a>';
		echo '<span class="vmsb-bc-sep">/</span>';
		echo '<span class="vmsb-bc-current">' . esc_html($label) . '</span>';
		echo '</nav>';
	}
}
