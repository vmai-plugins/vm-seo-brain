<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subview: Entity Coverage.
 */

global $wpdb;
// The post_id list has to come from raw SQL (there's no "which posts have
// this meta key" WP API) but the value itself must go through
// get_post_meta(), not a hand-rolled decode of the raw column: it's stored
// via update_post_meta() with a PHP array, which WordPress serializes with
// maybe_serialize() (native serialize()), not JSON. json_decode() on a
// serialized string always returns NULL, so every row here was silently
// skipped by the `is_array($audit)` guard below no matter how much real
// audit data existed - the tab rendered its table headers and nothing else.
$vmsb_audited = $wpdb->get_results("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_vmsb_entity_audit' LIMIT 50");

?>
<div class="vmsb-grid" style="grid-template-columns: 1fr; gap:30px;">
	<article class="vmsb-card vmsb-card-wide">
		<div class="vmsb-flex-space" style="margin-bottom:25px;">
			<h2 style="font-family:var(--serif);">Topical Entity Coverage</h2>
			<p class="vmsb-note">Measuring how deeply your content covers niche-relevant concepts.</p>
		</div>

		<?php if ( empty($vmsb_audited) ) : ?>
			<div class="vmsb-empty">
				<p>No entity audits performed yet. The Entity Specialist runs automatically in God Mode, or you can trigger a sweep from the Agents roster.</p>
			</div>
		<?php else : ?>
			<div class="vmsb-table-wrap">
				<table class="vmsb-table vmsb-table-full">
					<thead>
						<tr>
							<th>Page</th>
							<th>Coverage Score</th>
							<th>Identified Entities</th>
							<th>Missing (Opportunity)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($vmsb_audited as $row) :
							$audit = get_post_meta( $row->post_id, '_vmsb_entity_audit', true );
							if ( ! is_array($audit) ) continue;

							$post_title = get_the_title($row->post_id);
						?>
							<tr>
								<td><strong><?php echo esc_html($post_title); ?></strong></td>
								<td>
									<div class="vmsb-tiny-score <?php echo $audit['score'] >= 80 ? 'good' : ($audit['score'] >= 50 ? 'med' : 'low'); ?>">
										<span><?php echo (int)$audit['score']; ?></span>
									</div>
								</td>
								<td>
									<div class="vmsb-entity-list">
										<?php foreach (array_slice((array)$audit['present'], 0, 5) as $e) : ?>
											<li><?php echo esc_html($e); ?></li>
										<?php endforeach; ?>
										<?php if (count((array)$audit['present']) > 5) echo '<li>+</li>'; ?>
									</div>
								</td>
								<td>
									<div class="vmsb-entity-list">
										<?php foreach (array_slice((array)$audit['missing'], 0, 3) as $e) : ?>
											<li style="border-color:var(--gold); color:var(--gold-soft);"><?php echo esc_html($e); ?></li>
										<?php endforeach; ?>
										<?php if (count((array)$audit['missing']) > 0) : ?>
											<button class="vmsb-mini-btn" data-vmsb="improve-post" data-body='{"post_id":<?php echo (int)$row->post_id; ?>, "reason":"entity_injection"}'>Inject</button>
										<?php endif; ?>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</article>
</div>
