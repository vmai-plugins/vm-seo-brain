<?php
defined( 'ABSPATH' ) || exit;

/**
 * Production Bucket — VM SEO Brain X.
 *
 * Jobs: Queue Management, Content Factory, Task Execution, Approvals.
 */

$vmsb_content_engine = new VMSB_Content();
$vmsb_plan_stats     = $vmsb_content_engine->stats();
$vmsb_fleet          = VMSB_Strategist::fleet_status();

global $wpdb;
$vmsb_queue = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}vmsb_tasks ORDER BY score DESC, queued_at DESC LIMIT 50");
$vmsb_pending_posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}vmsb_plan WHERE status IN ('planned', 'approved', 'writing') ORDER BY priority DESC");

?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Editorial & Background Work</p>
			<h1>Production Hub</h1>
			<p class="vmsb-sub">Managing the mass production of authority and technical improvements.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="tasks-process">Process Queue Now</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="approve-all" data-confirm="Approve all planned posts?">Approve All Posts</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="queue">⚙️ Work Queue (<?php echo (int)$vmsb_fleet['queued']; ?>)</button>
		<button class="vmsb-tab" data-tab="content">🏭 Content Factory (<?php echo count($vmsb_pending_posts); ?>)</button>
		<button class="vmsb-tab" data-tab="approvals">✅ Approvals</button>
	</div>

	<!-- WORK QUEUE -->
	<div class="vmsb-panel is-active" data-panel="queue">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<h2 style="font-family:var(--serif);">Autonomous Task Queue</h2>
				<div style="display:flex; gap:10px;">
					<span class="vmsb-tag vmsb-tag-gold"><?php echo (int)$vmsb_fleet['running']; ?> Running</span>
					<span class="vmsb-tag"><?php echo (int)$vmsb_fleet['queued']; ?> Queued</span>
				</div>
			</div>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th>Priority</th>
							<th>Task Type</th>
							<th>Reason / Target</th>
							<th>Status</th>
							<th>Progress / Attempts</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty($vmsb_queue) ) : ?>
							<tr><td colspan="6" class="vmsb-note">The queue is empty. Strategist will populate it during the next cycle.</td></tr>
						<?php else :
							foreach ( $vmsb_queue as $task ) :
						?>
							<tr class="status-<?php echo esc_attr($task->status); ?>">
								<td><span class="vmsb-tag vmsb-tag-gold" style="font-weight:800;"><?php echo (float)$task->score; ?></span></td>
								<td><strong><?php echo esc_html(VMSB_Strategist::agent_label($task->task_type)); ?></strong></td>
								<td>
									<p style="font-size:13px; margin:0;"><?php echo esc_html($task->reason); ?></p>
								</td>
								<td>
									<span class="vmsb-tag <?php echo $task->status === 'done' ? 'vmsb-tag-good' : ($task->status === 'failed' ? 'vmsb-tag-crit' : 'vmsb-tag-blue'); ?>">
										<?php echo esc_html(strtoupper($task->status)); ?>
									</span>
								</td>
								<td>
									<?php if ($task->status === 'running') : ?>
										<div class="vmsb-bar" style="width:60px; height:6px;"><span class="vmsb-bar-fill-animate" style="width:75%; background:var(--gold);"></span></div>
									<?php else : ?>
										<span class="vmsb-note"><?php echo (int)(isset($task->attempts) ? $task->attempts : 0); ?> / <?php echo (int)(isset($task->max_attempts) ? $task->max_attempts : 3); ?></span>
									<?php endif; ?>
								</td>
								<td class="vmsb-row-actions">
									<button class="vmsb-mini-btn" data-vmsb-task-view="<?php echo (int)$task->id; ?>">View</button>
									<button class="vmsb-mini-btn" data-vmsb="task-cancel" data-id="<?php echo (int)$task->id; ?>">Cancel</button>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<!-- CONTENT FACTORY -->
	<div class="vmsb-panel" data-panel="content">
		<?php
		if (!defined('VMSB_NESTED')) define('VMSB_NESTED', true);
		include VMSB_DIR . 'admin/views/pipeline.php';
		?>
	</div>

	<!-- APPROVALS -->
	<div class="vmsb-panel" data-panel="approvals">
		<article class="vmsb-card vmsb-card-wide">
			<h2 style="font-family:var(--serif); margin-bottom:20px;">Awaiting Human Approval</h2>
			<p class="vmsb-note">Autonomous actions that require a final check before deployment.</p>

			<div class="vmsb-empty" style="padding:60px 0; text-align:center;">
				<p class="vmsb-note">No actions currently require approval.</p>
			</div>
		</article>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>

<style>
.status-running { background: rgba(201, 162, 39, 0.03); }
.vmsb-bar-fill-animate {
	display: block; height: 100%;
	animation: vmsb-progress-pulse 2s infinite;
}
@keyframes vmsb-progress-pulse {
	0% { opacity: 0.6; }
	50% { opacity: 1; }
	100% { opacity: 0.6; }
}
</style>
