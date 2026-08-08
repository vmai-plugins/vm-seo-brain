<?php
defined( 'ABSPATH' ) || exit;

/**
 * Growth Hub: The Unified Editorial & Strategy Center.
 *
 * Merges Growth Discovery with Production Pipeline for high-velocity
 * autonomous SEO management.
 */

global $wpdb;
$content = new VMSB_Content();
$current_plan = VMSB_License::plan();

// Production Data
$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_plan ORDER BY FIELD(status,'failed','writing','approved','planned','drafted','published','rejected'), priority DESC LIMIT 200" );
$stats = $content->stats();
$pub_today = (int) get_option( 'vmsb_pub_' . gmdate('Ymd'), 0 );
$daily_cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );

// Discovery Data
$news_engine   = new VMSB_News();
$trends_engine = new VMSB_Trends();
$velocity_signals = $trends_engine->analyze_velocity();
$rising_trends = $trends_engine->get_rising_signals(8);
?>

<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Strategic Content Factory</p>
			<h1 style="display:flex; align-items:center; gap:15px;">
				Growth Hub
				<span class="vmsb-tag vmsb-tag-gold" style="font-size:10px; text-transform:uppercase;"><?php echo esc_html($current_plan); ?></span>
			</h1>
			<p class="vmsb-sub">Managing discovery, planning, and production in one unified workspace.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-sheet">Sync Sheet</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="approve-all">Approve All</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="tasks-process" data-body='{"limit":5}'>Run Production Batch</button>
		</div>
	</header>

	<!-- KPI OVERVIEW -->
	<div class="vmsb-pipeline-overview" style="margin-bottom:30px;">
		<div class="vmsb-pipeline-grid" style="grid-template-columns: repeat(3, 1fr);">
			<div class="vmsb-pipeline-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">📅</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['planned'] ?? 0 + $stats['approved'] ?? 0); ?></span>
						<span class="vmsb-stage-label">In Pipeline</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">Topics prepped for production.</p>
			</div>

			<div class="vmsb-pipeline-stage" style="border-left: 3px solid var(--gold);">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">🤖</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($stats['writing'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Active Agents</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">AI Agents currently drafting content.</p>
			</div>

			<div class="vmsb-pipeline-stage vmsb-health-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚡</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo $pub_today; ?><small>/<?php echo $daily_cap; ?></small></span>
						<span class="vmsb-stage-label">Daily Velocity</span>
					</div>
				</div>
				<div class="vmsb-bar" style="height:8px; margin: 10px 0;"><span style="width:<?php echo min(100, ($pub_today / ($daily_cap ?: 1)) * 100); ?>%; background: var(--good);"></span></div>
				<p class="vmsb-stage-desc"><?php echo max(0, $daily_cap - $pub_today); ?> hyper-growth slots left.</p>
			</div>
		</div>
	</div>

	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="queue">🛠️ Production Queue</button>
		<button class="vmsb-tab" data-tab="discovery">🎯 Authority Discovery</button>
		<button class="vmsb-tab" data-tab="signals">📡 Viral Signals</button>
		<button class="vmsb-tab" data-tab="roadmap">🗺️ Battle Roadmap</button>
		<button class="vmsb-tab" data-tab="activity">🦾 Agent Activity</button>
	</div>

	<!-- TAB 1: PRODUCTION QUEUE -->
	<div class="vmsb-panel is-active" data-panel="queue">
		<div class="vmsb-filter-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:var(--panel); padding:15px; border-radius:10px; border:1px solid var(--line);">
			<div class="vmsb-search-wrap" style="position:relative; flex:1; max-width:400px;">
				<span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:0.5;">🔍</span>
				<input type="text" id="vmsb-pipeline-search" placeholder="Search topics or keywords..." style="width:100%; padding-left:35px; height:42px; border-radius:8px;">
			</div>
			<div class="vmsb-bulk-actions">
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
									<div class="vmsb-editor-note" style="font-size:10px; color:var(--gold); font-style:italic; margin-top:4px;">✍️ <?php echo esc_html($row->editor_note); ?></div>
								<?php endif; ?>
								<?php if ($row->last_error) : ?>
									<div class="vmsb-error-box" style="color:var(--crit); font-size:11px; margin-top:5px;">⚠️ <?php echo esc_html($row->last_error); ?></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($score > 0) : ?>
									<div class="vmsb-tiny-score <?php echo $score >= 85 ? 'good' : ($score >= 70 ? 'med' : 'low'); ?>"><span><?php echo $score; ?></span></div>
								<?php else : ?><span class="vmsb-note">—</span><?php endif; ?>
							</td>
							<td><code><?php echo esc_html($row->primary_keyword); ?></code></td>
							<td><span class="vmsb-note" title="<?php echo esc_attr($row->created_at); ?>"><?php echo human_time_diff(strtotime($row->created_at)); ?> ago</span></td>
							<td><div class="vmsb-bar vmsb-mini-bar" style="width:50px;"><span style="width:<?php echo (float)$row->priority * 10; ?>%; background:var(--gold);"></span></div></td>
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

	<!-- TAB 2: AUTHORITY DISCOVERY -->
	<div class="vmsb-panel" data-panel="discovery">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<!-- CLUSTER ARCHITECT -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--accent-purple); background: linear-gradient(135deg, var(--panel) 0%, rgba(165, 94, 234, 0.05) 100%);">
					<div class="vmsb-flex-space" style="margin-bottom:14px;">
						<div>
							<h2 style="margin:0; color:var(--accent-purple);">Power Cluster Architect</h2>
							<p class="vmsb-note" style="margin:0;">Design a 100% complete authority silo from a single seed topic.</p>
						</div>
						<span class="vmsb-tag vmsb-tag-purple">Elite Strategy</span>
					</div>
					<?php if ( VMSB_License::has_feature('cluster_architect') ) : ?>
						<div id="vmsb-cluster-architect-form" class="vmsb-stack-form" style="display:flex; gap:15px; align-items:flex-end;">
							<label style="flex:1;"><input type="text" name="seed" placeholder="Enter a broad niche topic..." style="width:100%; height:45px; background:var(--ink); border-radius:8px;"></label>
							<label style="width:100px;">
								<select name="size" style="width:100%; height:45px; background:var(--ink); border-radius:8px;">
									<option value="4">4 Posts</option>
									<option value="6" selected>6 Posts</option>
									<option value="10">10 Posts</option>
								</select>
							</label>
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="cluster-architect" data-vmsb-form="vmsb-cluster-architect-form" style="height:45px; background:var(--accent-purple); border-color:var(--accent-purple); color:#fff;">Architect Silo</button>
						</div>
					<?php else : ?>
						<p class="vmsb-note">Upgrade to Pro to unlock Silo Architect.</p>
					<?php endif; ?>
				</div>

				<!-- BULK IMPORT -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--gold);">
					<h2 style="margin:0 0 10px;">Bulk Authority Import</h2>
					<div id="vmsb-bulk-topics-form" class="vmsb-stack-form">
						<textarea name="topics" data-list rows="5" placeholder="Paste your top topics (one per line)..." style="background:var(--ink); border:1px solid var(--line); color:var(--text); padding:15px; border-radius:8px;"></textarea>
						<div style="display:flex; gap: 20px; margin-top:15px; align-items: flex-end;">
							<label style="flex:1;"><span class="vmsb-note">Language</span><input type="text" name="language" placeholder="e.g. Spanish" style="width:100%; height:42px; border-radius:8px;"></label>
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="import-topics" data-vmsb-form="vmsb-bulk-topics-form" style="height:42px;">Import & Brief</button>
						</div>
					</div>
				</div>
			</section>

			<aside>
				<div class="vmsb-card">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--gold);">Top Opportunities</h3>
					<?php
					$top_opps = ( new VMSB_Keywords() )->top( 8 );
					foreach ( $top_opps as $opp ) : ?>
						<div style="display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($opp->keyword); ?></span>
							<button class="vmsb-mini-btn" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($opp->keyword); ?>", "count":1}'>Plan</button>
						</div>
					<?php endforeach; ?>
				</div>
			</aside>
		</div>
	</div>

	<!-- TAB 3: VIRAL SIGNALS -->
	<div class="vmsb-panel" data-panel="signals">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<!-- ELITE GAP RADAR -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--good); background: linear-gradient(135deg, var(--panel) 0%, rgba(95, 167, 120, 0.05) 100%);">
					<div class="vmsb-flex-space" style="margin-bottom:20px;">
						<div>
							<h2 style="margin:0; color:var(--good);">Elite Gap Radar</h2>
							<p class="vmsb-note" style="margin:0;">Discovers "Golden Gaps" from GSC and Rivals.</p>
						</div>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="gap-discovery">Scan Gaps</button>
					</div>
					<div id="vmsb-gap-results" style="display:none; margin-top:20px;">
						<div class="vmsb-table-wrap">
							<table class="vmsb-table vmsb-table-full">
								<thead><tr><th>Topic</th><th>Gap Type</th><th>Action</th></tr></thead>
								<tbody id="vmsb-gap-list"></tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="vmsb-cards" style="grid-template-columns: 1fr; gap: 20px;">
					<?php foreach ( $rising_trends as $trend ) : ?>
						<article class="vmsb-card" style="display:flex; justify-content:space-between; align-items:center; padding: 20px 25px;">
							<div><span class="vmsb-tag vmsb-tag-blue">Google Trend</span><h3 style="margin:5px 0; font-size:17px;"><?php echo esc_html($trend); ?></h3></div>
							<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($trend); ?>", "count":1}'>Push</button>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<aside>
				<div class="vmsb-card vmsb-card-wide" style="border-top: 4px solid var(--good);">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--good);">Impression Velocity</h3>
					<?php foreach ( $velocity_signals as $sig ) : ?>
						<div style="display:flex; justify-content:space-between; padding: 12px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px;"><?php echo esc_html($sig['keyword']); ?></span>
							<span style="color:var(--good); font-weight:700;">+<?php echo esc_html($sig['growth']); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</aside>
		</div>
	</div>

	<!-- TAB 4: BATTLE ROADMAP -->
	<div class="vmsb-panel" data-panel="roadmap">
		<div class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 25px;">
				<h2 style="margin:0; font-family:var(--serif);">50-Day Battle Roadmap</h2>
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="battle-roadmap">Re-generate</button>
			</div>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead><tr><th>Day</th><th>Strategic Task</th><th>Keyword</th><th>Impact</th></tr></thead>
					<tbody>
						<?php
						$battle_plan = get_option('vmsb_battle_plan', array());
						foreach ( (array)$battle_plan as $task ) :
							$is_today = (int)$task['day'] === (int)((time() - (int)get_option('vmsb_installed_at', time())) / DAY_IN_SECONDS) + 1;
						?>
							<tr <?php echo $is_today ? 'style="background:rgba(201, 162, 39, 0.05);"' : ''; ?>>
								<td><strong>#<?php echo (int)$task['day']; ?></strong></td>
								<td><?php echo esc_html($task['task']); ?><br><small class="vmsb-note"><?php echo esc_html($task['reason']); ?></small></td>
								<td><code><?php echo esc_html($task['keyword'] ?: 'N/A'); ?></code></td>
								<td><span class="vmsb-tag vmsb-tag-good">+<?php echo (int)$task['expected_impact']; ?>%</span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- TAB 5: AGENT ACTIVITY -->
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
});
</script>
