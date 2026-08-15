<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$stats = class_exists( 'VMSB_Vector_Store' ) ? VMSB_Vector_Store::stats() : array( 'total' => 0, 'pending' => 0, 'provider' => 'local', 'model' => '-', 'last_indexed' => null );

$total_targets = (int) $stats['total'] + (int) $stats['pending'];
$coverage      = $total_targets > 0 ? round( $stats['total'] / $total_targets * 100 ) : 100;
?>

<?php if ( ! $vmsb_is_nested ) : ?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Semantic memory</p>
			<h1>Memory</h1>
			<p class="vmsb-sub">What the brain can reason about by meaning, not just keywords. This powers duplicate detection, related-post linking, and cannibalisation checks.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="index-vectors" data-body='{"limit":25}'>Index next batch</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="rebuild-index" data-confirm="Clear the whole index and re-embed every page? Do this after changing the embedding model.">Rebuild</button>
		</div>
	</header>
	<span class="wp-header-end"></span>
<?php endif; ?>

	<div class="vmsb-cards">
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo (int) $stats['total']; ?></span>
			<span class="vmsb-card-label">Pages indexed</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo (int) $coverage; ?>%</span>
			<span class="vmsb-card-label">Coverage</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo (int) $stats['pending']; ?></span>
			<span class="vmsb-card-label">Waiting to index</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num vmsb-card-sm"><?php echo esc_html( $stats['provider'] ); ?></span>
			<span class="vmsb-card-label"><?php echo esc_html( $stats['model'] ); ?></span>
		</div>
	</div>

	<?php if ( 'local' === $stats['provider'] ) : ?>
		<div class="vmsb-note">
			<strong>Running on the local embedding driver.</strong> No API cost, but weaker at spotting paraphrase than a real model.
			Add an OpenAI or Gemini key (or point Ollama at <code>nomic-embed-text</code>) in Settings, then Rebuild. Thresholds auto-calibrate to whichever model you use.
		</div>
	<?php endif; ?>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>

