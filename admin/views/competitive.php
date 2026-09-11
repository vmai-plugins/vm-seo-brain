<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$vmsb_competitor_engine = new VMSB_Competitor();
$vmsb_backlinks_engine  = new VMSB_Backlinks();
$vmsb_programmatic      = new VMSB_Programmatic();
$vmsb_thief             = new VMSB_Thief();

$vmsb_competitors    = $vmsb_competitor_engine->list_all();
$vmsb_competitor_ct  = $vmsb_competitor_engine->counts();
$vmsb_prospects      = $vmsb_backlinks_engine->list_by_status( '', 40 );
$vmsb_backlink_ct    = $vmsb_backlinks_engine->counts();
$vmsb_pseo_stats     = $vmsb_programmatic->stats();
$vmsb_market_latest  = ( new VMSB_Market() )->latest();
$vmsb_hijacks        = $vmsb_thief->recent_hijacks( 10 );
?>

<?php if ( ! $vmsb_is_nested ) : ?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Beyond your own site</p>
			<h1>Competitive</h1>
			<p class="vmsb-sub">Competitor gaps, backlink pipeline, answer-engine readiness, and topical authority — everything that isn't purely about fixing your own pages.</p>
		</div>
	</header>
	<span class="wp-header-end"></span>
<?php endif; ?>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="market">⚔️ Market Rivals</button>
		<button class="vmsb-tab" data-tab="outreach">🔗 Authority Pipeline</button>
		<button class="vmsb-tab" data-tab="roi">💰 ROI & CTR</button>
		<button class="vmsb-tab" data-tab="scale">🚀 Scaled Growth</button>
	</div>

	<div class="vmsb-panel is-active" data-panel="market">
		<!-- ===================== COMPETITORS ===================== -->
		<section class="vmsb-section">
			<div style="display:flex; justify-content:space-between; align-items:baseline;">
				<h2>The Competitive War Room</h2>
				<?php if ( VMSB_License::has_feature('competitor_hijack') ) : ?>
					<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="competitor-scan" data-body='{"limit":10}'>Sync Market Intelligence</button>
				<?php else : ?>
					<a href="<?php echo esc_url( admin_url('admin.php?page=vmsb-plans') ); ?>" class="vmsb-btn vmsb-btn-gold vmsb-btn-sm">Unlock Competitor Intelligence</a>
				<?php endif; ?>
			</div>
			<p class="vmsb-note" style="margin-top:8px;">
				Velocity is a real sitemap count when the competitor's sitemap is reachable, falling back to an AI estimate otherwise.
				<?php if ( VMSB_External_Data::semrush_configured() ) : ?>
					Overlap and gaps use real SEMrush ranking data where the competitor has verified data for a query, falling back to the AI's reasoned estimate otherwise.
				<?php else : ?>
					Overlap and gaps are the AI's reasoned estimate of a competitor's likely coverage - connect a SEMrush key in Settings for verified ranking data instead.
				<?php endif; ?>
			</p>

			<div class="vmsb-grid" style="margin-top:20px;">
				<div class="vmsb-card vmsb-card-wide" style="grid-column: span 2;">
					<h3 style="font-size:14px; text-transform:uppercase; color:var(--muted); margin-bottom:15px;">Market Authority Leaderboard</h3>
					<?php if ( ! $vmsb_competitors ) : ?>
						<div class="vmsb-empty"><h2>No competitors tracked yet</h2><p>Add one below to start the intelligence engine.</p></div>
					<?php else : ?>
						<div class="vmsb-table-wrap">
							<table class="vmsb-table vmsb-table-full">
								<thead><tr><th>Domain</th><th>Market Overlap</th><th>Authority Gaps</th><th>Velocity</th><th>Action</th></tr></thead>
								<tbody>
								<?php
								$vmsb_my_count = (int) wp_count_posts( 'post' )->publish;
								$vmsb_velocity_report = $vmsb_competitor_engine->get_velocity_report();

								foreach ( $vmsb_competitors as $vmsb_c ) :
									$vmsb_v_match = array_filter($vmsb_velocity_report, fn($v) => $v->domain === $vmsb_c->domain);
									$vmsb_v_data = reset($vmsb_v_match);
									$vmsb_ratio = $vmsb_v_data ? ($vmsb_v_data->post_count / ($vmsb_my_count ?: 1)) : 1;
								?>
									<tr>
										<td><strong><?php echo esc_html( $vmsb_c->label ?: $vmsb_c->domain ); ?></strong><br><code><?php echo esc_html( $vmsb_c->domain ); ?></code></td>
										<td>
											<div style="display:flex; align-items:center; gap:8px;">
												<div class="vmsb-bar" style="width:80px; height:6px; margin:0;"><span style="width:<?php echo (float)$vmsb_c->overlap_score; ?>%; background:var(--gold);"></span></div>
												<span style="font-size:11px;"><?php echo esc_html( round($vmsb_c->overlap_score) ); ?>%</span>
											</div>
										</td>
										<td><span class="vmsb-tag vmsb-tag-gold"><?php echo (int) $vmsb_c->shared_keywords; ?> Gaps Found</span></td>
										<td><span class="vmsb-sev sev-<?php echo $vmsb_ratio > 1.2 ? 'high' : 'low'; ?>"><?php echo esc_html( round($vmsb_ratio, 1) ); ?>x your size</span></td>
										<td class="vmsb-row-actions">
											<button class="vmsb-mini-btn" data-vmsb="competitor-duel" data-id="0" data-body='{"domain":"<?php echo esc_attr($vmsb_c->domain); ?>"}'>Scout Gaps</button>
											<button class="vmsb-mini-btn" data-vmsb="competitor-remove" data-id="<?php echo (int) $vmsb_c->id; ?>" data-confirm="Stop tracking <?php echo esc_attr( $vmsb_c->domain ); ?>?">Remove</button>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>

					<div id="vmsb-competitor-add-form" class="vmsb-inline-form" style="margin-top:16px; gap:10px;">
						<input type="text" name="domain" placeholder="rival-domain.com" style="flex:1; min-width:180px;">
						<input type="text" name="label" placeholder="Display name (optional)" style="flex:1; min-width:160px;">
						<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="competitor-add" data-vmsb-form="vmsb-competitor-add-form">Add Competitor</button>
					</div>
				</div>

				<div class="vmsb-card">
					<h3 style="font-size:14px; text-transform:uppercase; color:var(--muted); margin-bottom:15px;">Market Pulse</h3>
					<?php if ( empty($vmsb_market_latest) ) : ?>
						<p class="vmsb-note">No market assessment found.</p>
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="market-assess">Analyze Niche</button>
					<?php else : ?>
						<div class="vmsb-alert" style="border-left: 4px solid var(--good); background:rgba(95, 167, 120, 0.05); margin-bottom:15px;">
							<p><strong><?php echo esc_html(ucfirst($vmsb_market_latest['saturation'])); ?> Niche</strong></p>
						</div>
						<p class="vmsb-note" style="color:var(--text); line-height:1.5;"><?php echo esc_html($vmsb_market_latest['recommendation']); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<div class="vmsb-card" style="margin-top:20px;">
				<h3 style="font-size:14px; text-transform:uppercase; color:var(--muted); margin-bottom:15px;">Live Hijack Feed</h3>
				<p class="vmsb-note" style="margin:0 0 15px;">Keywords Thief Mode and Blitz have queued from rival gaps.</p>
				<?php if ( ! $vmsb_hijacks ) : ?>
					<div class="vmsb-empty"><h2>No hijacks yet</h2><p>Run Sync Market Intelligence above, or wait for God Mode's competitor_blitz task if it's enabled.</p></div>
				<?php else : ?>
					<div class="vmsb-table-wrap">
						<table class="vmsb-table vmsb-table-full">
							<thead><tr><th>Keyword</th><th>Target</th><th>Mode</th><th>Status</th><th>Queued</th></tr></thead>
							<tbody>
							<?php foreach ( $vmsb_hijacks as $vmsb_h ) : ?>
								<tr>
									<td>
										<?php if ( $vmsb_h->post_id && get_post( $vmsb_h->post_id ) ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( $vmsb_h->post_id ) ); ?>"><?php echo esc_html( $vmsb_h->primary_keyword ); ?></a>
										<?php else : ?>
											<strong><?php echo esc_html( $vmsb_h->primary_keyword ); ?></strong>
										<?php endif; ?>
									</td>
									<td><?php echo $vmsb_h->target_domain ? '<code>' . esc_html( $vmsb_h->target_domain ) . '</code>' : '<span class="vmsb-note">—</span>'; ?></td>
									<td><span class="vmsb-tag <?php echo $vmsb_h->is_blitz ? 'vmsb-tag-crit' : 'vmsb-tag-gold'; ?>"><?php echo $vmsb_h->is_blitz ? 'Blitz' : 'Thief'; ?></span></td>
									<td><span class="vmsb-sev state-<?php echo esc_attr( $vmsb_h->status ); ?>"><?php echo esc_html( $vmsb_h->status ); ?></span></td>
									<td class="vmsb-note"><?php echo esc_html( human_time_diff( strtotime( $vmsb_h->created_at ) ) . ' ago' ); ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
	</div>

	<div class="vmsb-panel" data-panel="outreach">
		<!-- ===================== BACKLINKS ===================== -->
		<section class="vmsb-section">
			<h2>Backlink Pipeline</h2>
			<p class="vmsb-sub">Converting relationships into domain authority.</p>

			<?php if ( ! (int) VMSB_Settings::get( 'backlink_enabled' ) ) : ?>
				<p class="vmsb-status-line is-warning">⚠️ Outreach is off in Settings → God Mode. Discovery (below) still works either way.</p>
			<?php endif; ?>

			<div class="vmsb-btn-row">
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="backlink-shield" data-body='{"limit":30}'>Scan for Dead Outbound Links</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="backlink-discover-recent" data-body='{"limit":5}'>Discover Prospects for Recent Posts</button>
			</div>

			<?php if ( ! $vmsb_prospects ) : ?>
				<div class="vmsb-empty"><h2>No prospects yet</h2><p>Click "Discover Prospects for Recent Posts" above to start.</p></div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Target Domain</th><th>Status</th><th>Relevance</th><th>Target Page</th><th>Contact</th></tr></thead>
						<tbody>
						<?php foreach ( $vmsb_prospects as $vmsb_p ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $vmsb_p->domain ); ?></strong></td>
								<td><span class="vmsb-verdict v-<?php echo esc_attr( $vmsb_p->status ); ?>"><?php echo esc_html( $vmsb_p->status ); ?></span></td>
								<td><?php echo esc_html( round( (float) $vmsb_p->relevance * 100 ) ); ?>%</td>
								<td><?php if ( $vmsb_p->target_post_id && get_post( $vmsb_p->target_post_id ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $vmsb_p->target_post_id ) ); ?>"><?php echo esc_html( wp_trim_words(get_the_title( $vmsb_p->target_post_id ), 5) ); ?></a><?php else : ?>—<?php endif; ?></td>
								<td>
									<?php if ($vmsb_p->contact_email) : ?>
										<button class="vmsb-mini-btn" data-vmsb="backlink-draft" data-id="<?php echo esc_attr($vmsb_p->id); ?>">Draft Pitch</button>
									<?php else : ?>
										<span class="vmsb-note">No email found</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<div class="vmsb-panel" data-panel="roi">
		<!-- ===================== ROI + CTR + FORECAST ===================== -->
		<?php
		$vmsb_roi_counts = ( new VMSB_ROI() )->counts();
		$vmsb_forecast   = ( new VMSB_Forecaster() )->latest();
		$vmsb_running    = ( new VMSB_CTR() )->running();
		?>
		<section class="vmsb-section">
			<h2>Click-Through Experiments</h2>
			<p class="vmsb-sub">Optimizing how users see you in search results.</p>

			<div class="vmsb-cards" style="margin:20px 0;">
				<div class="vmsb-card">
					<span class="vmsb-card-num"><?php echo (int) $vmsb_roi_counts['open_leaks']; ?></span>
					<span class="vmsb-card-label">Conversion Leaks</span>
					<?php if ( $vmsb_roi_counts['open_leaks'] ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-issues&rule=roi_leak' ) ); ?>" class="vmsb-note" style="display:block; margin-top:6px;">View &amp; fix on Issues →</a>
					<?php endif; ?>
				</div>
				<div class="vmsb-card"><span class="vmsb-card-num"><?php echo count( $vmsb_running ); ?></span><span class="vmsb-card-label">Live CTR Tests</span></div>
				<div class="vmsb-card">
					<span class="vmsb-card-num vmsb-card-sm" style="color:var(--good);"><?php echo $vmsb_forecast ? esc_html( ucfirst( $vmsb_forecast['trend'] ) ) : '—'; ?></span>
					<span class="vmsb-card-label">Growth Forecast</span>
				</div>
			</div>

			<div class="vmsb-btn-row">
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="roi-scan" data-body='{"limit":15}'>Find Conversion Leaks</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="roi-forecast">Update ROI Projection</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="ctr-conclude">Finalize All Due Tests</button>
			</div>

			<?php if ( $vmsb_running ) : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Experiment Page</th><th>Variant Target</th><th>Baseline</th><th>Due Date</th></tr></thead>
						<tbody>
						<?php foreach ( $vmsb_running as $vmsb_exp ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $vmsb_exp->post_id ) ); ?>"><?php echo esc_html( get_the_title( $vmsb_exp->post_id ) ); ?></a></td>
								<td><code><?php echo esc_html( $vmsb_exp->variant_value ); ?></code></td>
								<td><?php echo esc_html( round($vmsb_exp->baseline_ctr * 100, 2) ); ?>%</td>
								<td><?php echo esc_html( mysql2date( 'j M', $vmsb_exp->concludes_at ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<div class="vmsb-panel" data-panel="scale">
		<!-- ===================== PROGRAMMATIC + GLOBAL ===================== -->
		<section class="vmsb-section">
			<h2>Hyper-Scale Growth</h2>
			<p class="vmsb-sub">Tools for massive keyword domination.</p>

			<div class="vmsb-grid">
				<div class="vmsb-card">
					<h3>Programmatic SEO</h3>
					<?php if ( VMSB_License::has_feature('programmatic_seo') ) : ?>
						<div id="vmsb-pseo-form" class="vmsb-stack-form" style="max-width:100%;">
							<label>Title Template</label>
							<input type="text" name="template" placeholder="{service} in {city}">
							<label>Variable Name</label>
							<input type="text" name="variable_name" placeholder="city">
							<label>Base Keyword</label>
							<input type="text" name="base_keyword" placeholder="best plumber">
							<label>Values <small>(one per line)</small></label>
							<textarea name="values" data-list rows="4" placeholder="One value per line&#10;e.g. a city, region, or product"></textarea>
							<button class="vmsb-btn vmsb-btn-gold vmsb-btn-block" data-vmsb="programmatic-build" data-vmsb-form="vmsb-pseo-form">Build Dominance Set</button>
						</div>
					<?php else : ?>
						<p class="vmsb-note" style="margin-bottom: 20px;">Automate thousands of high-intent local or service pages instantly.</p>
						<a href="<?php echo esc_url( admin_url('admin.php?page=vmsb-plans') ); ?>" class="vmsb-btn vmsb-btn-gold vmsb-btn-block">Unlock Programmatic SEO</a>
					<?php endif; ?>
				</div>

				<div class="vmsb-card">
					<h3>Global Expansion</h3>
					<p class="vmsb-note">Target new locations using your best-performing content as a blueprint.</p>
					<div id="vmsb-global-expand-form" class="vmsb-stack-form" style="max-width:100%;">
						<label>Post ID <small>(the source content to localize - find it in the URL when editing a post)</small></label>
						<input type="number" name="post_id" placeholder="e.g. 42">
						<label>Locations <small>(one per line)</small></label>
						<textarea name="locations" data-list rows="4" placeholder="Austin&#10;Denver&#10;Miami"></textarea>
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="global-expand" data-vmsb-form="vmsb-global-expand-form">Expand to New Locations</button>
					</div>
				</div>
			</div>
		</section>
	</div>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>

