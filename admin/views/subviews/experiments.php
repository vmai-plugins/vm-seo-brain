<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subview: CTR Experiments.
 */

$ctr_engine = new VMSB_CTR();
$running    = $ctr_engine->running();
$history    = $ctr_engine->recent_results(20);

?>
<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
	<section>
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<h2 style="font-family:var(--serif);">Active CTR Tests</h2>
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="ctr-conclude">Finalize Due Tests</button>
			</div>

			<?php if ( empty($running) ) : ?>
				<div class="vmsb-empty" style="padding:40px;">
					<p class="vmsb-note">No active A/B tests. Start one from the SEO Lab or Growth Center.</p>
				</div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Page</th><th>Variant</th><th>Baseline</th><th>Due</th></tr></thead>
						<tbody>
							<?php foreach ($running as $exp) : ?>
								<tr>
									<td><a href="<?php echo esc_url(get_edit_post_link($exp->post_id)); ?>"><strong><?php echo esc_html(get_the_title($exp->post_id)); ?></strong></a></td>
									<td><code><?php echo esc_html($exp->variant_value); ?></code></td>
									<td><?php echo number_format($exp->baseline_ctr * 100, 2); ?>%</td>
									<td class="vmsb-note"><?php echo esc_html(mysql2date('j M', $exp->concludes_at)); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</article>

		<article class="vmsb-card vmsb-card-wide" style="margin-top:30px;">
			<h2 style="font-family:var(--serif); margin-bottom:20px;">Recent Experiment Results</h2>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead><tr><th>Page</th><th>Status</th><th>Final CTR</th><th>Delta</th></tr></thead>
					<tbody>
						<?php if ( empty($history) ) : ?>
							<tr><td colspan="4" class="vmsb-note">No experiments concluded yet.</td></tr>
						<?php else :
							foreach ($history as $h) :
								$delta = $h->result_ctr - $h->baseline_ctr;
						?>
							<tr>
								<td><?php echo esc_html(get_the_title($h->post_id)); ?></td>
								<td><span class="vmsb-tag <?php echo $h->status === 'won' ? 'vmsb-tag-good' : 'vmsb-tag-blue'; ?>"><?php echo esc_html(strtoupper($h->status)); ?></span></td>
								<td><?php echo number_format($h->result_ctr * 100, 2); ?>%</td>
								<td class="<?php echo $delta > 0 ? 'is-up' : 'is-down'; ?>">
									<?php echo ($delta > 0 ? '+' : '') . number_format($delta * 100, 2); ?>%
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</section>

	<aside>
		<article class="vmsb-card">
			<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; color:var(--gold);">CTR Logic</h3>
			<p class="vmsb-note">The Brain tests variant SEO titles and meta descriptions against a 14-day baseline to find the highest-engagement hook for your niche.</p>
		</article>
	</aside>
</div>
