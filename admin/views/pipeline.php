<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;
$content = new VMSB_Content();

// Priority Sorting: Failed -> Writing -> Approved -> Planned
$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_plan ORDER BY FIELD(status,'failed','writing','approved','planned','drafted','published','rejected'), priority DESC LIMIT 200" );
$sheet = VMSB_Settings::get( 'sheet_id' );

$stats = $content->stats();
$pub_today = (int) get_option( 'vmsb_pub_' . gmdate('Ymd'), 0 );
$daily_cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Content Factory</p>
			<h1>Editorial Pipeline</h1>
			<p class="vmsb-sub">Managing the autonomous 15-post daily sprint and quality control.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-sheet">Sync Sheet</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="approve-all">Approve All Planned</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="tasks-process" data-body='{"limit":5}'>Drip Publish Now</button>
		</div>
	</header>

	<div class="vmsb-pipeline-overview" style="margin-bottom:40px;">
		<div class="vmsb-pipeline-grid">
			<div class="vmsb-pipeline-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">📅</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['planned'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Planned</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">Topics identified by Trend Scout & GSC.</p>
			</div>

			<div class="vmsb-pipeline-stage" style="border-left: 3px solid var(--good);">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">✅</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['approved'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Approved</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">Ready for autonomous generation.</p>
			</div>

			<div class="vmsb-pipeline-stage" style="background: rgba(212, 175, 55, 0.05); border-left: 3px solid var(--gold);">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">🤖</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['writing'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Active Writing</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">AI Agents currently drafting content.</p>
			</div>

			<div class="vmsb-pipeline-stage vmsb-failed-stage <?php echo ($stats['failed'] ?? 0) > 0 ? 'has-failures' : ''; ?>" id="vmsb-filter-failed" style="cursor:pointer;">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚠️</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['failed'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Failed</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">Technical or quality rejections.</p>
			</div>

			<div class="vmsb-pipeline-stage vmsb-health-stage" style="grid-column: span 2;">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚡</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo $pub_today; ?><small>/<?php echo $daily_cap; ?></small></span>
						<span class="vmsb-stage-label">Daily Sprint Velocity</span>
					</div>
				</div>
				<div class="vmsb-bar" style="height:10px; margin: 10px 0;"><span style="width:<?php echo min(100, ($pub_today / ($daily_cap ?: 1)) * 100); ?>%; background: linear-gradient(90deg, var(--good), var(--gold));"></span></div>
				<p class="vmsb-stage-desc"><?php echo max(0, $daily_cap - $pub_today); ?> slots remaining today.</p>
			</div>
		</div>
	</div>

	<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap: 30px; margin-bottom: 40px;">
		<!-- PRODUCTION PULSE -->
		<article class="vmsb-card vmsb-card-wide" style="background: linear-gradient(135deg, var(--panel) 0%, rgba(201, 162, 39, 0.03) 100%);">
			<div style="display:flex; justify-content:space-between; align-items: flex-start; margin-bottom: 20px;">
				<div>
					<h2 style="margin:0; font-size:20px; font-family:var(--serif);">Production Pulse</h2>
					<p class="vmsb-note">Content output velocity (last 14 days).</p>
				</div>
			</div>

			<?php
			$p_data = $wpdb->get_results("
				SELECT DATE(post_date) as day, COUNT(*) as count
				FROM {$wpdb->posts}
				WHERE post_type = 'post' AND post_status = 'publish'
				AND post_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
				GROUP BY day ORDER BY day ASC
			");
			$p_counts = wp_list_pluck($p_data, 'count');
			$max_p = max($p_counts ?: array(1)) ?: 1;
			$p_points = [];
			$w = 600; $h = 100;
			if (count($p_data) > 1) {
				$step = $w / (count($p_data) - 1);
				foreach ($p_counts as $i => $c) {
					$x = $i * $step;
					$y = $h - ($c / $max_p * $h);
					$p_points[] = "$x,$y";
				}
			}
			?>
			<div style="height:120px; width:100%; margin:20px 0;">
				<svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
					<polyline points="<?php echo implode(' ', $p_points); ?>" fill="none" stroke="var(--gold)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
				</svg>
			</div>
		</article>

		<!-- QUICK STATS -->
		<div style="display:flex; flex-direction:column; gap:20px;">
			<div class="vmsb-card" style="padding:20px; border-left:4px solid var(--accent-purple);">
				<span class="vmsb-note">Total Library</span>
				<p style="margin:5px 0 0; font-weight:700; font-size:24px;"><?php echo number_format($stats['published'] ?? 0); ?></p>
				<p class="vmsb-note">Post-authority assets.</p>
			</div>
			<div class="vmsb-card" style="padding:20px; border-left:4px solid var(--good);">
				<span class="vmsb-note">Avg Authority Score</span>
				<p style="margin:5px 0 0; font-weight:700; font-size:24px;"><?php
					$avg = $wpdb->get_var("SELECT AVG(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_vmsb_quality_score'");
					echo round($avg ?: 0);
				?>%</p>
			</div>
		</div>
	</div>

	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="queue">🛠️ Production Queue</button>
		<button class="vmsb-tab" data-tab="activity">🦾 Agent Activity</button>
		<button class="vmsb-tab" data-tab="calendar">📅 Calendar</button>
	</div>

	<!-- PRODUCTION QUEUE -->
	<div class="vmsb-panel is-active" data-panel="queue">
		<div class="vmsb-filter-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:var(--panel); padding:15px; border-radius:10px; border:1px solid var(--line);">
			<div class="vmsb-search-wrap" style="position:relative; flex:1; max-width:400px;">
				<span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:0.5;">🔍</span>
				<input type="text" id="vmsb-pipeline-search" placeholder="Search topics or keywords..." style="width:100%; padding-left:35px; height:42px; border-radius:8px;">
			</div>
			<div class="vmsb-bulk-actions" style="margin:0;">
				<select id="vmsb-bulk-select" style="height:42px; border-radius:8px; min-width:180px;">
					<option value="">Bulk Actions</option>
					<option value="bulk-approve">Approve Selected</option>
					<option value="bulk-produce">Write Selected Now</option>
					<option value="bulk-delete">Remove Selected</option>
				</select>
				<button class="vmsb-btn vmsb-btn-ghost" id="vmsb-bulk-apply">Apply</button>
			</div>
		</div>

		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full" id="vmsb-pipeline-table">
				<thead>
					<tr>
						<th class="vmsb-col-cb"><input type="checkbox" id="vmsb-select-all"></th>
						<th>Status</th>
						<th>Topic</th>
						<th>Quality</th>
						<th>Keyword</th>
						<th>Created</th>
						<th>Priority</th>
						<th class="vmsb-row-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7" class="vmsb-note">No topics in pipeline. Visit <strong>Growth Plan</strong> to find new trends.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) :
						$q_report = $row->post_id ? get_post_meta($row->post_id, '_vmsb_quality', true) : null;
						$score = $q_report ? ($q_report['score'] ?? 0) : 0;
					?>
						<tr class="state-row-<?php echo esc_attr($row->status); ?>">
							<td><input type="checkbox" class="vmsb-row-cb" value="<?php echo (int)$row->id; ?>"></td>
							<td>
								<span class="vmsb-tag state-<?php echo esc_attr($row->status); ?>"><?php echo esc_html(ucfirst($row->status)); ?></span>
								<?php if ($row->status === 'writing') : ?>
									<div class="vmsb-pulse-indicator" style="margin-top:5px; transform:scale(0.7); origin:left;">
										<span class="vmsb-dot vmsb-dot-gold"></span> <small><?php echo esc_html($row->agent_task ?: 'Reasoning...'); ?></small>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html($row->title); ?></strong>
								<?php if ($row->editor_note) : ?>
									<div class="vmsb-editor-note" style="font-size:10px; color:var(--gold); font-style:italic; margin-top:4px;">
										✍️ <?php echo esc_html($row->editor_note); ?>
									</div>
								<?php endif; ?>
								<?php if ($row->last_error) : ?>
									<div class="vmsb-error-box" style="color:var(--crit); font-size:11px; margin-top:5px;">
										⚠️ <?php echo esc_html($row->last_error); ?>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($score > 0) : ?>
									<div class="vmsb-tiny-score <?php echo $score >= 85 ? 'good' : ($score >= 70 ? 'med' : 'low'); ?>">
										<span><?php echo $score; ?></span>
									</div>
								<?php else : ?>
									<span class="vmsb-note">—</span>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html($row->primary_keyword); ?></code></td>
							<td>
								<span class="vmsb-note" title="<?php echo esc_attr($row->created_at); ?>">
									<?php echo human_time_diff(strtotime($row->created_at)); ?> ago
								</span>
							</td>
							<td>
								<div class="vmsb-bar vmsb-mini-bar" style="width:50px;"><span style="width:<?php echo (float)$row->priority * 10; ?>%; background:var(--gold);"></span></div>
							</td>
							<td class="vmsb-row-actions">
								<button class="vmsb-mini-btn" onclick="const note = prompt('Editor Note:', '<?php echo esc_js($row->editor_note); ?>'); if(note !== null) VMSB.api('save-editor-note', {id: <?php echo $row->id; ?>, note: note}).then(() => location.reload());">Note</button>
								<?php if ($row->status === 'failed') : ?>
									<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="retry-critique" data-id="<?php echo $row->id; ?>">Retry with Fix</button>
								<?php elseif ($row->status === 'planned') : ?>
									<button class="vmsb-mini-btn" data-vmsb="bulk-action" data-body='{"ids":[<?php echo $row->id; ?>], "bulk_action":"bulk-approve"}'>Approve</button>
								<?php elseif ($row->post_id) : ?>
									<a href="<?php echo get_edit_post_link($row->post_id); ?>" class="vmsb-mini-btn vmsb-btn-ghost">Edit</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- AGENT ACTIVITY -->
	<div class="vmsb-panel" data-panel="activity">
		<div class="vmsb-card vmsb-card-wide">
			<h2 style="margin-bottom:20px;">Live Reasoning Feed</h2>
			<div class="vmsb-activity-feed" style="max-height:600px; overflow-y:auto;">
				<?php
				$logs = ( new VMSB_Logger() )->recent( 30 );
				foreach ( $logs as $log ) :
					$icon = $log->level === 'error' ? '🔴' : ($log->level === 'warning' ? '🟡' : '🟢');
				?>
					<div class="vmsb-activity-item" style="padding:15px; border-bottom:1px solid var(--line); display:flex; gap:20px; align-items:center;">
						<span class="vmsb-note" style="width:100px; flex-shrink:0;"><?php echo human_time_diff(strtotime($log->created_at)); ?> ago</span>
						<span style="width:30px;"><?php echo $icon; ?></span>
						<p style="margin:0; flex:1;"><strong><?php echo esc_html(ucfirst($log->channel)); ?>:</strong> <?php echo esc_html($log->message); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<!-- CALENDAR VIEW (Simplified) -->
	<div class="vmsb-panel" data-panel="calendar">
		<div class="vmsb-alert">
			<p>Visual Calendar helps you see the 100-day dominance sprint slots.</p>
		</div>
		<!-- Simple list for now, can be expanded to full grid -->
		<div class="vmsb-card vmsb-card-wide">
			<table class="vmsb-table vmsb-table-full">
				<thead><tr><th>Date</th><th>Target Topic</th><th>Status</th></tr></thead>
				<tbody>
					<?php
					$upcoming = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}vmsb_plan WHERE scheduled_for IS NOT NULL AND status IN ('planned', 'approved') ORDER BY scheduled_for ASC LIMIT 20");
					foreach ($upcoming as $u) : ?>
						<tr>
							<td><strong><?php echo date('M j, Y', strtotime($u->scheduled_for)); ?></strong></td>
							<td><?php echo esc_html($u->title); ?></td>
							<td><span class="vmsb-tag"><?php echo esc_html($u->status); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>

<script>
jQuery(function($) {
	$('#vmsb-pipeline-search').on('input', function() {
		const val = $(this).val().toLowerCase();
		$('#vmsb-pipeline-table tbody tr').each(function() {
			$(this).toggle($(this).text().toLowerCase().indexOf(val) !== -1);
		});
	});

	$('#vmsb-filter-failed').on('click', function() {
		$('#vmsb-pipeline-search').val('failed').trigger('input');
		$('[data-tab="queue"]').trigger('click');
	});
});
</script>
