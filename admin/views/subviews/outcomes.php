<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subview: Outcomes Ledger.
 */

$ledger = new VMSB_Outcome_Ledger();
$stats  = $ledger->counts();
$recent = $ledger->recent( 50 );

?>
<div class="vmsb-cards">
	<div class="vmsb-card">
		<span class="vmsb-card-num is-up"><?php echo (int) $stats['wins']; ?></span>
		<span class="vmsb-card-label">Wins</span>
	</div>
	<div class="vmsb-card">
		<span class="vmsb-card-num is-down"><?php echo (int) $stats['losses']; ?></span>
		<span class="vmsb-card-label">Losses</span>
	</div>
	<div class="vmsb-card">
		<span class="vmsb-card-num"><?php echo (int) $stats['pending']; ?></span>
		<span class="vmsb-card-label">Awaiting Measurement</span>
	</div>
	<div class="vmsb-card">
		<span class="vmsb-card-num" style="color:var(--good);">+<?php echo number_format($stats['net_clicks']); ?></span>
		<span class="vmsb-card-label">Net Click Delta</span>
	</div>
</div>

<article class="vmsb-card vmsb-card-wide" style="margin-top:20px;">
	<h2 style="margin:0 0 20px; font-family:var(--serif);">Performance Ledger</h2>
	<div class="vmsb-table-wrap">
		<table class="vmsb-table vmsb-table-full">
			<thead>
				<tr>
					<th>Module / Action</th>
					<th>Target</th>
					<th>Hypothesis</th>
					<th>Delta (Clicks)</th>
					<th>Verdict</th>
					<th>Measured</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty($recent) ) : ?>
					<tr><td colspan="6" class="vmsb-note">No outcomes measured yet.</td></tr>
				<?php else :
					foreach ( $recent as $r ) :
						$verdict_cls = 'v-pending';
						if ($r->verdict === 'win') $verdict_cls = 'v-win';
						if ($r->verdict === 'loss') $verdict_cls = 'v-loss';
				?>
					<tr>
						<td><strong><?php echo esc_html(ucfirst($r->module)); ?></strong><br><small class="vmsb-note"><?php echo esc_html($r->action); ?></small></td>
						<td><?php echo esc_html($r->object_label ?: "#{$r->object_id}"); ?></td>
						<td style="max-width:250px;"><p class="vmsb-note" style="margin:0;"><?php echo esc_html($r->hypothesis); ?></p></td>
						<td class="<?php echo (float)$r->delta_short >= 0 ? 'is-up' : 'is-down'; ?>">
							<?php echo $r->delta_short !== null ? ((float)$r->delta_short > 0 ? '+' : '') . number_format($r->delta_short, 1) : '—'; ?>
						</td>
						<td><span class="vmsb-verdict <?php echo $verdict_cls; ?>"><?php echo esc_html(strtoupper($r->verdict ?: 'pending')); ?></span></td>
						<td class="vmsb-note"><?php echo $r->measured_at ? esc_html(human_time_diff(strtotime($r->measured_at))) . ' ago' : '—'; ?></td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
</article>
