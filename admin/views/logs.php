<?php
defined( 'ABSPATH' ) || exit;

$logger = new VMSB_Logger();
$logs   = $logger->recent( 150 );
?>
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Operations</p>
			<h1>Logs</h1>
			<p class="vmsb-sub">Operational history of the sentient brain.</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" onclick="window.location.reload()">Refresh</button>
		</div>
	</header>
	<span class="wp-header-end"></span>

	<div class="vmsb-table-wrap">
		<table class="vmsb-table vmsb-table-full">
			<thead>
				<tr>
					<th style="width: 160px;">Time</th>
					<th style="width: 80px;">Level</th>
					<th style="width: 120px;">Channel</th>
					<th>Message</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $logs ) : ?>
					<tr><td colspan="4" class="vmsb-note">No logs found yet.</td></tr>
				<?php endif; ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr>
						<td class="vmsb-note"><?php echo esc_html( human_time_diff( strtotime( $log->created_at ), current_time( 'timestamp', true ) ) . ' ago' ); ?></td>
						<td><span class="vmsb-verdict v-<?php echo esc_attr( $log->level === 'warning' ? 'neutral' : ( $log->level === 'error' ? 'loss' : 'pending' ) ); ?>"><?php echo esc_html( strtoupper( $log->level ) ); ?></span></td>
						<td><code><?php echo esc_html( $log->channel ); ?></code></td>
						<td>
							<?php echo esc_html( $log->message ); ?>
							<?php if ( $log->context ) : ?>
								<details style="margin-top: 5px;">
									<summary class="vmsb-note" style="cursor: pointer;">Context</summary>
									<pre style="background: rgba(0,0,0,0.1); padding: 10px; border-radius: 4px; overflow: auto;"><?php echo esc_html( wp_json_encode( json_decode( $log->context ), JSON_PRETTY_PRINT ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

