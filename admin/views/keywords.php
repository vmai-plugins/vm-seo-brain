<?php
defined( 'ABSPATH' ) || exit;

$k         = new VMSB_Keywords();
$striking  = $k->striking_distance( 50 );
$ctr       = $k->ctr_losers( 50 );
$gaps      = $k->content_gaps( 50 );

$total_count = $k->count();
$planned_count = $k->count('planned');
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Keyword Intelligence</p>
			<h1>Keyword Universe</h1>
			<p class="vmsb-sub">
				<?php echo number_format($total_count); ?> queries discovered, <?php echo number_format($planned_count); ?> already in content plan.
				<?php if ( (new VMSB_RankMath())->is_active() ) : ?>
					<span class="vmsb-tag vmsb-tag-good" style="margin-left:10px;">Rank Math Data Active</span>
				<?php endif; ?>
			</p>
		</div>
	</header>

	<?php
	// Onboarding guidance: the two things that most determine result
	// quality (a connected Search Console + a filled-in business profile)
	// aren't obvious from an empty table alone.
	$gsc_connected  = ( new VMSB_Google() )->is_connected();
	$profile_filled = (bool) ( new VMSB_Brain() )->profile()['type'];
	if ( $total_count < 5 && ( ! $gsc_connected || ! $profile_filled ) ) : ?>
		<div class="vmsb-alert" style="margin-bottom:24px;">
			<p><strong>Before your first research run:</strong></p>
			<ul style="margin:8px 0 0 20px; padding:0;">
				<li style="margin-bottom:4px;">
					<?php echo $gsc_connected ? '✅' : '⬜'; ?>
					Connect Google Search Console in
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-settings' ) ); ?>">Settings</a>
					— this is the highest-signal source: queries you already earn impressions for.
				</li>
				<li>
					<?php echo $profile_filled ? '✅' : '⬜'; ?>
					Let the brain read your business (Dashboard → <em>Re-calibrate DNA</em>) — without it, AI-expanded keywords have nothing to ground them in what you actually do.
				</li>
			</ul>
			<p class="vmsb-note" style="margin:10px 0 0;">Neither is required — <em>Sync &amp; Discover</em> below will still work from autocomplete alone — but results improve a lot with both connected.</p>
		</div>
	<?php endif; ?>

	<div class="vmsb-keyword-insights">
		<div class="vmsb-cards">
			<div class="vmsb-card vmsb-card-clickable" data-switch-tab="striking">
				<span class="vmsb-card-num"><?php echo count($striking); ?></span>
				<span class="vmsb-card-label">Striking Distance</span>
				<p class="vmsb-note">Quick wins (Pos 4-20)</p>
			</div>
			<div class="vmsb-card vmsb-card-clickable" data-switch-tab="gaps">
				<span class="vmsb-card-num"><?php echo count($gaps); ?></span>
				<span class="vmsb-card-label">Content Gaps</span>
				<p class="vmsb-note">Untapped demand</p>
			</div>
			<div class="vmsb-card vmsb-card-clickable" data-switch-tab="ctr">
				<span class="vmsb-card-num"><?php echo count($ctr); ?></span>
				<span class="vmsb-card-label">Low CTR Snippets</span>
				<p class="vmsb-note">Ranking but ignored</p>
			</div>
		</div>

		<div class="vmsb-card">
			<h3 style="margin:0 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:0.05em; color:var(--muted);">Position Distribution</h3>
			<?php
			global $wpdb;
			$table = $wpdb->prefix . 'vmsb_keywords';
			$dist = $wpdb->get_results("
				SELECT
					CASE
						WHEN position <= 3 THEN 'P1 (Top 3)'
						WHEN position <= 10 THEN 'P1 (4-10)'
						WHEN position <= 20 THEN 'Page 2'
						WHEN position <= 50 THEN 'Page 3-5'
						ELSE 'Page 6+'
					END as bucket,
					COUNT(*) as n
				FROM {$table}
				WHERE position > 0
				GROUP BY bucket
				ORDER BY MIN(position) ASC
			");
			$max_n = $dist ? max(wp_list_pluck($dist, 'n')) : 1;
			?>
			<div class="vmsb-dist-chart" style="display:flex; flex-direction:column; gap:10px;">
				<?php foreach ($dist as $d) : $pct = ($d->n / $max_n) * 100; ?>
					<div class="vmsb-dist-row">
						<span><?php echo esc_html($d->bucket); ?></span>
						<div class="vmsb-bar" style="flex:1; height:6px; margin:0;"><span style="width:<?php echo $pct; ?>%; background:var(--gold);"></span></div>
						<span><?php echo (int)$d->n; ?></span>
					</div>
				<?php endforeach; ?>
				<?php if ( ! $dist ) : ?><p class="vmsb-note">No rank data yet.</p><?php endif; ?>
			</div>

			<h3 style="margin:25px 0 15px; font-size:14px; text-transform:uppercase; letter-spacing:0.05em; color:var(--muted);">Top Cluster Health</h3>
			<div class="vmsb-cluster-stats" style="display:flex; flex-direction:column; gap:12px;">
				<?php
				$c_stats = $k->get_cluster_stats(3);
				foreach ($c_stats as $cs) : ?>
					<div class="vmsb-cluster-mini-row">
						<div style="display:flex; justify-content:space-between; font-size:12px; margin-bottom:4px;">
							<strong><?php echo esc_html($cs->cluster); ?></strong>
							<span><?php echo (int)$cs->keywords; ?> KWs</span>
						</div>
						<div class="vmsb-bar" style="height:4px; margin:0;"><span style="width:<?php echo (float)$cs->cluster_health; ?>%; background:var(--good);"></span></div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<div class="vmsb-filter-bar">
		<div class="vmsb-search-wrap">
			<span>🔍</span>
			<input type="text" id="vmsb-kw-search" placeholder="Search universe...">
		</div>

		<div class="vmsb-cluster-filter">
			<?php
			$clusters = $wpdb->get_col( "SELECT DISTINCT cluster FROM {$table} WHERE cluster IS NOT NULL AND cluster != '' ORDER BY cluster ASC" );
			?>
			<select id="vmsb-silo-filter" style="height:40px; border-radius:6px; min-width:200px;">
				<option value="">All Topical Silos</option>
				<?php foreach ($clusters as $c) : ?>
					<option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="research" title="Sync with GSC and Discover new trends">Sync & Discover</button>
		</div>
	</div>

	<?php if ( count( $clusters ) > 1 ) : ?>
		<div class="vmsb-inline-form" style="margin-top:-6px;">
			<span class="vmsb-note" style="white-space:nowrap;">Merge clusters:</span>
			<select id="vmsb-cluster-merge-from" style="min-width:180px;">
				<?php foreach ( $clusters as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="vmsb-note">into</span>
			<input type="text" id="vmsb-cluster-merge-to" list="vmsb-cluster-list" placeholder="Target cluster name" style="min-width:200px;">
			<datalist id="vmsb-cluster-list">
				<?php foreach ( $clusters as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php endforeach; ?>
			</datalist>
			<button class="vmsb-mini-btn" id="vmsb-cluster-merge-apply">Merge</button>
		</div>
	<?php endif; ?>

	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="striking">🚀 Quick Wins</button>
		<button class="vmsb-tab" data-tab="gaps">🎯 Content Gaps</button>
		<button class="vmsb-tab" data-tab="ctr">🖱️ CTR Optimization</button>
	</div>

	<?php
	$panels = array(
		'striking' => array( $striking, 'Pages ranking between position 4 and 20. High priority for content refresh.' ),
		'gaps'     => array( $gaps, 'High-opportunity keywords with no dedicated page on your site yet.' ),
		'ctr'      => array( $ctr, 'Pages with high impressions but low click-through rates. Focus on meta title/desc optimization.' ),
	);
	foreach ( $panels as $id => $panel ) :
		list( $rows, $blurb ) = $panel;
		?>
		<section class="vmsb-panel<?php echo 'striking' === $id ? ' is-active' : ''; ?>" data-panel="<?php echo esc_attr( $id ); ?>">
			<div class="vmsb-panel-header">
				<div class="vmsb-alert">
					<p><?php echo esc_html( $blurb ); ?></p>
				</div>
				<?php if ( 'gaps' === $id && ! empty($rows) ) : ?>
					<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="plan" data-body='{"count":10}' data-confirm="Plan the top 10 gaps as new articles?">Plan Top 10</button>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $rows ) ) : ?>
				<div class="vmsb-bulk-actions" style="margin-bottom:12px;">
					<select class="vmsb-kw-bulk-select">
						<option value="">Bulk Actions</option>
						<?php if ( 'gaps' === $id ) : ?><option value="plan">Plan Selected</option><?php endif; ?>
						<option value="dismiss">Dismiss Selected</option>
					</select>
					<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-small vmsb-kw-bulk-apply">Apply</button>
				</div>
			<?php endif; ?>

			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full vmsb-kw-table">
					<thead>
						<tr>
							<th class="vmsb-col-cb"><input type="checkbox" class="vmsb-kw-select-all"></th>
							<th>Query</th>
							<th>Intent</th>
							<th>Funnel</th>
							<th>Pos</th>
							<th>Impressions</th>
							<th title="Modeled ranking difficulty, 0-100. Higher means more competitive - real data when SEMrush/Ahrefs is connected, otherwise the model's estimate.">Difficulty &#9432;</th>
							<th title="Weighted score combining striking-distance position, funnel stage, and difficulty - higher means faster, cheaper leverage. Above 70 is flagged.">Opp Score &#9432;</th>
							<th class="vmsb-row-actions">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="9" class="vmsb-note">No keywords detected in this category. Run research to populate.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) :
						$intent_cls = 'vmsb-tag-' . ($row->intent === 'commercial' || $row->intent === 'transactional' ? 'gold' : 'blue');
						$funnel_cls = 'vmsb-tag-' . ($row->funnel === 'bottom' ? 'purple' : ($row->funnel === 'middle' ? 'blue' : 'gold'));
						$is_high_opp = (float)$row->opportunity > 70;
					?>
						<tr class="<?php echo $is_high_opp ? 'vmsb-high-opp-row' : ''; ?>">
							<td><input type="checkbox" class="vmsb-kw-row-cb" value="<?php echo esc_attr( $row->keyword ); ?>"></td>
							<td>
								<div class="vmsb-kw-main">
									<strong><?php echo esc_html( $row->keyword ); ?></strong>
									<?php if ($is_high_opp) : ?>
										<span class="vmsb-hot-badge" title="High Opportunity Target">🔥</span>
									<?php endif; ?>
									<?php if ($row->cluster) : ?>
										<br><span class="vmsb-note">Cluster: <span class="vmsb-kw-silo-text"><?php echo esc_html($row->cluster); ?></span></span>
									<?php endif; ?>
								</div>
							</td>
							<td><span class="vmsb-tag <?php echo $intent_cls; ?>"><?php echo esc_html( $row->intent ?: 'info' ); ?></span></td>
							<td><span class="vmsb-tag <?php echo $funnel_cls; ?>"><?php echo esc_html( $row->funnel ?: 'top' ); ?></span></td>
							<td>
								<div class="vmsb-pos-badge <?php echo (float)$row->position <= 10 ? 'good' : 'med'; ?>">
									<?php echo $row->position ? esc_html( number_format( (float) $row->position, 1 ) ) : '&mdash;'; ?>
								</div>
							</td>
							<td><?php echo esc_html( number_format( (int) $row->impressions ) ); ?></td>
							<td>
								<div class="vmsb-difficulty-wrap">
									<div class="vmsb-bar vmsb-mini-bar" style="width: 60px; height: 4px; margin: 5px 0;">
										<span style="width: <?php echo (int)($row->difficulty ?: 40); ?>%; background: <?php echo (int)$row->difficulty > 60 ? 'var(--crit)' : ((int)$row->difficulty > 30 ? 'var(--high)' : 'var(--good)'); ?>;"></span>
									</div>
									<span class="vmsb-note"><?php echo (int)($row->difficulty ?: 40); ?>/100</span>
								</div>
							</td>
							<td><strong><?php echo esc_html( number_format( (float) $row->opportunity, 1 ) ); ?></strong></td>
							<td class="vmsb-row-actions">
								<div class="vmsb-action-stack">
									<?php if ( 'gaps' === $id && $row->status === 'new' ) : ?>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="plan" data-body='{"count":1, "keyword":"<?php echo esc_attr($row->keyword); ?>"}'>Plan Topic</button>
									<?php elseif ( 'striking' === $id && $row->post_id ) : ?>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="improve-post" data-body='{"post_id":<?php echo (int) $row->post_id; ?>,"reason":"striking_distance"}' data-confirm="Revise this page to close the gap on this keyword?">Improve Page</button>
									<?php elseif ( 'ctr' === $id && $row->post_id ) : ?>
										<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="ctr-start" data-body='{"post_id":<?php echo (int) $row->post_id; ?>}' data-confirm="Start an A/B title test on this page's snippet?">Start CTR Test</button>
									<?php elseif ( $row->status !== 'new' ) : ?>
										<span class="vmsb-tag vmsb-tag-gold"><?php echo esc_html(ucfirst($row->status)); ?></span>
									<?php endif; ?>
									<button class="vmsb-mini-btn" data-vmsb="keyword-dismiss" data-body='{"keyword":"<?php echo esc_attr($row->keyword); ?>"}' data-confirm="Dismiss this keyword? It will stop appearing in Quick Wins, Gaps, and CTR.">Dismiss</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endforeach; ?>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>

<script>
jQuery(function($) {
	// Keyword Search & Silo Filter
	$('#vmsb-kw-search, #vmsb-silo-filter').on('input change', function() {
		const searchVal = $('#vmsb-kw-search').val().toLowerCase();
		const siloVal = $('#vmsb-silo-filter').val();

		$('.vmsb-kw-table tbody tr').each(function() {
			const text = $(this).text().toLowerCase();
			const rowSilo = $(this).find('.vmsb-kw-silo-text').text();

			const matchesSearch = text.indexOf(searchVal) !== -1;
			const matchesSilo = !siloVal || rowSilo === siloVal;

			$(this).toggleClass('is-hidden', !(matchesSearch && matchesSilo));
		});

		// Handle empty states per tab
		$('.vmsb-panel').each(function() {
			const visible = $(this).find('tbody tr:not(.is-hidden)').length;
			const emptyMsg = $(this).find('.vmsb-empty-search');

			if (visible === 0) {
				if (emptyMsg.length === 0) {
					$(this).find('table').after('<p class="vmsb-note vmsb-empty-search" style="text-align:center; padding:20px;">No matching keywords found.</p>');
				}
			} else {
				emptyMsg.remove();
			}
		});
	});

	// Quick Tab Switch via Cards
	$('[data-switch-tab]').on('click', function() {
		const tab = $(this).data('switch-tab');
		$(`.vmsb-tab[data-tab="${tab}"]`).trigger('click');
	});
});
</script>
