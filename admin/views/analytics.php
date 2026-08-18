<?php
defined( 'ABSPATH' ) || exit;

/**
 * Analytics Bucket — VM SEO Brain X.
 *
 * Jobs: Performance Tracking, GSC Data, GA4 ROI, Conversion Reports.
 */

$vmsb_performance = new VMSB_Performance();
$vmsb_growth      = new VMSB_Growth();
$vmsb_stats       = $vmsb_performance->business_summary();
$vmsb_roi_val     = VMSB_Outcome_Ledger::calculate_blitz_value();

?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Growth Measurement</p>
			<h1>Analytics & Reporting</h1>
			<p class="vmsb-sub">Tracking traffic, conversions, and financial ROI.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="measure-outcomes">Sync Data</button>
			<button class="vmsb-btn vmsb-btn-gold" onclick="window.print()">Export Report</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="performance">🏢 Executive</button>
		<button class="vmsb-tab" data-tab="gsc">🎯 SEO Manager</button>
		<button class="vmsb-tab" data-tab="ga4">💰 Conversion ROI</button>
		<button class="vmsb-tab" data-tab="technical">⚙️ Technical</button>
		<button class="vmsb-tab" data-tab="reports">📄 Boardroom Reports</button>
	</div>

	<!-- EXECUTIVE -->
	<div class="vmsb-panel is-active" data-panel="performance">
		<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); margin-top: 20px; gap: 20px;">
			<div class="vmsb-card">
				<span class="vmsb-note">Monthly Traffic Value</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--good);">$<?php echo number_format($vmsb_roi_val['estimated_value']); ?></span></div>
			</div>
			<div class="vmsb-card">
				<span class="vmsb-note">Organic Growth</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--good);">+<?php echo (int)$vmsb_stats['pct_change']; ?>%</span></div>
			</div>
		</div>

		<article class="vmsb-card vmsb-card-wide" style="margin-top:30px;">
			<?php
			// Reuse the complex growth trajectory from previous intelligence hub
			$vmsb_history = $vmsb_growth->series(90);
			$vmsb_clicks = wp_list_pluck($vmsb_history, 'clicks');
			// max() on an empty array is a fatal ValueError in PHP 8, and ?:
			// cannot catch it - the throw happens before the coalesce. series()
			// is empty on any site whose metrics table has no rows in range
			// (fresh install, or Google never connected), which would have
			// white-screened this page rather than drawing a flat chart.
			$vmsb_max = max($vmsb_clicks ?: array(0)) ?: 1;
			$vmsb_width = 1000; $vmsb_height = 250;
			$vmsb_points = [];
			if (count($vmsb_history) > 1) {
				$vmsb_step = $vmsb_width / (count($vmsb_history) - 1);
				foreach ($vmsb_clicks as $i => $c) {
					$vmsb_x = $i * $vmsb_step;
					$vmsb_y = $vmsb_height - ($c / $vmsb_max * $vmsb_height);
					$vmsb_points[] = "$vmsb_x,$vmsb_y";
				}
			}
			?>
			<h2 style="margin-bottom:20px; font-family:var(--serif);">Organic Click Trajectory (90 Days)</h2>
			<div style="height:250px; width:100%; position:relative;">
				<svg viewBox="0 0 <?php echo $vmsb_width; ?> <?php echo $vmsb_height; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
					<polyline points="<?php echo implode(' ', $vmsb_points); ?>" fill="none" stroke="var(--good)" stroke-width="4" stroke-linecap="round" />
				</svg>
			</div>
		</article>
	</div>

	<!-- GSC -->
	<div class="vmsb-panel" data-panel="gsc">
		<?php
		if (!defined('VMSB_NESTED')) define('VMSB_NESTED', true);
		include VMSB_DIR . 'admin/views/keywords.php';
		?>
	</div>

	<!-- GA4 -->
	<div class="vmsb-panel" data-panel="ga4">
		<?php
		$vmsb_ga4 = new VMSB_GA4();
		if ( ! $vmsb_ga4->is_connected() ) :
		?>
			<article class="vmsb-card">
				<h2 style="font-family:var(--serif);">Conversion ROI</h2>
				<p class="vmsb-note">Connect Google Analytics 4 to see which pages actually drive revenue, not just traffic. Add your GA4 Property ID and authorize Google access in <a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-settings' ) ); ?>">Settings</a>.</p>
			</article>
		<?php else :
			$vmsb_ga4_metrics = $vmsb_ga4->get_all_landing_page_metrics( 30 );
			uasort( $vmsb_ga4_metrics, fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
			$vmsb_ga4_total_revenue    = array_sum( wp_list_pluck( $vmsb_ga4_metrics, 'revenue' ) );
			$vmsb_ga4_total_conversions = array_sum( wp_list_pluck( $vmsb_ga4_metrics, 'conversions' ) );
		?>
			<div class="vmsb-grid" style="grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
				<div class="vmsb-card">
					<span class="vmsb-note">Revenue (Last 30 Days)</span>
					<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--good);">$<?php echo number_format( $vmsb_ga4_total_revenue, 2 ); ?></span></div>
				</div>
				<div class="vmsb-card">
					<span class="vmsb-note">Conversions</span>
					<div class="vmsb-figure"><span class="vmsb-number"><?php echo number_format( $vmsb_ga4_total_conversions ); ?></span></div>
				</div>
				<div class="vmsb-card">
					<span class="vmsb-note">Landing Pages Tracked</span>
					<div class="vmsb-figure"><span class="vmsb-number"><?php echo number_format( count( $vmsb_ga4_metrics ) ); ?></span></div>
				</div>
			</div>

			<article class="vmsb-card vmsb-card-wide">
				<h2 style="margin-bottom:20px; font-family:var(--serif);">Revenue by Landing Page</h2>
				<?php if ( empty( $vmsb_ga4_metrics ) ) : ?>
					<div class="vmsb-empty"><p class="vmsb-note">No GA4 data returned for the last 30 days.</p></div>
				<?php else : ?>
					<div class="vmsb-table-wrap">
						<table class="vmsb-table vmsb-table-full">
							<thead>
								<tr>
									<th>Page</th>
									<th>Sessions</th>
									<th>Conversions</th>
									<th>Engagement Rate</th>
									<th>Revenue</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_slice( $vmsb_ga4_metrics, 0, 30, true ) as $vmsb_path => $vmsb_m ) :
									$vmsb_post_id = url_to_postid( home_url( $vmsb_path ) );
									$vmsb_label   = $vmsb_post_id ? get_the_title( $vmsb_post_id ) : $vmsb_path;
								?>
									<tr>
										<td>
											<strong><?php echo esc_html( $vmsb_label ); ?></strong>
											<div class="vmsb-note" style="font-size:11px;"><?php echo esc_html( $vmsb_path ); ?></div>
										</td>
										<td><?php echo (int) $vmsb_m['sessions']; ?></td>
										<td><?php echo (int) $vmsb_m['conversions']; ?></td>
										<td><?php echo esc_html( number_format( $vmsb_m['engagement'] * 100, 1 ) ); ?>%</td>
										<td><strong style="color:var(--good);">$<?php echo number_format( $vmsb_m['revenue'], 2 ); ?></strong></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</article>
		<?php endif; ?>
	</div>

	<!-- TECHNICAL -->
	<div class="vmsb-panel" data-panel="technical">
		<?php include VMSB_DIR . 'admin/views/subviews/technical_report.php'; ?>
	</div>

	<!-- REPORTS -->
	<div class="vmsb-panel" data-panel="reports">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<?php
				$vmsb_boardroom = get_option('vmsb_boardroom_report');
				if ( $vmsb_boardroom ) :
				?>
				<article class="vmsb-card vmsb-card-wide boardroom-report" id="vmsb-boardroom-report" style="background:#fff; color:#111; padding:50px; border-radius:0; box-shadow:0 20px 50px rgba(0,0,0,0.2);">
					<header style="border-bottom:2px solid #eee; margin-bottom:40px; padding-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
						<div>
							<h1 style="color:#111; font-size:32px; margin:0;">Strategic Boardroom Briefing</h1>
							<p style="color:#666; margin:5px 0 0;">Period: <?php echo date('F Y', strtotime($vmsb_boardroom['date'])); ?></p>
						</div>
						<div style="text-align:right;">
							<strong style="color:var(--gold); font-size:18px;">VM SEO BRAIN X</strong>
						</div>
					</header>

					<div class="vmsb-report-kpis" style="display:grid; grid-template-columns: repeat(3, 1fr); gap:30px; margin-bottom:40px;">
						<div style="background:#f9f9f9; padding:20px; border-radius:8px;">
							<span style="font-size:11px; text-transform:uppercase; color:#999;">Organic Clicks</span>
							<div style="font-size:24px; font-weight:800;"><?php echo number_format($vmsb_boardroom['stats']['clicks']); ?></div>
						</div>
						<div style="background:#f9f9f9; padding:20px; border-radius:8px;">
							<span style="font-size:11px; text-transform:uppercase; color:#999;">PPC Value Built</span>
							<div style="font-size:24px; font-weight:800; color:#5fa778;">$<?php echo number_format($vmsb_boardroom['stats']['value']); ?></div>
						</div>
						<div style="background:#f9f9f9; padding:20px; border-radius:8px;">
							<span style="font-size:11px; text-transform:uppercase; color:#999;">Growth ROI</span>
							<div style="font-size:24px; font-weight:800;"><?php echo $vmsb_boardroom['stats']['roi']; ?>%</div>
						</div>
					</div>

					<div class="vmsb-report-text" style="line-height:1.8; font-size:16px;">
						<?php echo wp_kses_post(wpautop($vmsb_boardroom['text'])); ?>
					</div>

					<footer style="margin-top:60px; padding-top:20px; border-top:1px solid #eee; font-size:12px; color:#999; text-align:center;">
						Generated by VM SEO Brain X Autonomous Intelligence. Confidential Strategic Document.
					</footer>
				</article>
				<button class="vmsb-btn vmsb-btn-gold" style="margin-top:20px;" onclick="VMSB.api('generate-report').then(()=>location.reload())">Regenerate Boardroom Report</button>
				<?php else : ?>
				<article class="vmsb-card vmsb-card-wide">
					<div class="vmsb-empty">
						<h2 style="font-family:var(--serif);">No Boardroom Briefing Yet</h2>
						<p>The Brain needs to synthesize your current performance data into a strategic narrative.</p>
						<button class="vmsb-btn vmsb-btn-gold" data-vmsb="generate-report">Synthesize Monthly Report</button>
					</div>
				</article>
				<?php endif; ?>
			</section>

			<aside>
				<article class="vmsb-card">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; color:var(--gold);">Boardroom Standards</h3>
					<p class="vmsb-note">The Boardroom Briefing is a high-fidelity, printable document designed for stakeholders. It skips technical SEO jargon and focuses on:</p>
					<ul style="margin:15px 0 0; padding-left:18px; font-size:13px; color:var(--muted); line-height:1.6;">
						<li>Financial Value (PPC Savings)</li>
						<li>Market Share Capture</li>
						<li>Competitive Moat Building</li>
						<li>Strategic Growth Trajectory</li>
					</ul>
				</article>
			</aside>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>

