<?php
defined( 'ABSPATH' ) || exit;

/**
 * Performance & Logs Hub — VM SEO Brain.
 * Unifies Traffic Analytics, Conversion ROI, Action History & Rollback, Learning Outcomes, and System Logs.
 */

$vmsb_performance = new VMSB_Performance();
$vmsb_growth      = new VMSB_Growth();
$vmsb_stats       = $vmsb_performance->business_summary();
$vmsb_roi_val     = VMSB_Outcome_Ledger::calculate_blitz_value();

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'performance';
if ( ! in_array( $active_tab, array( 'performance', 'ga4', 'rollback', 'experiments', 'logs' ), true ) ) {
	$active_tab = 'performance';
}
?>

<div class="vmsb-analytics-hub">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Measurement, Rollback & Observability</p>
			<h1>Performance & Logs</h1>
			<p class="vmsb-sub">Track real organic growth, conversion ROI, inspect every autonomous AI action, and undo changes in 1 click.</p>
		</div>
		<div class="vmsb-head-actions">
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="measure-outcomes">🔄 Measure Outcomes</button>
			<button type="button" class="vmsb-btn vmsb-btn-gold" onclick="window.print()">Export Report</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<!-- NAVIGATION TABS -->
	<div class="vmsb-tabs" style="margin-top: 24px;">
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'performance' ? 'is-active' : ''; ?>" data-tab="performance">
			📈 Traffic Trends
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'ga4' ? 'is-active' : ''; ?>" data-tab="ga4">
			💰 Conversion ROI (GA4)
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'rollback' ? 'is-active' : ''; ?>" data-tab="rollback">
			🔄 Action History & Rollback
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'experiments' ? 'is-active' : ''; ?>" data-tab="experiments">
			🧪 Outcomes & Learning
		</button>
		<button type="button" class="vmsb-tab <?php echo $active_tab === 'logs' ? 'is-active' : ''; ?>" data-tab="logs">
			📜 System Logs
		</button>
	</div>

	<!-- TAB 1: TRAFFIC TRENDS -->
	<div class="vmsb-panel <?php echo $active_tab === 'performance' ? 'is-active' : ''; ?>" data-panel="performance">
		<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 24px;">
			<div class="vmsb-card vmsb-kpi-card">
				<span class="vmsb-status-label">Estimated Search Value</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color: var(--good);">$<?php echo number_format( abs( $vmsb_roi_val['estimated_value'] ?? 0 ) ); ?></span></div>
				<p class="vmsb-note">Monthly PPC equivalent</p>
			</div>
			<div class="vmsb-card vmsb-kpi-card">
				<span class="vmsb-status-label">Organic Growth</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color: var(--good);">+<?php echo (int) ( $vmsb_stats['pct_change'] ?? 0 ); ?>%</span></div>
				<p class="vmsb-note">Rolling 30-day velocity</p>
			</div>
			<div class="vmsb-card vmsb-kpi-card">
				<span class="vmsb-status-label">Tracked Keywords</span>
				<div class="vmsb-figure"><span class="vmsb-number"><?php echo number_format( (int) ( $vmsb_stats['total_keywords'] ?? 0 ) ); ?></span></div>
				<p class="vmsb-note">In active rank index</p>
			</div>
			<div class="vmsb-card vmsb-kpi-card">
				<span class="vmsb-status-label">Top 10 Positions</span>
				<div class="vmsb-figure"><span class="vmsb-number" style="color: var(--gold);"><?php echo (int) ( $vmsb_stats['top_10'] ?? 0 ); ?></span></div>
				<p class="vmsb-note">Page 1 Google rankings</p>
			</div>
		</div>

		<article class="vmsb-card vmsb-card-wide">
			<?php
			$vmsb_history = $vmsb_growth->series( 90 );
			$vmsb_clicks = wp_list_pluck( $vmsb_history, 'clicks' );
			$vmsb_max = max( $vmsb_clicks ?: array( 0 ) ) ?: 1;
			$vmsb_width = 1000;
			$vmsb_height = 220;
			$vmsb_points = array();

			if ( count( $vmsb_history ) > 1 ) {
				$vmsb_step = $vmsb_width / ( count( $vmsb_history ) - 1 );
				foreach ( $vmsb_clicks as $i => $c ) {
					$vmsb_x = $i * $vmsb_step;
					$vmsb_y = $vmsb_height - ( $c / $vmsb_max * $vmsb_height );
					$vmsb_points[] = "{$vmsb_x},{$vmsb_y}";
				}
			}
			?>
			<div class="vmsb-flex-space" style="margin-bottom: 18px;">
				<h2 style="font-family: var(--serif); margin: 0;">Organic Click Trajectory (90 Days)</h2>
				<span class="vmsb-note">Daily GSC click volume</span>
			</div>
			<div style="height: 220px; width: 100%; position: relative; padding: 10px 0;">
				<?php if ( empty( $vmsb_points ) ) : ?>
					<div class="vmsb-empty-state" style="margin: 0; padding: 30px;">
						<p class="vmsb-empty-desc" style="margin: 0;">No 90-day search metrics available yet. Ensure Google Search Console is connected in Settings.</p>
					</div>
				<?php else : ?>
					<svg viewBox="0 0 <?php echo $vmsb_width; ?> <?php echo $vmsb_height; ?>" preserveAspectRatio="none" style="width: 100%; height: 100%; overflow: visible;">
						<polyline points="<?php echo implode( ' ', $vmsb_points ); ?>" fill="none" stroke="var(--good)" stroke-width="4" stroke-linecap="round" />
					</svg>
				<?php endif; ?>
			</div>
		</article>
	</div>

	<!-- TAB 2: GA4 CONVERSIONS -->
	<div class="vmsb-panel <?php echo $active_tab === 'ga4' ? 'is-active' : ''; ?>" data-panel="ga4">
		<?php
		$vmsb_ga4 = new VMSB_GA4();
		if ( ! $vmsb_ga4->is_connected() ) :
		?>
			<div class="vmsb-empty-state">
				<div class="vmsb-empty-icon">📊</div>
				<h3 class="vmsb-empty-title">Connect Google Analytics 4</h3>
				<p class="vmsb-empty-desc">Link GA4 to measure real conversion value, customer inquiries, and e-commerce transactions generated by your SEO content.</p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-settings&tab=google' ) ); ?>" class="vmsb-btn vmsb-btn-gold">
					Configure GA4 in Settings →
				</a>
			</div>
		<?php else :
			$vmsb_ga4_metrics = $vmsb_ga4->get_all_landing_page_metrics( 30 );
		?>
			<article class="vmsb-card vmsb-card-wide">
				<h2 style="font-family: var(--serif); margin: 0 0 16px;">Landing Page Conversion Attributions</h2>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead>
							<tr>
								<th>Page Path</th>
								<th>Sessions</th>
								<th>Conversions</th>
								<th>Conversion Rate</th>
								<th>Revenue Value</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $vmsb_ga4_metrics ) ) : ?>
								<tr><td colspan="5" class="vmsb-note">No conversion data recorded in the last 30 days.</td></tr>
							<?php else :
								foreach ( $vmsb_ga4_metrics as $m ) : ?>
									<tr>
										<td><code><?php echo esc_html( $m['pagePath'] ); ?></code></td>
										<td><?php echo number_format( (int) $m['sessions'] ); ?></td>
										<td><strong style="color: var(--good);"><?php echo number_format( (int) $m['conversions'] ); ?></strong></td>
										<td><?php echo round( (float) $m['conversionRate'] * 100, 2 ); ?>%</td>
										<td>$<?php echo number_format( (float) ( $m['value'] ?? 0 ), 2 ); ?></td>
									</tr>
								<?php endforeach;
							endif; ?>
						</tbody>
					</table>
				</div>
			</article>
		<?php endif; ?>
	</div>

	<!-- TAB 3: ACTION HISTORY & ROLLBACK -->
	<div class="vmsb-panel <?php echo $active_tab === 'rollback' ? 'is-active' : ''; ?>" data-panel="rollback">
		<?php
		if ( ! defined( 'VMSB_NESTED' ) ) define( 'VMSB_NESTED', true );
		include VMSB_DIR . 'admin/views/observability.php';
		?>
	</div>

	<!-- TAB 4: OUTCOMES & EXPERIMENTS -->
	<div class="vmsb-panel <?php echo $active_tab === 'experiments' ? 'is-active' : ''; ?>" data-panel="experiments">
		<?php include VMSB_DIR . 'admin/views/learning.php'; ?>
	</div>

	<!-- TAB 5: SYSTEM LOGS -->
	<div class="vmsb-panel <?php echo $active_tab === 'logs' ? 'is-active' : ''; ?>" data-panel="logs">
		<?php include VMSB_DIR . 'admin/views/logs.php'; ?>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
