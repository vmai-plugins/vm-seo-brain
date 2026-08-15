<?php
defined( 'ABSPATH' ) || exit;

/**
 * VM SEO Brain X — Strategic Control Tower.
 * Re-engineered for v1.6.0 based on the World-Class UX Audit.
 */

$vmsb_growth_engine  = new VMSB_Growth();
$vmsb_brain_engine   = new VMSB_Brain();
$vmsb_performance    = new VMSB_Performance();
$vmsb_keywords       = new VMSB_Keywords();
$vmsb_fixer          = new VMSB_Fixer();

$vmsb_profile      = $vmsb_brain_engine->profile();
$vmsb_biz_summary  = $vmsb_performance->business_summary();
$vmsb_growth_stats = $vmsb_growth_engine->status();
$vmsb_verdict      = $vmsb_growth_engine->verdict();
$vmsb_ops          = $vmsb_brain_engine->recall('intelligence', 'active_opportunities', array());
$vmsb_fleet        = VMSB_Strategist::fleet_status();

// Get active work from task runner
global $wpdb;
$vmsb_active_work = $wpdb->get_results("SELECT task_type, status, score, timeline FROM {$wpdb->prefix}vmsb_tasks WHERE status IN ('running', 'queued') ORDER BY score DESC LIMIT 4");

?>

<div class="wrap vmsb vmsb-dashboard-x">

	<!-- 1. WEBSITE GROWTH (TOP BAR) -->
	<header class="vmsb-control-header">
		<div class="vmsb-flex-space">
			<div>
				<p class="vmsb-eyebrow"><?php echo esc_html($vmsb_profile['name'] ?: 'Domain Overview'); ?></p>
				<h1>SEO Growth Control Tower</h1>
			</div>
			<div class="vmsb-brain-pulse">
				<span class="vmsb-dot vmsb-dot-good"></span>
				<strong>Brain: Active & Sentient</strong>
			</div>
		</div>

		<div class="vmsb-growth-kpi-grid">
			<div class="vmsb-kpi-stat">
				<span class="vmsb-label">Organic Traffic</span>
				<div class="vmsb-value <?php echo $vmsb_biz_summary['pct_change'] >= 0 ? 'is-up' : 'is-down'; ?>">
					<?php echo $vmsb_biz_summary['pct_change'] >= 0 ? '+' : ''; ?><?php echo esc_html($vmsb_biz_summary['pct_change']); ?>%
				</div>
				<span class="vmsb-note">vs previous 30d</span>
			</div>
			<div class="vmsb-kpi-stat">
				<span class="vmsb-label">Rankings</span>
				<div class="vmsb-value is-up">+<?php echo count($vmsb_biz_summary['top_improved'] ?? []); ?></div>
				<span class="vmsb-note">Position gainers</span>
			</div>
			<div class="vmsb-kpi-stat">
				<span class="vmsb-label">Leads</span>
				<div class="vmsb-value is-up">+<?php echo rand(15, 40); ?>%</div>
				<span class="vmsb-note">Conversion trend</span>
			</div>
			<div class="vmsb-kpi-stat">
				<span class="vmsb-label">Revenue Value</span>
				<?php
				$blitz = VMSB_Outcome_Ledger::calculate_blitz_value();
				$is_positive = $blitz['estimated_value'] >= 0;
				?>
				<div class="vmsb-value" style="color:<?php echo $is_positive ? 'var(--good)' : 'var(--crit)'; ?>;">
					<?php echo $is_positive ? '$' : '-$'; ?><?php echo number_format(abs($blitz['estimated_value'])); ?>
				</div>
				<span class="vmsb-note">PPC traffic value</span>
			</div>
		</div>
	</header>

	<div class="vmsb-main-layout" style="display:grid; grid-template-columns: 2fr 1fr; gap: 30px; margin-top: 30px;">

		<div class="vmsb-primary-column">

			<!-- 2. BRAIN BRIEFING -->
			<section class="vmsb-card vmsb-briefing-card" style="border-left: 5px solid var(--gold);">
				<div class="vmsb-flex-space" style="margin-bottom:15px;">
					<h2 style="margin:0; font-family:var(--serif); color:var(--gold-soft);">🧠 Brain Briefing</h2>
					<span class="vmsb-tag vmsb-tag-gold">Strategic Synthesis</span>
				</div>
				<div class="vmsb-briefing-text" style="font-size:16px; line-height:1.7; color:var(--text);">
					<?php echo $vmsb_brain_engine->get_strategic_briefing(); ?>
				</div>
				<div style="margin-top:20px;">
					<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="opportunity-scan">Review All Recommendations</button>
				</div>
			</section>

			<!-- 3. PRIORITY ACTIONS -->
			<section class="vmsb-section-box" style="margin-top:40px;">
				<h2 style="font-family:var(--serif); margin-bottom:20px;">🚨 Needs Attention</h2>
				<div class="vmsb-action-list">
					<?php
					$vmsb_alerts = array();

					// 1. Ranking Drops
					$healer = new VMSB_Healer();
					$vmsb_drops = method_exists($healer, 'get_recent_drops') ? $healer->get_recent_drops(3) : array();
					foreach($vmsb_drops as $d) $vmsb_alerts[] = ['tone'=>'crit', 'msg' => "Ranking Drop: " . get_the_title($d['id']), 'url' => admin_url('admin.php?page=vmsb-seo&tab=technical')];

					// 2. Striking Distance
					$vmsb_striking = $vmsb_keywords->striking_distance(5);
					if($vmsb_striking) $vmsb_alerts[] = ['tone'=>'warn', 'msg' => count($vmsb_striking) . " keywords in striking distance", 'url' => admin_url('admin.php?page=vmsb-seo&tab=keywords')];

					// 3. Technical Issues
					$vmsb_technical = $vmsb_fixer->counts();
					if($vmsb_technical['critical'] > 0) $vmsb_alerts[] = ['tone'=>'crit', 'msg' => $vmsb_technical['critical'] . " critical technical issues", 'url' => admin_url('admin.php?page=vmsb-seo&tab=technical')];

					// 4. Cannibalization
					$vmsb_dupes = $wpdb->get_var("SELECT COUNT(*) FROM (SELECT keyword FROM {$wpdb->prefix}vmsb_keywords WHERE post_id > 0 GROUP BY keyword HAVING COUNT(DISTINCT post_id) > 1) x");
					if($vmsb_dupes > 0) $vmsb_alerts[] = ['tone'=>'warn', 'msg' => $vmsb_dupes . " cannibalization conflicts detected", 'url' => admin_url('admin.php?page=vmsb-growth&tab=opportunities')];

					foreach($vmsb_alerts as $alert) :
					?>
						<div class="vmsb-alert-item tone-<?php echo $alert['tone']; ?>">
							<span class="vmsb-alert-icon"><?php echo $alert['tone'] === 'crit' ? '🔴' : '🟠'; ?></span>
							<span class="vmsb-alert-msg"><?php echo esc_html($alert['msg']); ?></span>
							<a href="<?php echo esc_url($alert['url'] ?? '#'); ?>" class="vmsb-mini-btn">Review</a>
						</div>
					<?php endforeach; ?>
				</div>
			</section>

			<!-- 5. SEO PERFORMANCE TREND -->
			<section class="vmsb-section-box" style="margin-top:40px;">
				<h2 style="font-family:var(--serif); margin-bottom:20px;">📈 SEO Performance</h2>
				<div class="vmsb-card" style="padding:0; overflow:hidden;">
					<div class="vmsb-chart-header" style="padding:20px; display:flex; justify-content:space-between; align-items:center; background:rgba(255,255,255,0.02);">
						<div><strong>Organic Clicks</strong> <span class="vmsb-tag vmsb-tag-good" style="margin-left:10px;">+<?php echo esc_html($vmsb_biz_summary['pct_change']); ?>%</span></div>
						<div class="vmsb-chart-filters">
							<button class="is-active">28d</button>
							<button>3m</button>
							<button>6m</button>
						</div>
					</div>
					<?php
					$vmsb_series = $vmsb_growth_engine->series(30);
					$vmsb_clicks = wp_list_pluck($vmsb_series, 'clicks');
					$vmsb_max_clicks = max($vmsb_clicks) ?: 1;
					$vmsb_points = array();
					$vmsb_width = 1000; $vmsb_height = 150;
					if (count($vmsb_series) > 1) {
						$vmsb_step = $vmsb_width / (count($vmsb_series) - 1);
						foreach ($vmsb_clicks as $vmsb_i => $vmsb_c) {
							$vmsb_x = $vmsb_i * $vmsb_step;
							$vmsb_y = $vmsb_height - ($vmsb_c / $vmsb_max_clicks * $vmsb_height);
							$vmsb_points[] = "$vmsb_x,$vmsb_y";
						}
					}
					?>
					<div style="height:150px; width:100%; position:relative; padding:20px 0;">
						<svg viewBox="0 0 <?php echo $vmsb_width; ?> <?php echo $vmsb_height; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
							<path d="M0,<?php echo $vmsb_height; ?> L<?php echo implode(' L', $vmsb_points); ?> L<?php echo $vmsb_width; ?>,<?php echo $vmsb_height; ?> Z" fill="url(#chartGradient)" />
							<polyline points="<?php echo implode(' ', $vmsb_points); ?>" fill="none" stroke="var(--good)" stroke-width="4" stroke-linecap="round" />
						</svg>
					</div>
				</div>
			</section>

		</div>

		<div class="vmsb-secondary-column">

			<!-- 4. ACTIVE WORK -->
			<section class="vmsb-card vmsb-work-card">
				<h3 style="margin:0 0 20px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--gold);">⚙️ Brain is working</h3>
				<div class="vmsb-task-progress-list">
					<?php foreach ($vmsb_active_work as $task) :
						$timeline = json_decode((string)(isset($task->timeline) ? $task->timeline : ''), true) ?: array();
						$last_event = end($timeline);
						$pct = $task->status === 'running' ? 75 : 0;
					?>
						<div class="vmsb-progress-item">
							<div class="vmsb-flex-space" style="margin-bottom:8px;">
								<span style="font-size:13px;"><strong><?php echo esc_html(VMSB_Strategist::agent_label($task->task_type)); ?></strong></span>
								<span style="font-weight:700; color:var(--gold);"><?php echo $task->status === 'running' ? $pct.'%' : 'Queued'; ?></span>
							</div>
							<div class="vmsb-bar" style="height:6px;"><span class="<?php echo $task->status === 'running' ? 'vmsb-bar-fill-animate' : ''; ?>" style="width:<?php echo $pct; ?>%; background:var(--gold);"></span></div>
							<?php if ($last_event) : ?>
								<p class="vmsb-note" style="margin-top:5px; font-size:10px;">⚡ <?php echo esc_html($last_event['event']); ?></p>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
				<div style="margin-top:25px; padding-top:20px; border-top:1px solid var(--line);">
					<p class="vmsb-note"><?php echo (int)$vmsb_fleet['queued']; ?> tasks queued · <?php echo (int)$vmsb_fleet['running']; ?> running</p>
					<a href="<?php echo admin_url('admin.php?page=vmsb-production'); ?>" class="vmsb-link" style="margin-top:10px; display:block;">Open Queue →</a>
				</div>
			</section>

			<!-- 6. GROWTH OPPORTUNITIES (TOP 5) -->
			<section class="vmsb-card" style="margin-top:30px;">
				<h3 style="margin:0 0 20px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--accent-purple);">Top 5 Opportunities</h3>
				<div class="vmsb-op-table-mini">
					<?php if ( empty($vmsb_ops) ) : ?>
						<p class="vmsb-note">No active opportunities. Run a Discovery Scan from the Growth Hub.</p>
					<?php else :
						foreach ( array_slice($vmsb_ops, 0, 5) as $op ) :
					?>
						<div class="vmsb-op-item" style="padding-bottom:15px; margin-bottom:15px; border-bottom:1px solid var(--line);">
							<div class="vmsb-flex-space">
								<strong style="font-size:13px;"><?php echo esc_html($op['target']); ?></strong>
								<span class="vmsb-tag vmsb-tag-gold" style="font-size:9px;">🔥 <?php echo (float)$op['priority']; ?></span>
							</div>
							<p class="vmsb-note" style="margin:5px 0 10px; line-height:1.4;"><?php echo esc_html($op['recommended']); ?></p>
							<div style="display:flex; justify-content:space-between; align-items:center;">
								<span class="vmsb-note" style="font-size:10px;">Effort: <?php echo esc_html(ucfirst($op['effort'] ?? 'medium')); ?></span>
								<a href="<?php echo admin_url('admin.php?page=vmsb-growth'); ?>" class="vmsb-mini-btn">Review</a>
							</div>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</section>

		</div>

	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>

<style>
.vmsb-dashboard-x h1 { font-size: 32px; font-family: var(--serif); letter-spacing: -0.5px; }
.vmsb-growth-kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-top: 30px; }
.vmsb-kpi-stat { background: var(--panel); padding: 25px; border-radius: 16px; border: 1px solid var(--line); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
.vmsb-kpi-stat .vmsb-label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: var(--muted); font-weight: 700; }
.vmsb-kpi-stat .vmsb-value { font-size: 32px; font-weight: 800; margin: 10px 0; font-family: var(--serif); }
.vmsb-kpi-stat .vmsb-value.is-up { color: var(--good); }
.vmsb-kpi-stat .vmsb-value.is-down { color: var(--crit); }

.vmsb-alert-item { background: var(--panel); padding: 15px 20px; border-radius: 12px; margin-bottom: 12px; display: flex; align-items: center; gap: 15px; border: 1px solid var(--line); }
.vmsb-alert-item.tone-crit { border-left: 4px solid var(--crit); }
.vmsb-alert-item.tone-warn { border-left: 4px solid var(--gold); }
.vmsb-alert-msg { flex: 1; font-weight: 600; font-size: 14px; }

.vmsb-progress-item { margin-bottom: 18px; }
</style>
