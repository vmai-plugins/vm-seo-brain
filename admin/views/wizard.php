<?php
defined( 'ABSPATH' ) || exit;

/**
 * 4-Step Setup & Onboarding Wizard — VM SEO Brain.
 * Provides a guided, frictionless 2-minute setup for the AI engine.
 */

$s        = VMSB_Settings::masked();
$brain    = new VMSB_Brain();
$profile  = $brain->profile();

$site_name        = ! empty( $s['business_name'] ) ? $s['business_name'] : get_bloginfo( 'name' );
$site_desc        = ! empty( $s['business_description'] ) ? $s['business_description'] : get_bloginfo( 'description' );
$site_type        = ! empty( $s['business_type'] ) ? $s['business_type'] : '';
$site_services    = ! empty( $s['services'] ) ? $s['services'] : '';
$site_audience    = ! empty( $s['audience'] ) ? $s['audience'] : '';
$site_tone        = ! empty( $s['tone'] ) ? $s['tone'] : 'Professional & Authoritative';
$primary_ai       = ! empty( $s['ai_primary'] ) ? $s['ai_primary'] : 'gemini';

// Auto-suggest services from post categories if empty
if ( empty( $site_services ) ) {
	$cats = get_categories( array( 'number' => 6, 'hide_empty' => false ) );
	if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) {
		$site_services = implode( ', ', wp_list_pluck( $cats, 'name' ) );
	}
}
?>

<div class="vmsb-wizard-container">
	<div class="vmsb-wizard-header">
		<div class="vmsb-wizard-badge">⚡ Quick Setup Wizard</div>
		<h1 class="vmsb-wizard-title">Calibrate Your SEO Brain in 2 Minutes</h1>
		<p class="vmsb-wizard-subtitle">Follow these 4 simple steps to connect your AI intelligence, configure your site's business DNA, and unleash autonomous SEO growth.</p>

		<!-- Steps Progress Bar -->
		<div class="vmsb-wizard-stepper" id="vmsb-wizard-stepper">
			<div class="vmsb-step-node is-active" data-step="1">
				<div class="vmsb-step-num">1</div>
				<span class="vmsb-step-title">Connect AI</span>
			</div>
			<div class="vmsb-step-line"></div>
			<div class="vmsb-step-node" data-step="2">
				<div class="vmsb-step-num">2</div>
				<span class="vmsb-step-title">Business DNA</span>
			</div>
			<div class="vmsb-step-line"></div>
			<div class="vmsb-step-node" data-step="3">
				<div class="vmsb-step-num">3</div>
				<span class="vmsb-step-title">Autonomy Level</span>
			</div>
			<div class="vmsb-step-line"></div>
			<div class="vmsb-step-node" data-step="4">
				<div class="vmsb-step-num">4</div>
				<span class="vmsb-step-title">Initial Scan</span>
			</div>
		</div>
	</div>

	<form id="vmsb-wizard-form" class="vmsb-wizard-form">
		<!-- STEP 1: CONNECT AI -->
		<div class="vmsb-wizard-step is-active" data-step="1">
			<div class="vmsb-step-header">
				<h2>Step 1: Connect Your AI Model</h2>
				<p class="vmsb-note">Choose the primary AI intelligence provider for content writing, SEO gap analysis, and keyword clustering.</p>
			</div>

			<div class="vmsb-provider-selector">
				<label class="vmsb-provider-card <?php echo $primary_ai === 'gemini' ? 'is-selected' : ''; ?>">
					<input type="radio" name="wizard_ai_primary" value="gemini" <?php checked( $primary_ai, 'gemini' ); ?>>
					<div class="vmsb-provider-icon">✨</div>
					<div class="vmsb-provider-meta">
						<strong>Google Gemini</strong>
						<span>Fast, cost-effective & massive context window</span>
					</div>
					<span class="vmsb-badge-recommended">Recommended</span>
				</label>

				<label class="vmsb-provider-card <?php echo $primary_ai === 'openai' ? 'is-selected' : ''; ?>">
					<input type="radio" name="wizard_ai_primary" value="openai" <?php checked( $primary_ai, 'openai' ); ?>>
					<div class="vmsb-provider-icon">🟢</div>
					<div class="vmsb-provider-meta">
						<strong>OpenAI</strong>
						<span>GPT-4o, GPT-4o-mini industry standard</span>
					</div>
				</label>

				<label class="vmsb-provider-card <?php echo $primary_ai === 'openrouter' ? 'is-selected' : ''; ?>">
					<input type="radio" name="wizard_ai_primary" value="openrouter" <?php checked( $primary_ai, 'openrouter' ); ?>>
					<div class="vmsb-provider-icon">🔀</div>
					<div class="vmsb-provider-meta">
						<strong>OpenRouter</strong>
						<span>Claude 3.5 Sonnet, DeepSeek, Llama 3 & more</span>
					</div>
				</label>

				<label class="vmsb-provider-card <?php echo $primary_ai === 'aipuffer' ? 'is-selected' : ''; ?>">
					<input type="radio" name="wizard_ai_primary" value="aipuffer" <?php checked( $primary_ai, 'aipuffer' ); ?>>
					<div class="vmsb-provider-icon">🐡</div>
					<div class="vmsb-provider-meta">
						<strong>AI Puffer</strong>
						<span>Dedicated high-throughput SEO neural engine</span>
					</div>
				</label>

				<label class="vmsb-provider-card <?php echo $primary_ai === 'ollama' ? 'is-selected' : ''; ?>">
					<input type="radio" name="wizard_ai_primary" value="ollama" <?php checked( $primary_ai, 'ollama' ); ?>>
					<div class="vmsb-provider-icon">🦙</div>
					<div class="vmsb-provider-meta">
						<strong>Ollama (Local)</strong>
						<span>Self-hosted on your local/VPS server (Free)</span>
					</div>
				</label>
			</div>

			<div class="vmsb-wizard-fields">
				<div class="vmsb-field-group">
					<label for="wizard_api_key">API Key or Endpoint URL <span class="vmsb-req">*</span></label>
					<div class="vmsb-input-test-wrap">
						<input type="password" id="wizard_api_key" name="wizard_api_key" placeholder="Enter your API Key..." value="<?php
							if ( $primary_ai === 'gemini' ) echo esc_attr( $s['gemini_key'] );
							elseif ( $primary_ai === 'openai' ) echo esc_attr( $s['openai_key'] );
							elseif ( $primary_ai === 'openrouter' ) echo esc_attr( $s['openrouter_key'] );
							elseif ( $primary_ai === 'aipuffer' ) echo esc_attr( $s['aipuffer_key'] );
							elseif ( $primary_ai === 'ollama' ) echo esc_attr( $s['ollama_url'] );
						?>">
						<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" id="vmsb-wizard-test-ai">
							⚡ Test Connection
						</button>
					</div>
					<p class="vmsb-field-hint" id="wizard-key-hint">Get your free Gemini API key from <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.</p>
					<div id="vmsb-wizard-test-result" class="vmsb-test-result" hidden></div>
				</div>

				<div class="vmsb-field-group">
					<label for="wizard_model">Model Identifier</label>
					<input type="text" id="wizard_model" name="wizard_model" value="<?php
						if ( $primary_ai === 'gemini' ) echo esc_attr( $s['gemini_model'] ?: 'gemini-3.6-flash' );
						elseif ( $primary_ai === 'openai' ) echo esc_attr( $s['openai_model'] ?: 'gpt-4o-mini' );
						elseif ( $primary_ai === 'openrouter' ) echo esc_attr( $s['openrouter_model'] ?: 'anthropic/claude-3.5-sonnet' );
						elseif ( $primary_ai === 'aipuffer' ) echo esc_attr( $s['aipuffer_bot_id'] );
						elseif ( $primary_ai === 'ollama' ) echo esc_attr( $s['ollama_model'] ?: 'llama3.1' );
					?>" placeholder="e.g. gemini-3.6-flash">
				</div>
			</div>

			<div class="vmsb-wizard-actions">
				<button type="button" class="vmsb-btn vmsb-btn-gold vmsb-step-next" data-next="2">
					Continue to Business DNA →
				</button>
			</div>
		</div>

		<!-- STEP 2: BUSINESS PROFILE -->
		<div class="vmsb-wizard-step" data-step="2">
			<div class="vmsb-step-header">
				<h2>Step 2: Define Your Business DNA</h2>
				<p class="vmsb-note">The brain uses this context to anchor all content strategies, keyword discovery, and internal links so it never produces generic AI slop.</p>
			</div>

			<div class="vmsb-form-grid">
				<div class="vmsb-field-group">
					<label for="wizard_biz_name">Business or Website Name <span class="vmsb-req">*</span></label>
					<input type="text" id="wizard_biz_name" name="wizard_biz_name" value="<?php echo esc_attr( $site_name ); ?>" required>
				</div>

				<div class="vmsb-field-group">
					<label for="wizard_biz_type">Niche / Business Type <span class="vmsb-req">*</span></label>
					<input type="text" id="wizard_biz_type" name="wizard_biz_type" value="<?php echo esc_attr( $site_type ); ?>" placeholder="e.g. B2B SaaS, Digital Agency, Tech Blog, Law Firm" required>
				</div>

				<div class="vmsb-field-group vmsb-full">
					<label for="wizard_biz_desc">Strategic Description</label>
					<textarea id="wizard_biz_desc" name="wizard_biz_desc" rows="3" placeholder="Explain what your business does, what problem it solves, and why people choose you..."><?php echo esc_textarea( $site_desc ); ?></textarea>
				</div>

				<div class="vmsb-field-group vmsb-full">
					<label for="wizard_services">Core Services / Main Content Topics <span class="vmsb-req">*</span></label>
					<input type="text" id="wizard_services" name="wizard_services" value="<?php echo esc_attr( $site_services ); ?>" placeholder="e.g. Technical SEO, Content Marketing, Link Building, Web Design">
					<p class="vmsb-field-hint">Comma-separated list of your key offerings or topics.</p>
				</div>

				<div class="vmsb-field-group">
					<label for="wizard_audience">Target Audience</label>
					<input type="text" id="wizard_audience" name="wizard_audience" value="<?php echo esc_attr( $site_audience ); ?>" placeholder="e.g. Small business owners, CTOs, Marketing directors">
				</div>

				<div class="vmsb-field-group">
					<label for="wizard_tone">Brand Tone of Voice</label>
					<select id="wizard_tone" name="wizard_tone">
						<option value="Professional & Authoritative" <?php selected( $site_tone, 'Professional & Authoritative' ); ?>>Professional & Authoritative</option>
						<option value="Conversational & Friendly" <?php selected( $site_tone, 'Conversational & Friendly' ); ?>>Conversational & Friendly</option>
						<option value="Technical & Analytical" <?php selected( $site_tone, 'Technical & Analytical' ); ?>>Technical & Analytical</option>
						<option value="Direct & Bold" <?php selected( $site_tone, 'Direct & Bold' ); ?>>Direct & Bold</option>
						<option value="Luxury & Editorial" <?php selected( $site_tone, 'Luxury & Editorial' ); ?>>Luxury & Editorial</option>
					</select>
				</div>
			</div>

			<div class="vmsb-wizard-actions">
				<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-step-prev" data-prev="1">← Back</button>
				<button type="button" class="vmsb-btn vmsb-btn-gold vmsb-step-next" data-next="3">Continue to Autonomy Mode →</button>
			</div>
		</div>

		<!-- STEP 3: AUTONOMY MODE -->
		<div class="vmsb-wizard-step" data-step="3">
			<div class="vmsb-step-header">
				<h2>Step 3: Select Your Autonomy Level</h2>
				<p class="vmsb-note">Decide how much control you want over generated articles, internal links, and technical fixes.</p>
			</div>

			<div class="vmsb-autonomy-grid">
				<label class="vmsb-autonomy-card is-selected">
					<input type="radio" name="wizard_autonomy_mode" value="assisted" checked>
					<div class="vmsb-autonomy-icon">🛡️</div>
					<div class="vmsb-autonomy-content">
						<h3>Assisted Mode <span class="vmsb-tag vmsb-tag-gold">Recommended</span></h3>
						<p>The Brain discovers keyword gaps, plans authority topic clusters, and prepares draft articles. You review and approve in 1 click before anything is published.</p>
						<ul class="vmsb-feature-list">
							<li>✔ Human-in-the-loop review queue</li>
							<li>✔ Complete control over editorial schedule</li>
							<li>✔ Safe for established commercial brands</li>
						</ul>
					</div>
				</label>

				<label class="vmsb-autonomy-card">
					<input type="radio" name="wizard_autonomy_mode" value="autopilot">
					<div class="vmsb-autonomy-icon">⚡</div>
					<div class="vmsb-autonomy-content">
						<h3>Full Autopilot Mode</h3>
						<p>The Brain autonomously schedules, writes, attaches featured visuals, wires internal link silos, and publishes on schedule without manual intervention.</p>
						<ul class="vmsb-feature-list">
							<li>✔ 100% hands-free content production</li>
							<li>✔ God Mode automatic technical fixes</li>
							<li>✔ Maximum SEO velocity & gap coverage</li>
						</ul>
					</div>
				</label>
			</div>

			<div class="vmsb-wizard-actions">
				<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-step-prev" data-prev="2">← Back</button>
				<button type="button" class="vmsb-btn vmsb-btn-gold vmsb-step-next" data-next="4">Continue to Calibration →</button>
			</div>
		</div>

		<!-- STEP 4: INITIAL CALIBRATION -->
		<div class="vmsb-wizard-step" data-step="4">
			<div class="vmsb-step-header">
				<h2>Step 4: Launch Calibration & First Scan</h2>
				<p class="vmsb-note">Review your configuration summary and trigger the initial site understanding and link indexing.</p>
			</div>

			<div class="vmsb-calibration-summary">
				<div class="vmsb-summary-card">
					<span class="vmsb-summary-label">AI Engine</span>
					<strong id="summary-ai-provider">Google Gemini</strong>
				</div>
				<div class="vmsb-summary-card">
					<span class="vmsb-summary-label">Site Identity</span>
					<strong id="summary-biz-name"><?php echo esc_html( $site_name ); ?></strong>
				</div>
				<div class="vmsb-summary-card">
					<span class="vmsb-summary-label">Autonomy Level</span>
					<strong id="summary-autonomy">Assisted Mode</strong>
				</div>
			</div>

			<!-- Live Progress Checklist -->
			<div id="vmsb-calibration-box" class="vmsb-calibration-box" hidden>
				<h3 style="margin: 0 0 15px; font-size: 15px; color: var(--gold-soft);">Calibrating SEO Engine...</h3>
				<div class="vmsb-check-item" id="cal-step-1"><span class="vmsb-spin">⏳</span> Saving configurations & securing API credentials...</div>
				<div class="vmsb-check-item" id="cal-step-2"><span class="vmsb-spin">⏳</span> Mapping internal link graph & PageRank distribution...</div>
				<div class="vmsb-check-item" id="cal-step-3"><span class="vmsb-spin">⏳</span> Synthesizing Business DNA & topical authority clusters...</div>
				<div class="vmsb-check-item" id="cal-step-4"><span class="vmsb-spin">⏳</span> Discovering initial keyword opportunities & gaps...</div>
			</div>

			<div id="vmsb-calibration-success" class="vmsb-calibration-success" hidden>
				<div class="vmsb-success-icon">🎉</div>
				<h2>Calibration Complete!</h2>
				<p>Your VM SEO Brain is now calibrated and actively monitoring your website. You are ready to explore your overview dashboard and start producing high-ranking content.</p>
				<div style="margin-top: 25px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb' ) ); ?>" class="vmsb-btn vmsb-btn-gold vmsb-btn-lg">
						Enter SEO Brain Dashboard →
					</a>
				</div>
			</div>

			<div class="vmsb-wizard-actions" id="vmsb-launch-actions">
				<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-step-prev" data-prev="3">← Back</button>
				<button type="button" class="vmsb-btn vmsb-btn-gold vmsb-btn-lg" id="vmsb-wizard-launch-btn">
					🚀 Save & Launch SEO Brain
				</button>
			</div>
		</div>
	</form>
</div>
