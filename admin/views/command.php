<?php
defined( 'ABSPATH' ) || exit;

/**
 * Command — the "is this working?" screen.
 *
 * Deliberately answers in one order: what did it produce, what is stopping it,
 * can it reach a model, what is failing. Everything else in the plugin is a
 * place to do work; this is the place to find out whether the work is
 * happening.
 */

$vmsb_cmd   = VMSB_Command::snapshot();
$vmsb_tp    = $vmsb_cmd['throughput'];
$vmsb_creds = $vmsb_cmd['credentials'];

$vmsb_crit  = count( array_filter( $vmsb_cmd['blockers'], static fn( $b ) => 'critical' === $b['level'] ) );
$vmsb_warn  = count( array_filter( $vmsb_cmd['blockers'], static fn( $b ) => 'warning' === $b['level'] ) );

// One honest verdict, from the blockers rather than from a component ping.
if ( $vmsb_crit ) {
	$vmsb_state = array( 'label' => 'Blocked', 'tone' => 'var(--crit)', 'line' => 'Something is stopping the engine outright.' );
} elseif ( ! $vmsb_tp['published_7d'] && $vmsb_tp['expected_7d'] ) {
	$vmsb_state = array( 'label' => 'Not shipping', 'tone' => 'var(--high)', 'line' => 'The engine is running but nothing reached the site this week.' );
} elseif ( $vmsb_warn ) {
	$vmsb_state = array( 'label' => 'Running, with friction', 'tone' => 'var(--high)', 'line' => 'Publishing, but something below is holding it back.' );
} else {
	$vmsb_state = array( 'label' => 'Healthy', 'tone' => 'var(--good)', 'line' => 'Producing at the configured pace with nothing blocking.' );
}
?>

<header class="vmsb-head">
	<div>
		<p class="vmsb-eyebrow">Command</p>
		<h1 class="vmsb-title" style="font-family:var(--serif); font-size:32px; margin:0 0 6px;">
			<?php echo esc_html( $vmsb_state['label'] ); ?>
		</h1>
		<p class="vmsb-note" style="margin:0;"><?php echo esc_html( $vmsb_state['line'] ); ?></p>
	</div>
	<div class="vmsb-head-actions">
		<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="health-check">Re-check</button>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-production' ) ); ?>" class="vmsb-btn vmsb-btn-gold">Open Production</a>
	</div>
</header>
<span class="wp-header-end"></span>

<!-- ---------------------------------------------------------- throughput -->
<div class="vmsb-grid" style="margin-bottom:26px;">

	<article class="vmsb-card" style="border-left:3px solid <?php echo esc_attr( $vmsb_state['tone'] ); ?>;">
		<span class="vmsb-status-label">Published this week</span>
		<div class="vmsb-figure">
			<span class="vmsb-number" style="color:<?php echo esc_attr( $vmsb_tp['published_7d'] ? 'var(--good)' : 'var(--crit)' ); ?>;"><?php echo (int) $vmsb_tp['published_7d']; ?></span>
			<span class="vmsb-of">of <?php echo (int) $vmsb_tp['expected_7d']; ?> expected</span>
		</div>
		<?php if ( $vmsb_tp['failed_7d'] ) : ?>
			<p class="vmsb-note" style="margin:8px 0 0;"><?php echo (int) $vmsb_tp['failed_7d']; ?> attempts failed in the same period.</p>
		<?php endif; ?>
	</article>

	<article class="vmsb-card">
		<span class="vmsb-status-label">Waiting on you</span>
		<div class="vmsb-figure">
			<span class="vmsb-number"><?php echo number_format_i18n( $vmsb_tp['drafted'] ); ?></span>
			<span class="vmsb-of">drafts</span>
		</div>
		<p class="vmsb-note" style="margin:8px 0 0;"><?php echo number_format_i18n( $vmsb_tp['suggested'] ); ?> topics also awaiting approval.</p>
	</article>

	<article class="vmsb-card">
		<span class="vmsb-status-label">Queued ahead</span>
		<div class="vmsb-figure">
			<span class="vmsb-number"><?php echo number_format_i18n( $vmsb_tp['approved'] ); ?></span>
			<span class="vmsb-of">approved</span>
		</div>
		<?php
		$vmsb_ppd  = max( 1, (int) VMSB_Settings::get( 'posts_per_day' ) );
		$vmsb_days = (int) ceil( $vmsb_tp['approved'] / $vmsb_ppd );
		?>
		<p class="vmsb-note" style="margin:8px 0 0;"><?php echo esc_html( VMSB_Command::humanise_days( $vmsb_days ) ); ?> of work at <?php echo (int) $vmsb_ppd; ?>/day.</p>
	</article>

</div>

<!-- ------------------------------------------------------------ blockers -->
<article class="vmsb-card vmsb-card-wide" style="margin-bottom:26px;">
	<div class="vmsb-flex-space" style="margin-bottom:16px;">
		<h2 style="font-family:var(--serif); margin:0;">What is holding it back</h2>
		<span class="vmsb-note"><?php echo count( $vmsb_cmd['blockers'] ); ?> found</span>
	</div>

	<?php if ( ! $vmsb_cmd['blockers'] ) : ?>
		<p class="vmsb-note" style="margin:0;">Nothing is blocking production. The engine has everything it needs.</p>
	<?php else : ?>
		<div style="display:flex; flex-direction:column; gap:12px;">
			<?php
			foreach ( $vmsb_cmd['blockers'] as $vmsb_b ) :
				$vmsb_tone = 'critical' === $vmsb_b['level'] ? 'var(--crit)' : ( 'warning' === $vmsb_b['level'] ? 'var(--high)' : 'var(--med)' );
				?>
				<div style="display:grid; grid-template-columns:auto 1fr auto; gap:4px 14px; align-items:start; padding:14px 16px; background:var(--surface-2); border:1px solid var(--border); border-left:3px solid <?php echo esc_attr( $vmsb_tone ); ?>; border-radius:9px;">
					<span style="grid-row:1/span 2; width:8px; height:8px; border-radius:50%; background:<?php echo esc_attr( $vmsb_tone ); ?>; margin-top:7px;"></span>
					<h3 style="grid-column:2; margin:0; font-size:15px;"><?php echo esc_html( $vmsb_b['title'] ); ?></h3>
					<p style="grid-column:2; margin:2px 0 0; font-size:13.5px; color:var(--muted); line-height:1.55;"><?php echo esc_html( $vmsb_b['detail'] ); ?></p>
					<a href="<?php echo esc_url( $vmsb_b['url'] ); ?>" class="vmsb-mini-btn" style="grid-column:3; grid-row:1/span 2; white-space:nowrap;"><?php echo esc_html( $vmsb_b['action'] ); ?></a>
				</div>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</article>

<div class="vmsb-grid" style="grid-template-columns:repeat(auto-fit,minmax(340px,1fr));">

	<!-- ------------------------------------------------------ credentials -->
	<article class="vmsb-card">
		<h2 style="font-family:var(--serif); margin:0 0 4px; font-size:19px;">Credentials</h2>
		<p class="vmsb-note" style="margin:0 0 14px;">Whether a key is stored, and whether it can still be read back.</p>

		<?php if ( $vmsb_creds['decryption_failed'] ) : ?>
			<div style="padding:11px 14px; margin-bottom:12px; background:rgba(255,77,77,.09); border:1px solid var(--crit); border-radius:8px;">
				<strong style="color:var(--crit); font-size:13px;">Decryption has failed on this site.</strong>
				<p class="vmsb-note" style="margin:4px 0 0; font-size:12.5px;">A stored key could not be read, which usually means the WordPress security salts changed. Any key marked unreadable must be entered again.</p>
			</div>
		<?php endif; ?>

		<table class="vmsb-table vmsb-table-narrow" style="min-width:0;">
			<tbody>
				<?php
				foreach ( $vmsb_creds['items'] as $vmsb_c ) :
					$vmsb_map = array(
						'ok'         => array( 'Working', 'var(--good)' ),
						'absent'     => array( 'Not set', 'var(--muted)' ),
						'unreadable' => array( 'Unreadable', 'var(--crit)' ),
					);
					list( $vmsb_txt, $vmsb_col ) = $vmsb_map[ $vmsb_c['state'] ];
					?>
					<tr>
						<td><?php echo esc_html( $vmsb_c['label'] ); ?></td>
						<td style="text-align:right;"><span style="color:<?php echo esc_attr( $vmsb_col ); ?>; font-size:12px; font-weight:600;"><?php echo esc_html( $vmsb_txt ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-settings' ) ); ?>" class="vmsb-btn vmsb-btn-ghost" style="margin-top:14px; align-self:flex-start;">Manage keys</a>
	</article>

	<!-- ----------------------------------------------------------- errors -->
	<article class="vmsb-card">
		<h2 style="font-family:var(--serif); margin:0 0 4px; font-size:19px;">Failing right now</h2>
		<p class="vmsb-note" style="margin:0 0 14px;">Errors from the last 24 hours, grouped by cause.</p>

		<?php if ( ! $vmsb_cmd['errors'] ) : ?>
			<p class="vmsb-note" style="margin:0;">No errors logged in the last 24 hours.</p>
		<?php else : ?>
			<div style="display:flex; flex-direction:column; gap:9px;">
				<?php foreach ( $vmsb_cmd['errors'] as $vmsb_e ) : ?>
					<div style="padding:10px 13px; background:var(--surface-2); border:1px solid var(--border); border-radius:8px;">
						<div style="display:flex; justify-content:space-between; gap:12px; align-items:baseline;">
							<code style="font-size:11px; color:var(--gold);"><?php echo esc_html( $vmsb_e['channel'] ); ?></code>
							<span style="font-size:11px; color:var(--muted); white-space:nowrap;"><?php echo number_format_i18n( $vmsb_e['hits'] ); ?>&times;</span>
						</div>
						<p style="margin:5px 0 0; font-size:12.5px; line-height:1.5; color:var(--text);"><?php echo esc_html( $vmsb_e['message'] ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<a href="<?php echo esc_url( admin_url( 'admin.php?page=vmsb-logs' ) ); ?>" class="vmsb-btn vmsb-btn-ghost" style="margin-top:14px; align-self:flex-start;">Full log</a>
	</article>

</div>

<p class="vmsb-note" style="margin-top:22px; font-size:12px;">
	Snapshot taken <?php echo esc_html( human_time_diff( $vmsb_cmd['generated_at'] ) ); ?> ago. Refreshed every few minutes.
</p>

<div id="vmsb-output" class="vmsb-output" hidden></div>
