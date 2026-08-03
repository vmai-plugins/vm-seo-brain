<?php
defined( 'ABSPATH' ) || exit;

$counts     = class_exists( 'VMSB_Outcome_Ledger' ) ? VMSB_Outcome_Ledger::counts() : array( 'complete' => 0, 'pending' => 0, 'wins' => 0, 'losses' => 0, 'net_clicks' => 0 );
$scoreboard = class_exists( 'VMSB_Outcome_Ledger' ) ? VMSB_Outcome_Ledger::scoreboard() : array();
$recent     = class_exists( 'VMSB_Outcome_Ledger' ) ? VMSB_Outcome_Ledger::recent( 40 ) : array();
$queued     = class_exists( 'VMSB_Task_Runner' ) ? VMSB_Task_Runner::recent( 20 ) : array();
$pending_ct = class_exists( 'VMSB_Task_Runner' ) ? VMSB_Task_Runner::pending_count() : 0;

$decided = (int) $counts['wins'] + (int) $counts['losses'];
$winrate = $decided > 0 ? round( $counts['wins'] / $decided * 100 ) : null;
?>
<div class="wrap vmsb vmsb-learning">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Learning</p>
			<h1>Learning</h1>
			<p class="vmsb-sub">Whether the brain's actions actually moved anything. Every change is measured against Search Console at 7 and 28 days.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="measure-outcomes">Measure what is due</button>
		</div>
	</header>

	<div class="vmsb-cards">
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo (int) $counts['complete']; ?></span>
			<span class="vmsb-card-label">Actions measured</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo null === $winrate ? '—' : (int) $winrate . '%'; ?></span>
			<span class="vmsb-card-label">Win rate</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num <?php echo (int) $counts['net_clicks'] >= 0 ? 'is-up' : 'is-down'; ?>">
				<?php echo ( (int) $counts['net_clicks'] >= 0 ? '+' : '' ) . (int) $counts['net_clicks']; ?>
			</span>
			<span class="vmsb-card-label">Net clicks attributed</span>
		</div>
		<div class="vmsb-card">
			<span class="vmsb-card-num"><?php echo (int) $counts['pending']; ?></span>
			<span class="vmsb-card-label">Awaiting measurement</span>
		</div>
	</div>

	<div class="vmsb-console" id="vmsb-output" hidden>
		
	</div>

	<section class="vmsb-section">
		<h2>Module scoreboard</h2>
		<p class="vmsb-sub">Confidence is earned on this site, not assumed. 1.00 is neutral; a module that keeps producing measurable gains rises above it. Fewer than five measured outcomes stays neutral rather than being judged early.</p>

		<?php if ( ! $scoreboard ) : ?>
			<div class="vmsb-empty">
				<h2>Nothing measured yet</h2>
				<p>Outcomes are recorded as the brain works and first measured at 7 days, so the earliest useful reading is about a week after God Mode starts.</p>
			</div>
		<?php else : ?>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead><tr><th>Module</th><th>Confidence</th><th>Actions</th><th>Measured</th><th>Win</th><th>Neutral</th><th>Loss</th><th>Net clicks</th></tr></thead>
					<tbody>
					<?php foreach ( $scoreboard as $row ) :
						$c   = (float) $row['confidence'];
						$cls = $c > 1.05 ? 'is-up' : ( $c < 0.95 ? 'is-down' : '' );
						?>
						<tr>
							<td><strong><?php echo esc_html( $row['module'] ); ?></strong></td>
							<td class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( number_format( $c, 2 ) ); ?></td>
							<td><?php echo (int) $row['total']; ?></td>
							<td><?php echo (int) $row['measured']; ?></td>
							<td><?php echo (int) $row['wins']; ?></td>
							<td><?php echo (int) $row['neutrals']; ?></td>
							<td><?php echo (int) $row['losses']; ?></td>
							<td class="<?php echo (int) $row['net_clicks'] >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo ( (int) $row['net_clicks'] >= 0 ? '+' : '' ) . (int) $row['net_clicks']; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>

	<section class="vmsb-section">
		<h2>Recent outcomes</h2>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead><tr><th>When</th><th>Module</th><th>Action</th><th>Page</th><th>7 day</th><th>28 day</th><th>Verdict</th></tr></thead>
				<tbody>
				<?php if ( ! $recent ) : ?>
					<tr><td colspan="7">Nothing recorded yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $recent as $row ) : ?>
					<tr>
						<td><?php echo esc_html( mysql2date( 'j M', $row->created_at ) ); ?></td>
						<td><?php echo esc_html( $row->module ); ?></td>
						<td><?php echo esc_html( str_replace( '_', ' ', $row->action ) ); ?></td>
						<td>
							<?php if ( $row->object_id && get_post( $row->object_id ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row->object_id ) ); ?>"><?php echo esc_html( $row->object_label ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $row->object_label ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo null === $row->delta_short ? '—' : esc_html( ( $row->delta_short >= 0 ? '+' : '' ) . (int) $row->delta_short ); ?></td>
						<td><?php echo null === $row->delta_long ? '—' : esc_html( ( $row->delta_long >= 0 ? '+' : '' ) . (int) $row->delta_long ); ?></td>
						<td><span class="vmsb-verdict v-<?php echo esc_attr( $row->verdict ?: 'pending' ); ?>"><?php echo esc_html( $row->verdict ?: 'pending' ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>

	<section class="vmsb-section">
		<h2>Today's computed plan</h2>
		<p class="vmsb-sub">
			The strategist scores optional intelligence work as <code>impact × confidence ÷ effort</code> against this site's actual state,
			drops anything with nothing to do, and queues the rest for the hourly cron to drain a couple at a time — never all at once in one request.
		</p>
		<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="strategist-preview">Preview today's plan</button>
		<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="tasks-process" data-body='{"limit":3}'>Drain queue now</button>

		<?php if ( $queued ) : ?>
			<div class="vmsb-table-wrap" style="margin-top:16px;">
				<table class="vmsb-table vmsb-table-full">
					<thead><tr><th>Task</th><th>Score</th><th>Reason</th><th>Status</th><th>Queued</th></tr></thead>
					<tbody>
					<?php foreach ( $queued as $t ) : ?>
						<tr>
							<td><code><?php echo esc_html( $t->task_type ); ?></code></td>
							<td><?php echo esc_html( $t->score ); ?></td>
							<td class="vmsb-sub"><?php echo esc_html( $t->reason ); ?></td>
							<td><span class="vmsb-verdict v-<?php echo esc_attr( $t->status ); ?>"><?php echo esc_html( $t->status ); ?></span></td>
							<td><?php echo esc_html( mysql2date( 'j M H:i', $t->queued_at ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<p class="vmsb-note">No tasks queued yet — runs automatically once a day.</p>
		<?php endif; ?>
	</section>
</div>
