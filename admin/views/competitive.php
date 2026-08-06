<?php
defined( 'ABSPATH' ) || exit;

$competitor    = new VMSB_Competitor();
$backlinks     = new VMSB_Backlinks();
$programmatic  = new VMSB_Programmatic();

$competitors    = $competitor->list_all();
$competitor_ct  = $competitor->counts();
$prospects      = $backlinks->list_by_status( '', 40 );
$backlink_ct    = $backlinks->counts();
$pseo_stats     = $programmatic->stats();
$market_latest  = ( new VMSB_Market() )->latest();
?>
<div class="wrap vmsb vmsb-competitive">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Beyond your own site</p>
			<h1>Competitive</h1>
			<p class="vmsb-sub">Competitor gaps, backlink pipeline, answer-engine readiness, and topical authority — everything that isn't purely about fixing your own pages.</p>
		</div>
	</header>

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
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="competitor-scan" data-body='{"limit":10}'>Sync Market Intelligence</button>
			</div>

			<div class="vmsb-grid" style="margin-top:20px;">
				<div class="vmsb-card vmsb-card-wide" style="grid-column: span 2;">
					<h3 style="font-size:14px; text-transform:uppercase; color:var(--muted); margin-bottom:15px;">Market Authority Leaderboard</h3>
					<?php if ( ! $competitors ) : ?>
						<div class="vmsb-empty"><h2>No competitors tracked yet</h2><p>Add one below to start the intelligence engine.</p></div>
					<?php else : ?>
						<div class="vmsb-table-wrap">
							<table class="vmsb-table vmsb-table-full">
								<thead><tr><th>Domain</th><th>Market Overlap</th><th>Authority Gaps</th><th>Velocity</th><th>Action</th></tr></thead>
								<tbody>
								<?php
								$my_count = (int) wp_count_posts( 'post' )->publish;
								$velocity_report = $competitor->get_velocity_report();

								foreach ( $competitors as $c ) :
									$v_match = array_filter($velocity_report, fn($v) => $v->domain === $c->domain);
									$v_data = reset($v_match);
									$ratio = $v_data ? ($v_data->post_count / ($my_count ?: 1)) : 1;
								?>
									<tr>
										<td><strong><?php echo esc_html( $c->label ?: $c->domain ); ?></strong><br><code><?php echo esc_html( $c->domain ); ?></code></td>
										<td>
											<div style="display:flex; align-items:center; gap:8px;">
												<div class="vmsb-bar" style="width:80px; height:6px; margin:0;"><span style="width:<?php echo (float)$c->overlap_score; ?>%; background:var(--gold);"></span></div>
												<span style="font-size:11px;"><?php echo round($c->overlap_score); ?>%</span>
											</div>
										</td>
										<td><span class="vmsb-tag vmsb-tag-gold"><?php echo (int) $c->shared_keywords; ?> Gaps Found</span></td>
										<td><span class="vmsb-sev sev-<?php echo $ratio > 1.2 ? 'high' : 'low'; ?>"><?php echo round($ratio, 1); ?>x your size</span></td>
										<td class="vmsb-row-actions">
											<button class="vmsb-mini-btn" data-vmsb="competitor-duel" data-id="0" data-body='{"domain":"<?php echo esc_attr($c->domain); ?>"}'>Scout Gaps</button>
											<button class="vmsb-mini-btn" data-vmsb="competitor-remove" data-id="<?php echo (int) $c->id; ?>" data-confirm="Stop tracking <?php echo esc_attr( $c->domain ); ?>?">Remove</button>
										</td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>

					<!-- competitor-add existed as a working REST route with no form
					     anywhere to reach it - the empty state above literally said
					     "Add one below" while nothing was below it. -->
					<div id="vmsb-competitor-add-form" class="vmsb-inline-form" style="margin-top:16px; gap:10px;">
						<input type="text" name="domain" placeholder="rival-domain.com" style="flex:1; min-width:180px;">
						<input type="text" name="label" placeholder="Display name (optional)" style="flex:1; min-width:160px;">
						<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="competitor-add" data-vmsb-form="vmsb-competitor-add-form">Add Competitor</button>
					</div>
				</div>

				<div class="vmsb-card">
					<h3 style="font-size:14px; text-transform:uppercase; color:var(--muted); margin-bottom:15px;">Market Pulse</h3>
					<?php if ( empty($market_latest) ) : ?>
						<p class="vmsb-note">No market assessment found.</p>
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="market-assess">Analyze Niche</button>
					<?php else : ?>
						<div class="vmsb-alert" style="border-left: 4px solid var(--good); background:rgba(95, 167, 120, 0.05); margin-bottom:15px;">
							<p><strong><?php echo esc_html(ucfirst($market_latest['saturation'])); ?> Niche</strong></p>
						</div>
						<p class="vmsb-note" style="color:var(--text); line-height:1.5;"><?php echo esc_html($market_latest['recommendation']); ?></p>
					<?php endif; ?>
				</div>
			</div>
	</div>

	<div class="vmsb-panel" data-panel="outreach">
		<!-- ===================== BACKLINKS ===================== -->
		<section class="vmsb-section">
			<h2>Backlink Pipeline</h2>
			<p class="vmsb-sub">Converting relationships into domain authority.</p>

			<?php if ( ! (int) VMSB_Settings::get( 'backlink_enabled' ) ) : ?>
				<p class="vmsb-status-line is-warning">⚠️ Outreach is off in Settings → God Mode. Discovery (below) still works either way - it only reasons about prospects and sends nothing - but drafting and sending pitches stays locked until you opt in with a sender name and email.</p>
			<?php endif; ?>

			<div class="vmsb-btn-row">
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="backlink-shield" data-body='{"limit":30}'>Scan for Dead Outbound Links</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="backlink-discover-recent" data-body='{"limit":5}' title="Runs discovery for published posts that have no prospects yet">Discover Prospects for Recent Posts</button>
			</div>

			<?php if ( ! $prospects ) : ?>
				<div class="vmsb-empty"><h2>No prospects yet</h2><p>New posts get prospects automatically once published (if outreach is enabled in Settings) - or click "Discover Prospects for Recent Posts" above to backfill existing ones.</p></div>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Target Domain</th><th>Status</th><th>Relevance</th><th>Target Page</th><th>Contact</th></tr></thead>
						<tbody>
						<?php foreach ( $prospects as $p ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $p->domain ); ?></strong></td>
								<td><span class="vmsb-verdict v-<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( $p->status ); ?></span></td>
								<td><?php echo esc_html( round( (float) $p->relevance * 100 ) ); ?>%</td>
								<td><?php if ( $p->target_post_id && get_post( $p->target_post_id ) ) : ?><a href="<?php echo esc_url( get_edit_post_link( $p->target_post_id ) ); ?>"><?php echo esc_html( wp_trim_words(get_the_title( $p->target_post_id ), 5) ); ?></a><?php else : ?>—<?php endif; ?></td>
								<td>
									<?php if ($p->contact_email) : ?>
										<button class="vmsb-mini-btn" data-vmsb="backlink-draft" data-id="<?php echo $p->id; ?>">Draft Pitch</button>
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
		$roi_counts = ( new VMSB_ROI() )->counts();
		$forecast   = ( new VMSB_Forecaster() )->latest();
		$running    = ( new VMSB_CTR() )->running();
		?>
		<section class="vmsb-section">
			<h2>Click-Through Experiments</h2>
			<p class="vmsb-sub">Optimizing how users see you in search results.</p>

			<div class="vmsb-cards" style="margin:20px 0;">
				<div class="vmsb-card"><span class="vmsb-card-num"><?php echo (int) $roi_counts['open_leaks']; ?></span><span class="vmsb-card-label">Conversion Leaks</span></div>
				<div class="vmsb-card"><span class="vmsb-card-num"><?php echo count( $running ); ?></span><span class="vmsb-card-label">Live CTR Tests</span></div>
				<div class="vmsb-card">
					<span class="vmsb-card-num vmsb-card-sm" style="color:var(--good);"><?php echo $forecast ? esc_html( ucfirst( $forecast['trend'] ) ) : '—'; ?></span>
					<span class="vmsb-card-label">Growth Forecast</span>
				</div>
			</div>

			<div class="vmsb-btn-row">
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="roi-scan" data-body='{"limit":15}'>Find Conversion Leaks</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="roi-forecast">Update ROI Projection</button>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="ctr-conclude">Finalize All Due Tests</button>
			</div>

			<?php if ( $running ) : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Experiment Page</th><th>Variant Target</th><th>Baseline</th><th>Due Date</th></tr></thead>
						<tbody>
						<?php foreach ( $running as $exp ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $exp->post_id ) ); ?>"><?php echo esc_html( get_the_title( $exp->post_id ) ); ?></a></td>
								<td><code><?php echo esc_html( $exp->variant_value ); ?></code></td>
								<td><?php echo esc_html( round( $exp->baseline_ctr * 100, 2 ) ); ?>%</td>
								<td><?php echo esc_html( mysql2date( 'j M', $exp->concludes_at ) ); ?></td>
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
					<div id="vmsb-pseo-form" class="vmsb-stack-form" style="max-width:100%;">
						<label>Title Template</label>
						<input type="text" name="template" placeholder="{service} in {city}">
						<label>Variable Name <small>(the single placeholder above these values fill in, e.g. "city")</small></label>
						<input type="text" name="variable_name" placeholder="city">
						<label>Base Keyword <small>(optional)</small></label>
						<input type="text" name="base_keyword" placeholder="best plumber">
						<label>Values <small>(one per line)</small></label>
						<textarea name="values" data-list rows="4" placeholder="Indore&#10;Bhopal&#10;Pune"></textarea>
						<button class="vmsb-btn vmsb-btn-gold vmsb-btn-block" data-vmsb="programmatic-build" data-vmsb-form="vmsb-pseo-form">Build Dominance Set</button>
					</div>
				</div>

				<div class="vmsb-card">
					<h3>Global Expansion</h3>
					<p class="vmsb-note">Target new locations using your best-performing content as a blueprint.</p>
					<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="traffic-forecast">Refresh Opportunity Map</button>

					<h3 style="margin-top:30px;">Timely Angles</h3>
					<p class="vmsb-note">Hijack news and seasonal trends.</p>
					<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="news-scout" data-body='{"count":5}'>Scout News Signals</button>
				</div>
			</div>
		</section>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
