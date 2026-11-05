<?php
defined( 'ABSPATH' ) || exit;

/**
 * Unified Billing, Plans, and AI Usage Command Center.
 */

$current_plan = VMSB_License::plan();
$limits = VMSB_License::limits();

// Usage Data
$usage = new VMSB_Usage();
$summary = $usage->get_summary(30);
$providers = $usage->get_provider_breakdown(30);
$daily = $usage->get_daily_usage(14);

$cap = (int) VMSB_Settings::get( 'max_ai_calls_day', 500 );
$calls_today = (new VMSB_AI_Router())->calls_today();
$budget_pct = round(($calls_today / $cap) * 100);
?>

	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Agency Resources</p>
			<h1>Billing & Usage</h1>
			<p class="vmsb-sub">Current Tier: <strong style="color:var(--gold); text-transform:uppercase;">SOVEREIGN ENTERPRISE</strong> &mdash; Track AI expenditure and license status.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" onclick="window.location.reload()">Refresh Data</button>
			<a href="<?php echo esc_url( admin_url('admin.php?page=vmsb-settings') ); ?>" class="vmsb-btn vmsb-btn-gold">Adjust Limits</a>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="subscription">👑 License Status</button>
		<button class="vmsb-tab" data-tab="usage">📊 AI Expenditure</button>
		<button class="vmsb-tab" data-tab="latency">⚡ Provider Health</button>
	</div>

	<!-- SOVEREIGN LICENSE STATUS PANEL -->
	<section class="vmsb-panel is-active" data-panel="subscription">
		<div style="background: rgba(201, 162, 39, 0.08); border: 1px solid var(--gold); border-radius: 16px; padding: 40px 30px; text-align: center; margin-top: 20px;">
			<span style="display: inline-block; font-size: 36px; margin-bottom: 12px;">👑</span>
			<h2 style="margin: 0 0 10px; font-family: var(--serif); font-size: 26px; color: var(--gold);">Sovereign Enterprise Edition &mdash; 100% Free &amp; Unrestricted</h2>
			<p style="margin: 0 auto 20px; max-width: 650px; color: var(--muted); font-size: 15px; line-height: 1.6;">
				All enterprise AI SEO engines (Autonomous Silo Rebuilder, Competitor Hijacking, Programmatic Page Scaler, Vector Memory, and Trend Scout) are <strong>permanently unlocked and free</strong> for this workspace. No subscription or paid plan required.
			</p>
			<div style="display: inline-flex; gap: 20px; flex-wrap: wrap; justify-content: center; margin-top: 15px;">
				<div style="background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 20px; font-size: 13px;">
					<strong>Daily Quota:</strong> Unlimited Posts
				</div>
				<div style="background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 20px; font-size: 13px;">
					<strong>Keyword Tracking:</strong> Unlimited Keywords
				</div>
				<div style="background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 20px; font-size: 13px;">
					<strong>Pro &amp; Elite Engines:</strong> Unlocked &amp; Sovereign
				</div>
			</div>
		</div>
	</section>

	<!-- AI EXPENDITURE PANEL -->
	<section class="vmsb-panel" data-panel="usage">
		<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); margin: 30px 0;">
			<div class="vmsb-card">
				<span class="vmsb-note">Estimated Cost (30d)</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--gold);">$<?php echo number_format($summary['total_cost'] ?? 0, 2); ?></span></div>
			</div>
			<div class="vmsb-card">
				<span class="vmsb-note">Token Velocity (30d)</span>
				<div class="vmsb-figure"><span class="vmsb-number"><?php echo number_format(($summary['total_tokens'] ?? 0) / 1000000, 2); ?>M</span></div>
			</div>
			<div class="vmsb-card">
				<span class="vmsb-note">Intelligence Calls (30d)</span>
				<div class="vmsb-figure"><span class="vmsb-number"><?php echo number_format($summary['total_calls'] ?? 0); ?></span></div>
			</div>
			<div class="vmsb-card" style="border-left: 4px solid <?php echo $budget_pct > 80 ? 'var(--crit)' : 'var(--good)'; ?>;">
				<span class="vmsb-note">Daily Call Budget</span>
				<div class="vmsb-figure"><span class="vmsb-number"><?php echo $calls_today; ?></span><span class="vmsb-of">/ <?php echo $cap; ?></span></div>
			</div>
		</div>

		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<article class="vmsb-card vmsb-card-wide">
				<h2>Daily Expenditure (14d)</h2>
				<?php
				$costs = wp_list_pluck($daily, 'cost');
				$max_cost = max($costs ?: array(1)) ?: 1;
				$points = []; $w = 800; $h = 150;
				if (count($daily) > 1) {
					$step = $w / (count($daily) - 1);
					foreach ($costs as $i => $c) {
						$x = $i * $step;
						$y = $h - ($c / $max_cost * $h);
						$points[] = "$x,$y";
					}
				}
				?>
				<div style="height:180px; width:100%; margin:25px 0;">
					<svg viewBox="0 0 <?php echo $w; ?> <?php echo $h; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
						<?php if ($points) : ?>
						<polyline points="<?php echo implode(' ', $points); ?>" fill="none" stroke="var(--gold)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
						<?php endif; ?>
					</svg>
				</div>
			</article>

			<article class="vmsb-card">
				<h2>Provider Efficiency</h2>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table" style="width:100%;">
						<thead><tr><th>Provider</th><th style="text-align:right;">Cost</th></tr></thead>
						<tbody>
							<?php foreach ($providers as $p) : ?>
								<tr><td><strong><?php echo esc_html(ucfirst($p->provider)); ?></strong></td><td style="text-align:right;">$<?php echo number_format($p->cost, 3); ?></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</article>
		</div>
	</section>

	<!-- LATENCY MONITOR PANEL -->
	<section class="vmsb-panel" data-panel="latency">
		<div class="vmsb-card vmsb-card-wide" style="margin-top:20px;">
			<h2>Real-Time Latency Monitor</h2>
			<div class="vmsb-latency-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:25px; margin-top:25px;">
				<?php
				$latency_stats = get_transient( 'vmsb_ai_latency' ) ?: array();
				foreach ($latency_stats as $provider => $latencies) :
					$avg = count($latencies) ? array_sum($latencies) / count($latencies) : 0;
					$tone = $avg > 5 ? 'var(--crit)' : ($avg > 2 ? 'var(--high)' : 'var(--good)');
				?>
					<div class="vmsb-card" style="border-left: 4px solid <?php echo $tone; ?>;">
						<span class="vmsb-note"><?php echo esc_html(ucfirst($provider)); ?></span>
						<p style="margin:5px 0 0; font-weight:700; font-size:24px; color:<?php echo $tone; ?>;"><?php echo round($avg, 2); ?>s</p>
						<p class="vmsb-note">Avg Response</p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
