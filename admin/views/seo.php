<?php
defined( 'ABSPATH' ) || exit;

/**
 * SEO Lab Hub — VM SEO Brain.
 * Unifies Keywords & Rankings, Technical SEO Fixer, Internal Link Silos, Competitor Tracking, and Taxonomy Lab.
 */

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'keywords';
if ( ! in_array( $active_tab, array( 'keywords', 'technical', 'links', 'competitors', 'taxonomy' ), true ) ) {
	$active_tab = 'keywords';
}
?>

<div class="vmsb-seo-hub">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Technical & Structural Optimization</p>
			<h1>SEO Lab</h1>
			<p class="vmsb-sub">Keyword universe intelligence, one-click technical fixes, PageRank link silos, and competitive tracking.</p>
		</div>
		<div class="vmsb-head-actions">
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="research">🔍 Sync Keywords</button>
			<button type="button" class="vmsb-btn vmsb-btn-gold" data-vmsb="scan">🛠️ Run Technical Audit</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<!-- NAVIGATION TABS -->
	<div class="vmsb-tabs" style="margin-top: 24px;">
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'keywords' ? 'is-active' : ''; ?>" data-tab="keywords">
			🔍 Keywords & Rankings
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'technical' ? 'is-active' : ''; ?>" data-tab="technical">
			⚙️ Technical Fixer
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'links' ? 'is-active' : ''; ?>" data-tab="links">
			🔗 Internal Links & Silos
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'competitors' ? 'is-active' : ''; ?>" data-tab="competitors">
			🛡️ Competitor Intel
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'taxonomy' ? 'is-active' : ''; ?>" data-tab="taxonomy">
			🏷️ Taxonomy Lab
		</button>
	</div>

	<!-- TAB 1: KEYWORDS -->
	<div class="vmsb-panel <?php echo $active_tab === 'keywords' ? 'is-active' : ''; ?>" data-panel="keywords">
		<?php
		if ( ! defined( 'VMSB_NESTED' ) ) define( 'VMSB_NESTED', true );
		include VMSB_DIR . 'admin/views/keywords.php';
		?>
	</div>

	<!-- TAB 2: TECHNICAL FIXER -->
	<div class="vmsb-panel <?php echo $active_tab === 'technical' ? 'is-active' : ''; ?>" data-panel="technical">
		<?php include VMSB_DIR . 'admin/views/issues.php'; ?>
	</div>

	<!-- TAB 3: INTERNAL LINKS & SILOS -->
	<div class="vmsb-panel <?php echo $active_tab === 'links' ? 'is-active' : ''; ?>" data-panel="links">
		<?php include VMSB_DIR . 'admin/views/silo.php'; ?>
	</div>

	<!-- TAB 4: COMPETITORS -->
	<div class="vmsb-panel <?php echo $active_tab === 'competitors' ? 'is-active' : ''; ?>" data-panel="competitors">
		<?php include VMSB_DIR . 'admin/views/competitive.php'; ?>
	</div>

	<!-- TAB 5: TAXONOMY LAB -->
	<div class="vmsb-panel <?php echo $active_tab === 'taxonomy' ? 'is-active' : ''; ?>" data-panel="taxonomy">
		<?php include VMSB_DIR . 'admin/views/taxonomy.php'; ?>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
