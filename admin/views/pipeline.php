<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$vmsb_content_engine = new VMSB_Content();
$vmsb_current_plan    = VMSB_License::plan();

// Production Data
global $wpdb;
$vmsb_rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_plan ORDER BY FIELD(status,'failed','writing','approved','planned','drafted','published','rejected'), priority DESC LIMIT 200" );

// The table below reads _vmsb_quality for each row that has a post, and asks
// for an edit link too. Left alone that is two lookups per row over up to 200
// rows, each a separate query the first time an ID is seen. Prime both caches
// in one pass so the loop is served from memory.
$vmsb_row_post_ids = array_values( array_filter( array_map(
	static function ( $r ) { return isset( $r->post_id ) ? (int) $r->post_id : 0; },
	$vmsb_rows
) ) );
if ( $vmsb_row_post_ids ) {
	_prime_post_caches( $vmsb_row_post_ids, false, true ); // posts + meta, skip term cache
}
$vmsb_stats = $vmsb_content_engine->stats();
$vmsb_pub_today = (int) get_option( 'vmsb_pub_' . gmdate('Ymd'), 0 );
$vmsb_daily_cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );

// The daily cap only throttles anything when posts can actually go live;
// in review-first mode every run drafts and the cap never applies.
$vmsb_publishing_live = (int) VMSB_Settings::get( 'auto_publish' ) && ! (int) VMSB_Settings::get( 'require_review' );

// Discovery Data
$vmsb_trends_engine = new VMSB_Trends();
$vmsb_velocity_signals = $vmsb_trends_engine->analyze_velocity();
$vmsb_rising_trends = $vmsb_trends_engine->get_rising_signals(8);
?>

<?php if ( ! $vmsb_is_nested ) : ?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Strategic Content Factory</p>
			<h1 style="display:flex; align-items:center; gap:15px;">
				Growth Hub
				<span class="vmsb-tag vmsb-tag-gold" style="font-size:10px; text-transform:uppercase;"><?php echo esc_html($vmsb_current_plan); ?></span>
			</h1>
			<p class="vmsb-sub">Managing discovery, planning, and production in one unified workspace.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-sheet">Sync Sheet</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="approve-all">Approve All</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="tasks-process" data-body='{"limit":5}'>Run Production Batch</button>
		</div>
	</header>
	<span class="wp-header-end"></span>
<?php endif; ?>

<?php if ( ! $vmsb_is_nested ) : ?>
	<!-- KPI OVERVIEW -->
	<div class="vmsb-pipeline-overview" style="margin-bottom:30px;">
		<div class="vmsb-pipeline-grid" style="grid-template-columns: repeat(3, 1fr);">
			<div class="vmsb-pipeline-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">📅</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($vmsb_stats['planned'] ?? 0) + (int)($vmsb_stats['approved'] ?? 0); ?></span>
						<span class="vmsb-stage-label">In Pipeline</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">Topics prepped for production.</p>
			</div>

			<div class="vmsb-pipeline-stage" style="border-left: 3px solid var(--gold);">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">🤖</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)($vmsb_stats['writing'] ?? 0); ?></span>
						<span class="vmsb-stage-label">Active Agents</span>
					</div>
				</div>
				<p class="vmsb-stage-desc">AI Agents currently drafting content.</p>
			</div>

			<div class="vmsb-pipeline-stage vmsb-health-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚡</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo (int)$vmsb_pub_today; ?><small>/<?php echo (int)$vmsb_daily_cap; ?></small></span>
						<span class="vmsb-stage-label">Published Today</span>
					</div>
				</div>
				<div class="vmsb-bar" style="height:8px; margin: 10px 0;"><span style="width:<?php echo esc_attr( min(100, ($vmsb_pub_today / ($vmsb_daily_cap ?: 1)) * 100) ); ?>%; background: var(--good);"></span></div>
				<?php if ( $vmsb_publishing_live ) : ?>
					<p class="vmsb-stage-desc"><?php echo esc_html( max(0, $vmsb_daily_cap - $vmsb_pub_today) ); ?> publish slots left today.</p>
				<?php else : ?>
					<p class="vmsb-stage-desc">Review-first mode — drafts are unlimited and the cap only applies once auto-publish is on.</p>
				<?php endif; ?>
			</div>
		</div>
	</div>
<?php endif; ?>

	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="queue">🛠️ Production Queue</button>
		<button class="vmsb-tab" data-tab="discovery">🎯 Authority Discovery</button>
		<button class="vmsb-tab" data-tab="signals">📡 Viral Signals</button>
		<button class="vmsb-tab" data-tab="healer">🩹 Content Healer</button>
		<button class="vmsb-tab" data-tab="roadmap">🗺️ Battle Roadmap</button>
		<button class="vmsb-tab" data-tab="activity">🦾 Agent Activity</button>
	</div>

	<!-- TAB 1: PRODUCTION QUEUE -->
	<div class="vmsb-panel is-active" data-panel="queue">
		<div class="vmsb-filter-bar" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; background:var(--panel); padding:15px; border-radius:10px; border:1px solid var(--line);">
			<div class="vmsb-search-wrap" style="position:relative; flex:1; max-width:400px;">
				<span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:0.5;">🔍</span>
				<input type="text" id="vmsb-pipeline-search" placeholder="Search topics..." style="width:100%; padding-left:35px; height:42px; border-radius:8px;">
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
						<th>Since</th>
						<th>Priority</th>
						<th class="vmsb-row-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $vmsb_rows as $vmsb_row ) :
						$vmsb_q_report = $vmsb_row->post_id ? get_post_meta($vmsb_row->post_id, '_vmsb_quality', true) : null;
						$vmsb_score = $vmsb_q_report ? ($vmsb_q_report['score'] ?? 0) : 0;
					?>
						<tr class="state-row-<?php echo esc_attr($vmsb_row->status); ?>">
							<td><input type="checkbox" class="vmsb-row-cb" value="<?php echo (int)$vmsb_row->id; ?>"></td>
							<td>
								<span class="vmsb-tag state-<?php echo esc_attr($vmsb_row->status); ?>"><?php echo esc_html(ucfirst($vmsb_row->status)); ?></span>
								<?php if ($vmsb_row->status === 'writing') : ?>
									<div class="vmsb-pulse-indicator" style="margin-top:5px; transform:scale(0.7); origin:left;">
										<span class="vmsb-dot vmsb-dot-gold"></span> <small><?php echo esc_html($vmsb_row->agent_task ?: 'Reasoning...'); ?></small>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html($vmsb_row->title); ?></strong>
								<?php if ($vmsb_row->editor_note) : ?>
									<div class="vmsb-editor-note" style="font-size:10px; color:var(--gold); font-style:italic; margin-top:4px;">✍️ <?php echo esc_html($vmsb_row->editor_note); ?></div>
								<?php endif; ?>
								<?php if ($vmsb_row->last_error) : ?>
									<div class="vmsb-error-box" style="color:var(--crit); font-size:11px; margin-top:5px;">⚠️ <?php echo esc_html($vmsb_row->last_error); ?></div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($vmsb_score > 0) : ?>
									<div class="vmsb-tiny-score <?php echo $vmsb_score >= 85 ? 'good' : ($vmsb_score >= 70 ? 'med' : 'low'); ?>"><span><?php echo (int)$vmsb_score; ?></span></div>
								<?php else : ?><span class="vmsb-note">—</span><?php endif; ?>
							</td>
							<td><code><?php echo esc_html($vmsb_row->primary_keyword); ?></code></td>
							<td><span class="vmsb-note" title="<?php echo esc_attr($vmsb_row->created_at); ?>"><?php echo esc_html( human_time_diff(strtotime($vmsb_row->created_at)) ); ?> ago</span></td>
							<td><div class="vmsb-bar vmsb-mini-bar" style="width:50px;"><span style="width:<?php echo esc_attr( (float)$vmsb_row->priority * 10 ); ?>%; background:var(--gold);"></span></div></td>
							<td class="vmsb-row-actions">
								<button class="vmsb-mini-btn" onclick="const note = prompt('Editor Note:', '<?php echo esc_js($vmsb_row->editor_note); ?>'); if(note !== null) VMSB.api('save-editor-note', {id: <?php echo (int)$vmsb_row->id; ?>, note: note}).then(() => location.reload());">Note</button>
								<?php if ($vmsb_row->status === 'failed') : ?>
									<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="retry-critique" data-id="<?php echo (int)$vmsb_row->id; ?>">Retry</button>
								<?php elseif ($vmsb_row->status === 'planned') : ?>
									<button class="vmsb-mini-btn" data-vmsb="bulk-action" data-body='{"ids":[<?php echo (int)$vmsb_row->id; ?>], "bulk_action":"bulk-approve"}'>Approve</button>
								<?php elseif ($vmsb_row->post_id) : ?>
									<a href="<?php echo esc_url( get_edit_post_link($vmsb_row->post_id) ); ?>" class="vmsb-mini-btn vmsb-btn-ghost">Edit</a>
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
						<textarea name="topics" data-list rows="5" placeholder="Paste your topics..." style="background:var(--ink); border:1px solid var(--line); color:var(--text); padding:15px; border-radius:8px;"></textarea>
						<div style="display:flex; gap: 20px; margin-top:15px; align-items: flex-end;">
							<label style="flex:1;"><span class="vmsb-note">Language</span><input type="text" name="language" placeholder="e.g. Spanish" style="width:100%; height:42px; border-radius:8px;"></label>
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="import-topics" data-vmsb-form="vmsb-bulk-topics-form" style="height:42px;">Import Topics</button>
						</div>
					</div>
				</div>

				<!-- VIDEO TO BLOG -->
				<div class="vmsb-card" style="margin-bottom:30px; border-top: 4px solid var(--accent-blue);">
					<div class="vmsb-flex-space" style="margin-bottom:14px;">
						<div>
							<h2 style="margin:0; color:var(--accent-blue);">Video-to-Blog Transformer</h2>
							<p class="vmsb-note" style="margin:0;">Turn any YouTube URL or transcript into a 1,500-word SEO pillar post.</p>
						</div>
						<span class="vmsb-tag vmsb-tag-blue">Social Bridge</span>
					</div>
					<div id="vmsb-video-transform-form" class="vmsb-stack-form">
						<input type="url" name="video_url" placeholder="YouTube Video URL..." style="width:100%; height:42px; border-radius:8px; margin-bottom:10px;">
						<textarea name="transcript" rows="3" placeholder="Paste transcript here (optional if URL provided)..." style="background:var(--ink); border:1px solid var(--line); color:var(--text); padding:15px; border-radius:8px;"></textarea>
						<div style="text-align:right; margin-top:15px;">
							<button class="vmsb-btn vmsb-btn-gold" data-vmsb="video-to-blog" data-vmsb-form="vmsb-video-transform-form">Transform to Blog</button>
						</div>
					</div>
				</div>

				<!-- QUANTUM MODES -->
				<div class="vmsb-grid" style="grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
					<div class="vmsb-card" style="border-top: 4px solid var(--crit);">
						<h3 style="margin:0 0 10px;">Quantum Heist</h3>
						<p class="vmsb-note" style="margin-bottom:15px;">Steal high-value rankings from top competitors.</p>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="quantum-heist">Run Heist</button>
					</div>
					<div class="vmsb-card" style="border-top: 4px solid var(--accent-purple);">
						<h3 style="margin:0 0 10px;">Vulture Strike</h3>
						<p class="vmsb-note" style="margin-bottom:15px;">Target competitor rankings that are dropping.</p>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="vulture-strike">Run Strike</button>
					</div>
					<div class="vmsb-card" style="border-top: 4px solid var(--accent-blue);">
						<h3 style="margin:0 0 10px;">Quantum Blast</h3>
						<p class="vmsb-note" style="margin-bottom:15px;">Launch massive programmatic clusters.</p>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="quantum-blast">Run Blast</button>
					</div>
				</div>
			</section>

			<aside>
				<div class="vmsb-card">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--gold);">Top Opportunities</h3>
					<?php
					$vmsb_top_opps = ( new VMSB_Keywords() )->top( 8 );
					foreach ( $vmsb_top_opps as $vmsb_opp ) : ?>
						<div style="display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($vmsb_opp->keyword); ?></span>
							<button class="vmsb-mini-btn" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($vmsb_opp->keyword); ?>", "count":1}'>Plan</button>
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
					<?php foreach ( $vmsb_rising_trends as $vmsb_trend ) : ?>
						<article class="vmsb-card" style="display:flex; justify-content:space-between; align-items:center; padding: 20px 25px;">
							<div><span class="vmsb-tag vmsb-tag-blue">Google Trend</span><h3 style="margin:5px 0; font-size:17px;"><?php echo esc_html($vmsb_trend); ?></h3></div>
							<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($vmsb_trend); ?>", "count":1}'>Push</button>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<aside>
				<div class="vmsb-card vmsb-card-wide" style="border-top: 4px solid var(--good);">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--good);">Impression Velocity</h3>
					<?php foreach ( $vmsb_velocity_signals as $vmsb_sig ) : ?>
						<div style="display:flex; justify-content:space-between; padding: 12px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px;"><?php echo esc_html($vmsb_sig['keyword']); ?></span>
							<span style="color:var(--good); font-weight:700;">+<?php echo esc_html($vmsb_sig['growth']); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</aside>
		</div>
	</div>

	<!-- TAB 4: CONTENT HEALER -->
	<div class="vmsb-panel" data-panel="healer">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<div class="vmsb-card vmsb-card-wide" style="border-top: 4px solid var(--gold);">
					<div class="vmsb-flex-space" style="margin-bottom: 20px;">
						<div>
							<h2 style="margin:0; font-family:var(--serif);">Content Healer Agent</h2>
							<p class="vmsb-note" style="margin:0;">Surgical detection and repair of thin or underperforming content.</p>
						</div>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="agents-run-strategist">Run Audit Pass</button>
					</div>

					<div class="vmsb-table-wrap">
						<table class="vmsb-table vmsb-table-full">
							<thead><tr><th>Target Asset</th><th>Issue Detected</th><th>Status</th><th>Action</th></tr></thead>
							<tbody>
								<?php
								$vmsb_heals = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_issues WHERE rule IN ('thin_content', 'content_decay', 'rapid_decay', 'low_ctr_anomaly', 'semantic_stale') AND status = 'open' ORDER BY impact DESC LIMIT 10" );
								if ( empty($vmsb_heals) ) : ?>
									<tr><td colspan="4" class="vmsb-note">No active content decay or thin assets detected. Healthy state.</td></tr>
								<?php else :
									foreach ( $vmsb_heals as $h ) :
										$h_post = get_post($h->object_id);
								?>
									<tr>
										<td><strong><?php echo esc_html($h_post ? $h_post->post_title : 'Site-wide'); ?></strong></td>
										<td><span class="vmsb-sev sev-<?php echo esc_attr($h->severity); ?>"><?php echo esc_html( (new VMSB_Fixer())->get_rule_explanation($h->rule) ); ?></span></td>
										<td><span class="vmsb-tag vmsb-tag-gold">Detected</span></td>
										<td><button class="vmsb-mini-btn" data-vmsb="god-fix-90" data-id="<?php echo (int)$h->object_id; ?>">Heal Now</button></td>
									</tr>
								<?php endforeach; endif; ?>
							</tbody>
						</table>
					</div>
				</div>
			</section>
			<aside>
				<div class="vmsb-card">
					<h3 style="margin:0 0 10px; font-size:14px; text-transform:uppercase; color:var(--gold);">Healer Logic</h3>
					<p class="vmsb-note">The Healer agent performs post-mortem analysis on any traffic losses and automatically adjusts future writing prompts to avoid past mistakes.</p>
					<ul style="margin:15px 0 0; padding-left:18px; font-size:12px; color:var(--muted);">
						<li>Detects 50%+ traffic drops.</li>
						<li>Identifies outdated year in titles.</li>
						<li>Flags high-reach/low-CTR pages.</li>
						<li>Recursive feedback loop enabled.</li>
					</ul>
				</div>
			</aside>
		</div>
	</div>

	<!-- TAB 5: BATTLE ROADMAP -->
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
						$vmsb_battle_plan = get_option('vmsb_battle_plan', array());
						foreach ( (array)$vmsb_battle_plan as $vmsb_task ) :
							$vmsb_is_today = (int)$vmsb_task['day'] === (int)((time() - (int)get_option('vmsb_installed_at', time())) / DAY_IN_SECONDS) + 1;
						?>
							<tr <?php echo $vmsb_is_today ? 'style="background:rgba(201, 162, 39, 0.05);"' : ''; ?>>
								<td><strong>#<?php echo (int)$vmsb_task['day']; ?></strong></td>
								<td><?php echo esc_html($vmsb_task['task']); ?><br><small class="vmsb-note"><?php echo esc_html($vmsb_task['reason']); ?></small></td>
								<td><code><?php echo esc_html($vmsb_task['keyword'] ?: 'N/A'); ?></code></td>
								<td><span class="vmsb-tag vmsb-tag-good">+<?php echo (int)$vmsb_task['expected_impact']; ?>%</span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- TAB 6: AGENT ACTIVITY -->
	<div class="vmsb-panel" data-panel="activity">
		<div class="vmsb-card vmsb-card-wide">
			<h2 style="margin-bottom:20px;">Live Reasoning Feed</h2>
			<div class="vmsb-activity-feed" style="max-height:600px; overflow-y:auto;">
				<?php
				$vmsb_logs = ( new VMSB_Logger() )->recent( 30 );
				foreach ( $vmsb_logs as $vmsb_log ) :
					$vmsb_icon = $vmsb_log->level === 'error' ? '🔴' : ($vmsb_log->level === 'warning' ? '🟡' : '🟢');
				?>
					<div class="vmsb-activity-item" style="padding:15px; border-bottom:1px solid var(--line); display:flex; gap:20px; align-items:center;">
						<span class="vmsb-note" style="width:100px; flex-shrink:0;"><?php echo esc_html( human_time_diff(strtotime($vmsb_log->created_at)) ); ?> ago</span>
						<span style="width:30px;"><?php echo esc_html($vmsb_icon); ?></span>
						<p style="margin:0; flex:1;"><strong><?php echo esc_html(ucfirst($vmsb_log->channel)); ?>:</strong> <?php echo esc_html($vmsb_log->message); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>

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
