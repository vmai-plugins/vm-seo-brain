<?php
defined( 'ABSPATH' ) || exit;

$fixer   = new VMSB_Fixer();
$growth  = new VMSB_Growth();
$brain   = new VMSB_Brain();
$content = new VMSB_Content();
$keywords= new VMSB_Keywords();
$google  = new VMSB_Google();
$rm      = new VMSB_RankMath();

$counts  = $fixer->counts();
$status  = $growth->status();
$verdict = $growth->verdict();
$profile = $brain->profile();
$plan    = $content->stats();

$health_latest = ( new VMSB_Health() )->latest();
$market_latest = ( new VMSB_Market() )->latest();
$biz_summary   = ( new VMSB_Performance() )->business_summary();
?>
<div class="wrap vmsb">

	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Strategic Executive Command</p>
			<h1 style="display:flex; align-items:center; gap:15px;">
				SEO Brain Intelligence
				<span class="vmsb-tag vmsb-tag-<?php echo VMSB_License::plan() === 'elite' ? 'gold' : (VMSB_License::plan() === 'pro' ? 'purple' : 'blue'); ?>" style="font-size:11px; padding:4px 12px; text-transform:uppercase; letter-spacing:1px; font-weight:800;">
					<?php echo esc_html(VMSB_License::plan()); ?>
				</span>
			</h1>
			<p class="vmsb-sub"><?php echo esc_html( $profile['type'] ? $profile['name'] . ' — ' . $profile['type'] : 'The brain has not read this site yet.' ); ?></p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="understand">Re-calibrate DNA</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="scan">Deep Audit</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="god-fix">Execute God Fix</button>
		</div>
	</header>

	<nav class="vmsb-hub-grid" aria-label="SEO Brain modules">
		<?php
		$hub_items = array(
			array( 'slug' => '',              'icon' => 'superhero',    'title' => 'Overview',      'desc' => 'Executive summary and growth trajectory.',            'badge' => '', 'active' => true ),
			array( 'slug' => 'vmsb-issues',     'icon' => 'warning',      'title' => 'Issues',        'desc' => 'Every technical and on-page issue, one-click fixes.', 'badge' => (int) $counts['total'] . ' open', 'badge_class' => $counts['total'] > 0 ? 'vmsb-tag-crit' : 'vmsb-tag-good' ),
			array( 'slug' => 'vmsb-keywords',   'icon' => 'search',       'title' => 'Keywords',      'desc' => 'Your keyword universe, clustered by opportunity.' ),
			array( 'slug' => 'vmsb-silo',       'icon' => 'networking',   'title' => 'Silos',         'desc' => 'Topical clusters, pillar strength, internal links.' ),
			array( 'slug' => 'vmsb-taxonomy',   'icon' => 'category',     'title' => 'Taxonomy',      'desc' => 'Category and tag structure, zombies, duplicates.' ),
			array( 'slug' => 'vmsb-pipeline',   'icon' => 'edit-page',    'title' => 'Pipeline',      'desc' => 'Editorial production factory.',    'badge' => (int) ( $plan['planned'] ?? 0 ) . ' queued', 'badge_class' => 'vmsb-tag-gold' ),
			array( 'slug' => 'vmsb-growth',     'icon' => 'performance',  'title' => 'Growth Plan',  'desc' => 'Viral trends and niche expansion.' ),
			array( 'slug' => 'vmsb-competitive','icon' => 'shield',       'title' => 'Competitive',   'desc' => 'Rival tracking, backlinks, ROI, CTR experiments.' ),
			array( 'slug' => 'vmsb-plans',      'icon' => 'cart',         'title' => 'Billing & Usage','desc' => 'Subscription and resource tracking.', 'badge' => strtoupper(VMSB_License::plan()), 'badge_class' => 'vmsb-tag-gold' ),
			array( 'slug' => 'vmsb-settings',   'icon' => 'admin-generic','title' => 'Settings',      'desc' => 'Providers, autonomy limits, and integrations.' ),
		);
		foreach ( $hub_items as $item ) :
			$url = $item['slug'] ? admin_url( 'admin.php?page=' . $item['slug'] ) : admin_url( 'admin.php?page=vmsb' );
		?>
			<a class="vmsb-hub-card<?php echo ! empty( $item['active'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
				<span class="vmsb-hub-icon"><span class="dashicons dashicons-<?php echo esc_attr( $item['icon'] ); ?>"></span></span>
				<h3 class="vmsb-hub-title"><?php echo esc_html( $item['title'] ); ?></h3>
				<p class="vmsb-hub-desc"><?php echo esc_html( $item['desc'] ); ?></p>
				<?php if ( ! empty( $item['badge'] ) ) : ?>
					<span class="vmsb-hub-badge vmsb-tag <?php echo esc_attr( $item['badge_class'] ?? 'vmsb-tag-blue' ); ?>"><?php echo esc_html( $item['badge'] ); ?></span>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<?php
	$weekly_narrative = get_option( 'vmsb_weekly_narrative' );
	if ( $weekly_narrative && VMSB_License::at_least('pro') ) : ?>
		<div class="vmsb-card vmsb-card-wide vmsb-executive-summary" style="margin-bottom: 30px; border-left: 4px solid var(--gold);">
			<div class="vmsb-flex-space" style="margin-bottom: 15px;">
				<h2 style="margin:0; font-family:var(--serif); color:var(--gold-soft);">Executive Growth Narrative</h2>
				<span class="vmsb-note">Synthesized <?php echo esc_html( human_time_diff( strtotime( $weekly_narrative['date'] ) ) ); ?> ago</span>
			</div>
			<div class="vmsb-narrative-text" style="font-size: 15px; line-height: 1.7; color: var(--text); opacity: 0.9;">
				<?php echo wpautop( esc_html( $weekly_narrative['text'] ) ); ?>
			</div>
		</div>
	<?php endif; ?>

	<section class="vmsb-grid">

		<!-- GROWTH INTELLIGENCE SPARKLINE -->
		<article class="vmsb-card vmsb-card-wide" style="grid-column: span 3; background: linear-gradient(135deg, var(--panel) 0%, rgba(29, 209, 161, 0.05) 100%);">
			<div class="vmsb-flex-space" style="margin-bottom: 20px; align-items: flex-start;">
				<div>
					<h2 style="margin:0; font-size:22px; font-family:var(--serif);">Organic Velocity</h2>
					<p class="vmsb-note">Measured click yield over the last 30 days.</p>
				</div>
				<div style="text-align:right;">
					<span class="vmsb-number" style="font-size:32px; color:var(--good);">+<?php echo esc_html( $biz_summary['pct_change'] ?? 0 ); ?>%</span>
					<br><small class="vmsb-note">Growth Trajectory</small>
				</div>
			</div>

			<?php
			$series = $growth->series(30);
			$clicks = wp_list_pluck($series, 'clicks');
			$max_clicks = max($clicks) ?: 1;
			$points = [];
			$width = 1000; $height = 120;
			if (count($series) > 1) {
				$step = $width / (count($series) - 1);
				foreach ($clicks as $i => $c) {
					$x = $i * $step;
					$y = $height - ($c / $max_clicks * $height);
					$points[] = "$x,$y";
				}
			}
			?>
			<div class="vmsb-chart-container" style="height:140px; width:100%; position:relative; margin:25px 0;">
				<svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" preserveAspectRatio="none" style="width:100%; height:100%; overflow:visible;">
					<defs>
						<linearGradient id="chartGradient" x1="0%" y1="0%" x2="0%" y2="100%">
							<stop offset="0%" style="stop-color:var(--good); stop-opacity:0.3" />
							<stop offset="100%" style="stop-color:var(--good); stop-opacity:0" />
						</linearGradient>
					</defs>
					<?php if ($points) : ?>
					<path d="M0,<?php echo $height; ?> L<?php echo implode(' L', $points); ?> L<?php echo $width; ?>,<?php echo $height; ?> Z" fill="url(#chartGradient)" />
					<polyline points="<?php echo implode(' ', $points); ?>" fill="none" stroke="var(--good)" stroke-width="4" stroke-linecap="round" stroke-linejoin="round" style="filter: drop-shadow(0 0 8px rgba(29, 209, 161, 0.4));" />
					<?php endif; ?>
				</svg>
			</div>

			<div style="display:flex; justify-content:space-between; border-top:1px solid var(--line); padding-top:20px;">
				<div class="vmsb-stat-mini">
					<span class="vmsb-note">Gross Clicks</span>
					<p style="margin:5px 0 0; font-weight:700; font-size:20px; color:var(--text);"><?php echo number_format($biz_summary['clicks_this_month']); ?></p>
				</div>
				<div class="vmsb-stat-mini">
					<span class="vmsb-note">Impression Mass</span>
					<p style="margin:5px 0 0; font-weight:700; font-size:20px; color:var(--text);"><?php echo number_format(array_sum(wp_list_pluck($series, 'impressions')) / 1000, 1); ?>k</p>
				</div>
				<div class="vmsb-stat-mini">
					<span class="vmsb-note">Top Mover</span>
					<p style="margin:5px 0 0; font-weight:700; font-size:14px; color:var(--gold);"><?php echo esc_html($biz_summary['top_improved'][0]->keyword ?? 'Benchmarking...'); ?></p>
				</div>
			</div>
		</article>

		<!-- STRATEGIC MOVE -->
		<article class="vmsb-card vmsb-card-wide vmsb-strategic-advice" style="grid-column: span 3; background: linear-gradient(135deg, rgba(201,162,39,0.1) 0%, rgba(165,94,234,0.1) 100%); border: 1px solid rgba(201,162,39,0.3); box-shadow: 0 10px 30px var(--shadow-strong);">
			<div class="vmsb-flex-space" style="align-items: flex-start;">
				<div>
					<span class="vmsb-tag vmsb-tag-gold" style="margin-bottom:10px; background:var(--gold); color:#000; font-weight:800;">Strategic Intelligence</span>
					<h2 style="margin:5px 0 15px; font-size:26px; font-family:var(--serif); color:var(--gold-soft);">Today's Executive Move</h2>
				</div>
				<div class="vmsb-pulse-indicator" title="Sentient Brain Active">
					<span class="vmsb-dot vmsb-dot-gold"></span>
				</div>
			</div>
			<?php
			$daily_decisions = $brain->recall( 'decisions', gmdate( 'Y-m-d' ) );
			if ( $daily_decisions && isset($daily_decisions[0]) ) :
				$top_move = $daily_decisions[0];
			?>
				<div class="vmsb-advice-content">
					<p style="font-size:18px; line-height:1.6; color:var(--text); font-weight:500;">
						Highest leverage action: <strong><?php echo esc_html(str_replace('_', ' ', $top_move['type'])); ?></strong>.
						<span style="display:block; margin-top:10px; font-weight:400; opacity:0.8;"><?php echo esc_html($top_move['reason']); ?></span>
					</p>
					<div class="vmsb-flex-space" style="margin-top:20px; align-items:center;">
						<div style="display:flex; gap:10px; flex-wrap:wrap;">
							<div class="vmsb-tag vmsb-tag-good">Lift: <?php echo esc_html($top_move['expected_lift']); ?></div>
							<div class="vmsb-tag vmsb-tag-purple">Effort: <?php echo esc_html(ucfirst($top_move['effort'])); ?></div>
						</div>
						<div style="margin-left:auto; display:flex; gap:12px;" class="vmsb-head-actions">
							<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="understand">Recalibrate</button>
							<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="tasks-process" data-body='{"limit":1}'>Execute Move</button>
						</div>
					</div>
				</div>
			<?php else : ?>
				<p class="vmsb-note" style="font-size:16px;">Analyzing site data to formulate today's strategy... <button class="vmsb-mini-btn" data-vmsb="understand">Force Re-read</button></p>
			<?php endif; ?>
		</article>

		<!-- AUTHORITY SPRINT -->
		<article class="vmsb-card vmsb-card-wide" style="grid-column: span 2;">
			<?php
			$scenario = $growth->scenario();
			$published = (int) wp_count_posts( 'post' )->publish;
			$target = $scenario['target'];
			$pct = round(($published / $target) * 100, 1);
			$daily_pace = (float)$status['daily_now'] ?: 1.0;
			$days_to_target = max(1, round(($target - $published) / $daily_pace));
			?>
			<div style="display:flex; justify-content:space-between; align-items:center;">
				<h2 style="font-family:var(--serif);">Authority Sprint</h2>
				<span class="vmsb-tag vmsb-tag-blue"><?php echo esc_html($scenario['label']); ?> Phase</span>
			</div>
			<div class="vmsb-figure" style="margin: 25px 0;">
				<span class="vmsb-number" style="font-size:48px;"><?php echo number_format($published); ?></span>
				<span class="vmsb-of">/ <?php echo number_format($target); ?> Master Assets</span>
			</div>
			<div class="vmsb-bar" style="height:14px; background:var(--track-bg); border-radius:10px; overflow:hidden;">
				<span style="width:<?php echo min(100, $pct); ?>%; background: linear-gradient(90deg, var(--good) 0%, var(--gold) 100%); display:block; height:100%;"></span>
			</div>
			<p class="vmsb-note" style="margin-top:20px; font-size:15px; line-height:1.5;">
				<strong><?php echo $pct; ?>%</strong> complete. <?php echo esc_html($scenario['message']); ?>
				<?php if ($published < $target) : ?>
					Estimated completion in <strong><?php echo $days_to_target; ?> days</strong> at current velocity.
				<?php endif; ?>
			</p>
		</article>

		<!-- TOPICAL AUTHORITY -->
		<article class="vmsb-card" style="border-top: 4px solid var(--accent-purple); background: linear-gradient(180deg, rgba(165, 94, 234, 0.05) 0%, transparent 100%);">
			<h2 style="font-family:var(--serif);">Silo Authority</h2>
			<?php
			$silos = ( new VMSB_Silo() )->map_for_display();
			$healthy_silos = count(array_filter($silos, fn($s) => $s['strength'] > 75));
			$total_silos = count($silos);
			$auth_pct = $total_silos > 0 ? round(($healthy_silos / $total_silos) * 100) : 0;
			?>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--accent-purple); font-size:42px;"><?php echo $auth_pct; ?>%</span>
			</div>
			<div class="vmsb-bar" style="margin:15px 0; height:8px;"><span style="width:<?php echo $auth_pct; ?>%; background:linear-gradient(90deg, var(--accent-purple), var(--gold));"></span></div>
			<p class="vmsb-note"><strong><?php echo $healthy_silos; ?> of <?php echo $total_silos; ?></strong> silos are considered authoritative in your niche.</p>

			<div class="vmsb-mini-tags" style="margin-top:15px; display:flex; flex-wrap:wrap; gap:6px;">
				<?php foreach (array_slice($silos, 0, 4) as $s) : ?>
					<span class="vmsb-tag <?php echo $s['strength'] > 75 ? 'vmsb-tag-good' : 'vmsb-tag-gold'; ?>" style="font-size:9px;"><?php echo esc_html(wp_trim_words($s['name'], 2)); ?></span>
				<?php endforeach; ?>
			</div>
		</article>

		<!-- KEYWORD DOMINANCE -->
		<article class="vmsb-card" style="border-top: 4px solid var(--high); background: linear-gradient(180deg, rgba(255, 159, 67, 0.05) 0%, transparent 100%);">
			<h2 style="font-family:var(--serif);">Keyword Reach</h2>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--high); font-size:42px;"><?php echo (int) $keywords->count(); ?></span>
			</div>
			<p class="vmsb-note">Total discovered queries in universe.</p>
			<div style="margin-top:20px; display:flex; flex-direction:column; gap:8px;">
				<div style="display:flex; justify-content:space-between; font-size:12px;">
					<span class="vmsb-note">Striking Distance</span>
					<strong><?php echo count($keywords->striking_distance(100)); ?></strong>
				</div>
				<div class="vmsb-bar" style="height:4px;"><span style="width:<?php echo min(100, (count($keywords->striking_distance(100)) / max(1, $keywords->count())) * 500); ?>%; background:var(--high);"></span></div>
			</div>
			<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" style="margin-top:20px;" data-vmsb="research">Sync Intelligence</button>
		</article>

		<!-- AI EFFICIENCY -->
		<article class="vmsb-card" style="border-top: 4px solid var(--good); background: linear-gradient(180deg, rgba(29, 209, 161, 0.05) 0%, transparent 100%);">
			<h2 style="font-family:var(--serif);">AI Agent ROI</h2>
			<?php
			$outcomes = class_exists('VMSB_Outcome_Ledger') ? VMSB_Outcome_Ledger::counts() : array();
			$net_clicks = (int) ($outcomes['net_clicks'] ?? 0);
			$calls_today = (new VMSB_AI_Router())->calls_today();
			$eff_score = $calls_today > 0 ? round(($net_clicks / ($calls_today * 30)) * 100) : 100;
			?>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--good); font-size:42px;">+<?php echo number_format($net_clicks); ?></span>
			</div>
			<p class="vmsb-note">Net clicks gained via AI optimizations.</p>
			<p class="vmsb-note" style="color: var(--good); font-weight: 700; font-size:16px; margin-top:15px;">Efficiency: <?php echo min(100, $eff_score); ?>%</p>
			<a class="vmsb-link" href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-competitive' ) ); ?>#roi">ROI Analysis</a>
		</article>

		<!-- CONTENT QUALITY -->
		<article class="vmsb-card" style="border-top: 4px solid var(--accent-blue); background: linear-gradient(180deg, rgba(69, 170, 242, 0.05) 0%, transparent 100%);">
			<h2 style="font-family:var(--serif);">Avg Asset Quality</h2>
			<?php
			global $wpdb;
			$avg_score = (int) $wpdb->get_var( "SELECT AVG(CAST(meta_value AS UNSIGNED)) FROM $wpdb->postmeta WHERE meta_key = 'rank_math_seo_score'" );
			$published_posts = (int) wp_count_posts( 'post' )->publish;
			?>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--accent-blue); font-size:42px;"><?php echo $avg_score; ?></span>
				<span class="vmsb-of">/ 100</span>
			</div>
			<div class="vmsb-bar" style="margin:15px 0; height:8px;"><span style="width:<?php echo $avg_score; ?>%; background:linear-gradient(90deg, var(--accent-blue), var(--good));"></span></div>
			<p class="vmsb-note">Across <strong><?php echo $published_posts; ?></strong> published assets. Standard: 85+.</p>
			<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" style="margin-top:20px;" data-vmsb="scan" data-body='{"limit":50}'>Surgical Audit</button>
		</article>

		<!-- SYSTEM HEALTH -->
		<article class="vmsb-card">
			<h2 style="font-family:var(--serif);">Agent Ecosystem</h2>
			<?php if ( ! $health_latest ) : ?>
				<p class="vmsb-note">Initializing systems...</p>
			<?php else :
				$ok_count = count( array_filter( $health_latest['checks'], static fn( $c ) => ! empty( $c['ok'] ) ) );
				$total    = count( $health_latest['checks'] );
				?>
				<div class="vmsb-figure"><span class="vmsb-number" style="font-size:42px;"><?php echo (int) $ok_count; ?></span><span class="vmsb-of">/ <?php echo (int) $total; ?> OK</span></div>
				<ul class="vmsb-legend" style="margin-top:20px;">
					<?php foreach ( array_slice($health_latest['checks'], 0, 4) as $c ) : ?>
						<li style="font-size:11px;"><i class="<?php echo empty( $c['ok'] ) ? 'sev-critical' : 'sev-low'; ?>" style="width:8px; height:8px;"></i><?php echo esc_html( wp_trim_words($c['label'], 2) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" style="margin-top:15px;" data-vmsb="health-check">System Health</button>
		</article>

	</section>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
