<?php
defined( 'ABSPATH' ) || exit;

/**
 * Growth Bucket — VM SEO Brain X.
 *
 * Jobs: Identifying Gaps, Planning Strategy, Projecting ROI.
 * Contains: Opportunities, Strategy, Roadmap.
 */

$vmsb_brain_engine = new VMSB_Brain();
$vmsb_ops          = $vmsb_brain_engine->recall('intelligence', 'active_opportunities', array());
$vmsb_battle_plan  = get_option('vmsb_battle_plan', array());
$vmsb_pivot        = $vmsb_brain_engine->recall('intelligence', 'current_strategy_pivot');

?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Strategic Discovery</p>
			<h1>Growth Center</h1>
			<p class="vmsb-sub">Identifying and planning your path to SEO dominance.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="opportunity-scan">Discovery Scan</button>
		</div>
	</header>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="opportunities">🎯 Opportunities (<?php echo count($vmsb_ops); ?>)</button>
		<button class="vmsb-tab" data-tab="strategy">🧠 Strategic Pivot</button>
		<button class="vmsb-tab" data-tab="roadmap">🗺️ Roadmap</button>
	</div>

	<!-- OPPORTUNITIES -->
	<div class="vmsb-panel is-active" data-panel="opportunities">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<h2 style="font-family:var(--serif);">Active Growth Opportunities</h2>
				<p class="vmsb-note">Ranked by (Impact × Confidence × Value) / Effort.</p>
			</div>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th>Type</th>
							<th>Target</th>
							<th>Priority</th>
							<th>Reason / Recommended</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty($vmsb_ops) ) : ?>
							<tr><td colspan="5" class="vmsb-note">No active opportunities. Run a Discovery Scan to find new leads.</td></tr>
						<?php else :
							foreach ( $vmsb_ops as $op ) :
						?>
							<tr>
								<td><span class="vmsb-tag"><?php echo esc_html(str_replace('_', ' ', $op['type'])); ?></span></td>
								<td><strong><?php echo esc_html($op['target']); ?></strong></td>
								<td><span class="vmsb-tag vmsb-tag-gold" style="font-weight:800;">🔥 <?php echo (float)$op['priority']; ?></span></td>
								<td>
									<p style="font-size:13px; margin:0;"><strong>Why:</strong> <?php echo esc_html($op['reason']); ?></p>
									<p class="vmsb-note" style="margin:5px 0 0;"><?php echo esc_html($op['recommended']); ?></p>
								</td>
								<td class="vmsb-row-actions">
									<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="execute-opportunity" data-body='<?php echo wp_json_encode($op); ?>'>Execute</button>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<!-- STRATEGY -->
	<div class="vmsb-panel" data-panel="strategy">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<article class="vmsb-card" style="border-left: 5px solid var(--gold);">
					<div class="vmsb-flex-space" style="margin-bottom:20px;">
						<h2 style="font-family:var(--serif);">Active Strategic Pivot</h2>
						<span class="vmsb-tag vmsb-tag-gold">30-Day Focus</span>
					</div>
					<?php if ( $vmsb_pivot ) : ?>
						<h3 style="margin:0 0 10px; color:var(--gold-soft);"><?php echo esc_html($vmsb_pivot['pivot_name']); ?></h3>
						<p style="font-size:15px; line-height:1.6; color:var(--text);"><?php echo esc_html($vmsb_pivot['pivot_reason']); ?></p>

						<h4 style="margin:20px 0 10px; font-size:14px; text-transform:uppercase;">Directives</h4>
						<ul class="vmsb-legend" style="flex-direction:column; gap:10px;">
							<?php foreach ((array)$vmsb_pivot['new_directives'] as $d) : ?>
								<li><i class="sev-low"></i><?php echo esc_html($d); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="vmsb-note">The Brain is currently using the baseline SEO policy. Trigger a Strategic Evaluation to pivot.</p>
					<?php endif; ?>

					<div style="margin-top:30px; padding-top:20px; border-top:1px solid var(--line);">
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="evaluate-pivot">Evaluate Strategic Pivot</button>
					</div>
				</article>
			</section>

			<aside>
				<article class="vmsb-card">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase;">Business DNA</h3>
					<div class="vmsb-note" style="line-height:1.5;">
						<p><strong>Type:</strong> <?php echo esc_html($vmsb_brain_engine->profile()['type']); ?></p>
						<p><strong>Persona:</strong> <?php echo esc_html($vmsb_brain_engine->profile()['tone']); ?></p>
					</div>
					<a href="<?php echo admin_url('admin.php?page=vmsb-settings'); ?>" class="vmsb-link" style="margin-top:15px; display:block;">Edit Profile →</a>
				</article>
			</aside>
		</div>
	</div>

	<!-- ROADMAP -->
	<div class="vmsb-panel" data-panel="roadmap">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 25px;">
				<div>
					<h2 style="margin:0; font-family:var(--serif);">The Dominance Roadmap</h2>
					<p class="vmsb-note">Autonomous day-by-day battle plan to hit your growth targets.</p>
				</div>
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="battle-roadmap">Re-generate Roadmap</button>
			</div>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th style="width:60px;">Day</th>
							<th>Strategic Task</th>
							<th>Keyword Focus</th>
							<th style="text-align:right;">Exp. Impact</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty($vmsb_battle_plan) ) : ?>
							<tr><td colspan="4" class="vmsb-note">No battle plan generated yet. Click to architect your roadmap.</td></tr>
						<?php else :
							foreach ( $vmsb_battle_plan as $task ) :
								$day_num = (int)$task['day'];
						?>
							<tr>
								<td><strong>#<?php echo $day_num; ?></strong></td>
								<td>
									<?php echo esc_html($task['task']); ?>
									<br><small class="vmsb-note"><?php echo esc_html($task['reason']); ?></small>
								</td>
								<td><code><?php echo esc_html($task['keyword'] ?: 'N/A'); ?></code></td>
								<td style="text-align:right;"><span class="vmsb-tag vmsb-tag-good">+<?php echo (int)$task['expected_impact']; ?>%</span></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
