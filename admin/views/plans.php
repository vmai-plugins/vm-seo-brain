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

$checkout_base = 'https://vmstudio.digital/checkout/';
$site_url = urlencode( home_url() );
?>

<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Agency Resources</p>
			<h1>Billing & Usage</h1>
			<p class="vmsb-sub">Current Tier: <strong style="color:var(--gold); text-transform:uppercase;"><?php echo esc_html($current_plan); ?></strong> &mdash; Track AI expenditure and license status.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" onclick="window.location.reload()">Refresh Data</button>
			<a href="<?php echo esc_url( admin_url('admin.php?page=vmsb-settings') ); ?>" class="vmsb-btn vmsb-btn-gold">Adjust Limits</a>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="subscription">💳 Subscription Plans</button>
		<button class="vmsb-tab" data-tab="usage">📊 AI Expenditure</button>
		<button class="vmsb-tab" data-tab="latency">⚡ Provider Health</button>
	</div>

	<!-- SUBSCRIPTION PLANS PANEL -->
	<section class="vmsb-panel is-active" data-panel="subscription">
		<div class="vmsb-plans-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-top: 20px;">
			<!-- FREE PLAN -->
			<div class="vmsb-plan-card <?php echo 'free' === $current_plan ? 'is-active' : ''; ?>" style="background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 40px 30px; display: flex; flex-direction: column; position: relative;">
				<?php if('free' === $current_plan): ?><span style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--gold); color: #000; font-size: 10px; font-weight: bold; padding: 4px 12px; border-radius: 20px;">CURRENT PLAN</span><?php endif; ?>
				<div class="vmsb-plan-head">
					<h3 style="margin: 0; font-family: var(--serif); font-size: 24px;">Starter</h3>
					<div class="vmsb-plan-price" style="font-size: 36px; font-weight: bold; margin: 20px 0; color: var(--gold);">FREE <span style="font-size: 14px; color: var(--muted); font-weight: normal;">/ forever</span></div>
				</div>
				<ul class="vmsb-plan-features" style="list-style: none; margin: 30px 0; padding: 0; flex-grow: 1;">
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ 3 Posts per day</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ 100 Keywords tracking</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px; opacity: 0.4; text-decoration: line-through;">✕ Trend Scout 2026</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px; opacity: 0.4; text-decoration: line-through;">✕ Competitor Hijacking</li>
				</ul>
				<button class="vmsb-btn" disabled>Currently Active</button>
			</div>

			<!-- PRO PLAN -->
			<div class="vmsb-plan-card <?php echo 'pro' === $current_plan ? 'is-active' : ''; ?>" style="background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 40px 30px; display: flex; flex-direction: column; position: relative;">
				<?php if('pro' === $current_plan): ?><span style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--gold); color: #000; font-size: 10px; font-weight: bold; padding: 4px 12px; border-radius: 20px;">CURRENT PLAN</span><?php endif; ?>
				<div class="vmsb-plan-head">
					<h3 style="margin: 0; font-family: var(--serif); font-size: 24px;">Pro Authority</h3>
					<div class="vmsb-plan-price" style="font-size: 36px; font-weight: bold; margin: 20px 0; color: var(--gold);">₹2,499 <span style="font-size: 14px; color: var(--muted); font-weight: normal;">/ month</span></div>
				</div>
				<ul class="vmsb-plan-features" style="list-style: none; margin: 30px 0; padding: 0; flex-grow: 1;">
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ 15 Posts per day</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ 1,000 Keywords tracking</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ Trend Scout 2026 RSS</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ Competitor Hijacking (Thief)</li>
				</ul>
				<a href="<?php echo $checkout_base; ?>?plan=pro&product=vm-seo-brain&site=<?php echo $site_url; ?>" target="_blank" class="vmsb-btn vmsb-btn-gold">
					<?php echo 'pro' === $current_plan ? 'Active' : 'Upgrade to Pro'; ?>
				</a>
			</div>

			<!-- ELITE PLAN -->
			<div class="vmsb-plan-card <?php echo 'elite' === $current_plan ? 'is-active' : ''; ?>" style="background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 40px 30px; display: flex; flex-direction: column; position: relative;">
				<?php if('elite' === $current_plan): ?><span style="position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--gold); color: #000; font-size: 10px; font-weight: bold; padding: 4px 12px; border-radius: 20px;">CURRENT PLAN</span><?php endif; ?>
				<div class="vmsb-plan-head">
					<h3 style="margin: 0; font-family: var(--serif); font-size: 24px;">Elite Dominance</h3>
					<div class="vmsb-plan-price" style="font-size: 36px; font-weight: bold; margin: 20px 0; color: var(--gold);">₹5,999 <span style="font-size: 14px; color: var(--muted); font-weight: normal;">/ month</span></div>
				</div>
				<ul class="vmsb-plan-features" style="list-style: none; margin: 30px 0; padding: 0; flex-grow: 1;">
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ 50+ Posts per day</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ Unlimited Keywords</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ Programmatic SEO Engine</li>
					<li style="margin-bottom: 12px; font-size: 13px; display: flex; align-items: center; gap: 10px;">✓ Vector Memory Access</li>
				</ul>
				<a href="<?php echo $checkout_base; ?>?plan=elite&product=vm-seo-brain&site=<?php echo $site_url; ?>" target="_blank" class="vmsb-btn vmsb-btn-gold">
					<?php echo 'elite' === $current_plan ? 'Active' : 'Get Elite Access'; ?>
				</a>
			</div>
		</div>

		<div style="margin-top: 40px; text-align: center; background: rgba(201, 162, 39, 0.05); padding: 30px; border-radius: 12px; border: 1px dashed var(--gold);">
			<h2 style="margin: 0 0 10px;">Already have a license key?</h2>
			<div id="vmsb-license-form" class="vmsb-inline-form" style="justify-content: center; gap: 15px;">
				<input type="text" name="license_key" placeholder="VMSB-XXXX-XXXX-XXXX" style="min-width: 300px; height: 45px;" value="<?php echo esc_attr( get_option('vmsb_license', [])['key'] ?? '' ); ?>">
				<button class="vmsb-btn vmsb-btn-gold" data-vmsb="license-verify" data-vmsb-form="vmsb-license-form">Activate License</button>
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
</div>
