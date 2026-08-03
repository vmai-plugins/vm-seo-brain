<?php
defined( 'ABSPATH' ) || exit;

$usage = new VMSB_Usage();
$summary = $usage->get_summary(30);
$providers = $usage->get_provider_breakdown(30);
$daily = $usage->get_daily_usage(14);

// Total Budget
$cap = (int) VMSB_Settings::get( 'max_ai_calls_day', 500 );
$calls_today = (new VMSB_AI_Router())->calls_today();
$budget_pct = round(($calls_today / $cap) * 100);
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Resource Management</p>
			<h1>AI Usage & Costs</h1>
			<p class="vmsb-sub">Tracking token efficiency and API expenditure across your autonomous chain.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" onclick="window.location.reload()">Refresh Data</button>
			<a href="<?php echo esc_url( admin_url('admin.php?page=vmsb-settings&tab=autonomy') ); ?>" class="vmsb-btn vmsb-btn-gold">Adjust Budget</a>
		</div>
	</header>

	<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 30px;">
		<div class="vmsb-card">
			<span class="vmsb-note">Estimated Cost (30d)</span>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--gold);">$<?php echo number_format($summary['total_cost'] ?? 0, 2); ?></span>
			</div>
			<p class="vmsb-note">Managed across <?php echo count($providers); ?> providers.</p>
		</div>

		<div class="vmsb-card">
			<span class="vmsb-note">Token Velocity (30d)</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo number_format(($summary['total_tokens'] ?? 0) / 1000000, 2); ?>M</span>
			</div>
			<p class="vmsb-note">Total context volume.</p>
		</div>

		<div class="vmsb-card">
			<span class="vmsb-note">Intelligence Calls (30d)</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo number_format($summary['total_calls'] ?? 0); ?></span>
			</div>
			<p class="vmsb-note">Total reasoning cycles.</p>
		</div>

		<div class="vmsb-card" style="border-left: 4px solid <?php echo $budget_pct > 80 ? 'var(--crit)' : 'var(--good)'; ?>;">
			<span class="vmsb-note">Daily Call Budget</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo $calls_today; ?></span>
				<span class="vmsb-of">/ <?php echo $cap; ?></span>
			</div>
			<div class="vmsb-bar" style="height:6px; margin-top:10px;"><span style="width:<?php echo min(100, $budget_pct); ?>%; background:<?php echo $budget_pct > 80 ? 'var(--crit)' : 'var(--good)'; ?>;"></span></div>
		</div>
	</div>

	<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
		<!-- DAILY COST CHART -->
		<article class="vmsb-card vmsb-card-wide">
			<h2>Daily Expenditure (14d)</h2>
			<?php
			$costs = wp_list_pluck($daily, 'cost');
			$max_cost = max($costs ?: array(1)) ?: 1;
			$points = [];
			$width = 800; $height = 150;
			if (count($daily) > 1) {
				$step = $width / (count($daily) - 1);
				foreach ($costs as $i => $c) {
					$x = $i * $step;
					$y = $height - ($c / $max_cost * $height);
					$points[] = "$x,$y";
				}
			}
			?>
			<div class="vmsb-chart-container" style="height:180px; width:100%; margin:25px 0;">
				<svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
					<defs>
						<linearGradient id="usageGradient" x1="0%" y1="0%" x2="0%" y2="100%">
							<stop offset="0%" style="stop-color:var(--gold); stop-opacity:0.2" />
							<stop offset="100%" style="stop-color:var(--gold); stop-opacity:0" />
						</linearGradient>
					</defs>
					<?php if ($points) : ?>
					<path d="M0,<?php echo $height; ?> L<?php echo implode(' L', $points); ?> L<?php echo $width; ?>,<?php echo $height; ?> Z" fill="url(#usageGradient)" />
					<polyline points="<?php echo implode(' ', $points); ?>" fill="none" stroke="var(--gold)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" />
					<?php else : ?>
						<text x="50%" y="50%" fill="var(--muted)" text-anchor="middle">Insufficient data for chart.</text>
					<?php endif; ?>
				</svg>
			</div>
		</article>

		<!-- PROVIDER BREAKDOWN -->
		<article class="vmsb-card">
			<h2>Provider Efficiency</h2>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table" style="width:100%;">
					<thead>
						<tr>
							<th>Provider</th>
							<th style="text-align:right;">Cost</th>
							<th style="text-align:right;">Calls</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($providers as $p) : ?>
							<tr>
								<td><strong style="color:var(--text);"><?php echo esc_html(ucfirst($p->provider)); ?></strong></td>
								<td style="text-align:right;">$<?php echo number_format($p->cost, 3); ?></td>
								<td style="text-align:right;"><?php echo number_format($p->calls); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if (!$providers) : ?>
							<tr><td colspan="3" class="vmsb-note">No usage recorded.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<div class="vmsb-card vmsb-card-wide" style="margin-top:30px;">
		<h2>Real-Time Latency Monitor</h2>
		<p class="vmsb-note">Average response time for the last 10 requests per provider.</p>
		<div class="vmsb-latency-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap:20px; margin-top:20px;">
			<?php
			$latency_stats = get_transient( 'vmsb_ai_latency' ) ?: array();
			foreach ($latency_stats as $provider => $latencies) :
				$avg = count($latencies) ? array_sum($latencies) / count($latencies) : 0;
				$tone = $avg > 5 ? 'var(--crit)' : ($avg > 2 ? 'var(--high)' : 'var(--good)');
			?>
				<div class="vmsb-stat-mini" style="border-left: 2px solid <?php echo $tone; ?>; padding-left:15px;">
					<span class="vmsb-note"><?php echo esc_html(ucfirst($provider)); ?></span>
					<p style="margin:5px 0 0; font-weight:700; font-size:18px; color:<?php echo $tone; ?>;"><?php echo round($avg, 2); ?>s</p>
				</div>
			<?php endforeach; ?>
			<?php if (!$latency_stats) : ?><p class="vmsb-note">No latency data yet.</p><?php endif; ?>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
