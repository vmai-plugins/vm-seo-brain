<?php
defined( 'ABSPATH' ) || exit;

/**
 * VM SEO Brain — Unified Overview Hub.
 * Combines real-time autonomous engine status, 30-day KPI growth metrics,
 * strategic briefing, critical attention items, and active background jobs.
 */

$vmsb_growth_engine = new VMSB_Growth();
$vmsb_brain_engine  = new VMSB_Brain();
$vmsb_performance   = new VMSB_Performance();
$vmsb_keywords      = new VMSB_Keywords();
$vmsb_fixer         = new VMSB_Fixer();

$vmsb_profile      = $vmsb_brain_engine->profile();
$vmsb_biz_summary  = $vmsb_performance->business_summary();
$vmsb_growth_stats = $vmsb_growth_engine->status();
$vmsb_ops          = $vmsb_brain_engine->recall( 'intelligence', 'active_opportunities', array() );
$vmsb_fleet        = VMSB_Strategist::fleet_status();
$vmsb_conv_trend   = class_exists( 'VMSB_GA4' ) ? ( new VMSB_GA4() )->get_conversions_trend( 30 ) : null;

global $wpdb;
$vmsb_active_work = $wpdb->get_results( "SELECT task_type, status, score, timeline FROM {$wpdb->prefix}vmsb_tasks WHERE status IN ('running', 'queued') ORDER BY score DESC LIMIT 4" );

$blitz = VMSB_Outcome_Ledger::calculate_blitz_value();
$is_positive_value = ( $blitz['estimated_value'] ?? 0 ) >= 0;

$s = VMSB_Settings::masked();
$ai_configured = ! empty( $s['ai_primary'] ) && (
	! empty( $s['aipuffer_key'] ) || ! empty( $s['openai_key'] ) ||
	! empty( $s['gemini_key'] ) || ! empty( $s['openrouter_key'] ) ||
	! empty( $s['ollama_url'] ) || ! empty( $s['omniroute_url'] )
);
?>

<div class="vmsb-overview-hub">
	<!-- HEADER SECTION -->
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow"><?php echo esc_html( $vmsb_profile['name'] ?: get_bloginfo( 'name' ) ); ?></p>
			<h1>Overview & Autonomous Control</h1>
			<p class="vmsb-sub">Real-time status of your SEO neural engine, rankings velocity, and editorial queue.</p>
		</div>
		<div class="vmsb-head-actions">
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="opportunity-scan">🔍 Scan Keyword Gaps</button>
			<button type="button" class="vmsb-btn vmsb-btn-ghost" data-vmsb="scan">🛠️ Technical Audit</button>
			<button type="button" class="vmsb-btn vmsb-btn-gold" data-vmsb="agents-run-strategist">⚡ Run Strategist Now</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<!-- 4 TOP KPI METRICS -->
	<div class="vmsb-grid vmsb-kpi-row" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 30px; gap: 18px;">
		<article class="vmsb-card vmsb-kpi-card">
			<span class="vmsb-status-label">Organic Traffic</span>
			<div class="vmsb-figure">
				<?php if ( null === $vmsb_biz_summary['pct_change'] ) : ?>
					<span class="vmsb-number" style="font-size: 22px; color: var(--muted);">Calibrating</span>
				<?php else : ?>
					<span class="vmsb-number" style="color: <?php echo $vmsb_biz_summary['pct_change'] >= 0 ? 'var(--good)' : 'var(--crit)'; ?>;">
						<?php echo $vmsb_biz_summary['pct_change'] >= 0 ? '+' : ''; ?><?php echo esc_html( $vmsb_biz_summary['pct_change'] ); ?>%
					</span>
				<?php endif; ?>
			</div>
			<p class="vmsb-note">vs. previous 30-day baseline</p>
		</article>

		<article class="vmsb-card vmsb-kpi-card">
			<span class="vmsb-status-label">Position Gainers</span>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color: var(--good);">+<?php echo count( $vmsb_biz_summary['top_improved'] ?? array() ); ?></span>
			</div>
			<p class="vmsb-note">Keywords climbing in SERPs</p>
		</article>

		<article class="vmsb-card vmsb-kpi-card">
			<span class="vmsb-status-label">Leads & Conversions</span>
			<div class="vmsb-figure">
				<?php if ( null === $vmsb_conv_trend ) : ?>
					<span class="vmsb-number" style="font-size: 20px; color: var(--muted);">GA4 Disconnected</span>
				<?php else : ?>
					<span class="vmsb-number" style="color: <?php echo $vmsb_conv_trend['pct_change'] >= 0 ? 'var(--good)' : 'var(--crit)'; ?>;">
						<?php echo $vmsb_conv_trend['pct_change'] >= 0 ? '+' : ''; ?><?php echo esc_html( $vmsb_conv_trend['pct_change'] ); ?>%
					</span>
				<?php endif; ?>
			</div>
			<p class="vmsb-note">GA4 30-day conversion trend</p>
		</article>

		<article class="vmsb-card vmsb-kpi-card">
			<span class="vmsb-status-label">Est. Search Traffic Value</span>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color: <?php echo $is_positive_value ? 'var(--good)' : 'var(--crit)'; ?>;">
					$<?php echo number_format( abs( $blitz['estimated_value'] ?? 0 ) ); ?>
				</span>
			</div>
			<p class="vmsb-note">Equivalent PPC ad spend value</p>
		</article>
	</div>

	<!-- 2-COLUMN MAIN LAYOUT -->
	<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap: 26px;">
		<!-- LEFT COLUMN -->
		<div class="vmsb-col-left" style="display: flex; flex-direction: column; gap: 26px;">

			<!-- 1. STRATEGIC BRAIN BRIEFING -->
			<section class="vmsb-card vmsb-card-wide" style="border-left: 4px solid var(--gold);">
				<div class="vmsb-flex-space" style="margin-bottom: 14px;">
					<h2 style="margin: 0; font-family: var(--serif); font-size: 20px; color: var(--gold-soft);">🧠 Strategic Intelligence Briefing</h2>
					<span class="vmsb-tag vmsb-tag-gold">AI Synthesis</span>
				</div>
				<div class="vmsb-briefing-body" style="font-size: 14.5px; line-height: 1.7; color: var(--text);">
					<?php echo $vmsb_brain_engine->get_strategic_briefing(); ?>
				</div>
				<div style="margin-top: 20px; display: flex; gap: 12px; align-items: center;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-content&tab=discovery' ) ); ?>" class="vmsb-btn vmsb-btn-gold vmsb-btn-sm">
						Review Growth Opportunities →
					</a>
					<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="understand">
						Force Re-read Site
					</button>
				</div>
			</section>

			<!-- 2. PRIORITY ACTIONS / NEEDS ATTENTION -->
			<section class="vmsb-card vmsb-card-wide">
				<div class="vmsb-flex-space" style="margin-bottom: 16px;">
					<h2 style="margin: 0; font-family: var(--serif); font-size: 19px;">🚨 Needs Attention</h2>
					<span class="vmsb-note">Surgical fixes requiring review</span>
				</div>

				<div class="vmsb-action-list">
					<?php
					$vmsb_alerts = array();

					// Ranking Drops
					$healer = new VMSB_Healer();
					$vmsb_drops = method_exists( $healer, 'get_recent_drops' ) ? $healer->get_recent_drops( 3 ) : array();
					foreach ( $vmsb_drops as $d ) {
						$vmsb_alerts[] = array(
							'tone' => 'crit',
							'msg'  => 'Ranking Drop: ' . get_the_title( $d['id'] ),
							'url'  => admin_url( 'admin.php?page=vmsb-seo&tab=technical' ),
						);
					}

					// Striking Distance Keywords
					$vmsb_striking = $vmsb_keywords->striking_distance( 5 );
					if ( ! empty( $vmsb_striking ) ) {
						$vmsb_alerts[] = array(
							'tone' => 'warn',
							'msg'  => count( $vmsb_striking ) . ' high-potential keywords in striking distance (positions 11-20)',
							'url'  => admin_url( 'admin.php?page=vmsb-seo&tab=keywords' ),
						);
					}

					// Critical Technical Issues
					$vmsb_technical = $vmsb_fixer->counts();
					if ( ! empty( $vmsb_technical['critical'] ) && $vmsb_technical['critical'] > 0 ) {
						$vmsb_alerts[] = array(
							'tone' => 'crit',
							'msg'  => $vmsb_technical['critical'] . ' critical technical SEO issues affecting indexing',
							'url'  => admin_url( 'admin.php?page=vmsb-seo&tab=technical' ),
						);
					}

					// Cannibalization conflicts
					$vmsb_dupes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT keyword FROM {$wpdb->prefix}vmsb_keywords WHERE post_id > 0 GROUP BY keyword HAVING COUNT(DISTINCT post_id) > 1) x" );
					if ( $vmsb_dupes > 0 ) {
						$vmsb_alerts[] = array(
							'tone' => 'warn',
							'msg'  => $vmsb_dupes . ' keyword cannibalization conflicts detected across published posts',
							'url'  => admin_url( 'admin.php?page=vmsb-content&tab=discovery' ),
						);
					}

					if ( empty( $vmsb_alerts ) ) :
					?>
						<div class="vmsb-empty-state" style="padding: 24px; margin: 0;">
							<div class="vmsb-empty-icon">✅</div>
							<h3 class="vmsb-empty-title">All Systems Healthy</h3>
							<p class="vmsb-empty-desc" style="margin-bottom: 0;">No critical ranking drops, cannibalization conflicts, or broken technical issues detected.</p>
						</div>
					<?php else :
						foreach ( $vmsb_alerts as $alert ) : ?>
							<div class="vmsb-alert-item tone-<?php echo esc_attr( $alert['tone'] ); ?>">
								<span class="vmsb-alert-icon"><?php echo $alert['tone'] === 'crit' ? '🔴' : '🟠'; ?></span>
								<span class="vmsb-alert-msg"><?php echo esc_html( $alert['msg'] ); ?></span>
								<a href="<?php echo esc_url( $alert['url'] ); ?>" class="vmsb-mini-btn vmsb-btn-gold">Fix / Review</a>
							</div>
						<?php endforeach;
					endif; ?>
				</div>
			</section>

			<!-- 3. PERFORMANCE CHART -->
			<section class="vmsb-card vmsb-card-wide" style="padding: 0; overflow: hidden;">
				<div style="padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border);">
					<div style="display: flex; align-items: center; gap: 10px;">
						<strong style="font-size: 16px;">📈 Organic Clicks Trend (30 Days)</strong>
						<?php if ( null !== $vmsb_biz_summary['pct_change'] ) : ?>
							<span class="vmsb-tag vmsb-tag-good">+<?php echo esc_html( $vmsb_biz_summary['pct_change'] ); ?>%</span>
						<?php endif; ?>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-analytics' ) ); ?>" class="vmsb-link" style="font-size: 12.5px;">Full Analytics →</a>
				</div>
				<?php
				$vmsb_series = $vmsb_growth_engine->series( 30 );
				$vmsb_clicks = wp_list_pluck( $vmsb_series, 'clicks' );
				$vmsb_max_clicks = max( $vmsb_clicks ?: array( 0 ) ) ?: 1;
				$vmsb_points = array();
				$vmsb_width = 1000;
				$vmsb_height = 140;

				if ( count( $vmsb_series ) > 1 ) {
					$vmsb_step = $vmsb_width / ( count( $vmsb_series ) - 1 );
					foreach ( $vmsb_clicks as $vmsb_i => $vmsb_c ) {
						$vmsb_x = $vmsb_i * $vmsb_step;
						$vmsb_y = $vmsb_height - ( $vmsb_c / $vmsb_max_clicks * $vmsb_height );
						$vmsb_points[] = "{$vmsb_x},{$vmsb_y}";
					}
				}
				?>
				<div style="height: 140px; width: 100%; position: relative; padding: 15px 0;">
					<?php if ( empty( $vmsb_points ) ) : ?>
						<div style="display: flex; align-items: center; justify-content: center; height: 100%; color: var(--muted); font-size: 13px;">
							No search performance snapshots recorded yet. Connect Google Search Console in Settings.
						</div>
					<?php else : ?>
						<svg viewBox="0 0 <?php echo $vmsb_width; ?> <?php echo $vmsb_height; ?>" preserveAspectRatio="none" style="width: 100%; height: 100%; overflow: visible;">
							<polyline points="<?php echo implode( ' ', $vmsb_points ); ?>" fill="none" stroke="var(--good)" stroke-width="3.5" stroke-linecap="round" />
						</svg>
					<?php endif; ?>
				</div>
			</section>

		</div>

		<!-- RIGHT COLUMN -->
		<div class="vmsb-col-right" style="display: flex; flex-direction: column; gap: 26px;">

			<!-- 1. ACTIVE WORK / BACKGROUND QUEUE -->
			<section class="vmsb-card">
				<div class="vmsb-flex-space" style="margin-bottom: 16px;">
					<h3 style="margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.8px; color: var(--gold);">⚙️ Active AI Fleet</h3>
					<span class="vmsb-tag"><?php echo (int) $vmsb_fleet['running']; ?> Running</span>
				</div>

				<div class="vmsb-task-progress-list">
					<?php if ( empty( $vmsb_active_work ) ) : ?>
						<p class="vmsb-note" style="margin: 10px 0;">Agent queue is clear. Next scheduled batch runs on the next hourly cron.</p>
					<?php else :
						foreach ( $vmsb_active_work as $task ) :
							$timeline = json_decode( (string) ( isset( $task->timeline ) ? $task->timeline : '' ), true ) ?: array();
							$last_event = end( $timeline );
							$pct = $task->status === 'running' ? 75 : 10;
						?>
							<div class="vmsb-progress-item" style="margin-bottom: 14px;">
								<div class="vmsb-flex-space" style="margin-bottom: 6px;">
									<strong style="font-size: 12.5px;"><?php echo esc_html( VMSB_Strategist::agent_label( $task->task_type ) ); ?></strong>
									<span style="font-size: 11px; font-weight: 700; color: var(--gold);"><?php echo $task->status === 'running' ? 'Working' : 'Queued'; ?></span>
								</div>
								<div class="vmsb-bar" style="height: 5px; background: rgba(255, 255, 255, 0.06); border-radius: 4px; overflow: hidden;">
									<span style="display: block; height: 100%; width: <?php echo $pct; ?>%; background: var(--gold);"></span>
								</div>
							</div>
						<?php endforeach;
					endif; ?>
				</div>

				<div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;">
					<span class="vmsb-note" style="font-size: 11.5px;"><?php echo (int) $vmsb_fleet['queued']; ?> queued · <?php echo (int) $vmsb_fleet['running']; ?> running</span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-content&tab=queue' ) ); ?>" class="vmsb-link" style="font-size: 12px; font-weight: 600;">Manage Queue →</a>
				</div>
			</section>

			<!-- 2. TOP OPPORTUNITIES SUMMARY -->
			<section class="vmsb-card">
				<div class="vmsb-flex-space" style="margin-bottom: 16px;">
					<h3 style="margin: 0; font-size: 13px; text-transform: uppercase; letter-spacing: 0.8px; color: var(--accent-purple);">Top Growth Levers</h3>
					<span class="vmsb-tag vmsb-tag-purple"><?php echo count( $vmsb_ops ); ?> Open</span>
				</div>

				<div class="vmsb-op-mini-list">
					<?php if ( empty( $vmsb_ops ) ) : ?>
						<p class="vmsb-note" style="margin: 10px 0;">No growth opportunities recorded yet. Run a Discovery Scan to discover new keywords & topics.</p>
						<button type="button" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" style="width: 100%; margin-top: 10px;" data-vmsb="opportunity-scan">
							Run Discovery Scan
						</button>
					<?php else :
						foreach ( array_slice( $vmsb_ops, 0, 4 ) as $op ) : ?>
							<div style="padding: 10px 0; border-bottom: 1px solid rgba(255, 255, 255, 0.04);">
								<div class="vmsb-flex-space">
									<strong style="font-size: 12.5px;"><?php echo esc_html( $op['target'] ); ?></strong>
									<span class="vmsb-tag vmsb-tag-gold" style="font-size: 9px; font-weight: 800;">🔥 <?php echo (float) $op['priority']; ?></span>
								</div>
								<p class="vmsb-note" style="margin: 4px 0 0; font-size: 11.5px; line-height: 1.4;"><?php echo esc_html( $op['recommended'] ?? $op['reason'] ?? '' ); ?></p>
							</div>
						<?php endforeach; ?>
						<div style="margin-top: 16px; text-align: center;">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-content&tab=discovery' ) ); ?>" class="vmsb-link" style="font-size: 12px; font-weight: 600;">
								View All Opportunities →
							</a>
						</div>
					<?php endif; ?>
				</div>
			</section>

		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
