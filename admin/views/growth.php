<?php
defined( 'ABSPATH' ) || exit;

/**
 * Growth Bucket — VM SEO Brain X.
 *
 * Jobs: Identifying Gaps, Planning Strategy, Projecting ROI.
 * Contains: Opportunities, Strategy, Roadmap.
 */

$vmsb_brain_engine = new VMSB_Brain();
$vmsb_ops          = $vmsb_brain_engine->recall('intelligence', 'active_opportunities', array());
$vmsb_battle_plan  = get_option('vmsb_battle_plan', array());
$vmsb_pivot        = $vmsb_brain_engine->recall('intelligence', 'current_strategy_pivot');

// Opportunities (above) only ever proposes fixes to posts that already
// exist - it has no path to suggest new topics. VMSB_Growth_Engine is the
// one that scans for content that hasn't been written yet (including a
// site's registered CPTs, like a travel site's destinations/events) and
// queues it here for a human yes/no instead of writing it unattended.
$vmsb_growth_engine = new VMSB_Growth_Engine();
$vmsb_suggestions   = $vmsb_growth_engine->pending( 50 );

$vmsb_is_nested = defined( 'VMSB_NESTED' ) && VMSB_NESTED;
?>
	<?php if ( ! $vmsb_is_nested ) : ?>
		<header class="vmsb-head">
			<div>
				<p class="vmsb-eyebrow">Strategic Discovery</p>
				<h1>Growth Center</h1>
				<p class="vmsb-sub">Identifying and planning your path to SEO dominance.</p>
			</div>
			<div class="vmsb-head-actions">
				<button class="vmsb-btn vmsb-btn-gold" data-vmsb="opportunity-scan">Discovery Scan</button>
			</div>
		</header>
		<span class="wp-header-end"></span>
	<?php endif; ?>

	<div class="vmsb-tabs" style="margin-top:30px;">
		<button class="vmsb-tab is-active" data-tab="opportunities">🎯 Opportunities (<?php echo count($vmsb_ops); ?>)</button>
		<button class="vmsb-tab" data-tab="suggestions">💡 Suggestions (<?php echo count($vmsb_suggestions); ?>)</button>
		<button class="vmsb-tab" data-tab="strategy">🧠 Strategic Pivot</button>
		<button class="vmsb-tab" data-tab="roadmap">🗺️ Roadmap</button>
		<button class="vmsb-tab" data-tab="geo">📍 Local Coverage<?php
			$vmsb_geo_cov = class_exists( 'VMSB_Geo' ) ? VMSB_Geo::coverage() : array( 'totals' => array( 'total' => 0, 'missing' => 0 ) );
			if ( $vmsb_geo_cov['totals']['total'] ) {
				echo ' (' . (int) $vmsb_geo_cov['totals']['missing'] . ' open)';
			}
		?></button>
	</div>

	<!-- OPPORTUNITIES -->
	<div class="vmsb-panel is-active" data-panel="opportunities">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<h2 style="font-family:var(--serif);">Active Growth Opportunities</h2>
				<p class="vmsb-note">Ranked by (Impact × Confidence × Value) / Effort.</p>
			</div>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th>Type</th>
							<th>Target</th>
							<th>Priority</th>
							<th>Reason / Recommended</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty($vmsb_ops) ) : ?>
							<tr><td colspan="5" class="vmsb-note">No active opportunities. Run a Discovery Scan to find new leads.</td></tr>
						<?php else :
							foreach ( $vmsb_ops as $op ) :
						?>
							<tr>
								<td><span class="vmsb-tag"><?php echo esc_html(str_replace('_', ' ', $op['type'])); ?></span></td>
								<td><strong><?php echo esc_html($op['target']); ?></strong></td>
								<td><span class="vmsb-tag vmsb-tag-gold" style="font-weight:800;">🔥 <?php echo (float)$op['priority']; ?></span></td>
								<td>
									<p style="font-size:13px; margin:0;"><strong>Why:</strong> <?php echo esc_html($op['reason']); ?></p>
									<p class="vmsb-note" style="margin:5px 0 0;"><?php echo esc_html($op['recommended']); ?></p>
								</td>
								<td class="vmsb-row-actions">
									<?php if ( empty( $op['object_id'] ) ) : ?>
										<?php // execute-opportunity only ever acts on an existing post
										// (see class-vmsb-rest.php's execute_opportunity() docblock) -
										// this opportunity type recommends writing something new
										// instead, so it needs the content-planning action, not the
										// surgical-fixer one. Every "Execute" click on one of these
										// used to fail with "no target post to act on" no matter what. ?>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="plan" data-body='{"keyword":<?php echo wp_json_encode( (string) $op['target'] ); ?>,"count":1}' data-confirm="Plan a new article targeting this keyword?">Plan Article</button>
									<?php else : ?>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="execute-opportunity" data-body='<?php echo wp_json_encode($op); ?>'>Execute</button>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<!-- SUGGESTIONS -->
	<div class="vmsb-panel" data-panel="suggestions">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<div>
					<h2 style="font-family:var(--serif);">Content Suggestions</h2>
					<p class="vmsb-note">New destinations, events, and blog topics the Brain found - nothing here gets written until you approve it.</p>
				</div>
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="growth-scan">Scan Now</button>
			</div>

			<?php if ( empty( $vmsb_suggestions ) ) : ?>
				<p class="vmsb-note">No pending suggestions. Click "Scan Now" - if Business DNA has discovered custom post types like "destinations" or "events", they'll be scanned specifically, not just generic blog topics.</p>
			<?php else : ?>
				<div class="vmsb-table-wrap">
					<table class="vmsb-table vmsb-table-full">
						<thead><tr><th>Topic</th><th>Keyword</th><th>Type</th><th>Why</th><th>Actions</th></tr></thead>
						<tbody>
							<?php foreach ( $vmsb_suggestions as $vmsb_sug ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $vmsb_sug->title ); ?></strong></td>
									<td><code><?php echo esc_html( $vmsb_sug->primary_keyword ); ?></code></td>
									<td><span class="vmsb-tag vmsb-tag-purple"><?php echo esc_html( $vmsb_sug->cluster ?: 'Blog' ); ?></span></td>
									<td><p class="vmsb-note" style="max-width:280px;"><?php echo esc_html( $vmsb_sug->brief ); ?></p></td>
									<td class="vmsb-row-actions">
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="growth-suggestion-approve" data-id="<?php echo (int) $vmsb_sug->id; ?>">Approve</button>
										<button class="vmsb-mini-btn" data-vmsb="growth-suggestion-reject" data-id="<?php echo (int) $vmsb_sug->id; ?>">Reject</button>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<!-- STRATEGY -->
	<div class="vmsb-panel" data-panel="strategy">
		<div class="vmsb-grid" style="grid-template-columns: 2fr 1fr; gap:30px;">
			<section>
				<article class="vmsb-card" style="border-left: 5px solid var(--gold);">
					<div class="vmsb-flex-space" style="margin-bottom:20px;">
						<h2 style="font-family:var(--serif);">Active Strategic Pivot</h2>
						<span class="vmsb-tag vmsb-tag-gold">30-Day Focus</span>
					</div>
					<?php if ( $vmsb_pivot ) : ?>
						<h3 style="margin:0 0 10px; color:var(--gold-soft);"><?php echo esc_html($vmsb_pivot['pivot_name']); ?></h3>
						<p style="font-size:15px; line-height:1.6; color:var(--text);"><?php echo esc_html($vmsb_pivot['pivot_reason']); ?></p>

						<h4 style="margin:20px 0 10px; font-size:14px; text-transform:uppercase;">Directives</h4>
						<ul class="vmsb-legend" style="flex-direction:column; gap:10px;">
							<?php foreach ((array)$vmsb_pivot['new_directives'] as $d) : ?>
								<li><i class="sev-low"></i><?php echo esc_html($d); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php else : ?>
						<p class="vmsb-note">The Brain is currently using the baseline SEO policy. Trigger a Strategic Evaluation to pivot.</p>
					<?php endif; ?>

					<div style="margin-top:30px; padding-top:20px; border-top:1px solid var(--line);">
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="evaluate-pivot">Evaluate Strategic Pivot</button>
					</div>
				</article>
			</section>

			<aside>
				<article class="vmsb-card">
					<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase;">Business DNA</h3>
					<div class="vmsb-note" style="line-height:1.5;">
						<p><strong>Type:</strong> <?php echo esc_html($vmsb_brain_engine->profile()['type']); ?></p>
						<p><strong>Persona:</strong> <?php echo esc_html($vmsb_brain_engine->profile()['tone']); ?></p>
					</div>
					<a href="<?php echo admin_url('admin.php?page=vmsb-settings'); ?>" class="vmsb-link" style="margin-top:15px; display:block;">Edit Profile →</a>
				</article>
			</aside>
		</div>
	</div>

	<!-- ROADMAP -->
	<div class="vmsb-panel" data-panel="roadmap">
		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom: 25px;">
				<div>
					<h2 style="margin:0; font-family:var(--serif);">The Dominance Roadmap</h2>
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
						<?php if ( empty($vmsb_battle_plan) ) : ?>
							<tr><td colspan="4" class="vmsb-note">No battle plan generated yet. Click to architect your roadmap.</td></tr>
						<?php else :
							foreach ( $vmsb_battle_plan as $task ) :
								$day_num = (int)$task['day'];
						?>
							<tr>
								<td><strong>#<?php echo $day_num; ?></strong></td>
								<td>
									<?php echo esc_html($task['task']); ?>
									<br><small class="vmsb-note"><?php echo esc_html($task['reason']); ?></small>
								</td>
								<td><code><?php echo esc_html($task['keyword'] ?: 'N/A'); ?></code></td>
								<td style="text-align:right;"><span class="vmsb-tag vmsb-tag-good">+<?php echo (int)$task['expected_impact']; ?>%</span></td>
							</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</article>
	</div>

	<!-- LOCAL COVERAGE -->
	<div class="vmsb-panel" data-panel="geo">
		<?php
		$vmsb_geo_services = $vmsb_geo_cov['services'] ?? array();
		$vmsb_geo_cities   = $vmsb_geo_cov['cities'] ?? array();
		$vmsb_geo_cells    = $vmsb_geo_cov['cells'] ?? array();
		$vmsb_geo_totals   = $vmsb_geo_cov['totals'];
		$vmsb_geo_map_text = '';
		if ( class_exists( 'VMSB_Geo' ) ) {
			foreach ( VMSB_Geo::map() as $vmsb_st => $vmsb_ct ) {
				$vmsb_geo_map_text .= $vmsb_st . ': ' . implode( ', ', $vmsb_ct ) . "\n";
			}
		}
		?>

		<article class="vmsb-card vmsb-card-wide">
			<div class="vmsb-flex-space" style="margin-bottom:20px;">
				<div>
					<h2 style="font-family:var(--serif);">Service &times; City Coverage</h2>
					<p class="vmsb-note">Every combination of a service you offer and a city you serve. Fill the gaps deliberately &mdash; each cell can only ever become one page.</p>
				</div>
				<?php if ( $vmsb_geo_totals['total'] ) : ?>
					<div style="text-align:right;">
						<span class="vmsb-tag vmsb-tag-good"><?php echo (int) $vmsb_geo_totals['published']; ?> live</span>
						<span class="vmsb-tag"><?php echo (int) $vmsb_geo_totals['queued']; ?> queued</span>
						<span class="vmsb-tag vmsb-tag-warn"><?php echo (int) $vmsb_geo_totals['missing']; ?> open</span>
					</div>
				<?php endif; ?>
			</div>

			<div id="vmsb-geo-map-form" class="vmsb-stack-form" style="max-width:100%; margin-bottom:26px;">
				<label>State and city map <small>(one state per line &mdash; <code>State: City, City</code>)</small></label>
				<textarea name="map" rows="5" placeholder="Uttar Pradesh: Noida, Ghaziabad, Lucknow&#10;Delhi: New Delhi, Dwarka"><?php echo esc_textarea( $vmsb_geo_map_text ); ?></textarea>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="geo-save-map" data-vmsb-form="vmsb-geo-map-form">Save Map</button>
			</div>

			<?php if ( ! $vmsb_geo_services ) : ?>
				<p class="vmsb-note">No services defined yet. Add them to your business profile in <a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-settings' ) ); ?>">Settings</a> &mdash; they form the rows of this matrix.</p>
			<?php elseif ( ! $vmsb_geo_cities ) : ?>
				<p class="vmsb-note">Add your states and cities above to build the matrix.</p>
			<?php else : ?>
				<div class="vmsb-table-wrap" style="overflow-x:auto;">
					<table class="vmsb-table vmsb-table-full">
						<thead>
							<tr>
								<th style="min-width:190px;">Service</th>
								<?php foreach ( $vmsb_geo_cities as $vmsb_c ) : ?>
									<th style="text-align:center; white-space:nowrap;" title="<?php echo esc_attr( $vmsb_c['state'] ); ?>"><?php echo esc_html( $vmsb_c['city'] ); ?></th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $vmsb_geo_services as $vmsb_s ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $vmsb_s ); ?></strong></td>
									<?php foreach ( $vmsb_geo_cities as $vmsb_c ) :
										$vmsb_cell = $vmsb_geo_cells[ VMSB_Geo::uid( $vmsb_s, $vmsb_c['city'] ) ] ?? array( 'status' => 'missing', 'post_id' => 0 );
										$vmsb_mark = array( 'published' => '&#9679;', 'queued' => '&#9673;', 'missing' => '&#9675;' );
										$vmsb_tone = array( 'published' => 'var(--good)', 'queued' => 'var(--gold)', 'missing' => 'var(--line)' );
									?>
										<td style="text-align:center;" title="<?php echo esc_attr( $vmsb_s . ' in ' . $vmsb_c['city'] . ' - ' . $vmsb_cell['status'] ); ?>">
											<?php if ( 'published' === $vmsb_cell['status'] && $vmsb_cell['post_id'] ) : ?>
												<a href="<?php echo esc_url( get_edit_post_link( $vmsb_cell['post_id'] ) ); ?>" style="color:<?php echo $vmsb_tone['published']; ?>; font-size:15px; text-decoration:none;"><?php echo $vmsb_mark['published']; ?></a>
											<?php else : ?>
												<span style="color:<?php echo $vmsb_tone[ $vmsb_cell['status'] ]; ?>; font-size:15px;"><?php echo $vmsb_mark[ $vmsb_cell['status'] ]; ?></span>
											<?php endif; ?>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="vmsb-note" style="margin-top:12px;">
					<span style="color:var(--good);">&#9679;</span> published &nbsp;
					<span style="color:var(--gold);">&#9673;</span> queued &nbsp;
					<span style="color:var(--line);">&#9675;</span> not covered
				</p>

				<div id="vmsb-geo-expand-form" class="vmsb-stack-form" style="max-width:100%; margin-top:24px; padding-top:22px; border-top:1px solid var(--line);">
					<h3 style="margin:0 0 4px;">Fill the gaps</h3>
					<p class="vmsb-note">New pages arrive as suggestions for your approval, never straight into production.</p>
					<label>Service</label>
					<select name="service">
						<option value="">All services</option>
						<?php foreach ( $vmsb_geo_services as $vmsb_s ) : ?>
							<option value="<?php echo esc_attr( $vmsb_s ); ?>"><?php echo esc_html( $vmsb_s ); ?></option>
						<?php endforeach; ?>
					</select>
					<label>State</label>
					<select name="state">
						<option value="">All states</option>
						<?php foreach ( VMSB_Geo::states() as $vmsb_st ) : ?>
							<option value="<?php echo esc_attr( $vmsb_st ); ?>"><?php echo esc_html( $vmsb_st ); ?></option>
						<?php endforeach; ?>
					</select>
					<label>How many <small>(max <?php echo (int) VMSB_Geo::MAX_PER_RUN; ?> per run)</small></label>
					<input type="number" name="limit" value="10" min="1" max="<?php echo (int) VMSB_Geo::MAX_PER_RUN; ?>">
					<button class="vmsb-btn vmsb-btn-gold" data-vmsb="geo-expand" data-vmsb-form="vmsb-geo-expand-form">Queue Location Pages</button>
				</div>
			<?php endif; ?>
		</article>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
