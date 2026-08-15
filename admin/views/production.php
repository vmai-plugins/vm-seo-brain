<?php
defined( 'ABSPATH' ) || exit;

/**
 * Production Bucket — VM SEO Brain X.
 *
 * Jobs: Queue Management, Content Factory, Task Execution, Approvals.
 */

$vmsb_content_engine = new VMSB_Content();
$vmsb_fleet          = VMSB_Strategist::fleet_status();

global $wpdb;

// Only tasks a human can still act on. This used to select every row in
// the table with no status filter, so the "Work Queue" listed 50 finished
// jobs while the tab counted only genuinely queued ones - the tab read
// "Work Queue (0)" above a table of 50 rows, each with a Cancel button
// that would have hard-deleted a completed history record.
$vmsb_queue = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}vmsb_tasks
	 WHERE status IN ('queued', 'running', 'retrying', 'failed')
	 ORDER BY FIELD(status, 'failed', 'running', 'retrying', 'queued'), score DESC, queued_at DESC
	 LIMIT 50"
);
$vmsb_queue_count = count( $vmsb_queue );

// Finished work, shown separately so it can't be mistaken for a backlog.
$vmsb_recent_done = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}vmsb_tasks
	 WHERE status = 'done' ORDER BY id DESC LIMIT 15"
);

$vmsb_pending_posts = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}vmsb_plan WHERE status IN ('planned', 'approved', 'writing') ORDER BY priority DESC");

// Drafted rewrites waiting on a human yes/no. The Approvals tab below used
// to be a hardcoded "No actions currently require approval" message that
// said the same thing whether or not anything was actually waiting - this
// is the same source the Issues screen already reviews from.
$vmsb_approvals = $vmsb_content_engine->pending_reviews( 50 );

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
		<button class="vmsb-tab is-active" data-tab="queue">⚙️ Work Queue (<?php echo (int)$vmsb_queue_count; ?>)</button>
		<button class="vmsb-tab" data-tab="content">🏭 Content Factory (<?php echo count($vmsb_pending_posts); ?>)</button>
		<button class="vmsb-tab" data-tab="approvals">✅ Approvals (<?php echo count($vmsb_approvals); ?>)</button>
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
							<tr><td colspan="6" class="vmsb-note">Nothing waiting or failed. Strategist will queue more work during the next cycle.</td></tr>
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

		<?php if ( $vmsb_recent_done ) : ?>
		<article class="vmsb-card vmsb-card-wide" style="margin-top:24px;">
			<div class="vmsb-flex-space" style="margin-bottom:16px;">
				<h2 style="font-family:var(--serif); font-size:18px;">Recently Completed</h2>
				<span class="vmsb-note">Last <?php echo count($vmsb_recent_done); ?> finished tasks — history, not a backlog.</span>
			</div>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead><tr><th>Task Type</th><th>Reason / Target</th><th>Attempts</th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $vmsb_recent_done as $vmsb_done_task ) : ?>
							<tr>
								<td><strong><?php echo esc_html(VMSB_Strategist::agent_label($vmsb_done_task->task_type)); ?></strong></td>
								<td><p style="font-size:13px; margin:0;"><?php echo esc_html($vmsb_done_task->reason); ?></p></td>
								<td><span class="vmsb-note"><?php echo (int)(isset($vmsb_done_task->attempts) ? $vmsb_done_task->attempts : 0); ?></span></td>
								<td class="vmsb-row-actions">
									<button class="vmsb-mini-btn" data-vmsb-task-view="<?php echo (int)$vmsb_done_task->id; ?>">View</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</article>
		<?php endif; ?>
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
			<h2 style="font-family:var(--serif); margin-bottom:6px;">Awaiting Human Approval</h2>
			<p class="vmsb-note" style="margin-bottom:20px;">Rewrites the Brain has drafted against live pages. Nothing here is published until you approve it.</p>

			<?php if ( empty( $vmsb_approvals ) ) : ?>
				<div class="vmsb-empty" style="padding:60px 0; text-align:center;">
					<p class="vmsb-note">No actions currently require approval.</p>
				</div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Page</th><th>Why it was rewritten</th><th class="vmsb-row-actions">Actions</th></tr></thead>
						<tbody>
							<?php foreach ( $vmsb_approvals as $vmsb_ap ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $vmsb_ap['title'] ); ?></strong>
										<div style="margin-top:4px;">
											<a href="<?php echo esc_url( $vmsb_ap['edit_url'] ); ?>" class="vmsb-note">Edit</a>
											<span class="vmsb-note"> · </span>
											<a href="<?php echo esc_url( $vmsb_ap['view_url'] ); ?>" class="vmsb-note" target="_blank" rel="noopener">View live</a>
										</div>
									</td>
									<td><p class="vmsb-note" style="max-width:420px; margin:0;"><?php echo esc_html( $vmsb_ap['reason'] ?: 'No reason recorded.' ); ?></p></td>
									<td class="vmsb-row-actions">
										<button class="vmsb-mini-btn" data-vmsb-pending-view="<?php echo (int) $vmsb_ap['post_id']; ?>">Preview draft</button>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="pending-approve" data-body='{"post_id":<?php echo (int) $vmsb_ap['post_id']; ?>}' data-confirm="Publish this drafted rewrite to the live page?">Approve</button>
										<button class="vmsb-mini-btn" data-vmsb="pending-reject" data-body='{"post_id":<?php echo (int) $vmsb_ap['post_id']; ?>}' data-confirm="Discard this draft? The issue will reopen.">Reject</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
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
