<?php
defined( 'ABSPATH' ) || exit;

/**
 * Observability & Rollback Dashboard (VM SEO Brain X).
 *
 * Provides a "Black Box" view of every autonomous action.
 * Allows the operator to understand 'Why' the Brain acted and 'Undo' any set of changes.
 */

global $wpdb;
$vmsb_actions = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_actions ORDER BY created_at DESC LIMIT 50" );
?>

	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Autonomous Integrity Control</p>
			<h1>Observability & Rollback</h1>
			<p class="vmsb-sub">Understand exactly why the Brain acted, and undo any change with one click.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" onclick="if(confirm('Undo last 5 actions?')) VMSB.api('rollback-recent', {count:5}).then(()=>location.reload())">Rollback Last 5</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-table-wrap" style="margin-top:30px;">
		<table class="vmsb-table vmsb-table-full">
			<thead>
				<tr>
					<th>Timestamp</th>
					<th>Action Type</th>
					<th>Target Object</th>
					<th>Reasoning / Evidence</th>
					<th>Model / Cost</th>
					<th>Rollback</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty($vmsb_actions) ) : ?>
					<tr><td colspan="6" class="vmsb-note">No autonomous actions recorded yet.</td></tr>
				<?php else :
					foreach ( $vmsb_actions as $a ) :
						$target_label = get_the_title($a->object_id) ?: "#{$a->object_id}";
				?>
					<tr>
						<td><span class="vmsb-note"><?php echo esc_html(human_time_diff(strtotime($a->created_at))); ?> ago</span></td>
						<td><span class="vmsb-tag"><?php echo esc_html(str_replace('_', ' ', $a->action_type)); ?></span></td>
						<td><strong><?php echo esc_html($target_label); ?></strong></td>
						<td style="max-width:300px;"><p style="font-size:12.5px; opacity:0.9;"><?php echo esc_html($a->reason); ?></p></td>
						<td><span class="vmsb-note"><?php echo esc_html($a->model); ?></span></td>
						<td>
							<?php if ($a->rollback_status === 'completed') : ?>
								<span class="vmsb-tag vmsb-tag-good">Reverted</span>
							<?php else : ?>
								<button class="vmsb-mini-btn" data-vmsb="action-rollback" data-id="<?php echo (int)$a->id; ?>">Undo</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
