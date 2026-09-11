<?php
defined( 'ABSPATH' ) || exit;

$s       = VMSB_Settings::masked();
$google  = new VMSB_Google();
$brain   = new VMSB_Brain();
$profile = $brain->profile();
$field   = static function ( $key ) { return 'vmsb[' . $key . ']'; };

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'identity';
if ( ! in_array( $active_tab, array( 'identity', 'ai-images', 'google', 'autonomy', 'license', 'appearance', 'webhooks' ), true ) ) {
	$active_tab = 'identity';
}
?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Configuration</p>
			<h1>Settings</h1>
			<p class="vmsb-sub">Manage API connections, Business DNA, Google Cloud integrations, and autonomy guardrails.</p>
		</div>
		<div class="vmsb-head-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-wizard' ) ); ?>" class="vmsb-btn vmsb-btn-ghost">
				⚡ Launch Setup Wizard
			</a>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-settings-status">
		<div class="vmsb-card vmsb-status-card" style="border-left-color: <?php echo $profile['type'] ? 'var(--good)' : 'var(--crit)'; ?>;">
			<span class="vmsb-status-label">Business DNA</span>
			<p class="vmsb-status-value"><?php echo $profile['type'] ? 'Anchored' : 'Missing'; ?></p>
		</div>
		<div class="vmsb-card vmsb-status-card" style="border-left-color: <?php echo $s['ai_primary'] ? 'var(--good)' : 'var(--crit)'; ?>;">
			<span class="vmsb-status-label">AI Router</span>
			<p class="vmsb-status-value"><?php echo esc_html(ucfirst($s['ai_primary'])); ?> Active</p>
		</div>
		<div class="vmsb-card vmsb-status-card" style="border-left-color: <?php echo $google->is_connected() ? 'var(--good)' : 'var(--crit)'; ?>;">
			<span class="vmsb-status-label">Google Cloud</span>
			<p class="vmsb-status-value"><?php echo $google->is_connected() ? 'Synchronized' : 'Disconnected'; ?></p>
		</div>
		<div class="vmsb-card vmsb-status-card" style="border-left-color: <?php echo $s['god_mode'] ? 'var(--gold)' : 'var(--muted)'; ?>;">
			<span class="vmsb-status-label">Autonomy</span>
			<p class="vmsb-status-value"><?php echo $s['god_mode'] ? 'GOD MODE ON' : 'Manual Only'; ?></p>
		</div>
	</div>

	<div class="vmsb-settings-layout">
		<aside class="vmsb-settings-nav">
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'identity' ? 'is-active' : ''; ?>" data-tab="identity">🏢 Business DNA</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'ai-images' ? 'is-active' : ''; ?>" data-tab="ai-images">🤖 AI & Visuals</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'google' ? 'is-active' : ''; ?>" data-tab="google">🌐 Google Cloud</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'autonomy' ? 'is-active' : ''; ?>" data-tab="autonomy">⚡ God Mode</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'license' ? 'is-active' : ''; ?>" data-tab="license">💳 License & Plans</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'appearance' ? 'is-active' : ''; ?>" data-tab="appearance">🎨 Appearance</button>
			<button type="button" class="vmsb-nav-item <?php echo $active_tab === 'webhooks' ? 'is-active' : ''; ?>" data-tab="webhooks">🔌 Webhooks</button>
		</aside>

		<div class="vmsb-settings-panels">
			<form method="post" action="">
				<?php wp_nonce_field( 'vmsb_save_settings', 'vmsb_settings_nonce' ); ?>

				<!-- IDENTITY PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'identity' ? 'is-active' : ''; ?>" data-panel="identity">
					<section class="vmsb-fieldset">
						<h2>Business Identity</h2>
						<p class="vmsb-note">The brain anchors all content generation to these core definitions.</p>
						<div class="vmsb-form-grid">
							<label>Business Name<input type="text" name="<?php echo esc_attr( $field( 'business_name' ) ); ?>" value="<?php echo esc_attr( $s['business_name'] ); ?>"></label>
							<label>Business Type<input type="text" name="<?php echo esc_attr( $field( 'business_type' ) ); ?>" value="<?php echo esc_attr( $s['business_type'] ); ?>" placeholder="e.g. Luxury Real Estate Agency"></label>
							<label class="vmsb-full">Strategic Description<textarea name="<?php echo esc_attr( $field( 'business_description' ) ); ?>" rows="3"><?php echo esc_textarea( $s['business_description'] ); ?></textarea></label>
							<label class="vmsb-full">Core Services<textarea name="<?php echo esc_attr( $field( 'services' ) ); ?>" rows="2"><?php echo esc_textarea( $s['services'] ); ?></textarea></label>
							<label>Target Audience<input type="text" name="<?php echo esc_attr( $field( 'audience' ) ); ?>" value="<?php echo esc_attr( $s['audience'] ); ?>"></label>
							<label>Service Locations<input type="text" name="<?php echo esc_attr( $field( 'primary_locations' ) ); ?>" value="<?php echo esc_attr( $s['primary_locations'] ); ?>"></label>
							<label>Direct Competitors<input type="text" name="<?php echo esc_attr( $field( 'competitors' ) ); ?>" value="<?php echo esc_attr( $s['competitors'] ); ?>"></label>
							<label>Brand Voice<input type="text" name="<?php echo esc_attr( $field( 'tone' ) ); ?>" value="<?php echo esc_attr( $s['tone'] ); ?>"></label>
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'profile_locked' ) ); ?>" value="1" <?php checked( $s['profile_locked'], 1 ); ?>> Freeze Identity — stop the Brain from auto-updating</label>
						</div>
					</section>
				</section>

				<!-- AI & IMAGES PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'ai-images' ? 'is-active' : ''; ?>" data-panel="ai-images">
					<section class="vmsb-fieldset">
						<div class="vmsb-fieldset-head">
							<h2>AI Intelligence Chain</h2>
							<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" id="vmsb-sync-all-models">Sync All Models</button>
						</div>

						<?php
						$circuits = VMSB_AI_Circuit::get_stats();
						if ( ! empty($circuits) ) : ?>
							<div class="vmsb-circuit-status" style="margin-bottom:25px; padding:15px; background:rgba(255, 77, 77, 0.05); border:1px solid rgba(255, 77, 77, 0.2); border-radius:8px;">
								<h3 style="margin:0 0 10px; font-size:12px; text-transform:uppercase; color:var(--crit);">Router Health (Blocked Providers)</h3>
								<div style="display:flex; flex-wrap:wrap; gap:10px;">
									<?php foreach ( $circuits as $p => $st ) : ?>
										<div class="vmsb-tag vmsb-tag-crit" title="Error: <?php echo esc_attr($st['last_error']); ?>">
											<?php echo esc_html(ucfirst($p)); ?>: <?php echo (int)$st['fails']; ?> Fails
										</div>
									<?php endforeach; ?>
									<button type="button" class="vmsb-mini-btn" data-vmsb="health-reset" style="margin-left:auto;">Reset Circuits</button>
								</div>
							</div>
						<?php endif; ?>

						<div class="vmsb-form-grid">
							<label>Primary Intelligence
								<select name="<?php echo esc_attr( $field( 'ai_primary' ) ); ?>">
									<?php foreach ( array( 'aipuffer' => 'AI Puffer', 'openrouter' => 'OpenRouter', 'gemini' => 'Gemini', 'openai' => 'OpenAI', 'ollama' => 'Ollama', 'omniroute' => 'OmniRoute' ) as $k => $label ) : ?>
										<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['ai_primary'], $k ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<label class="vmsb-full">Resilience Chain (Fallbacks)
								<span class="vmsb-checks">
									<?php
								// aipuffer belongs here too. It was selectable as
								// Primary but absent from the fallback list, so a
								// site running any other provider as primary had no
								// way to fall back to AI Puffer - on this install
								// the single most reliable provider configured.
								foreach ( array( 'aipuffer', 'openrouter', 'gemini', 'openai', 'ollama', 'omniroute' ) as $k ) :
								?>
										<label><input type="checkbox" name="<?php echo esc_attr( $field( 'ai_fallbacks' ) ); ?>[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['ai_fallbacks'], true ) ); ?>> <?php echo esc_html( ucfirst($k) ); ?></label>
									<?php endforeach; ?>
								</span>
							</label>

							<div class="vmsb-full vmsb-provider-row">
								<label>AI Puffer URL<input type="url" name="<?php echo esc_attr( $field( 'aipuffer_url' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_url'] ); ?>" placeholder="Leave empty for local AI Power / AI Engine"></label>
								<label>AI Puffer Key<input type="password" name="<?php echo esc_attr( $field( 'aipuffer_key' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_key'] ); ?>" autocomplete="new-password"></label>
								<label>AI Puffer KB ID<input type="text" name="<?php echo esc_attr( $field( 'aipuffer_kb_id' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_kb_id'] ); ?>"></label>
								<label>AI Puffer Bot ID
									<div class="vmsb-input-group">
										<input type="text" name="<?php echo esc_attr( $field( 'aipuffer_bot_id' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_bot_id'] ); ?>" id="vmsb-aipuffer-bot-id">
										<button type="button" class="vmsb-mini-btn" id="vmsb-sync-bots">Sync</button>
										<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="aipuffer">Test</button>
									</div>
									<select id="vmsb-bot-selector" style="display: none; margin-top: 4px;"></select>
								</label>
							</div>

							<div class="vmsb-full vmsb-provider-row">
								<label>OpenRouter Key<input type="password" name="<?php echo esc_attr( $field( 'openrouter_key' ) ); ?>" value="<?php echo esc_attr( $s['openrouter_key'] ); ?>" autocomplete="new-password"></label>
								<label>OpenRouter Model
									<div class="vmsb-input-group">
										<input type="text" name="<?php echo esc_attr( $field( 'openrouter_model' ) ); ?>" value="<?php echo esc_attr( $s['openrouter_model'] ); ?>" id="vmsb-openrouter-model">
										<button type="button" class="vmsb-mini-btn vmsb-sync-models" data-provider="openrouter">Sync</button>
										<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="openrouter">Test</button>
									</div>
									<?php $or_models = VMSB_Model_Sync::get_models('openrouter'); ?>
									<select class="vmsb-model-selector" data-target="vmsb-openrouter-model" style="<?php echo empty($or_models) ? 'display:none;' : ''; ?> margin-top: 4px;">
										<option value="">Select a model...</option>
										<?php foreach ($or_models as $m) : ?>
											<option value="<?php echo esc_attr($m['id']); ?>" <?php selected($s['openrouter_model'], $m['id']); ?>><?php echo esc_html($m['name']); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>

							<div class="vmsb-full vmsb-provider-row">
								<label>Gemini Key<input type="password" name="<?php echo esc_attr( $field( 'gemini_key' ) ); ?>" value="<?php echo esc_attr( $s['gemini_key'] ); ?>" autocomplete="new-password"></label>
								<label>Gemini Model
									<div class="vmsb-input-group">
										<input type="text" name="<?php echo esc_attr( $field( 'gemini_model' ) ); ?>" value="<?php echo esc_attr( $s['gemini_model'] ); ?>" id="vmsb-gemini-model">
										<button type="button" class="vmsb-mini-btn vmsb-sync-models" data-provider="gemini">Sync</button>
										<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="gemini">Test</button>
									</div>
									<?php $ge_models = VMSB_Model_Sync::get_models('gemini'); ?>
									<select class="vmsb-model-selector" data-target="vmsb-gemini-model" style="<?php echo empty($ge_models) ? 'display:none;' : ''; ?> margin-top: 4px;">
										<option value="">Select a model...</option>
										<?php foreach ($ge_models as $m) : ?>
											<option value="<?php echo esc_attr($m['id']); ?>" <?php selected($s['gemini_model'], $m['id']); ?>><?php echo esc_html($m['name']); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>

							<div class="vmsb-full vmsb-provider-row">
								<label>OpenAI Key<input type="password" name="<?php echo esc_attr( $field( 'openai_key' ) ); ?>" value="<?php echo esc_attr( $s['openai_key'] ); ?>" autocomplete="new-password"></label>
								<label>OpenAI Model
									<div class="vmsb-input-group">
										<input type="text" name="<?php echo esc_attr( $field( 'openai_model' ) ); ?>" value="<?php echo esc_attr( $s['openai_model'] ); ?>" id="vmsb-openai-model">
										<button type="button" class="vmsb-mini-btn vmsb-sync-models" data-provider="openai">Sync</button>
										<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="openai">Test</button>
									</div>
									<?php $oa_models = VMSB_Model_Sync::get_models('openai'); ?>
									<select class="vmsb-model-selector" data-target="vmsb-openai-model" style="<?php echo empty($oa_models) ? 'display:none;' : ''; ?> margin-top: 4px;">
										<option value="">Select a model...</option>
										<?php foreach ($oa_models as $m) : ?>
											<option value="<?php echo esc_attr($m['id']); ?>" <?php selected($s['openai_model'], $m['id']); ?>><?php echo esc_html($m['name']); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>

							<div class="vmsb-full vmsb-provider-row">
								<label>Ollama URL<input type="url" name="<?php echo esc_attr( $field( 'ollama_url' ) ); ?>" value="<?php echo esc_attr( $s['ollama_url'] ); ?>"></label>
								<label>Ollama Model
									<div class="vmsb-input-group">
										<input type="text" name="<?php echo esc_attr( $field( 'ollama_model' ) ); ?>" value="<?php echo esc_attr( $s['ollama_model'] ); ?>" id="vmsb-ollama-model">
										<button type="button" class="vmsb-mini-btn vmsb-sync-models" data-provider="ollama">Sync</button>
										<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="ollama">Test</button>
									</div>
									<?php $ol_models = VMSB_Model_Sync::get_models('ollama'); ?>
									<select class="vmsb-model-selector" data-target="vmsb-ollama-model" style="<?php echo empty($ol_models) ? 'display:none;' : ''; ?> margin-top: 4px;">
										<option value="">Select a model...</option>
										<?php foreach ($ol_models as $m) : ?>
											<option value="<?php echo esc_attr($m['id']); ?>" <?php selected($s['ollama_model'], $m['id']); ?>><?php echo esc_html($m['name']); ?></option>
										<?php endforeach; ?>
									</select>
								</label>
							</div>
						</div>
					</section>

					<section class="vmsb-fieldset" style="margin-top:40px;">
						<div class="vmsb-fieldset-head">
							<h2>Visual Engine</h2>
							<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" id="vmsb-test-image-engine">Test Image Engine</button>
						</div>
						<div class="vmsb-form-grid">
							<label class="vmsb-full">Generation Chain
								<span class="vmsb-checks">
									<?php foreach ( array( 'aipuffer' => 'AI Puffer', 'google' => 'Google Imagen', 'banana' => 'Nano Banana', 'pollinations' => 'Pollinations', 'huggingface' => 'Hugging Face', 'cloudflare' => 'Cloudflare', 'pexels' => 'Pexels' ) as $k => $label ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( $field( 'image_chain' ) ); ?>[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, (array) $s['image_chain'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
								</span>
							</label>
							<label>House Photography Style<input type="text" name="<?php echo esc_attr( $field( 'image_style' ) ); ?>" value="<?php echo esc_attr( $s['image_style'] ); ?>" placeholder="Professional 35mm photography, soft lighting"></label>
							<label>Image Width<input type="number" name="<?php echo esc_attr( $field( 'image_width' ) ); ?>" value="<?php echo esc_attr( $s['image_width'] ); ?>" min="0"></label>
							<label>Image Height<input type="number" name="<?php echo esc_attr( $field( 'image_height' ) ); ?>" value="<?php echo esc_attr( $s['image_height'] ); ?>" min="0"></label>

							<label>Pexels Key<input type="password" name="<?php echo esc_attr( $field( 'pexels_key' ) ); ?>" value="<?php echo esc_attr( $s['pexels_key'] ); ?>" autocomplete="new-password"></label>

							<label>Pollinations URL<input type="url" name="<?php echo esc_attr( $field( 'pollinations_url' ) ); ?>" value="<?php echo esc_attr( $s['pollinations_url'] ); ?>"></label>
							<label>Pollinations Model<input type="text" name="<?php echo esc_attr( $field( 'pollinations_model' ) ); ?>" value="<?php echo esc_attr( $s['pollinations_model'] ); ?>"></label>

							<label>Hugging Face Key<input type="password" name="<?php echo esc_attr( $field( 'huggingface_key' ) ); ?>" value="<?php echo esc_attr( $s['huggingface_key'] ); ?>" autocomplete="new-password"></label>
							<label>Hugging Face Model<input type="text" name="<?php echo esc_attr( $field( 'huggingface_model' ) ); ?>" value="<?php echo esc_attr( $s['huggingface_model'] ); ?>"></label>

							<label>Cloudflare Account ID<input type="text" name="<?php echo esc_attr( $field( 'cloudflare_account_id' ) ); ?>" value="<?php echo esc_attr( $s['cloudflare_account_id'] ); ?>"></label>
							<label>Cloudflare API Token<input type="password" name="<?php echo esc_attr( $field( 'cloudflare_api_token' ) ); ?>" value="<?php echo esc_attr( $s['cloudflare_api_token'] ); ?>" autocomplete="new-password"></label>
							<label>Cloudflare Model<input type="text" name="<?php echo esc_attr( $field( 'cloudflare_model' ) ); ?>" value="<?php echo esc_attr( $s['cloudflare_model'] ); ?>"></label>

							<?php
							/*
							 * Display only - deliberately NO name attribute.
							 *
							 * This was a second <input name="vmsb[gemini_key]">,
							 * identical to the real Gemini Key field in the AI
							 * section above. Two inputs sharing one name means the
							 * browser posts both and PHP keeps only the LAST, so
							 * this box silently overwrote whatever was typed up
							 * there. Since it rendered the mask for an
							 * already-stored key, every save sent the mask - which
							 * the save handler correctly skips as "field left
							 * untouched". The result: typing a Gemini key into the
							 * Gemini Key field did nothing at all, with the form
							 * still reporting "saved".
							 */
							?>
							<label>Google Imagen Key
								<input type="password" value="<?php echo esc_attr( $s['gemini_key'] ); ?>" autocomplete="new-password" readonly disabled>
								<small class="vmsb-note">Shared with Gemini AI - set it in the <strong>Gemini Key</strong> field under Intelligence Routing above. Uses Imagen 3.</small>
							</label>
							<label>Google Imagen Model<input type="text" name="<?php echo esc_attr( $field( 'google_imagen_model' ) ); ?>" value="<?php echo esc_attr( $s['google_imagen_model'] ); ?>" placeholder="imagen-3|imagen-3-nano"></label>

							<label>Nano Banana API Key<input type="password" name="<?php echo esc_attr( $field( 'banana_key' ) ); ?>" value="<?php echo esc_attr( $s['banana_key'] ); ?>" autocomplete="new-password"></label>
							<label>Banana Model Key<input type="text" name="<?php echo esc_attr( $field( 'banana_model' ) ); ?>" value="<?php echo esc_attr( $s['banana_model'] ); ?>"></label>

							<label>AI Puffer Image Provider<input type="text" name="<?php echo esc_attr( $field( 'aipuffer_image_provider' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_image_provider'] ); ?>" placeholder="openai|google|azure|replicate"></label>
							<label>AI Puffer Image Model<input type="text" name="<?php echo esc_attr( $field( 'aipuffer_image_model' ) ); ?>" value="<?php echo esc_attr( $s['aipuffer_image_model'] ); ?>"></label>

							<label class="vmsb-full">ComfyUI URL<input type="url" name="<?php echo esc_attr( $field( 'comfy_url' ) ); ?>" value="<?php echo esc_attr( $s['comfy_url'] ); ?>" disabled placeholder="Not yet connected in this build - see note below"></label>
							<label class="vmsb-full">ComfyUI Workflow (JSON)<textarea name="<?php echo esc_attr( $field( 'comfy_workflow' ) ); ?>" rows="5" disabled><?php echo esc_textarea( $s['comfy_workflow'] ); ?></textarea></label>
							<p class="vmsb-note vmsb-full" style="margin:-10px 0 10px;">ComfyUI isn't wired up in the image-generation chain yet - these fields save but nothing reads them. Disabled to avoid the false impression that filling them in turns it on.</p>
						</div>
					</section>

					<section class="vmsb-fieldset" style="margin-top:40px;">
						<h2>OmniRoute (self-hosted images + video)</h2>
						<p class="vmsb-note" style="margin-bottom:15px;">A self-hosted <a href="https://github.com/diegosouzapw/OmniRoute" target="_blank" rel="noopener">OmniRoute</a> instance. When a URL is set here, it automatically takes priority over the Generation Chain above for image generation - no checkbox needed. It can also be selected as a text provider above (Primary Intelligence / Resilience Chain) since it exposes an OpenAI-compatible chat endpoint.</p>
						<div class="vmsb-form-grid">
							<label class="vmsb-full">OmniRoute URL<input type="url" name="<?php echo esc_attr( $field( 'omniroute_url' ) ); ?>" value="<?php echo esc_attr( $s['omniroute_url'] ); ?>" placeholder="https://your-omniroute-host.example.com"></label>
							<label>OmniRoute API Key<input type="password" name="<?php echo esc_attr( $field( 'omniroute_key' ) ); ?>" value="<?php echo esc_attr( $s['omniroute_key'] ); ?>" autocomplete="new-password"></label>
							<?php
							// Live model list pulled from the instance itself. A gateway
							// fronts hundreds of upstream models and the exact ids are
							// specific to how that instance is provisioned - "flux" is a
							// family, not an id, and sending it produces a model-not-found
							// at generation time with nothing pointing at the cause.
							$vmsb_omni_all = VMSB_Model_Sync::get_models( 'omniroute' );
							$vmsb_omni_img = get_option( 'vmsb_models_omniroute_image', array() );
							?>
							<label>Text Model
								<div class="vmsb-input-group">
									<input type="text" name="<?php echo esc_attr( $field( 'omniroute_text_model' ) ); ?>" value="<?php echo esc_attr( $s['omniroute_text_model'] ); ?>" id="vmsb-omniroute-text-model" placeholder="auto">
									<button type="button" class="vmsb-mini-btn vmsb-sync-models" data-provider="omniroute">Sync</button>
									<button type="button" class="vmsb-mini-btn vmsb-test-provider" data-provider="omniroute">Test</button>
								</div>
								<select class="vmsb-model-selector" data-target="vmsb-omniroute-text-model" style="<?php echo empty( $vmsb_omni_all ) ? 'display:none;' : ''; ?> margin-top:4px;">
									<option value="">Select a model...</option>
									<option value="auto" <?php selected( $s['omniroute_text_model'], 'auto' ); ?>>auto — let OmniRoute route it</option>
									<?php foreach ( $vmsb_omni_all as $vmsb_m ) : ?>
										<option value="<?php echo esc_attr( $vmsb_m['id'] ); ?>" <?php selected( $s['omniroute_text_model'], $vmsb_m['id'] ); ?>><?php echo esc_html( $vmsb_m['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>

							<label>Image Model
								<input type="text" name="<?php echo esc_attr( $field( 'omniroute_image_model' ) ); ?>" value="<?php echo esc_attr( $s['omniroute_image_model'] ); ?>" id="vmsb-omniroute-image-model" placeholder="e.g. black-forest-labs/flux.1-schnell">
								<?php if ( $vmsb_omni_img ) : ?>
									<select class="vmsb-model-selector" data-target="vmsb-omniroute-image-model" style="margin-top:4px;">
										<option value="">Select an image model...</option>
										<?php foreach ( $vmsb_omni_img as $vmsb_m ) : ?>
											<option value="<?php echo esc_attr( $vmsb_m['id'] ); ?>" <?php selected( $s['omniroute_image_model'], $vmsb_m['id'] ); ?>><?php echo esc_html( $vmsb_m['id'] ); ?></option>
										<?php endforeach; ?>
									</select>
									<?php
									$vmsb_img_ids = wp_list_pluck( $vmsb_omni_img, 'id' );
									if ( $s['omniroute_image_model'] && ! in_array( $s['omniroute_image_model'], $vmsb_img_ids, true ) ) :
										?>
										<small class="vmsb-note" style="color:var(--high); display:block; margin-top:4px;">
											&#9888; &ldquo;<?php echo esc_html( $s['omniroute_image_model'] ); ?>&rdquo; is not one of the <?php echo count( $vmsb_img_ids ); ?> image models on your instance. Image generation will fail until you pick one above.
										</small>
									<?php endif; ?>
								<?php else : ?>
									<small class="vmsb-note">Press <strong>Sync</strong> above to load the image models available on your instance.</small>
								<?php endif; ?>
							</label>

							<label>Video Model<input type="text" name="<?php echo esc_attr( $field( 'omniroute_video_model' ) ); ?>" value="<?php echo esc_attr( $s['omniroute_video_model'] ); ?>" placeholder="luma-ray"></label>
						</div>
					</section>
				</section>

				<!-- GOOGLE PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'google' ? 'is-active' : ''; ?>" data-panel="google">
					<section class="vmsb-fieldset">
						<h2>Google Cloud Connectivity</h2>
						<?php if ( $google->is_connected() ) : ?>
							<p class="vmsb-status-line is-good">✅ Search Console & Sheets Connected</p>
						<?php else : ?>
							<p class="vmsb-status-line is-warning">⚠️ Not connected — authorize below to enable Sheets sync and Search Console data.</p>
						<?php endif; ?>
						<div class="vmsb-form-grid">
							<label>Client ID<input type="text" name="<?php echo esc_attr( $field( 'google_client_id' ) ); ?>" value="<?php echo esc_attr( $s['google_client_id'] ); ?>"></label>
							<label>Client Secret<input type="password" name="<?php echo esc_attr( $field( 'google_client_secret' ) ); ?>" value="<?php echo esc_attr( $s['google_client_secret'] ); ?>" autocomplete="new-password"></label>
							<label>GSC Property<input type="text" name="<?php echo esc_attr( $field( 'gsc_property' ) ); ?>" value="<?php echo esc_attr( $s['gsc_property'] ); ?>" placeholder="sc-domain:example.com"></label>
							<label>GA4 ID<input type="text" name="<?php echo esc_attr( $field( 'ga4_property_id' ) ); ?>" value="<?php echo esc_attr( $s['ga4_property_id'] ); ?>"></label>
							<label>Planning Sheet ID<input type="text" name="<?php echo esc_attr( $field( 'sheet_id' ) ); ?>" value="<?php echo esc_attr( $s['sheet_id'] ); ?>"></label>
							<label>Planning Sheet Tab<input type="text" name="<?php echo esc_attr( $field( 'sheet_tab' ) ); ?>" value="<?php echo esc_attr( $s['sheet_tab'] ); ?>" placeholder="Pipeline"></label>
							<label>Bulk Topics Tab<input type="text" name="<?php echo esc_attr( $field( 'bulk_topics_tab' ) ); ?>" value="<?php echo esc_attr( $s['bulk_topics_tab'] ); ?>" placeholder="Bulk Topics"></label>
						</div>
						<?php if ( $s['google_client_id'] ) : ?>
							<a class="vmsb-btn vmsb-btn-gold" style="margin-top:20px;" href="<?php echo esc_url( $google->consent_url() ); ?>"><?php echo $google->is_connected() ? 'Refresh Connection' : 'Authorize Google Access'; ?></a>
						<?php endif; ?>
					</section>
				</section>

				<!-- AUTONOMY PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'autonomy' ? 'is-active' : ''; ?>" data-panel="autonomy">
					<section class="vmsb-fieldset">
						<h2>Autonomous God Mode</h2>
						<p class="vmsb-note">When active, the Brain handles technical debt and content production nightly.</p>
						<div class="vmsb-form-grid">
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'god_mode' ) ); ?>" value="1" <?php checked( $s['god_mode'], 1 ); ?>> Enable God Mode (Nightly Maintenance)</label>
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'require_review' ) ); ?>" value="1" <?php checked( $s['require_review'], 1 ); ?>> Safety: Hold all rewrites for manual approval</label>
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'auto_publish' ) ); ?>" value="1" <?php checked( $s['auto_publish'], 1 ); ?>> Auto-Publish new high-score articles directly</label>
							<label>Publishing Velocity (Posts/Day)<input type="number" name="<?php echo esc_attr( $field( 'posts_per_day' ) ); ?>" value="<?php echo esc_attr( $s['posts_per_day'] ); ?>" min="0" max="24"></label>
							<label>Growth Target (Traffic)<input type="number" name="<?php echo esc_attr( $field( 'growth_target' ) ); ?>" value="<?php echo esc_attr( $s['growth_target'] ); ?>"></label>
							<label>Max AI Calls / Day<input type="number" name="<?php echo esc_attr( $field( 'max_ai_calls_day' ) ); ?>" value="<?php echo esc_attr( $s['max_ai_calls_day'] ); ?>" min="0"></label>
							<label>Max Auto-Fixes / Day<input type="number" name="<?php echo esc_attr( $field( 'max_god_fixes_day' ) ); ?>" value="<?php echo esc_attr( $s['max_god_fixes_day'] ); ?>" min="0"></label>
							<label>Treat Content As Stale After (Days)<input type="number" name="<?php echo esc_attr( $field( 'staleness_threshold_days' ) ); ?>" value="<?php echo esc_attr( $s['staleness_threshold_days'] ); ?>" min="0"></label>
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'auto_growth_mode' ) ); ?>" value="1" <?php checked( $s['auto_growth_mode'], 1 ); ?>> Auto Growth Mode: scan for new topic suggestions on a daily cadence (still requires your approval before anything gets written)</label>
							<label class="vmsb-full">God Mode Fix Categories
								<span class="vmsb-note" style="display:block; margin-bottom:8px;">Which kinds of issues God Mode is allowed to fix automatically. Was DB-only until now - every install ran with the same fixed set with no way to narrow it.</span>
								<span class="vmsb-checks">
									<?php
									/*
									 * These must stay in step with the families in
									 * VMSB_Fixer::rule_in_scope(). 'content' and
									 * 'technical' were live there - with working
									 * handlers behind them - but missing from this
									 * list, so no operator could ever switch them
									 * on. Anything they cover simply never got
									 * fixed. 'technical' carries the
									 * accidental-noindex repair, which matters
									 * more than the rest of this list combined.
									 */
									foreach ( array(
										'meta'           => 'Meta (titles/descriptions)',
										'alt'            => 'Image Alt Text',
										'schema'         => 'Structured Data',
										'internal_links' => 'Internal Linking',
										'taxonomy'       => 'Taxonomy',
										'technical'      => 'Technical (noindex, sitemap, site visibility)',
										'content'        => 'Content rewrites (uses AI, respects Require Review)',
									) as $vmsb_gk => $vmsb_glabel ) :
									?>
										<label><input type="checkbox" name="<?php echo esc_attr( $field( 'god_mode_scope' ) ); ?>[]" value="<?php echo esc_attr( $vmsb_gk ); ?>" <?php checked( in_array( $vmsb_gk, (array) $s['god_mode_scope'], true ) ); ?>> <?php echo esc_html( $vmsb_glabel ); ?></label>
									<?php endforeach; ?>
								</span>
							</label>
						</div>

						<div style="margin-top: 30px; padding: 25px; background: rgba(0,0,0,0.03); border-radius: 12px; border: 1px solid var(--line);">
							<h3 style="margin: 0 0 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold);">Goal Ladder</h3>
							<p class="vmsb-note" style="margin-bottom: 20px;">
								Phase 1 uses the Growth Target and window above. When a phase is met the next opens automatically at 3&times; the target, with a window sized from the growth rate actually measured &mdash; not a fixed guess.
								The controller only ever moves publishing velocity, and only between the bounds below. It never changes Auto-Publish or the review hold, and when a target cannot be reached in its window it holds the maximum sustainable pace and says so rather than escalating.
							</p>
							<div class="vmsb-form-grid">
								<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'goal_autopilot' ) ); ?>" value="1" <?php checked( $s['goal_autopilot'], 1 ); ?>> Run the goal ladder: advance phases and adjust pace automatically</label>
								<label>Growth Window (Days per phase)<input type="number" name="<?php echo esc_attr( $field( 'growth_window' ) ); ?>" value="<?php echo esc_attr( $s['growth_window'] ); ?>" min="1"></label>
								<label>Minimum Posts / Day<input type="number" name="<?php echo esc_attr( $field( 'goal_min_posts' ) ); ?>" value="<?php echo esc_attr( $s['goal_min_posts'] ); ?>" min="0" max="50"></label>
								<label>Maximum Posts / Day<input type="number" name="<?php echo esc_attr( $field( 'goal_max_posts' ) ); ?>" value="<?php echo esc_attr( $s['goal_max_posts'] ); ?>" min="1" max="50"></label>
							</div>
						</div>

						<div style="margin-top: 30px; padding: 25px; background: rgba(0,0,0,0.03); border-radius: 12px; border: 1px solid var(--line);">
							<h3 style="margin: 0 0 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold);">Quality Gate</h3>
							<p class="vmsb-note" style="margin-bottom: 20px;">Every drafted article is scored before it can publish. Below the minimum score it is held for review instead. These thresholds decide what ships.</p>
							<div class="vmsb-form-grid">
								<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'quality_gate' ) ); ?>" value="1" <?php checked( $s['quality_gate'], 1 ); ?>> Score drafts before publishing</label>
								<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'quality_dup_block' ) ); ?>" value="1" <?php checked( $s['quality_dup_block'], 1 ); ?>> Block drafts that duplicate existing content</label>
								<label>Minimum Overall Score (0-100)<input type="number" name="<?php echo esc_attr( $field( 'quality_min_score' ) ); ?>" value="<?php echo esc_attr( $s['quality_min_score'] ); ?>" min="0" max="100"></label>
								<label>Minimum Word Count<input type="number" name="<?php echo esc_attr( $field( 'quality_min_words' ) ); ?>" value="<?php echo esc_attr( $s['quality_min_words'] ); ?>" min="0"></label>
								<label>Minimum Brand Alignment (0-100)<input type="number" name="<?php echo esc_attr( $field( 'quality_min_alignment' ) ); ?>" value="<?php echo esc_attr( $s['quality_min_alignment'] ); ?>" min="0" max="100"></label>
								<label>Minimum Originality (0-100)<input type="number" name="<?php echo esc_attr( $field( 'quality_min_originality' ) ); ?>" value="<?php echo esc_attr( $s['quality_min_originality'] ); ?>" min="0" max="100"></label>
							</div>
						</div>

						<div style="margin-top: 30px; padding: 25px; background: rgba(0,0,0,0.03); border-radius: 12px; border: 1px solid var(--line);">
							<h3 style="margin: 0 0 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold);">Conversion Focus</h3>
							<p class="vmsb-note" style="margin-bottom: 20px;">Used by the ROI engine when it writes and places calls to action.</p>
							<div class="vmsb-form-grid">
								<label>What counts as a conversion?<input type="text" name="<?php echo esc_attr( $field( 'conversion_goal' ) ); ?>" value="<?php echo esc_attr( $s['conversion_goal'] ); ?>" placeholder="e.g. a booking enquiry"></label>
								<label>CTA Style<input type="text" name="<?php echo esc_attr( $field( 'cta_style' ) ); ?>" value="<?php echo esc_attr( $s['cta_style'] ); ?>"></label>
								<label>Average CPC ($)<input type="number" step="0.01" name="<?php echo esc_attr( $field( 'avg_cpc' ) ); ?>" value="<?php echo esc_attr( $s['avg_cpc'] ); ?>" min="0">
									<small class="vmsb-note">Used to value organic traffic in the Boardroom report. The $1.85 default is a generic benchmark - correct it to what a click is actually worth in your market.</small>
								</label>
								<label>Default Order Value ($)<input type="number" step="0.01" name="<?php echo esc_attr( $field( 'default_aov' ) ); ?>" value="<?php echo esc_attr( $s['default_aov'] ); ?>" min="0">
									<small class="vmsb-note">Fallback used for pages with no measured GA4 revenue yet.</small>
								</label>
								<label>Default Conversion Rate (%)<input type="number" step="0.1" name="<?php echo esc_attr( $field( 'default_conversion_rate' ) ); ?>" value="<?php echo esc_attr( $s['default_conversion_rate'] ); ?>" min="0" max="100">
									<small class="vmsb-note">Assumed conversion rate for revenue-opportunity scoring absent real data.</small>
								</label>
							</div>
						</div>

						<div style="margin-top: 30px; padding: 25px; background: rgba(0,0,0,0.03); border-radius: 12px; border: 1px solid var(--line);">
							<h3 style="margin: 0 0 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold);">Feature Control</h3>
							<p class="vmsb-note" style="margin-bottom: 20px;">Individually enable or disable specific AI intelligence modules.</p>
							<div class="vmsb-checks" style="display: flex; gap: 20px; flex-wrap: wrap;">
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_production' ) ); ?>" value="1" <?php checked( $s['feature_production'], 1 ); ?>>
									Auto Writing (New Posts)
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_maintenance' ) ); ?>" value="1" <?php checked( $s['feature_maintenance'], 1 ); ?>>
									Auto Optimization (Existing Posts)
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_aeo' ) ); ?>" value="1" <?php checked( $s['feature_aeo'], 1 ); ?>>
									AEO (AI Search Readiness)
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_entity' ) ); ?>" value="1" <?php checked( $s['feature_entity'], 1 ); ?>>
									Entity Injection
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_silo' ) ); ?>" value="1" <?php checked( $s['feature_silo'], 1 ); ?>>
									Silo & Link Rebuilding
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_schema' ) ); ?>" value="1" <?php checked( $s['feature_schema'], 1 ); ?>>
									Structured Data (Schema)
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_images' ) ); ?>" value="1" <?php checked( $s['feature_images'], 1 ); ?>>
									Visual Engine (Image Gen)
								</label>
								<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
									<input type="checkbox" name="<?php echo esc_attr( $field( 'feature_taxonomy' ) ); ?>" value="1" <?php checked( $s['feature_taxonomy'], 1 ); ?>>
									Taxonomy Lab (Archives)
								</label>
								<?php
								// These agents were all gated on a setting the code read
								// but no screen ever offered, so they could only be
								// changed in the database.
								$vmsb_agent_toggles = array(
									'learning_enabled'     => 'Learning Loop (measure outcomes)',
									'vector_enabled'       => 'Semantic Index (embeddings)',
									'competitor_enabled'   => 'Competitor Tracking',
									'news_enabled'         => 'News Scout (trending topics)',
									'programmatic_enabled' => 'Programmatic SEO',
									'backlink_enabled'     => 'Backlink Outreach',
									'video_enabled'        => 'Video Pipeline (YouTube scripts)',
								);
								foreach ( $vmsb_agent_toggles as $vmsb_key => $vmsb_label ) :
								?>
									<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
										<input type="checkbox" name="<?php echo esc_attr( $field( $vmsb_key ) ); ?>" value="1" <?php checked( $s[ $vmsb_key ], 1 ); ?>>
										<?php echo esc_html( $vmsb_label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<div class="vmsb-form-grid" style="margin-top:20px;">
								<label>Programmatic Pages / Day<input type="number" name="<?php echo esc_attr( $field( 'programmatic_daily_cap' ) ); ?>" value="<?php echo esc_attr( $s['programmatic_daily_cap'] ); ?>" min="0"></label>
							</div>

							<?php
							// Backlink Outreach above stayed permanently inert even when
							// checked "on": is_enabled() also requires a from-name and a
							// valid from-email, and neither had a field anywhere, only a
							// default of empty string - so the toggle could never
							// actually turn the feature on, only look like it did.
							?>
							<div class="vmsb-form-grid" style="margin-top:20px;">
								<label>Outreach From Name<input type="text" name="<?php echo esc_attr( $field( 'outreach_from_name' ) ); ?>" value="<?php echo esc_attr( $s['outreach_from_name'] ); ?>" placeholder="Required for Backlink Outreach to actually send"></label>
								<label>Outreach From Email<input type="email" name="<?php echo esc_attr( $field( 'outreach_from_email' ) ); ?>" value="<?php echo esc_attr( $s['outreach_from_email'] ); ?>" placeholder="you@yourdomain.com"></label>
								<label>Outreach Tone<input type="text" name="<?php echo esc_attr( $field( 'outreach_tone' ) ); ?>" value="<?php echo esc_attr( $s['outreach_tone'] ); ?>" placeholder="brief, human, no hype"></label>
							</div>
						</div>

						<div style="margin-top: 30px; padding: 25px; background: rgba(0,0,0,0.03); border-radius: 12px; border: 1px solid var(--line);">
							<h3 style="margin: 0 0 15px; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; color: var(--gold);">Optimization Guardrails</h3>
							<p class="vmsb-note" style="margin-bottom: 20px;">Select the post types the Brain is allowed to optimize. We recommend starting with just 'Posts'.</p>

							<div class="vmsb-checks" style="display: flex; gap: 20px; flex-wrap: wrap;">
								<?php
								$all_types = get_post_types( array( 'public' => true ), 'objects' );
								$excluded  = array( 'attachment', 'elementor_library', 'ae_global_templates' );
								foreach ( $all_types as $type ) :
									if ( in_array( $type->name, $excluded ) ) continue;
								?>
									<label style="display: flex; align-items: center; gap: 10px; font-weight: 600; color: var(--text); cursor: pointer;">
										<input type="checkbox" name="<?php echo esc_attr( $field( 'safe_post_types' ) ); ?>[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['safe_post_types'], true ) ); ?>>
										<?php echo esc_html( $type->label ); ?>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</section>
				</section>

				<!-- LICENSE & PLANS PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'license' ? 'is-active' : ''; ?>" data-panel="license">
					<?php
					$current_plan = VMSB_License::plan();
					$checkout_base = 'https://vmstudio.digital/checkout/';
					$site_url = urlencode( home_url() );
					?>
					<section class="vmsb-fieldset">
						<h2>License & Subscription Plans</h2>
						<p class="vmsb-note">Current Active Tier: <strong style="color:var(--gold); text-transform:uppercase;"><?php echo esc_html($current_plan); ?></strong></p>

						<div class="vmsb-plans-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-top: 20px;">
							<div class="vmsb-card <?php echo 'free' === $current_plan ? 'is-active' : ''; ?>" style="padding: 24px; border-radius: 14px; position: relative;">
								<?php if('free' === $current_plan): ?><span class="vmsb-tag vmsb-tag-gold" style="position: absolute; top: 12px; right: 12px;">ACTIVE PLAN</span><?php endif; ?>
								<h3 style="margin: 0; font-family: var(--serif); font-size: 20px;">Starter</h3>
								<div style="font-size: 28px; font-weight: 800; margin: 12px 0; color: var(--gold);">FREE <span style="font-size: 13px; color: var(--muted); font-weight: normal;">/ forever</span></div>
								<ul style="list-style: none; margin: 16px 0; padding: 0; font-size: 13px; line-height: 1.8; color: var(--text);">
									<li>✓ 3 Posts per day</li>
									<li>✓ 100 Keywords tracking</li>
									<li>✓ Basic Technical Fixes</li>
								</ul>
							</div>

							<div class="vmsb-card <?php echo 'pro' === $current_plan ? 'is-active' : ''; ?>" style="padding: 24px; border-radius: 14px; position: relative;">
								<?php if('pro' === $current_plan): ?><span class="vmsb-tag vmsb-tag-gold" style="position: absolute; top: 12px; right: 12px;">ACTIVE PLAN</span><?php endif; ?>
								<h3 style="margin: 0; font-family: var(--serif); font-size: 20px;">Pro Authority</h3>
								<div style="font-size: 28px; font-weight: 800; margin: 12px 0; color: var(--gold);">₹2,499 <span style="font-size: 13px; color: var(--muted); font-weight: normal;">/ month</span></div>
								<ul style="list-style: none; margin: 16px 0; padding: 0; font-size: 13px; line-height: 1.8; color: var(--text);">
									<li>✓ 15 Posts per day</li>
									<li>✓ 1,000 Keywords tracking</li>
									<li>✓ Trend Scout 2026 RSS</li>
									<li>✓ Competitor Hijacking (Thief)</li>
								</ul>
								<a href="<?php echo $checkout_base; ?>?plan=pro&product=vm-seo-brain&site=<?php echo $site_url; ?>" target="_blank" class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" style="margin-top: 10px; text-align: center;">
									<?php echo 'pro' === $current_plan ? 'Active' : 'Upgrade to Pro →'; ?>
								</a>
							</div>
						</div>
					</section>
				</section>

				<!-- APPEARANCE PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'appearance' ? 'is-active' : ''; ?>" data-panel="appearance">
					<section class="vmsb-fieldset">
						<h2>UI Appearance</h2>
						<div class="vmsb-form-grid">
							<label>Theme Mode
								<select name="<?php echo esc_attr( $field( 'theme_mode' ) ); ?>">
									<option value="dark" <?php selected( $s['theme_mode'], 'dark' ); ?>>Deep Space (Dark)</option>
									<option value="lite" <?php selected( $s['theme_mode'], 'lite' ); ?>>Professional (Light)</option>
								</select>
							</label>
							<label>Language Context<input type="text" name="<?php echo esc_attr( $field( 'language' ) ); ?>" value="<?php echo esc_attr( $s['language'] ); ?>">
								<small class="vmsb-note">The site-wide default every generated piece writes in. Content Plan's Bulk Import can override this per batch.</small>
							</label>
							<label>Country Target<input type="text" name="<?php echo esc_attr( $field( 'country' ) ); ?>" value="<?php echo esc_attr( $s['country'] ); ?>"></label>
							<label>Currency<input type="text" name="<?php echo esc_attr( $field( 'currency' ) ); ?>" value="<?php echo esc_attr( $s['currency'] ); ?>" placeholder="USD">
								<small class="vmsb-note">Used when generated content quotes prices. Defaulted to INR before this field existed - check it matches your business.</small>
							</label>
						</div>
					</section>
				</section>

				<!-- WEBHOOKS PANEL -->
				<section class="vmsb-panel <?php echo $active_tab === 'webhooks' ? 'is-active' : ''; ?>" data-panel="webhooks">
					<section class="vmsb-fieldset">
						<h2>Outbound Webhooks</h2>
						<p class="vmsb-note">Notify an external tool (Zapier, Make, a custom script) when the brain publishes, holds, or fails a piece of content — instead of it having to poll the REST API.</p>
						<div class="vmsb-form-grid">
							<label class="vmsb-check"><input type="checkbox" name="<?php echo esc_attr( $field( 'webhook_enabled' ) ); ?>" value="1" <?php checked( $s['webhook_enabled'], 1 ); ?>> Enable outbound webhooks</label>
							<label class="vmsb-full">Webhook URL
								<div class="vmsb-input-group">
									<input type="url" name="<?php echo esc_attr( $field( 'webhook_url' ) ); ?>" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" placeholder="https://hooks.zapier.com/hooks/catch/...">
									<button type="button" class="vmsb-mini-btn" data-vmsb="webhook-test">Send Test</button>
								</div>
								<small class="vmsb-note">Save Settings first, then test - the test sends to whatever URL is currently saved, not what's still unsaved in the box above.</small>
							</label>
							<label class="vmsb-full">Events to send
								<span class="vmsb-checks">
									<?php foreach ( VMSB_Webhooks::EVENTS as $event => $description ) : ?>
										<label><input type="checkbox" name="<?php echo esc_attr( $field( 'webhook_events' ) ); ?>[]" value="<?php echo esc_attr( $event ); ?>" <?php checked( in_array( $event, (array) $s['webhook_events'], true ) ); ?>> <?php echo esc_html( $description ); ?></label>
									<?php endforeach; ?>
								</span>
							</label>
						</div>
					</section>
				</section>

				<div class="vmsb-settings-footer" style="margin-top:40px; padding-top:20px; border-top:1px solid var(--line);">
					<button type="submit" class="vmsb-btn vmsb-btn-gold">Save Global Configuration</button>
				</div>
			</form>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>

