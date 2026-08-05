<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;
$content = new VMSB_Content();
// 'failed' sorts first, not last - a technical failure needs attention now,
// while a 200-row table previously buried it at the very bottom where it
// was easy to never notice at all.
$rows    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}vmsb_plan ORDER BY FIELD(status,'failed','writing','approved','planned','drafted','published','rejected'), priority DESC LIMIT 200" );
$sheet  = VMSB_Settings::get( 'sheet_id' );

// Calendar tab: month navigable via ?cal=YYYY-MM, defaulting to the
// current month. gmdate/strtotime throughout this file already treats
// scheduled_for as UTC, so the calendar range matches that.
$cal_param = isset( $_GET['cal'] ) ? sanitize_text_field( wp_unslash( $_GET['cal'] ) ) : '';
if ( $cal_param && preg_match( '/^(\d{4})-(\d{2})$/', $cal_param, $cm ) ) {
	$cal_year  = (int) $cm[1];
	$cal_month = (int) $cm[2];
} else {
	$cal_year  = (int) gmdate( 'Y' );
	$cal_month = (int) gmdate( 'n' );
}
$cal_days      = $content->calendar_month( $cal_year, $cal_month );
$cal_first_ts  = mktime( 0, 0, 0, $cal_month, 1, $cal_year );
$cal_days_in   = (int) gmdate( 't', $cal_first_ts );
$cal_start_dow = (int) gmdate( 'w', $cal_first_ts ); // 0 = Sunday
$cal_prev      = gmdate( 'Y-m', strtotime( '-1 month', $cal_first_ts ) );
$cal_next      = gmdate( 'Y-m', strtotime( '+1 month', $cal_first_ts ) );
$cal_today     = ( $cal_year === (int) gmdate( 'Y' ) && $cal_month === (int) gmdate( 'n' ) ) ? (int) gmdate( 'j' ) : 0;
// Prev/Next reload the page (no JS calendar router here), so without this
// the tab-switching JS's hardcoded "pipeline is-active" would silently
// bounce the user back to the pipeline tab every time they paged the month.
$cal_is_active = (bool) $cal_param;
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Editorial</p>
			<h1>Content plan</h1>
			<p class="vmsb-sub">
				<?php if ( $sheet ) : ?>
					Synced with <a href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $sheet ); ?>" target="_blank" rel="noopener">the plan sheet</a>. Edits there win.
				<?php else : ?>
					No spreadsheet connected yet.
				<?php endif; ?>
			</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-sheet">Pull from sheet</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="push-sheet">Push new rows</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="push-sheet" data-body='{"force":true}' data-confirm="This will re-push all non-published items to the sheet. Continue?">Push all</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="approve-all" data-confirm="Mark all 'planned' items as 'approved'?">Approve All</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="replan-rejected" data-confirm="Reset all rejected items to 'planned'?">Re-plan Rejected</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="clear-rejected" data-confirm="Permanently delete all rejected items?">Clear Rejected</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="trend-scout" title="Scout live news & Google Trends">Trend Scout 2026</button>
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="niche-plan" data-confirm="This will analyze your niche and plan 15 fresh pieces to expand authority. Continue?">Niche Expansion</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="plan">Plan next 20</button>
		</div>
	</header>

	<div class="vmsb-pipeline-overview">
		<?php
		$stats = $content->stats();
		$pub_today = (int) get_option( 'vmsb_pub_' . gmdate('Ymd'), 0 );
		$daily_cap = (int) VMSB_Settings::get( 'posts_per_day', 3 );

		$stages = array(
			'planned'   => array( 'label' => 'Planned', 'icon' => '📅', 'desc' => 'Topics in the waiting room.' ),
			'approved'  => array( 'label' => 'Approved', 'icon' => '✅', 'desc' => 'Queued for today\'s run.' ),
			'writing'   => array( 'label' => 'Active Agents', 'icon' => '🤖', 'desc' => 'Internal agents at work.' ),
			'published' => array( 'label' => 'Live Assets', 'icon' => '🚀', 'desc' => 'Driving organic growth.' ),
		);
		?>
		<div class="vmsb-pipeline-grid">
			<?php foreach ( $stages as $status => $data ) : ?>
				<div class="vmsb-pipeline-stage">
					<div class="vmsb-stage-head">
						<span class="vmsb-stage-icon"><?php echo $data['icon']; ?></span>
						<div class="vmsb-stage-meta">
							<span class="vmsb-stage-count"><?php echo (int) ( $stats[ $status ] ?? 0 ); ?></span>
							<span class="vmsb-stage-label"><?php echo esc_html( $data['label'] ); ?></span>
						</div>
					</div>
					<p class="vmsb-stage-desc"><?php echo esc_html( $data['desc'] ); ?></p>
				</div>
			<?php endforeach; ?>

			<?php $failed_count = (int) ( $stats['failed'] ?? 0 ); ?>
			<div class="vmsb-pipeline-stage vmsb-failed-stage<?php echo $failed_count ? ' has-failures' : ''; ?>"<?php echo $failed_count ? ' id="vmsb-filter-failed" style="cursor:pointer;" title="Show only failed items"' : ''; ?>>
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚠️</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo $failed_count; ?></span>
						<span class="vmsb-stage-label">Failed</span>
					</div>
				</div>
				<p class="vmsb-stage-desc"><?php echo $failed_count ? 'Technical failures - click to filter the pipeline below.' : 'Nothing has failed to write.'; ?></p>
			</div>

			<div class="vmsb-pipeline-stage vmsb-health-stage">
				<div class="vmsb-stage-head">
					<span class="vmsb-stage-icon">⚡</span>
					<div class="vmsb-stage-meta">
						<span class="vmsb-stage-count"><?php echo $pub_today; ?><small>/<?php echo $daily_cap; ?></small></span>
						<span class="vmsb-stage-label">Velocity (Today)</span>
					</div>
				</div>
				<div class="vmsb-bar vmsb-mini-bar"><span style="width:<?php echo ($pub_today / ($daily_cap ?: 1)) * 100; ?>%"></span></div>
				<p class="vmsb-stage-desc"><?php echo $daily_cap - $pub_today; ?> slots remaining for hyper-growth.</p>
			</div>
		</div>
	</div>

	<div class="vmsb-card" style="margin-bottom:24px;">
		<div class="vmsb-flex-space" style="margin-bottom:14px;">
			<h2 style="margin:0;">Bulk Import Topics</h2>
			<p class="vmsb-note" style="margin:0;">
				Raw topic ideas in, full Content Plan rows out - each gets its own keyword, cluster, intent and brief from the brain, same as everything else here.
			</p>
		</div>
		<div class="vmsb-flex-space" style="align-items:flex-start; gap:30px; flex-wrap:wrap;">
			<div id="vmsb-bulk-topics-form" class="vmsb-stack-form" style="flex:1; min-width:280px; max-width:520px; margin:0;">
				<label>Paste topics <small>(one per line)</small></label>
				<textarea name="topics" data-list rows="6" placeholder="Best plumbers in Austin&#10;How much does a kitchen remodel cost&#10;Signs you need a new water heater"></textarea>
				<label style="margin-top:10px;">Language <small>(optional — blank uses the site default set in Settings)</small>
					<input type="text" name="language" placeholder="e.g. Spanish, French — blank = site default" style="max-width:320px;">
				</label>
				<button class="vmsb-btn vmsb-btn-gold" data-vmsb="import-topics" data-vmsb-form="vmsb-bulk-topics-form" style="margin-top:14px; align-self:flex-start;">Import Topics</button>
			</div>
			<div style="flex:0 0 auto;">
				<p class="vmsb-note" style="margin:0 0 10px; max-width:260px;">
					Or pull from a dedicated <code>Bulk Topics</code> tab in the same spreadsheet - column A: topic, column B gets marked once imported. Keeps this separate from the sync tab above, so another automation can write topics there safely.
				</p>
				<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="pull-bulk-topics">Pull from Bulk Topics Sheet</button>
			</div>
		</div>
	</div>

	<div class="vmsb-tabs">
		<button class="vmsb-tab<?php echo $cal_is_active ? '' : ' is-active'; ?>" data-tab="pipeline">Editorial Pipeline</button>
		<button class="vmsb-tab<?php echo $cal_is_active ? ' is-active' : ''; ?>" data-tab="calendar">Calendar</button>
		<button class="vmsb-tab" data-tab="social">Social Distribution</button>
	</div>

	<div class="vmsb-panel<?php echo $cal_is_active ? '' : ' is-active'; ?>" data-panel="pipeline">
		<div class="vmsb-content-layout">

		<aside class="vmsb-activity-sidebar vmsb-activity-top">
			<div class="vmsb-flex-space" style="margin-bottom:2px;">
				<h3 style="margin:0;">Agent Activity</h3>
				<p class="vmsb-note" style="margin:0;">Live feed of the Sentient Brain at work.</p>
			</div>

			<div class="vmsb-activity-feed">
				<?php
				$logs = ( new VMSB_Logger() )->recent( 15 );
				if ( ! $logs ) : ?>
					<p class="vmsb-note">No recent activity detected.</p>
				<?php else :
					foreach ( $logs as $log ) :
						$icon = $log->level === 'error' ? '🔴' : ($log->level === 'warn' ? '🟡' : '🟢');
				?>
					<div class="vmsb-activity-item">
						<span class="vmsb-activity-time"><?php echo esc_html( human_time_diff( strtotime( $log->created_at ) ) ); ?> ago</span>
						<p class="vmsb-activity-msg"><?php echo $icon; ?> <strong><?php echo esc_html( ucfirst($log->channel) ); ?>:</strong> <?php echo esc_html( $log->message ); ?></p>
					</div>
				<?php endforeach; endif; ?>
			</div>
		</aside>

		<div class="vmsb-main-pipeline">
			<div class="vmsb-table-wrap">
				<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; gap:20px;">
					<div class="vmsb-search-wrap" style="position:relative; flex:1;">
						<span style="position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:0.5;">🔍</span>
						<input type="text" id="vmsb-plan-search" placeholder="Search editorial pipeline..." style="width:100%; padding-left:35px; height:40px; border-radius:8px;">
					</div>
					<div class="vmsb-bulk-actions" style="margin:0;">
						<select id="vmsb-bulk-select">
							<option value="">Bulk Actions</option>
							<option value="bulk-approve">Approve</option>
							<option value="bulk-delete">Delete</option>
							<option value="bulk-produce">Write Selected</option>
						</select>
						<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-small" id="vmsb-bulk-apply">Apply</button>
					</div>
				</div>

				<table class="vmsb-table vmsb-table-full" id="vmsb-plan-table">
					<thead>
						<tr>
							<th class="vmsb-col-cb"><input type="checkbox" id="vmsb-select-all"></th>
							<th>Status</th>
							<th>Title</th>
							<th>Quality</th>
							<th>Primary keyword</th>
							<th>Cluster</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7" class="vmsb-note">Nothing planned yet.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) :
						// VMSB_Quality_Gate::attach_report() saves to '_vmsb_quality', not
						// '_vmsb_quality_report' - this was reading a meta key that never
						// gets written, so the Quality column always showed "-".
						$q_report = $row->post_id ? get_post_meta($row->post_id, '_vmsb_quality', true) : null;
						$score    = $q_report ? ($q_report['score'] ?? 0) : 0;
						$claims   = $q_report['checks']['alignment']['unverified_claims'] ?? array();
					?>
						<tr data-id="<?php echo (int) $row->id; ?>">
							<td><input type="checkbox" class="vmsb-row-cb" value="<?php echo (int) $row->id; ?>"></td>
							<td>
								<span class="vmsb-sev state-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status ); ?></span>
								<?php if ( $row->status === 'writing' ) : ?>
									<div class="vmsb-agent-step" style="font-size:9px; color:var(--gold); margin-top:4px; font-weight:700; text-transform:uppercase;">
										<span class="vmsb-dot vmsb-dot-gold vmsb-pulse"></span> Reasoning...
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $row->post_id ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $row->post_id ) ); ?>"><?php echo esc_html( $row->title ); ?></a>
									<button class="vmsb-icon-btn" data-vmsb-insight="<?php echo (int) $row->post_id; ?>" title="Strategic Insights">🧠</button>
								<?php else : ?>
									<?php echo esc_html( $row->title ); ?>
								<?php endif; ?>
								<?php if ( $row->last_error ) : ?>
									<br><span class="vmsb-error"><?php echo esc_html( $row->last_error ); ?></span>
								<?php endif; ?>
								<?php if ( $claims ) : ?>
									<div class="vmsb-claims-flag" title="Flagged by the quality gate before this could publish unattended">
										⚠ Needs a source check:
										<ul>
											<?php foreach ( array_slice( $claims, 0, 5 ) as $claim ) : ?>
												<li><?php echo esc_html( $claim ); ?></li>
											<?php endforeach; ?>
										</ul>
									</div>
								<?php endif; ?>
							</td>
							<td>
								<?php if ($score > 0) : ?>
									<div class="vmsb-tiny-score <?php echo $score >= 80 ? 'good' : ($score >= 60 ? 'med' : 'low'); ?>">
										<span><?php echo $score; ?></span>
									</div>
								<?php else : ?>
									<span class="vmsb-note">—</span>
								<?php endif; ?>
							</td>
							<td>
								<code><?php echo esc_html( $row->primary_keyword ); ?></code>
								<?php if ( ! empty( $row->content_language ) ) : ?>
									<span class="vmsb-tag vmsb-tag-purple" title="Overrides the site default for this piece only" style="margin-left:4px;"><?php echo esc_html( $row->content_language ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="vmsb-tag vmsb-tag-blue"><?php echo esc_html( $row->cluster ); ?></span></td>
							<td class="vmsb-row-actions">
								<?php if ( ! $row->post_id ) : ?>
									<button class="vmsb-mini-btn" data-vmsb="produce" data-id="<?php echo (int) $row->id; ?>">Write</button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

	</div>

	</div>

	<div class="vmsb-panel<?php echo $cal_is_active ? ' is-active' : ''; ?>" data-panel="calendar">
		<div class="vmsb-cal-head">
			<a class="vmsb-btn vmsb-btn-ghost vmsb-btn-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'vmsb-plan', 'cal' => $cal_prev ) ) ); ?>">‹ Prev</a>
			<h2 style="margin:0;"><?php echo esc_html( gmdate( 'F Y', $cal_first_ts ) ); ?></h2>
			<a class="vmsb-btn vmsb-btn-ghost vmsb-btn-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'vmsb-plan', 'cal' => $cal_next ) ) ); ?>">Next ›</a>
		</div>

		<?php if ( ! array_filter( $cal_days ) ) : ?>
			<p class="vmsb-note" style="margin:14px 0;">Nothing scheduled for <?php echo esc_html( gmdate( 'F Y', $cal_first_ts ) ); ?>. Items get a date automatically when planned - see "Plan next 20" above.</p>
		<?php endif; ?>

		<div class="vmsb-cal-grid">
			<?php foreach ( array( 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat' ) as $dow ) : ?>
				<div class="vmsb-cal-dow"><?php echo esc_html( $dow ); ?></div>
			<?php endforeach; ?>

			<?php
			for ( $i = 0; $i < $cal_start_dow; $i++ ) {
				echo '<div class="vmsb-cal-cell is-outside"></div>';
			}
			for ( $day = 1; $day <= $cal_days_in; $day++ ) :
				$items = $cal_days[ $day ] ?? array();
				?>
				<div class="vmsb-cal-cell<?php echo $day === $cal_today ? ' is-today' : ''; ?>">
					<span class="vmsb-cal-daynum"><?php echo (int) $day; ?></span>
					<?php foreach ( array_slice( $items, 0, 3 ) as $item ) : ?>
						<div class="vmsb-cal-item state-<?php echo esc_attr( $item->status ); ?>" title="<?php echo esc_attr( $item->title . ' — ' . $item->status ); ?>">
							<?php if ( $item->post_id ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $item->post_id, 'raw' ) ); ?>"><?php echo esc_html( mb_strimwidth( $item->title, 0, 34, '…' ) ); ?></a>
							<?php else : ?>
								<?php echo esc_html( mb_strimwidth( $item->title, 0, 34, '…' ) ); ?>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
					<?php if ( count( $items ) > 3 ) : ?>
						<div class="vmsb-cal-more">+<?php echo count( $items ) - 3; ?> more</div>
					<?php endif; ?>
				</div>
			<?php endfor; ?>
			<?php
			$trailing = ( 7 - ( ( $cal_start_dow + $cal_days_in ) % 7 ) ) % 7;
			for ( $i = 0; $i < $trailing; $i++ ) {
				echo '<div class="vmsb-cal-cell is-outside"></div>';
			}
			?>
		</div>
	</div>

	<div class="vmsb-panel" data-panel="social">
		<div class="vmsb-alert" style="margin-bottom:20px;">
			<p><strong>Integration Active:</strong> High-engagement distribution packs are automatically pushed to <strong>VM Social AI</strong> upon generation.</p>
		</div>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table vmsb-table-full">
				<thead>
					<tr>
						<th>Target Post</th>
						<th>LinkedIn</th>
						<th>X (Twitter)</th>
						<th>Facebook</th>
						<th>VMSAI Status</th>
					</tr>
				</thead>
				<tbody>
				<?php
				$social_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 20 ) );
				global $wpdb;
				$vmsai_table = $wpdb->prefix . 'vmsai_queue';
				$vmsai_active = $wpdb->get_var( "SHOW TABLES LIKE '$vmsai_table'" ) === $vmsai_table;

				foreach ( $social_posts as $sp ) :
					$pack = get_post_meta( $sp->ID, '_vmsb_social_pack', true );
					$vmsai_entry = $vmsai_active ? $wpdb->get_row( $wpdb->prepare( "SELECT status FROM $vmsai_table WHERE link = %s ORDER BY id DESC LIMIT 1", get_permalink($sp->ID) ) ) : null;
				?>
					<tr>
						<td>
							<strong><?php echo esc_html( $sp->post_title ); ?></strong>
							<div class="vmsb-btn-row" style="margin-top:8px;">
								<button class="vmsb-mini-btn" data-vmsb="social-generate" data-id="<?php echo $sp->ID; ?>">Regenerate Pack</button>
							</div>
						</td>
						<td>
							<?php if ( ! empty( $pack['linkedin']['post'] ) ) : ?>
								<span class="vmsb-tag vmsb-tag-blue">Ready</span>
								<button class="vmsb-icon-btn" onclick="alert('<?php echo esc_js($pack['linkedin']['post']); ?>')">👁️</button>
							<?php else : ?>
								<span class="vmsb-note">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $pack['x']['thread'] ) ) : ?>
								<span class="vmsb-tag vmsb-tag-gold">Thread</span>
								<button class="vmsb-icon-btn" onclick="alert('<?php echo esc_js(implode('\n\n', (array)$pack['x']['thread'])); ?>')">👁️</button>
							<?php else : ?>
								<span class="vmsb-note">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $pack['facebook']['post'] ) ) : ?>
								<span class="vmsb-tag vmsb-tag-blue">Ready</span>
								<button class="vmsb-icon-btn" onclick="alert('<?php echo esc_js($pack['facebook']['post']); ?>')">👁️</button>
							<?php else : ?>
								<span class="vmsb-note">—</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $vmsai_entry ) : ?>
								<span class="vmsb-sev state-<?php echo esc_attr( $vmsai_entry->status ); ?>"><?php echo esc_html( ucfirst($vmsai_entry->status) ); ?></span>
							<?php elseif ( $vmsai_active ) : ?>
								<span class="vmsb-note">Not Pushed</span>
							<?php else : ?>
								<span class="vmsb-note">VMSAI Disabled</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>

<script>
jQuery(function($) {
	// Pipeline Search
	$('#vmsb-plan-search').on('input', function() {
		const val = $(this).val().toLowerCase();
		$('#vmsb-plan-table tbody tr').each(function() {
			const text = $(this).text().toLowerCase();
			$(this).toggle(text.indexOf(val) !== -1);
		});
	});

	// Clicking the Failed stat card reuses the same search filter rather
	// than a separate filtering code path - types "failed" into the search
	// box, which matches the status badge text every failed row already has.
	$('#vmsb-filter-failed').on('click', function() {
		$('#vmsb-plan-search').val('failed').trigger('input').get(0).scrollIntoView({ behavior: 'smooth', block: 'center' });
	});
});
</script>
