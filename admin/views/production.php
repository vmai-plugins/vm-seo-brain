<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content Engine Hub — VM SEO Brain.
 * Unifies Editorial Pipeline, Topic Discovery & Gaps, Approvals, Queue, and Agent Fleet.
 */

$vmsb_content_engine = new VMSB_Content();
$vmsb_fleet          = VMSB_Strategist::fleet_status();

global $wpdb;

// Pending counts
$vmsb_queue_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_tasks WHERE status IN ('queued', 'running', 'retrying')"
);
$vmsb_failed_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_tasks WHERE status = 'failed'"
);
$vmsb_pending_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_plan WHERE status IN ('planned', 'approved', 'writing')"
);
$vmsb_approvals_count = $vmsb_content_engine->pending_reviews_count();
$vmsb_approvals       = $vmsb_content_engine->pending_reviews( 50 );

// Tasks list
$vmsb_queue = $wpdb->get_results(
	"SELECT * FROM {$wpdb->prefix}vmsb_tasks
	 WHERE status IN ('queued', 'running', 'retrying', 'failed')
	 ORDER BY FIELD(status, 'running', 'retrying', 'queued', 'failed'), score DESC, queued_at DESC
	 LIMIT 50"
);

// Active Tab determination
$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pipeline';
if ( ! in_array( $active_tab, array( 'pipeline', 'discovery', 'approvals', 'queue', 'agents' ), true ) ) {
	$active_tab = 'pipeline';
}
?>

<div class="vmsb-content-hub">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Autonomous Publishing & Editorial</p>
			<h1>Content Engine</h1>
			<p class="vmsb-sub">Discover keyword gaps, schedule authority content, and monitor automated writing pipelines.</p>
		</div>
		<div class="vmsb-head-actions">
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="plan" data-body='{"count":5}'>⚡ Plan 5 Topics</button>
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-sheet">Sync Sheet</button>
			<button type="button" class="vmsb-btn vmsb-btn-gold" data-vmsb="tasks-process" data-body='{"limit":3}'>Run Production Batch</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<!-- NAVIGATION TABS -->
	<div class="vmsb-tabs" style="margin-top: 24px;">
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'pipeline' ? 'is-active' : ''; ?>" data-tab="pipeline">
			📝 Editorial Pipeline (<?php echo (int) $vmsb_pending_count; ?>)
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'discovery' ? 'is-active' : ''; ?>" data-tab="discovery">
			💡 Topic Discovery & Gaps
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'approvals' ? 'is-active' : ''; ?>" data-tab="approvals">
			✅ Approvals & Reviews <?php if ( $vmsb_approvals_count > 0 ) : ?><span class="vmsb-badge" style="background:var(--gold); color:#000; padding:2px 6px; border-radius:10px; font-size:10px; margin-left:4px; font-weight:800;"><?php echo (int) $vmsb_approvals_count; ?></span><?php endif; ?>
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>" data-tab="queue">
			⚙️ Work Queue (<?php echo (int) $vmsb_queue_count; ?>)
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'agents' ? 'is-active' : ''; ?>" data-tab="agents">
			🤖 Agent Fleet
		</button>
	</div>

	<!-- TAB 1: EDITORIAL PIPELINE -->
	<div class="vmsb-panel <?php echo $active_tab === 'pipeline' ? 'is-active' : ''; ?>" data-panel="pipeline">
		<?php
		if ( ! defined( 'VMSB_NESTED' ) ) define( 'VMSB_NESTED', true );
		include VMSB_DIR . 'admin/views/pipeline.php';
		?>
	</div>

	<!-- TAB 2: TOPIC DISCOVERY & GAPS -->
	<div class="vmsb-panel <?php echo $active_tab === 'discovery' ? 'is-active' : ''; ?>" data-panel="discovery">
		<?php include VMSB_DIR . 'admin/views/growth.php'; ?>
	</div>

	<!-- TAB 3: APPROVALS & REVIEWS -->
	<div class="vmsb-panel <?php echo $active_tab === 'approvals' ? 'is-active' : ''; ?>" data-panel="approvals">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 20px;">
				<div>
					<h2 style="font-family: var(--serif); margin: 0 0 4px;">Articles Awaiting Review</h2>
					<p class="vmsb-note" style="margin: 0;">Drafted content and suggested revisions parked for your sign-off.</p>
				</div>
				<?php if ( ! empty( $vmsb_approvals ) ) : ?>
					<button type="button" class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="approve-all">Approve All Pending</button>
				<?php endif; ?>
			</div>

			<?php if ( empty( $vmsb_approvals ) ) : ?>
				<div class="vmsb-empty-state">
					<div class="vmsb-empty-icon">🎉</div>
					<h3 class="vmsb-empty-title">All Caught Up!</h3>
					<p class="vmsb-empty-desc">No drafts or rewrites are currently waiting for approval. New pieces produced in Assisted Mode will appear here.</p>
				</div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead>
							<tr>
								<th>Article / Topic</th>
								<th>Primary Keyword</th>
								<th>Proposed Changes / Summary</th>
								<th>Quality Score</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $vmsb_approvals as $app ) :
								$post_id = (int) $app['post_id'];
								$score = (int) get_post_meta( $post_id, '_vmsb_quality_score', true ) ?: 85;
							?>
								<tr>
									<td>
										<strong><?php echo esc_html( $app['title'] ); ?></strong>
										<br><a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>" target="_blank" class="vmsb-link" style="font-size: 11px;">Edit in WordPress ↗</a>
									</td>
									<td><span class="vmsb-tag"><?php echo esc_html( $app['primary_keyword'] ); ?></span></td>
									<td><p class="vmsb-note" style="margin: 0; max-width: 320px;"><?php echo esc_html( $app['summary'] ?? 'Full article drafted and ready for review.' ); ?></p></td>
									<td><span class="vmsb-tag vmsb-tag-good">Score: <?php echo (int) $score; ?>/100</span></td>
									<td class="vmsb-row-actions">
										<button type="button" class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="pending-approve" data-body='{"post_id":<?php echo (int) $post_id; ?>}'>Approve & Publish</button>
										<button type="button" class="vmsb-mini-btn" data-vmsb="pending-reject" data-body='{"post_id":<?php echo (int) $post_id; ?>}'>Reject</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<!-- TAB 4: WORK QUEUE -->
	<div class="vmsb-panel <?php echo $active_tab === 'queue' ? 'is-active' : ''; ?>" data-panel="queue">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 20px;">
				<div>
					<h2 style="font-family: var(--serif); margin: 0 0 4px;">Background Task Queue</h2>
					<p class="vmsb-note" style="margin: 0;">Autonomous jobs scheduled across the strategist, writers, indexers, and healers.</p>
				</div>
				<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="tasks-process" data-body='{"limit":5}'>Drain Queue Now</button>
			</div>

			<?php if ( empty( $vmsb_queue ) ) : ?>
				<div class="vmsb-empty-state">
					<div class="vmsb-empty-icon">☕</div>
					<h3 class="vmsb-empty-title">Queue is Quiet</h3>
					<p class="vmsb-empty-desc">No tasks are currently running or waiting in the background. The scheduler will automatically enqueue the next batch.</p>
				</div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead>
							<tr>
								<th>Task</th>
								<th>Priority Score</th>
								<th>Status</th>
								<th>Reason / Context</th>
								<th>Queued</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $vmsb_queue as $t ) : ?>
								<tr>
									<td><strong><?php echo esc_html( VMSB_Strategist::agent_label( $t->task_type ) ); ?></strong></td>
									<td><span class="vmsb-tag vmsb-tag-gold"><?php echo round( (float) $t->score, 1 ); ?></span></td>
									<td>
										<?php if ( $t->status === 'running' ) : ?>
											<span class="vmsb-tag vmsb-tag-good">● Running</span>
										<?php elseif ( $t->status === 'failed' ) : ?>
											<span class="vmsb-tag vmsb-tag-crit">Failed (<?php echo (int) $t->attempts; ?>)</span>
										<?php else : ?>
											<span class="vmsb-tag">Queued</span>
										<?php endif; ?>
									</td>
									<td><p class="vmsb-note" style="margin: 0; max-width: 280px;"><?php echo esc_html( $t->reason ); ?></p></td>
									<td><span class="vmsb-note"><?php echo esc_html( human_time_diff( strtotime( $t->queued_at ) ) ); ?> ago</span></td>
									<td class="vmsb-row-actions">
										<?php if ( $t->status === 'failed' ) : ?>
											<button type="button" class="vmsb-mini-btn" data-vmsb="task-retry" data-id="<?php echo (int) $t->id; ?>">Retry</button>
										<?php else : ?>
											<button type="button" class="vmsb-mini-btn" data-vmsb="task-cancel" data-id="<?php echo (int) $t->id; ?>">Cancel</button>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<!-- TAB 5: AGENT FLEET -->
	<div class="vmsb-panel <?php echo $active_tab === 'agents' ? 'is-active' : ''; ?>" data-panel="agents">
		<?php include VMSB_DIR . 'admin/views/agents.php'; ?>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
