<?php
defined( 'ABSPATH' ) || exit;

$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

$silo    = new VMSB_Silo();
$map     = $silo->map_for_display();
$orphans = $silo->orphans( 60 );
?>

<?php if ( ! $vmsb_is_nested ) : ?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Architecture</p>
			<h1>Silo map</h1>
			<p class="vmsb-sub"><?php echo count( $map ); ?> silos, <?php echo count( $orphans ); ?> orphan pages.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="silo-push-gaps" data-confirm="Push all 'planned' pillar and supporting topics into the content plan?">Push gaps to plan</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="silo-map" data-body='{"force":true}'>Rebuild map</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="god-fix" data-body='{"scope":["internal_links"]}'>Fix linking</button>
		</div>
	</header>
<?php endif; ?>

	<?php if ( ! $map ) : ?>
		<div class="vmsb-empty">
			<h2>No map yet</h2>
			<p>The map is built from the keyword clusters, so run keyword research first, then rebuild.</p>
		</div>
	<?php endif; ?>

	<div class="vmsb-grid">
		<!-- Silo Visualization: Semantic Authority Radar -->
		<article class="vmsb-card vmsb-card-wide" style="min-height: 400px; overflow: hidden;">
			<div class="vmsb-card-head">
				<div>
					<h2>Semantic Authority Radar</h2>
					<p class="vmsb-note">Mapping topical depth and linking density across your growth clusters.</p>
				</div>
				<div class="vmsb-radar-meta">
					<span class="vmsb-tag vmsb-tag-gold"><?php echo count($map); ?> Active Silos</span>
				</div>
			</div>

			<div class="vmsb-radar-visualization">
				<svg viewBox="0 0 400 400" class="vmsb-radar-svg">
					<!-- Radial background lines -->
					<circle cx="200" cy="200" r="150" fill="none" stroke="var(--line)" stroke-width="1" stroke-dasharray="4 4" />
					<circle cx="200" cy="200" r="100" fill="none" stroke="var(--line)" stroke-width="1" stroke-dasharray="4 4" />
					<circle cx="200" cy="200" r="50" fill="none" stroke="var(--line)" stroke-width="1" stroke-dasharray="4 4" />

					<?php
					$count = count($map);
					if ( $count > 0 ) :
						$angle_step = (2 * M_PI) / $count;
						$points = [];
						foreach ( $map as $i => $s ) :
							$angle = $i * $angle_step - M_PI/2;
							$r = ($s['strength'] / 100) * 150;
							$x = 200 + $r * cos($angle);
							$y = 200 + $r * sin($angle);
							$points[] = "$x,$y";

							// Axis labels
							$lx = 200 + 175 * cos($angle);
							$ly = 200 + 175 * sin($angle);
							?>
							<line x1="200" y1="200" x2="<?php echo 200 + 150 * cos($angle); ?>" y2="<?php echo 200 + 150 * sin($angle); ?>" stroke="var(--line)" stroke-width="1" />
							<text x="<?php echo $lx; ?>" y="<?php echo $ly; ?>" fill="var(--muted)" font-size="10" text-anchor="middle" dominant-baseline="middle"><?php echo esc_html(wp_trim_words($s['name'], 2)); ?></text>
						<?php endforeach; ?>

						<!-- Radar polygon -->
						<polygon points="<?php echo implode(' ', $points); ?>" fill="rgba(201, 162, 39, 0.2)" stroke="var(--gold)" stroke-width="2" />

						<!-- Data points -->
						<?php foreach ( $points as $p ) : list($px, $py) = explode(',', $p); ?>
							<circle cx="<?php echo $px; ?>" cy="<?php echo $py; ?>" r="4" fill="var(--gold)" />
						<?php endforeach; ?>
					<?php endif; ?>
				</svg>
			</div>

			<div class="vmsb-legend" style="padding: 20px; border-top: 1px solid var(--line); display: flex; justify-content: center; gap: 30px;">
				<div class="vmsb-legend-item"><i style="background: var(--gold);"></i> <span>Silo Strength (%)</span></div>
				<div class="vmsb-legend-item"><i style="border: 1px dashed var(--muted);"></i> <span>Growth Potential</span></div>
			</div>
		</article>

		<!-- Structural Health Dashboard -->
		<article class="vmsb-card">
			<h2>Structural Health</h2>
			<p class="vmsb-note">Technical verification of your site architecture.</p>

			<div class="vmsb-health-metrics" style="margin-top: 24px;">
				<div class="vmsb-health-stat">
					<span class="vmsb-stat-label">Internal Link Density</span>
					<div style="display: flex; align-items: baseline; gap: 8px;">
						<span class="vmsb-stat-val"><?php echo count($orphans) === 0 ? 'High' : (count($orphans) < 5 ? 'Moderate' : 'Critical'); ?></span>
						<span class="vmsb-tag <?php echo count($orphans) === 0 ? 'vmsb-tag-good' : 'vmsb-tag-crit'; ?>"><?php echo count($orphans); ?> Orphans</span>
					</div>
				</div>

				<div class="vmsb-health-stat" style="margin-top: 20px;">
					<span class="vmsb-stat-label">Pillar Coverage</span>
					<?php
					$pillars_ready = count(array_filter($map, fn($s) => $s['pillar_id'] > 0));
					$pct = round(($pillars_ready / max(1, count($map))) * 100);
					?>
					<div style="display: flex; align-items: center; gap: 12px; margin-top: 8px;">
						<div class="vmsb-bar" style="flex: 1; height: 6px;"><span style="width: <?php echo $pct; ?>%; background: var(--good);"></span></div>
						<span style="font-size: 13px; font-weight: 700; color: var(--text);"><?php echo $pct; ?>%</span>
					</div>
					<p class="vmsb-note" style="margin-top: 6px;"><?php echo $pillars_ready; ?> of <?php echo count($map); ?> silos have active pillar pages.</p>
				</div>
			</div>

			<div style="margin-top: 32px; display: flex; flex-direction: column; gap: 12px;">
				<button class="vmsb-btn vmsb-btn-gold vmsb-btn-block" data-vmsb="god-fix" data-body='{"scope":["internal_links"]}'>Plug All Linking Gaps</button>
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-block" data-vmsb="silo-audit">Run Structural Audit</button>
			</div>
		</article>
	</div>

	<h2 class="vmsb-h2" style="margin-top: 50px;">Sentient Silo Architect</h2>
	<p class="vmsb-sub" style="margin-bottom: 30px;">Deep breakdown of content clusters and their authority flow.</p>

	<div class="vmsb-silo-grid">
		<?php foreach ( $map as $s ) :
			$is_active = (bool) $s['pillar_id'];
			$health_cls = $s['strength'] > 80 ? 'healthy' : ($s['strength'] > 40 ? 'growing' : 'thin');
		?>
			<article class="vmsb-silo-card <?php echo $health_cls; ?>">
				<div class="vmsb-silo-head">
					<div class="vmsb-silo-title">
						<h3><?php echo esc_html( $s['name'] ); ?></h3>
						<span class="vmsb-silo-status-tag" style="background:<?php echo $s['strength'] > 80 ? 'rgba(29, 209, 161, 0.2)' : 'rgba(212, 175, 55, 0.2)'; ?>; color:<?php echo $s['strength'] > 80 ? 'var(--good)' : 'var(--gold)'; ?>;">
							<?php echo ucfirst($health_cls); ?>
						</span>
					</div>
					<div class="vmsb-silo-power">
						<span class="vmsb-power-val" style="color:var(--good); text-shadow:0 0 10px rgba(29, 209, 161, 0.3);"><?php echo (int)$s['strength']; ?>%</span>
						<span class="vmsb-power-label">Authority</span>
					</div>
				</div>

				<div class="vmsb-silo-content">
					<div class="vmsb-intent-bar" style="display:flex; height:6px; border-radius:3px; overflow:hidden; margin-bottom:15px;">
						<span title="Informational: <?php echo $s['intent_mix']['informational']; ?>%" style="width:<?php echo $s['intent_mix']['informational']; ?>%; background:var(--tag-blue-bg);"></span>
						<span title="Commercial: <?php echo $s['intent_mix']['commercial']; ?>%" style="width:<?php echo $s['intent_mix']['commercial']; ?>%; background:var(--gold);"></span>
						<span title="Transactional: <?php echo $s['intent_mix']['transactional']; ?>%" style="width:<?php echo $s['intent_mix']['transactional']; ?>%; background:var(--tag-gold-bg);"></span>
					</div>

					<div class="vmsb-pillar-box <?php echo $is_active ? 'is-active' : 'is-missing'; ?>">
						<span class="vmsb-box-label">Central Pillar</span>
						<p class="vmsb-box-title">
							<?php if ( $is_active ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $s['pillar_id'] ) ); ?>"><?php echo esc_html( $s['pillar'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $s['pillar'] ); ?>
							<?php endif; ?>
						</p>
					</div>

					<div class="vmsb-supporting-list">
						<span class="vmsb-box-label">Supporting Clusters (<?php echo count($s['children']); ?>)</span>
						<ul>
							<?php foreach ( array_slice((array) $s['children'], 0, 4) as $child ) : ?>
								<li>
									<span class="vmsb-dot <?php echo ( isset( $child['status'] ) && 'exists' === $child['status'] ) ? 'is-live' : 'is-planned'; ?>"></span>
									<span class="vmsb-child-title"><?php echo esc_html( $child['title'] ); ?></span>
								</li>
							<?php endforeach; ?>
							<?php if ( count($s['children']) > 4 ) : ?>
								<li class="vmsb-more-count">+ <?php echo count($s['children']) - 4; ?> more items</li>
							<?php endif; ?>
						</ul>
					</div>
				</div>

				<div class="vmsb-silo-footer">
					<?php if ( $s['money_page'] ) : ?>
						<div class="vmsb-funnel-info">
							<span class="vmsb-icon">💰</span>
							<span>Funnels to <strong><?php echo esc_html( $s['money_page'] ); ?></strong></span>
						</div>
					<?php endif; ?>

					<div class="vmsb-silo-actions">
						<?php
						$advice = '';
						if ( ! $is_active ) $advice = 'Create central pillar first.';
						elseif ( $s['assets'] < 5 ) $advice = 'Needs more supporting depth.';
						elseif ( $s['intent_mix']['commercial'] < 20 ) $advice = 'Needs commercial-intent hooks.';
						else $advice = 'Silo is healthy. Focus on linking.';
						?>
						<span class="vmsb-advice-chip" title="Brain Strategy"><?php echo esc_html($advice); ?></span>
						<button class="vmsb-mini-btn vmsb-btn-ghost" data-vmsb="niche-plan" data-body='{"count":3, "cluster":"<?php echo esc_attr($s['name']); ?>"}'>Expand Silo</button>
					</div>
				</div>
			</article>
		<?php endforeach; ?>
	</div>

	<?php if ( $orphans ) : ?>
		<h2 class="vmsb-h2">Orphans</h2>
		<p class="vmsb-note">Nothing on the site links to these, so crawlers reach them slowly and they inherit no authority.</p>
		<ul class="vmsb-orphans">
			<?php foreach ( $orphans as $orphan ) : ?>
				<li><a href="<?php echo esc_url( get_edit_post_link( $orphan->ID ) ); ?>"><?php echo esc_html( $orphan->post_title ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>
<?php endif; ?>
