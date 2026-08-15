<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

?>
<?php if ( ! $vmsb_is_nested ) : ?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Artificial Reasoning & Context</p>
			<h1>Intelligence Lab</h1>
			<p class="vmsb-sub">Inspecting the Brain's semantic understanding and knowledge base.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="understand">Force Re-read Site</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="graph-sync">Sync Knowledge Graph</button>
		</div>
	</header>
	<span class="wp-header-end"></span>
<?php endif; ?>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="brain">🧠 Brain State</button>
		<button class="vmsb-tab" data-tab="knowledge">📚 Knowledge</button>
		<button class="vmsb-tab" data-tab="entities">🏷️ Entities</button>
		<button class="vmsb-tab" data-tab="clusters">🗂️ Clusters</button>
	</div>

	<!-- BRAIN STATE -->
	<div class="vmsb-panel is-active" data-panel="brain">
		<?php
		if (!defined('VMSB_NESTED')) define('VMSB_NESTED', true);
		include VMSB_DIR . 'admin/views/memory.php';
		?>
	</div>

	<!-- KNOWLEDGE -->
	<div class="vmsb-panel" data-panel="knowledge">
		<?php include VMSB_DIR . 'admin/views/subviews/graph_visualizer.php'; ?>
	</div>

	<!-- ENTITIES -->
	<div class="vmsb-panel" data-panel="entities">
		<?php include VMSB_DIR . 'admin/views/subviews/entities.php'; ?>
	</div>

	<!-- CLUSTERS -->
	<div class="vmsb-panel" data-panel="clusters">
		<?php include VMSB_DIR . 'admin/views/silo.php'; ?>
	</div>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>

