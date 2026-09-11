<?php
defined( 'ABSPATH' ) || exit;

/**
 * Learning Bucket — VM SEO Brain X.
 *
 * Jobs: CTR Experiments, Performance Outcomes, Strategic Lessons.
 */

$vmsb_is_nested = defined( 'VMSB_NESTED' ) && VMSB_NESTED;
?>
	<?php if ( ! $vmsb_is_nested ) : ?>
		<header class="vmsb-head">
			<div>
				<p class="vmsb-eyebrow">Continuous Improvement</p>
				<h1>Learning Center</h1>
				<p class="vmsb-sub">Inspecting how the Brain learns from past actions and experiments.</p>
			</div>
			<div class="vmsb-head-actions">
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="measure-outcomes">Measure Outcomes</button>
			</div>
		</header>
		<span class="wp-header-end"></span>
	<?php endif; ?>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="outcomes">🧪 Outcomes</button>
		<button class="vmsb-tab" data-tab="experiments">🖱️ Experiments</button>
		<button class="vmsb-tab" data-tab="lessons">🧠 Lessons</button>
	</div>

	<!-- OUTCOMES -->
	<div class="vmsb-panel is-active" data-panel="outcomes">
		<?php
		// Reuse existing learning view logic for outcomes
		// But wait, the existing file is named learning.php too.
		// I'll rename the original to outcomes.php if I can,
		// or just include it with a nested flag.
		if (!defined('VMSB_NESTED')) define('VMSB_NESTED', true);
		include VMSB_DIR . 'admin/views/subviews/outcomes.php';
		?>
	</div>

	<!-- EXPERIMENTS -->
	<div class="vmsb-panel" data-panel="experiments">
		<?php include VMSB_DIR . 'admin/views/subviews/experiments.php'; ?>
	</div>

	<!-- LESSONS -->
	<div class="vmsb-panel" data-panel="lessons">
		<article class="vmsb-card vmsb-card-wide">
			<h2 style="font-family:var(--serif);">Autonomous Lessons Learned</h2>
			<?php
			global $wpdb;
			$vmsb_lessons = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}vmsb_memory WHERE bucket = 'healer' ORDER BY updated_at DESC");
			if ( empty($vmsb_lessons) ) : ?>
				<p class="vmsb-note">No strategic lessons recorded yet.</p>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Module</th><th>Lesson</th><th>Date</th></tr></thead>
						<tbody>
							<?php foreach ($vmsb_lessons as $l) : ?>
								<tr>
									<td><strong><?php echo esc_html(str_replace('lesson_', '', $l->mkey)); ?></strong></td>
									<td><?php echo esc_html($l->mvalue); ?></td>
									<td class="vmsb-note"><?php echo esc_html(human_time_diff(strtotime($l->updated_at))); ?> ago</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>

