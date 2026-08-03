<?php
defined( 'ABSPATH' ) || exit;

$stats = class_exists( 'VMSB_Vector_Store' ) ? VMSB_Vector_Store::stats() : array( 'total' => 0, 'pending' => 0, 'provider' => 'local', 'model' => '-', 'last_indexed' => null );

$total_targets = (int) $stats['total'] + (int) $stats['pending'];
$coverage      = $total_targets > 0 ? round( $stats['total'] / $total_targets * 100 ) : 100;
?>
<div class="wrap vmsb vmsb-memory">
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

	<div class="vmsb-console" id="vmsb-output" hidden>
		
	</div>

	<section class="vmsb-section">
		<h2>How the brain uses this</h2>
		<ul class="vmsb-bullets">
			<li><strong>Before publishing</strong> — a new draft is compared against every existing page by meaning. A near-duplicate is blocked, not just flagged.</li>
			<li><strong>Internal linking</strong> — the silo builder picks link targets that are actually related, instead of anything sharing a category.</li>
			<li><strong>Cannibalisation</strong> — pages that are semantically close <em>and</em> competing for the same query surface as issues to merge or differentiate.</li>
		</ul>
	</section>
</div>
