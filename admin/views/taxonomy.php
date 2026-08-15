<?php
defined( 'ABSPATH' ) || exit;

$tax = new VMSB_Taxonomy();
$categories = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
$tags       = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) );

// Sort tags by count to find bloat
usort( $tags, fn($a, $b) => $b->count <=> $a->count );

$fixer = new VMSB_Fixer();
$issues = $fixer->open_issues( 100, '', '', 'missing_term_description,empty_archive,thin_tag,duplicate_term' );
?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Topical Organization</p>
			<h1>Taxonomy Lab</h1>
			<p class="vmsb-sub">Optimizing categories and tags to maximize crawl budget and topical authority.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="taxonomy-audit">Run Audit</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="taxonomy-propose">Propose AI Structure</button>
		</div>
	</header>
	<span class="wp-header-end"></span>


	<div class="vmsb-grid" style="grid-template-columns: 1fr 1fr; margin-bottom:30px;">
		<div class="vmsb-card">
			<span class="vmsb-note">Category Health</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo count($categories); ?></span>
				<span class="vmsb-of">Live Categories</span>
			</div>
			<p class="vmsb-note">Aim for 5-12 high-authority pillars.</p>
		</div>

		<div class="vmsb-card">
			<span class="vmsb-note">Tag Bloat Monitor</span>
			<div class="vmsb-figure">
				<span class="vmsb-number"><?php echo count($tags); ?></span>
				<span class="vmsb-of">Active Tags</span>
			</div>
			<?php
			$thin_tags = count(array_filter($tags, fn($t) => $t->count <= 2));
			?>
			<p class="vmsb-note" style="color:<?php echo $thin_tags > 20 ? 'var(--crit)' : 'var(--muted)'; ?>;">
				<strong><?php echo $thin_tags; ?></strong> tags have 2 or fewer posts (Thin Content Risk).
			</p>
		</div>
	</div>

	<div class="vmsb-tabs">
		<button class="vmsb-tab is-active" data-tab="categories">📁 Categories</button>
		<button class="vmsb-tab" data-tab="personas">🧠 Expert Personas</button>
		<button class="vmsb-tab" data-tab="tags">🏷️ Tags & Bloat</button>
		<button class="vmsb-tab" data-tab="issues">⚠️ Taxonomy Issues</button>
	</div>

	<!-- CATEGORIES PANEL -->
	<div class="vmsb-panel is-active" data-panel="categories">
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead>
					<tr>
						<th>Name</th>
						<th>Slug</th>
						<th>Posts</th>
						<th>Description</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($categories as $c) : ?>
						<tr>
							<td><strong><?php echo esc_html($c->name); ?></strong></td>
							<td><code><?php echo esc_html($c->slug); ?></code></td>
							<td><?php echo (int)$c->count; ?></td>
							<td><?php echo $c->description ? wp_trim_words($c->description, 10) : '<span class="vmsb-sev sev-high">Missing</span>'; ?></td>
							<td>
								<button class="vmsb-mini-btn" data-vmsb="taxonomy-propose" data-id="<?php echo $c->term_id; ?>">Optimise</button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- PERSONAS PANEL -->
	<div class="vmsb-panel" data-panel="personas">
		<div class="vmsb-alert" style="margin-bottom:20px;">
			<p>Expert personas build <strong>E-E-A-T</strong> authority. The Brain automatically generates a distinct professional profile for each category and attaches their bio to published posts.</p>
		</div>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead>
					<tr>
						<th>Category</th>
						<th>Assigned Expert</th>
						<th>Title</th>
						<th>Expertise</th>
						<th>Bio Snippet</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$all_personas = get_option('vmsb_personas', array());
					foreach ($categories as $c) :
						$p = $all_personas[$c->term_id] ?? null;
					?>
						<tr>
							<td><strong><?php echo esc_html($c->name); ?></strong></td>
							<td><?php echo $p ? esc_html($p['name']) : '<span class="vmsb-note">Not generated yet</span>'; ?></td>
							<td><?php echo $p ? esc_html($p['title']) : '&mdash;'; ?></td>
							<td>
								<?php if ($p && !empty($p['expertise'])) : ?>
									<?php foreach ((array)$p['expertise'] as $ex) : ?>
										<span class="vmsb-tag vmsb-tag-blue" style="font-size:9px;"><?php echo esc_html($ex); ?></span>
									<?php endforeach; ?>
								<?php else : echo '&mdash;'; endif; ?>
							</td>
							<td>
								<small class="vmsb-note"><?php echo $p ? wp_trim_words($p['bio'], 10) : 'Will generate on next publish'; ?></small>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- TAGS PANEL -->
	<div class="vmsb-panel" data-panel="tags">
		<div class="vmsb-alert" style="margin-bottom:20px; display:flex; justify-content:space-between; align-items:center;">
			<p style="margin:0;"><strong>Dev Ops Insight:</strong> Tags with very few posts or zero traffic create "Crawl Waste". We recommend setting them to <code>noindex</code> or merging them into categories.</p>
			<button class="vmsb-btn vmsb-btn-gold vmsb-btn-sm" data-vmsb="god-fix" data-body='{"scope":["taxonomy"], "limit":50}' data-confirm="This will bulk deindex up to 50 unnecessary tags. Continue?">Prune Zombie Tags</button>
		</div>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead>
					<tr>
						<th>Tag Name</th>
						<th>Posts</th>
						<th>SEO Status</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach (array_slice($tags, 0, 50) as $t) :
						$is_thin = $t->count <= 2;
						$robots = get_term_meta($t->term_id, 'rank_math_robots', true);
						$is_noindex = is_array($robots) && in_array('noindex', $robots);
					?>
						<tr>
							<td><strong><?php echo esc_html($t->name); ?></strong></td>
							<td>
								<span class="vmsb-tag <?php echo $is_thin ? 'vmsb-tag-gold' : ''; ?>">
									<?php echo (int)$t->count; ?> Posts
								</span>
							</td>
							<td>
								<?php if ($is_noindex) : ?>
									<span class="vmsb-tag vmsb-tag-blue">NoIndex</span>
								<?php else : ?>
									<span class="vmsb-tag vmsb-tag-good">Indexed</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($is_thin && !$is_noindex) : ?>
									<button class="vmsb-mini-btn vmsb-btn-ghost" data-vmsb="god-fix" data-body='{"scope":["taxonomy"], "rule":"thin_tag"}'>Deindex</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<!-- ISSUES PANEL -->
	<div class="vmsb-panel" data-panel="issues">
		<?php if (empty($issues)) : ?>
			<div class="vmsb-empty"><h2>No Taxonomy Issues Found</h2><p>Great job! Your site structure is clean.</p></div>
		<?php else : ?>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th>Priority</th>
							<th>Rule</th>
							<th>Details</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($issues as $issue) : ?>
							<tr>
								<td><span class="vmsb-sev sev-<?php echo esc_attr($issue->severity); ?>"><?php echo esc_html($issue->severity); ?></span></td>
								<td><code><?php echo esc_html($issue->rule); ?></code></td>
								<td><?php echo esc_html($issue->detail); ?></td>
								<td>
									<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="god-fix" data-id="<?php echo $issue->id; ?>">Fix</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>

