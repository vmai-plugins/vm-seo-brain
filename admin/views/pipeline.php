<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$vmsb_content_engine = new VMSB_Content();
$vmsb_current_plan    = VMSB_License::plan();

global $wpdb;

// Stage counts for the funnel. The rows themselves are no longer selected
// here: the table is fed by the pipeline-feed endpoint so it can refresh
// without a page load. That retired a "SELECT * ... LIMIT 200" and the
// posts+meta cache prime that went with it, both of which ran on every page
// load and, once the rows moved client-side, fed nothing at all.
$vmsb_stats = $vmsb_content_engine->stats();

$vmsb_pub_today = (int) get_option( 'vmsb_pub_' . gmdate('Ymd'), 0 );
$vmsb_daily_cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );

// The daily cap only throttles anything when posts can actually go live;
// in review-first mode every run drafts and the cap never applies.
$vmsb_publishing_live = (int) VMSB_Settings::get( 'auto_publish' ) && ! (int) VMSB_Settings::get( 'require_review' );

// Discovery data feeds the tabs further down, which only render standalone.
// Nested inside Production this file is the queue and nothing else, so the
// trend work is skipped there - analyze_velocity() can reach for Search
// Console, and the Production page was paying for it to build panels it
// then discarded.
$vmsb_velocity_signals = array();
$vmsb_rising_trends    = array();
if ( ! $vmsb_is_nested ) {
	$vmsb_trends_engine    = new VMSB_Trends();
	$vmsb_velocity_signals = $vmsb_trends_engine->analyze_velocity();
	$vmsb_rising_trends    = $vmsb_trends_engine->get_rising_signals( 8 );
}
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

	<!-- GOAL BANNER: Moved here so it stays visible across tabs and is hidden when nested in Production Factory. -->
	<div class="vmsb-goal" id="vmsb-goal" hidden style="margin-bottom:30px;">
		<div class="vmsb-goal-main">
			<div class="vmsb-goal-head">
				<span class="vmsb-goal-phase" id="vmsb-goal-phase"></span>
				<span class="vmsb-goal-posture" id="vmsb-goal-posture"></span>
			</div>
			<div class="vmsb-goal-numbers">
				<strong id="vmsb-goal-achieved"></strong>
				<span class="vmsb-note"> of </span>
				<strong id="vmsb-goal-target"></strong>
				<span class="vmsb-note" id="vmsb-goal-days"></span>
			</div>
			<div class="vmsb-bar vmsb-goal-bar"><span id="vmsb-goal-fill"></span></div>
			<p class="vmsb-note vmsb-goal-note" id="vmsb-goal-note"></p>
		</div>
		<div class="vmsb-goal-side" id="vmsb-goal-focus"></div>
	</div>
<?php endif; ?>

	<?php
	// Nested (inside Production's "Content Factory" tab) this file renders the
	// queue and nothing else. It used to render all six tabs there too, so the
	// Production page contained a second, complete copy of this screen - both
	// using data-tab="queue", one inside the other. That collision is why the
	// tab handler needed scoping to survive at all; removing the duplicate
	// removes the reason for the collision rather than working around it.
	if ( ! $vmsb_is_nested ) :
	?>
	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="queue">🛠️ Production Queue</button>
		<button class="vmsb-tab" data-tab="discovery">🎯 Authority Discovery</button>
		<button class="vmsb-tab" data-tab="signals">📡 Viral Signals</button>
	</div>
	<?php endif; ?>

	<!-- TAB 1: PRODUCTION QUEUE -->
	<div class="vmsb-panel is-active" data-panel="queue">
		<?php
		// The funnel below is the shape of the pipeline. Every stage gets its
		// own chip whether or not it has rows in it, because "Writing: 0" is
		// real information - it is the difference between "the brain is busy"
		// and "the brain has stalled", and the old table could not say either.
		$vmsb_flow_stages = array(
			'planned'   => array( 'Planned',   'Ideas captured, not yet cleared to write.' ),
			'approved'  => array( 'Approved',  'Cleared and waiting for a writer slot.' ),
			'writing'   => array( 'Writing',   'An agent is drafting this right now.' ),
			'drafted'   => array( 'Drafted',   'Written and waiting on your review.' ),
			'published' => array( 'Published', 'Live on the site.' ),
		);
		$vmsb_off_track = array(
			'failed'   => array( 'Failed',   'Stopped on an error. Needs a retry or a fix.' ),
			'rejected' => array( 'Rejected', 'Discarded. Can be re-planned.' ),
		);
		$vmsb_total_rows = array_sum( array_map( 'intval', $vmsb_stats ) );
		?>

		<div class="vmsb-flow" id="vmsb-flow">
			<button type="button" class="vmsb-flow-chip is-active" data-stage="all" title="Everything in the pipeline.">
				<span class="vmsb-flow-n" data-count="all"><?php echo (int) $vmsb_total_rows; ?></span>
				<span class="vmsb-flow-l">Everything</span>
			</button>


			<?php foreach ( $vmsb_flow_stages as $vmsb_key => $vmsb_meta ) : ?>
				<button type="button" class="vmsb-flow-chip stage-<?php echo esc_attr( $vmsb_key ); ?>" data-stage="<?php echo esc_attr( $vmsb_key ); ?>" title="<?php echo esc_attr( $vmsb_meta[1] ); ?>">
					<span class="vmsb-flow-n" data-count="<?php echo esc_attr( $vmsb_key ); ?>"><?php echo (int) ( $vmsb_stats[ $vmsb_key ] ?? 0 ); ?></span>
					<span class="vmsb-flow-l"><?php echo esc_html( $vmsb_meta[0] ); ?></span>
				</button>
			<?php endforeach; ?>


			<?php foreach ( $vmsb_off_track as $vmsb_key => $vmsb_meta ) : ?>
				<button type="button" class="vmsb-flow-chip stage-<?php echo esc_attr( $vmsb_key ); ?>" data-stage="<?php echo esc_attr( $vmsb_key ); ?>" title="<?php echo esc_attr( $vmsb_meta[1] ); ?>">
					<span class="vmsb-flow-n" data-count="<?php echo esc_attr( $vmsb_key ); ?>"><?php echo (int) ( $vmsb_stats[ $vmsb_key ] ?? 0 ); ?></span>
					<span class="vmsb-flow-l"><?php echo esc_html( $vmsb_meta[0] ); ?></span>
				</button>
			<?php endforeach; ?>
		</div>

		<div class="vmsb-pipe-bar">
			<div class="vmsb-search-wrap">
				<span class="vmsb-search-icon" aria-hidden="true">🔍</span>
				<input type="search" id="vmsb-pipeline-search" placeholder="Search every topic and keyword…" autocomplete="off">
			</div>

			<div class="vmsb-pipe-bar-right">
				<span class="vmsb-live" id="vmsb-pipe-live" hidden>
					<span class="vmsb-dot vmsb-dot-gold"></span>
					<span id="vmsb-pipe-live-text">Live</span>
				</span>
				<span class="vmsb-note" id="vmsb-pipe-stamp"></span>
				<button type="button" class="vmsb-mini-btn" id="vmsb-pipe-refresh" title="Refresh now">↻</button>

				<select id="vmsb-bulk-select">
					<option value="">Bulk Actions</option>
					<option value="bulk-approve">Approve Selected</option>
					<option value="bulk-produce">Write Selected Now</option>
					<option value="bulk-delete">Remove Selected</option>
				</select>
				<button class="vmsb-btn vmsb-btn-ghost" id="vmsb-bulk-apply">Apply</button>
			</div>
		</div>

		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full vmsb-pipe-table" id="vmsb-pipeline-table">
				<thead>
					<tr>
						<th class="vmsb-col-cb"><input type="checkbox" id="vmsb-select-all" title="Select all shown"></th>
						<th>Stage</th>
						<th>Topic</th>
						<th>Quality</th>
						<th>Age</th>
						<th>Priority</th>
						<th class="vmsb-row-actions">Actions</th>
					</tr>
				</thead>
				<tbody id="vmsb-pipe-body" data-total="<?php echo (int) $vmsb_total_rows; ?>">
					<tr class="vmsb-pipe-boot">
						<td colspan="7">
							<div class="vmsb-skeleton"></div>
							<div class="vmsb-skeleton"></div>
							<div class="vmsb-skeleton"></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
	</div>

	<?php if ( ! $vmsb_is_nested ) : ?>

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
					if ( empty( $vmsb_top_opps ) ) : ?>
						<p class="vmsb-note" style="margin:0;">No keywords researched yet. Connect Search Console or run keyword research to populate this list.</p>
					<?php else :
						foreach ( $vmsb_top_opps as $vmsb_opp ) : ?>
						<div style="display:flex; justify-content:space-between; align-items:center; padding: 10px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($vmsb_opp->keyword); ?></span>
							<button class="vmsb-mini-btn" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($vmsb_opp->keyword); ?>", "count":1}'>Plan</button>
						</div>
					<?php endforeach; endif; ?>
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
					<?php if ( empty( $vmsb_rising_trends ) ) : ?>
						<div class="vmsb-card vmsb-empty-state">
							<p class="vmsb-empty-title">No rising signals right now</p>
							<p class="vmsb-note">Trend detection compares impression growth across recent Search Console windows. It needs a connected property and at least a few days of history before it can report movement.</p>
						</div>
					<?php else :
						foreach ( $vmsb_rising_trends as $vmsb_trend ) : ?>
						<article class="vmsb-card" style="display:flex; justify-content:space-between; align-items:center; padding: 20px 25px;">
							<div><span class="vmsb-tag vmsb-tag-blue">Google Trend</span><h3 style="margin:5px 0; font-size:17px;"><?php echo esc_html($vmsb_trend); ?></h3></div>
							<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="plan" data-body='{"keyword":"<?php echo esc_attr($vmsb_trend); ?>", "count":1}'>Push</button>
						</article>
					<?php endforeach; endif; ?>
				</div>
			</section>
			<aside>
				<div class="vmsb-card vmsb-card-wide" style="border-top: 4px solid var(--good);">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:1px; color:var(--good);">Impression Velocity</h3>
					<?php if ( empty( $vmsb_velocity_signals ) ) : ?>
						<p class="vmsb-note" style="margin:0;">No measurable impression velocity yet.</p>
					<?php else :
						foreach ( $vmsb_velocity_signals as $vmsb_sig ) : ?>
						<div style="display:flex; justify-content:space-between; padding: 12px 0; border-bottom:1px solid var(--line);">
							<span style="font-weight:600; font-size:12px;"><?php echo esc_html($vmsb_sig['keyword']); ?></span>
							<span style="color:var(--good); font-weight:700;">+<?php echo esc_html($vmsb_sig['growth']); ?></span>
						</div>
					<?php endforeach; endif; ?>
				</div>
			</aside>
		</div>
	</div>

	<?php endif; // ! $vmsb_is_nested ?>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>
