<?php
defined( 'ABSPATH' ) || exit;

/**
 * Subview: Technical SEO Report.
 */

$fixer = new VMSB_Fixer();
$counts = $fixer->counts();
$recent_logs = ( new VMSB_Logger() )->recent( 50 );

?>
<div class="vmsb-grid" style="grid-template-columns: repeat(4, 1fr); gap: 20px;">
	<div class="vmsb-card">
		<span class="vmsb-note">Open Critical</span>
		<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--crit);"><?php echo (int)$counts['critical']; ?></span></div>
	</div>
	<div class="vmsb-card">
		<span class="vmsb-note">Fix Rate (30d)</span>
		<?php
		global $wpdb;
		$fixed_30 = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vmsb_issues WHERE status = 'fixed' AND fixed_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
		?>
		<div class="vmsb-figure"><span class="vmsb-number" style="color:var(--good);"><?php echo $fixed_30; ?></span></div>
		<p class="vmsb-note">Technical issues resolved.</p>
	</div>
</div>

<article class="vmsb-card vmsb-card-wide" style="margin-top:30px;">
	<h2 style="font-family:var(--serif); margin-bottom:20px;">System Error Log</h2>
	<div class="vmsb-activity-feed" style="max-height:400px; overflow-y:auto;">
		<?php
		$errors = array_filter((array)$recent_logs, fn($l) => $l->level === 'error');
		if ( empty($errors) ) : ?>
			<p class="vmsb-note">No technical errors recorded in the last 50 events. System is stable.</p>
		<?php else :
			foreach ( $errors as $log ) :
		?>
			<div class="vmsb-activity-item" style="padding:15px; border-bottom:1px solid var(--line); display:flex; gap:20px;">
				<span class="vmsb-note" style="width:100px; flex-shrink:0;"><?php echo esc_html( human_time_diff(strtotime($log->created_at)) ); ?> ago</span>
				<p style="margin:0; flex:1; font-family:var(--mono); font-size:12px; color:var(--crit);">[<?php echo esc_html(strtoupper($log->channel)); ?>] <?php echo esc_html($log->message); ?></p>
			</div>
		<?php endforeach; endif; ?>
	</div>
</article>
