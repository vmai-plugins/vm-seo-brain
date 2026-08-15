<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO Lab Bucket — VM SEO Brain X.
 *
 * Jobs: Keywords, Rankings, Competitors, Technical SEO, Internal Links.
 */

?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Technical & Structural Optimization</p>
			<h1>SEO Lab</h1>
			<p class="vmsb-sub">Deep-level experimentation and structural hardening.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="research">Sync Keywords</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="scan">Technical Audit</button>
		</div>
	</header>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="keywords">🔍 Keywords</button>
		<button class="vmsb-tab" data-tab="competitors">🛡️ Competitors</button>
		<button class="vmsb-tab" data-tab="technical">⚙️ Technical SEO</button>
		<button class="vmsb-tab" data-tab="links">🔗 Internal Links</button>
	</div>

	<!-- KEYWORDS -->
	<div class="vmsb-panel is-active" data-panel="keywords">
		<?php
		if (!defined('VMSB_NESTED')) define('VMSB_NESTED', true);
		include VMSB_DIR . 'admin/views/keywords.php';
		?>
	</div>

	<!-- COMPETITORS -->
	<div class="vmsb-panel" data-panel="competitors">
		<?php include VMSB_DIR . 'admin/views/competitive.php'; ?>
	</div>

	<!-- TECHNICAL -->
	<div class="vmsb-panel" data-panel="technical">
		<?php include VMSB_DIR . 'admin/views/issues.php'; ?>
	</div>

	<!-- INTERNAL LINKS -->
	<div class="vmsb-panel" data-panel="links">
		<?php include VMSB_DIR . 'admin/views/silo.php'; ?>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
