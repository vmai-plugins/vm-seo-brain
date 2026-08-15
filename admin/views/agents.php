<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$vmsb_fleet_status = VMSB_Strategist::fleet_status();
$vmsb_fleet        = $vmsb_fleet_status['fleet'];

// PERFORMANCE: Use Object Cache for Active Writers to avoid direct DB hit on every refresh
$vmsb_writing = wp_cache_get( 'vmsb_active_writers', 'vmsb' );
if ( false === $vmsb_writing ) {
	global $wpdb;
	$vmsb_writing = $wpdb->get_results(
		"SELECT id, title, primary_keyword, agent_task, updated_at FROM {$wpdb->prefix}vmsb_plan WHERE status = 'writing' ORDER BY updated_at DESC LIMIT 10"
	);
	wp_cache_set( 'vmsb_active_writers', $vmsb_writing, 'vmsb', 300 ); // Cache for 5 mins
}

$vmsb_activity = ( new VMSB_Logger() )->recent( 25, 'task_runner' );

$vmsb_enabled_count = count( array_filter( $vmsb_fleet, static fn( $a ) => $a['enabled'] ) );
$vmsb_failed_recent = count( array_filter( $vmsb_fleet, static fn( $a ) => $a['status'] === 'failed' ) );

$vmsb_status_tone = array(
	'running' => 'gold',
	'queued'  => 'blue',
	'done'    => 'good',
	'failed'  => 'crit',
	'idle'    => '',
);
?>
<?php if ( ! $vmsb_is_nested ) : ?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Autonomous Operations</p>
			<h1>Agent Fleet</h1>
			<p class="vmsb-sub">Every specialist task the Strategist can schedule, its current state, and what it last did.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="tasks-process" data-body='{"limit":5}'>Process Queue Now</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="agents-run-strategist">Run Strategist Now</button>
		</div>
	</header>
	<span class="wp-header-end"></span>
<?php endif; ?>

	<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); margin-top: 30px; gap: 20px;">
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Queued</span>
			<div class="vmsb-figure"><span class="vmsb-number"><?php echo (int) $vmsb_fleet_status['queued']; ?></span></div>
			<p class="vmsb-note">Waiting for the next cron drain.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Running</span>
			<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--gold);"><?php echo (int) $vmsb_fleet_status['running']; ?></span></div>
			<p class="vmsb-note">Claimed by the current batch.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Agents Enabled</span>
			<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--good);"><?php echo (int) $vmsb_enabled_count; ?><span class="vmsb-of">/ <?php echo count( $vmsb_fleet ); ?></span></span></div>
			<p class="vmsb-note">Gated by their feature toggle in Settings.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px; <?php echo $vmsb_failed_recent ? 'border-left: 3px solid var(--crit);' : ''; ?>">
			<span class="vmsb-note">Last Run Failed</span>
			<div class="vmsb-figure"><span class="vmsb-number" style="<?php echo $vmsb_failed_recent ? 'color:var(--crit);' : ''; ?>"><?php echo (int) $vmsb_failed_recent; ?></span></div>
			<p class="vmsb-note">Agents whose most recent run errored.</p>
		</div>
	</div>

	<?php if ( $vmsb_writing ) : ?>
	<!-- ACTIVE WRITERS -->
	<section class="vmsb-card vmsb-card-wide" style="margin-top:30px; border-top: 4px solid var(--gold);">
		<h2 style="margin:0 0 15px; font-family:var(--serif);">Active Writers</h2>
		<p class="vmsb-note" style="margin:0 0 15px;">Pipeline rows currently in production, with the writer's live stage.</p>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead><tr><th>Topic</th><th>Keyword</th><th>Stage</th><th>Since</th></tr></thead>
				<tbody>
					<?php foreach ( $vmsb_writing as $w ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $w->title ); ?></strong></td>
							<td><code><?php echo esc_html( $w->primary_keyword ); ?></code></td>
							<td>
								<div class="vmsb-pulse-indicator" style="transform:scale(0.85); transform-origin:left;">
									<span class="vmsb-dot vmsb-dot-gold"></span> <small><?php echo esc_html( $w->agent_task ?: 'Reasoning...' ); ?></small>
								</div>
							</td>
							<td><span class="vmsb-note"><?php echo esc_html( human_time_diff( strtotime( $w->updated_at ) ) ); ?> ago</span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<!-- AGENT ROSTER -->
	<section style="margin-top:30px;">
		<h2 style="margin:0 0 15px; font-family:var(--serif);">Agent Roster</h2>
		<div class="vmsb-grid" style="grid-template-columns: repeat(3, 1fr); gap: 20px;">
			<?php foreach ( $vmsb_fleet as $agent ) :
				$vmsb_tone = isset( $vmsb_status_tone[ $agent['status'] ] ) ? $vmsb_status_tone[ $agent['status'] ] : 'muted';
			?>
				<div class="vmsb-card" style="padding:20px; opacity:<?php echo $agent['enabled'] ? '1' : '0.55'; ?>;">
					<div class="vmsb-flex-space" style="margin-bottom:8px;">
						<strong style="font-size:15px;"><?php echo esc_html( $agent['label'] ); ?></strong>
						<span class="vmsb-tag<?php echo $vmsb_tone ? ' vmsb-tag-' . esc_attr( $vmsb_tone ) : ''; ?>"><?php echo esc_html( ucfirst( $agent['status'] ) ); ?></span>
					</div>
					<p class="vmsb-note" style="min-height:36px;"><?php echo esc_html( $agent['explanation'] ); ?></p>
					<div style="display:flex; justify-content:space-between; align-items:center; margin-top:12px; padding-top:12px; border-top:1px solid var(--line);">
						<span class="vmsb-note">
							<?php if ( $agent['last_ran'] ) : ?>
								Last ran <?php echo esc_html( human_time_diff( strtotime( $agent['last_ran'] ) ) ); ?> ago
								<?php if ( ! $agent['last_ok'] ) : ?><span style="color:var(--crit);">— failed</span><?php endif; ?>
							<?php else : ?>
								Never run yet
							<?php endif; ?>
						</span>
						<span class="vmsb-note"><?php echo $agent['enabled'] ? 'Enabled' : 'Disabled'; ?></span>
					</div>
					<?php if ( $agent['last_reason'] ) : ?>
						<p class="vmsb-note" style="margin-top:6px; font-style:italic;"><?php echo esc_html( $agent['last_reason'] ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</section>

	<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">
		<!-- LIVE ACTIVITY -->
		<section class="vmsb-card vmsb-card-wide">
			<h2 style="margin:0 0 15px; font-family:var(--serif);">Live Activity</h2>
			<?php if ( empty( $vmsb_activity ) ) : ?>
				<p class="vmsb-note">No task-runner activity logged yet. Agents run automatically once God Mode / Auto Growth Mode is on, or click "Run Strategist Now" above.</p>
			<?php else : ?>
				<div class="vmsb-activity-feed" style="max-height:500px; overflow-y:auto;">
					<?php foreach ( $vmsb_activity as $log ) :
						$icon = 'error' === $log->level ? '🔴' : ( 'warning' === $log->level ? '🟡' : '🟢' );
					?>
						<div class="vmsb-activity-item" style="padding:12px 0; border-bottom:1px solid var(--line); display:flex; gap:16px; align-items:center;">
							<span class="vmsb-note" style="width:90px; flex-shrink:0;"><?php echo esc_html( human_time_diff( strtotime( $log->created_at ) ) ); ?> ago</span>
							<span style="width:24px;"><?php echo esc_html( $icon ); ?></span>
							<p style="margin:0; flex:1; font-size:13px;"><?php echo esc_html( $log->message ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</section>

		<!-- ASK THE COMMANDER -->
		<aside class="vmsb-card">
			<h3 style="margin:0 0 10px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--gold);">Ask the Commander</h3>
			<p class="vmsb-note">The one conversational agent in the fleet - open the chat bubble in the bottom-right corner of any page and try:</p>
			<ul style="margin:10px 0 0; padding-left:18px; font-size:13px; color:var(--muted);">
				<li><code>/status</code> — system health check</li>
				<li><code>/report</code> — top gaps and quick wins</li>
				<li><code>/scan</code> — run a full issue scan</li>
				<li><code>/blog &lt;topic&gt;</code> — queue and write a post now</li>
				<li>anything else — free-form question, answered in your business's voice</li>
			</ul>
		</aside>
	</div>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
<?php endif; ?>
