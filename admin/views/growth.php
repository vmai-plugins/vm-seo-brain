<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;

/**
 * Growth Plan Command Center.
 *
 * Aggregates Trend Scout 2026, Rising Search Signals, and Niche Expansion
 * into a single high-velocity editorial dashboard.
 */

$news_engine   = new VMSB_News();
$trends_engine = new VMSB_Trends();
$brain         = new VMSB_Brain();
$profile       = $brain->profile();

// 1. Live Rising Signals (from GSC Velocity)
$velocity_signals = $trends_engine->analyze_velocity();

// 2. Rising News Topics (Filtered Google Trends)
$rising_trends = $trends_engine->get_rising_signals(8);

// 3. System Check
$news_enabled = (int) VMSB_Settings::get( 'news_enabled' );

// 4. Suggestion inbox + growth-target progress
$growth_engine = new VMSB_Growth_Engine();
$suggestions   = $growth_engine->pending( 50 );
$progress      = $growth_engine->progress();
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Strategic Content Velocity</p>
			<h1>Growth Plan</h1>
			<p class="vmsb-sub">Discovery of new territories and real-time viral trends.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="trend-scout">Refresh Trends</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="niche-plan" data-body='{"count":15}'>Trigger Niche Expansion</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="plan" data-body='{"count":20}'>Plan next 20</button>
			<?php if ( VMSB_License::has_feature('trend_scout') ) : ?>
				<button class="vmsb-btn vmsb-btn-gold" data-vmsb="news-scout" data-confirm="Trend Scout will fetch live news and propose new trending articles. Continue?">Run Trend Scout 2026</button>
			<?php else : ?>
				<a href="<?php echo admin_url('admin.php?page=vmsb-plans'); ?>" class="vmsb-btn vmsb-btn-gold" style="opacity: 0.7;">Unlock Trend Scout 2026</a>
			<?php endif; ?>
		</div>
	</header>

	<!-- EXECUTIVE SUMMARY BAR -->
	<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); margin-top: 30px; gap: 20px;">
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Niche Dominance</span>
			<div class="vmsb-figure">
				<?php
				$total_kw = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_keywords");
				$p10_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_keywords WHERE position <= 10 AND position > 0");
				$coverage = $total_kw > 0 ? round(($p10_count / $total_kw) * 100) : 0;
				?>
				<span class="vmsb-number" style="color:var(--gold);"><?php echo $coverage; ?>%</span>
			</div>
			<p class="vmsb-note"><?php echo $p10_count; ?> of <?php echo $total_kw; ?> queries in Top 10.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Impression Velocity</span>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--good);">+<?php echo count($velocity_signals); ?></span>
			</div>
			<p class="vmsb-note">Keywords with >50% growth.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px;">
			<span class="vmsb-note">Unmet Gaps</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo (new VMSB_Keywords())->count('new'); ?></span>
			</div>
			<p class="vmsb-note">High-leverage opportunities found.</p>
		</div>
		<div class="vmsb-card" style="padding: 20px; background: linear-gradient(135deg, var(--panel) 0%, rgba(29, 209, 161, 0.05) 100%);">
			<span class="vmsb-note">Growth ROI</span>
			<?php $summary = VMSB_Outcome_Ledger::counts(); ?>
			<div class="vmsb-figure">
				<span class="vmsb-number" style="color:var(--good);">+<?php echo number_format($summary['net_clicks']); ?></span>
			</div>
			<p class="vmsb-note">Clicks gained via AI (30d).</p>
		</div>
	</div>

	<!-- GROWTH TARGET PROGRESS -->
	<div class="vmsb-card" style="margin-top:20px; padding:20px;">
		<div class="vmsb-flex-space" style="margin-bottom:10px;">
			<span class="vmsb-note">Growth Target Progress</span>
			<span class="vmsb-note"><?php echo number_format( $progress['achieved'] ); ?> / <?php echo number_format( $progress['target'] ); ?> (<?php echo esc_html( $progress['pct'] ); ?>%)</span>
		</div>
		<div class="vmsb-bar" style="height:10px;"><span style="width:<?php echo min( 100, (float) $progress['pct'] ); ?>%; background: linear-gradient(90deg, var(--gold), var(--good));"></span></div>
		<p class="vmsb-note" style="margin-top:8px;"><?php echo $progress['on_track'] ? 'On pace to hit the target.' : 'Behind pace on the target — Auto Growth Mode scans harder for opportunities while this is off track.'; ?></p>
	</div>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="production">🏭 Injection Hub</button>
		<button class="vmsb-tab" data-tab="signals">📡 Viral Signals</button>
		<button class="vmsb-tab" data-tab="roadmap">🗺️ Battle Roadmap</button>
		<button class="vmsb-tab" data-tab="suggestions">💡 Suggestions<?php echo $suggestions ? ' (' . count( $suggestions ) . ')' : ''; ?></button>
	</div>

	<!-- INJECTION HUB PANEL -->
	<div class="vmsb-panel is-active" data-panel="production">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap: 30px;">
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
							<label style="flex:1;">
								<span class="vmsb-note">Seed Topic (e.g. "Commercial Solar Setup")</span>
								<input type="text" name="seed" placeholder="Enter a broad niche topic..." style="width:100%; height:45px; background:var(--ink); border-radius:8px;">
							</label>
							<label style="width:100px;">
								<span class="vmsb-note">Depth</span>
								<select name="size" style="width:100%; height:45px; background:var(--ink); border-radius:8px;">
									<option value="4">4 Posts</option>
									<option value="6" selected>6 Posts</option>
									<option value="10">10 Posts</option>
								</select>
							</label>
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="cluster-architect" data-vmsb-form="vmsb-cluster-architect-form" style="height:45px; background:var(--accent-purple); border-color:var(--accent-purple); color:#fff;">Architect Silo</button>
						</div>
					<?php else : ?>
						<div class="vmsb-alert" style="margin:0;">
							<p>Upgrade to <strong>Pro Authority</strong> to unlock the Power Cluster Architect and dominate your niche silos.</p>
							<a href="<?php echo admin_url('admin.php?page=vmsb-plans'); ?>" class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" style="margin-top:10px;">View Plans</a>
						</div>
					<?php endif; ?>
				</div>

				<!-- BULK IMPORT -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--gold);">
					<div class="vmsb-flex-space" style="margin-bottom:14px;">
						<h2 style="margin:0;">Bulk Authority Import</h2>
						<p class="vmsb-note" style="margin:0;">Transform raw topics into 90+ score briefs instantly.</p>
					</div>
					<div id="vmsb-bulk-topics-form" class="vmsb-stack-form">
						<textarea name="topics" data-list rows="6" placeholder="Paste your top topics (one per line)..." style="background:var(--ink); border:1px solid var(--line); color:var(--text); padding:15px; border-radius:8px;"></textarea>

						<div style="display:flex; gap: 20px; margin-top:15px; align-items: flex-end;">
							<label style="flex:1; margin:0;">
								<span class="vmsb-note">Output Language (optional)</span>
								<input type="text" name="language" placeholder="e.g. Spanish, Hindi — blank = site default" style="width:100%; height:42px; border-radius:8px;">
							</label>
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="import-topics" data-vmsb-form="vmsb-bulk-topics-form" style="height:42px;">Import & Brief Topics</button>
						</div>
						<div style="margin-top:15px; text-align:right;">
							<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="pull-bulk-topics">Pull from Sheet (Bulk Topics Tab)</button>
						</div>
					</div>
				</div>
			</section>

			<aside>
				<!-- INTENT BREAKDOWN -->
				<div class="vmsb-card" style="margin-bottom: 30px;">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--muted);">Authority Distribution</h3>
					<div class="vmsb-grid" style="grid-template-columns: 1fr; gap: 10px;">
						<?php
						$db = $GLOBALS['wpdb'];
						$intent_data = $db->get_results("SELECT intent, COUNT(*) as n FROM {$db->prefix}vmsb_keywords WHERE intent IS NOT NULL AND intent != '' GROUP BY intent");
						$intent_colors = array('informational' => 'var(--accent-blue)', 'commercial' => 'var(--gold)', 'transactional' => 'var(--good)', 'navigational' => 'var(--accent-purple)');
						foreach ($intent_data as $id) :
						?>
							<div style="display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom: 1px solid var(--line);">
								<span class="vmsb-note" style="text-transform:uppercase; font-size:10px; color:<?php echo $intent_colors[$id->intent] ?? 'var(--muted)'; ?>;"><?php echo esc_html($id->intent); ?></span>
								<span style="font-weight:700; font-size:14px;"><?php echo (int)$id->n; ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- TOP OPPORTUNITIES SIDEBAR -->
				<div class="vmsb-card" style="border-top: 4px solid var(--gold);">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--gold);">Top Opportunity</h3>
					<?php
					$top_opps = ( new VMSB_Keywords() )->top( 5 );
					if ( empty($top_opps) ) : ?>
						<p class="vmsb-note">Run research to find opportunities...</p>
					<?php else : ?>
						<ul style="margin:0; list-style:none; padding:0;">
							<?php foreach ( $top_opps as $opp ) : ?>
								<li style="display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom:1px solid var(--line);">
									<span style="font-weight:600; font-size:12px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($opp->keyword); ?></span>
									<button class="vmsb-mini-btn" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($opp->keyword); ?>", "count":1}'>Plan</button>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</div>
			</aside>
		</div>
	</div>

	<!-- VIRAL SIGNALS PANEL -->
	<div class="vmsb-panel" data-panel="signals">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap: 30px;">
			<section>
				<!-- ELITE GAP RADAR -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--good); background: linear-gradient(135deg, var(--panel) 0%, rgba(95, 167, 120, 0.05) 100%);">
					<div class="vmsb-flex-space" style="margin-bottom:20px;">
						<div>
							<h2 style="margin:0; color:var(--good);">Elite Gap Radar</h2>
							<p class="vmsb-note" style="margin:0;">Discovers "Golden Gaps" by cross-referencing GSC data and Rival analysis.</p>
						</div>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="gap-discovery">Scan for Golden Gaps</button>
					</div>
					<div id="vmsb-gap-results" style="display:none; margin-top:20px;">
						<div class="vmsb-table-wrap">
							<table class="vmsb-table vmsb-table-full">
								<thead><tr><th>Opportunity Topic</th><th>Gap Type</th><th>Reasoning</th><th>Action</th></tr></thead>
								<tbody id="vmsb-gap-list"></tbody>
							</table>
						</div>
					</div>
				</div>

				<div class="vmsb-flex-space" style="margin-bottom: 20px;">
					<h2 style="margin:0; font-family:var(--serif);">Live Viral Discovery</h2>
					<span class="vmsb-tag vmsb-tag-good">RSS Active</span>
				</div>

				<div class="vmsb-cards" style="grid-template-columns: 1fr; gap: 20px;">
					<?php if ( empty($rising_trends) ) : ?>
						<div class="vmsb-card vmsb-empty" style="padding:40px;">
							<p class="vmsb-note">No relevant rising trends detected. Run Scout to trigger discovery.</p>
						</div>
					<?php else : ?>
						<?php foreach ( $rising_trends as $trend ) : ?>
							<article class="vmsb-card" style="display:flex; justify-content:space-between; align-items:center; padding: 20px 25px;">
								<div>
									<span class="vmsb-tag vmsb-tag-blue" style="margin-bottom:8px;">Google Trend</span>
									<h3 style="margin:5px 0; font-size:17px;"><?php echo esc_html($trend); ?></h3>
								</div>
								<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($trend); ?>", "count":1}'>Push to Pipeline</button>
							</article>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</section>

			<aside>
				<!-- IMPRESSION VELOCITY -->
				<div class="vmsb-card vmsb-card-wide" style="border-top: 4px solid var(--good); margin-bottom: 30px;">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--good);">Impression Velocity</h3>
					<div style="margin-top:20px;">
						<?php if ( empty($velocity_signals) ) : ?>
							<p class="vmsb-note">Waiting for Search Console data...</p>
						<?php else : ?>
							<ul style="margin:0; list-style:none; padding:0;">
								<?php foreach ( $velocity_signals as $sig ) : ?>
									<li style="display:flex; justify-content:space-between; padding: 12px 0; border-bottom:1px solid var(--line);">
										<span style="font-weight:600; font-size:12px;"><?php echo esc_html($sig['keyword']); ?></span>
										<span style="color:var(--good); font-weight:700;">+<?php echo esc_html($sig['growth']); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
				</div>
			</aside>
		</div>
	</div>

	<!-- ROADMAP PANEL -->
	<div class="vmsb-panel" data-panel="roadmap">
		<div class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 25px;">
				<div>
					<h2 style="margin:0; font-family:var(--serif);">The 50-Day Dominance Roadmap</h2>
					<p class="vmsb-note">Autonomous day-by-day battle plan to hit your growth targets.</p>
				</div>
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="battle-roadmap">Re-generate Roadmap</button>
			</div>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th style="width:60px;">Day</th>
							<th>Strategic Task</th>
							<th>Keyword Focus</th>
							<th style="text-align:right;">Exp. Impact</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$battle_plan = get_option('vmsb_battle_plan', array());
						if ( empty($battle_plan) ) : ?>
							<tr><td colspan="4" class="vmsb-note">No battle plan generated yet. Click to architect your roadmap.</td></tr>
						<?php else :
							foreach ( $battle_plan as $task ) :
								$is_today = (int)$task['day'] === (int)((time() - (int)get_option('vmsb_installed_at', time())) / DAY_IN_SECONDS) + 1;
						?>
							<tr <?php echo $is_today ? 'style="background:rgba(201, 162, 39, 0.05);"' : ''; ?>>
								<td><strong>#<?php echo (int)$task['day']; ?></strong></td>
								<td>
									<?php echo esc_html($task['task']); ?>
									<?php if ($is_today) : ?><span class="vmsb-tag vmsb-tag-gold" style="margin-left:8px;">TODAY</span><?php endif; ?>
									<br><small class="vmsb-note"><?php echo esc_html($task['reason']); ?></small>
								</td>
								<td><code><?php echo esc_html($task['keyword'] ?: 'N/A'); ?></code></td>
								<td style="text-align:right;"><span class="vmsb-tag vmsb-tag-good">+<?php echo (int)$task['expected_impact']; ?>%</span></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- SUGGESTIONS INBOX PANEL -->
	<div class="vmsb-panel" data-panel="suggestions">
		<div class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 20px;">
				<div>
					<h2 style="margin:0; font-family:var(--serif);">Suggestions Inbox</h2>
					<p class="vmsb-note">Scans Search Console gaps, competitor gaps, and thin silos, then waits here for a yes or no — nothing gets written until you approve it.</p>
				</div>
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="growth-scan">Scan Now</button>
			</div>

			<?php if ( empty( $suggestions ) ) : ?>
				<div class="vmsb-card vmsb-empty" style="padding:40px;">
					<p class="vmsb-note">No pending suggestions. Click "Scan Now" to have the Brain look for new opportunities, or enable Auto Growth Mode in Settings to have it scan on a daily cadence.</p>
				</div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Topic</th><th>Keyword</th><th>Cluster</th><th>Why</th><th>Actions</th></tr></thead>
						<tbody>
							<?php foreach ( $suggestions as $sug ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $sug->title ); ?></strong></td>
									<td><code><?php echo esc_html( $sug->primary_keyword ); ?></code></td>
									<td><?php echo esc_html( $sug->cluster ); ?></td>
									<td><p class="vmsb-note" style="max-width:280px;"><?php echo esc_html( $sug->brief ); ?></p></td>
									<td class="vmsb-row-actions">
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="growth-suggestion-approve" data-id="<?php echo (int) $sug->id; ?>">Approve</button>
										<button class="vmsb-mini-btn" data-vmsb="growth-suggestion-reject" data-id="<?php echo (int) $sug->id; ?>">Reject</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
