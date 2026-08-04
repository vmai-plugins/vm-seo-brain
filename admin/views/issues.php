<?php
defined( 'ABSPATH' ) || exit;

global $wpdb;
$fixer   = new VMSB_Fixer();
$counts  = $fixer->counts();
$pending = ( new VMSB_Content() )->pending_reviews( 50 );

// The filter field below is named vmsb_post_type, not post_type - WordPress
// core treats any `post_type` query var on admin.php as a signal that the
// page is a post-type-scoped submenu (like edit.php?post_type=post) and
// resolves the menu hook differently for it. Since this plugin page isn't
// registered that way, a bare `post_type` param here made admin.php fail to
// find the page hook entirely and white-screen with "Cannot load vmsb-issues."
$current_severity  = isset( $_GET['severity'] ) ? sanitize_key( $_GET['severity'] ) : '';
$current_post_type = isset( $_GET['vmsb_post_type'] ) ? sanitize_key( $_GET['vmsb_post_type'] ) : '';
$current_rule      = isset( $_GET['rule'] ) ? sanitize_key( $_GET['rule'] ) : '';

$issues = $fixer->open_issues( 300, $current_severity, $current_post_type, $current_rule );

// Batch-warm the object cache for every post/term referenced below before the
// render loop runs - without this, each row's get_post_type()/get_edit_post_link()/
// get_the_title()/get_term() call is an individual uncached DB hit, up to ~300
// extra queries on a full issues page.
$issue_post_ids = array();
$issue_term_ids = array();
foreach ( $issues as $issue ) {
	if ( ! $issue->object_id ) continue;
	if ( 'post' === $issue->object_type ) {
		$issue_post_ids[] = (int) $issue->object_id;
	} elseif ( 'term' === $issue->object_type ) {
		$issue_term_ids[] = (int) $issue->object_id;
	}
}
if ( $issue_post_ids ) {
	_prime_post_caches( array_unique( $issue_post_ids ), true, true );
}
if ( $issue_term_ids && function_exists( '_prime_term_caches' ) ) {
	_prime_term_caches( array_unique( $issue_term_ids ) );
}

$post_types = get_post_types( array( 'public' => true ), 'objects' );

// Remove some noise types from the filter
$exclude_types = array( 'attachment', 'elementor_library', 'ae_global_templates' );
foreach ( $exclude_types as $et ) {
	unset( $post_types[ $et ] );
}
?>
<div class="wrap vmsb">
	<header class="vmsb-head">
		<div>
			<p class="vmsb-eyebrow">Audit & Optimization</p>
			<h1>Issues</h1>
			<p class="vmsb-sub">
				<?php echo (int) $counts['total']; ?> open, <?php echo (int) $counts['fixed']; ?> already fixed<?php echo $pending ? ', ' . count( $pending ) . ' awaiting review' : ''; ?>.
				<span class="vmsb-note" style="margin-left:10px;">Audit active: <?php echo human_time_diff( get_option('vmsb_last_scan', 0) ); ?> ago</span>
			</p>
		</div>
		<div class="vmsb-head-actions">
			<button class="vmsb-btn vmsb-btn-ghost" data-vmsb="scan">Re-scan Site</button>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="god-fix" data-confirm="God Fix will change live pages automatically. Revert any change later from the logs. Continue?">God Fix</button>
		</div>
	</header>

	<?php if ( $pending ) : ?>
	<section class="vmsb-card" style="margin-bottom:24px; border-left: 3px solid var(--gold);">
		<div class="vmsb-flex-space" style="margin-bottom: 14px;">
			<h2 style="margin:0;">Pending Review <span class="vmsb-tag vmsb-tag-gold" style="margin-left:8px;"><?php echo count( $pending ); ?></span></h2>
			<p class="vmsb-note" style="margin:0;">Drafted rewrites parked instead of published (Settings → Autonomy → Require Review). Approve to publish, reject to discard and reopen the issue.</p>
		</div>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table">
				<thead><tr><th>Page</th><th>Reason</th><th>Length change</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $pending as $p ) :
					$before_words = str_word_count( wp_strip_all_tags( $p['current'] ) );
					$after_words  = str_word_count( wp_strip_all_tags( $p['proposed'] ) );
					$delta        = $after_words - $before_words;
				?>
					<tr>
						<td><a class="vmsb-object-link" href="<?php echo esc_url( $p['edit_url'] ); ?>" target="_blank"><?php echo esc_html( $p['title'] ); ?></a></td>
						<td class="vmsb-issue-detail"><?php echo esc_html( $p['reason'] ); ?></td>
						<td class="<?php echo $delta >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo (int) $before_words; ?> → <?php echo (int) $after_words; ?> words (<?php echo ( $delta >= 0 ? '+' : '' ) . (int) $delta; ?>)</td>
						<td class="vmsb-row-actions">
							<div class="vmsb-action-stack">
								<button class="vmsb-mini-btn" data-vmsb-pending-view="<?php echo (int) $p['post_id']; ?>">Preview draft</button>
								<button class="vmsb-btn vmsb-btn-gold vmsb-btn-xs" data-vmsb="pending-approve" data-body='{"post_id":<?php echo (int) $p['post_id']; ?>}' data-confirm="Publish this drafted rewrite to the live page?">Approve</button>
								<button class="vmsb-mini-btn" data-vmsb="pending-reject" data-body='{"post_id":<?php echo (int) $p['post_id']; ?>}' data-confirm="Discard this draft? The issue will reopen.">Reject</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<div class="vmsb-filter-bar">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; gap:20px;" class="vmsb-filter-head">
			<div class="vmsb-search-wrap">
				<span>🔍</span>
				<input type="text" id="vmsb-issue-search" placeholder="Search issues, pages, or rules...">
			</div>
			<div class="vmsb-surgical-actions">
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="god-fix" data-body='{"scope":["meta"]}' title="Fix all missing SEO titles and descriptions">Fix All Meta</button>
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="god-fix" data-body='{"scope":["alt"]}' title="Fix all missing image alt text">Fix All ALTs</button>
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm" data-vmsb="god-fix" data-body='{"scope":["internal_links"]}' title="Rescue all orphan pages">Link Orphans</button>
			</div>
		</div>

		<form method="get" action="" class="vmsb-inline-filters">
			<input type="hidden" name="page" value="vmsb-issues">

			<select name="severity">
				<option value="">All Severities</option>
				<option value="critical" <?php selected( $current_severity, 'critical' ); ?>>Critical</option>
				<option value="high" <?php selected( $current_severity, 'high' ); ?>>High</option>
				<option value="medium" <?php selected( $current_severity, 'medium' ); ?>>Medium</option>
				<option value="low" <?php selected( $current_severity, 'low' ); ?>>Low</option>
			</select>

			<?php
			$rules = $wpdb->get_col( "SELECT DISTINCT rule FROM {$wpdb->prefix}vmsb_issues WHERE status = 'open' ORDER BY rule ASC" );
			$current_rule = isset( $_GET['rule'] ) ? sanitize_key( $_GET['rule'] ) : '';
			?>
			<select name="rule">
				<option value="">All Rules</option>
				<?php foreach ( $rules as $rule ) : ?>
					<option value="<?php echo esc_attr( $rule ); ?>" <?php selected( $current_rule, $rule ); ?>><?php echo esc_html( str_replace('_', ' ', $rule) ); ?></option>
				<?php endforeach; ?>
			</select>

			<select name="vmsb_post_type">
				<option value="">All Post Types</option>
				<?php foreach ( $post_types as $pt ) : ?>
					<option value="<?php echo esc_attr( $pt->name ); ?>" <?php selected( $current_post_type, $pt->name ); ?>><?php echo esc_html( $pt->label ); ?></option>
				<?php endforeach; ?>
			</select>

			<button type="submit" class="vmsb-btn vmsb-btn-ghost vmsb-btn-sm">Filter</button>
			<?php if ( $current_severity || $current_post_type || $current_rule ) : ?>
				<a href="admin.php?page=vmsb-issues" class="vmsb-note" style="margin-left:10px;">Clear Filters</a>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( ! $issues ) : ?>
		<div class="vmsb-empty">
			<h2>No issues found</h2>
			<p>Your site appears optimized for the selected filters. Run a full scan if you haven't recently.</p>
			<button class="vmsb-btn vmsb-btn-gold" data-vmsb="scan">Scan site</button>
		</div>
	<?php else : ?>
		<div class="vmsb-table-wrap">
			<div class="vmsb-bulk-actions" style="margin-bottom:15px; display:flex; gap:10px; align-items:center;">
				<select id="vmsb-issue-bulk-select" style="width:180px !important;">
					<option value="">Bulk Actions</option>
					<option value="fix">God Fix Selected</option>
					<option value="dismiss">Dismiss Selected</option>
				</select>
				<button class="vmsb-btn vmsb-btn-ghost vmsb-btn-small" id="vmsb-issue-bulk-apply">Apply to Selected</button>
			</div>
			<table class="vmsb-table vmsb-table-full" id="vmsb-issue-table">
				<thead>
					<tr>
						<th class="vmsb-col-cb" style="width:40px;"><input type="checkbox" id="vmsb-issue-select-all"></th>
						<th>Priority</th>
						<th>Rule</th>
						<th>Location</th>
						<th>Technical Detail</th>
						<th class="vmsb-row-actions">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $issues as $issue ) : ?>
					<tr data-issue="<?php echo (int) $issue->id; ?>">
						<td><input type="checkbox" class="vmsb-issue-cb" value="<?php echo (int) $issue->id; ?>"></td>
						<td style="width: 120px;">
							<span class="vmsb-sev sev-<?php echo esc_attr( $issue->severity ); ?>">
								<?php echo esc_html( $issue->severity ); ?>
							</span>
							<div class="vmsb-impact-score" title="Estimated impact on traffic">
								<strong>+<?php echo esc_html( number_format( (float) ( $issue->impact ?? 0 ), 1 ) ); ?></strong>
								<span>Potential</span>
							</div>
						</td>
						<td>
							<span class="vmsb-rule-slug" title="<?php echo esc_attr( $issue->rule ); ?>: <?php echo esc_attr( $fixer->get_rule_explanation( $issue->rule ) ); ?>"><?php echo esc_html( ucfirst( str_replace( '_', ' ', $issue->rule ) ) ); ?></span>
							<div class="vmsb-confidence-tag"><?php echo round( (float) ( $issue->confidence ?? 1.0 ) * 100 ); ?>% confidence</div>
						</td>
						<td style="max-width: 250px;">
							<?php
							if ( 'post' === $issue->object_type && $issue->object_id ) {
								$type = get_post_type( $issue->object_id );
								$type_label = get_post_type_object( $type )->labels->singular_name ?? $type;
								printf(
									'<div class="vmsb-object-meta"><span class="vmsb-tag vmsb-tag-blue">%s</span></div><a class="vmsb-object-link" href="%s" target="_blank">%s</a>',
									esc_html($type_label),
									esc_url( get_edit_post_link( $issue->object_id ) ),
									esc_html( get_the_title( $issue->object_id ) )
								);
							} elseif ( 'term' === $issue->object_type && $issue->object_id ) {
								$term = get_term( $issue->object_id );
								echo '<strong>Term</strong><br>' . esc_html( $term && ! is_wp_error( $term ) ? $term->name : 'term #' . $issue->object_id );
							} else {
								echo '<span class="vmsb-tag vmsb-tag-purple">Site-wide</span>';
							}
							?>
						</td>
						<td>
							<div class="vmsb-issue-detail"><?php echo esc_html( $issue->detail ); ?></div>
							<?php
							// Only a handful of rules stash a $suggested payload shaped like
							// seo_title/meta_description/type/query - most record() calls
							// don't pass one at all. Building the string first and only
							// rendering the box when it's non-empty avoids a "Recommendation:"
							// label with nothing after it on every other issue type.
							$rec = '';
							if ( $issue->suggested ) {
								$sug = json_decode( $issue->suggested, true );
								if ( $sug ) {
									if ( isset( $sug['seo_title'] ) ) $rec .= "Set SEO Title to '" . esc_html( $sug['seo_title'] ) . "'. ";
									if ( isset( $sug['meta_description'] ) ) $rec .= "Update Meta Description. ";
									if ( isset( $sug['type'] ) ) $rec .= "Apply " . esc_html( $sug['type'] ) . " Schema. ";
									if ( isset( $sug['query'] ) ) $rec .= "Target '" . esc_html( $sug['query'] ) . "' cluster. ";
								}
							}
							if ( $rec ) : ?>
								<div class="vmsb-suggested-fix">
									<strong>Recommendation:</strong>
									<?php echo $rec; ?>
								</div>
							<?php endif; ?>
						</td>
						<td class="vmsb-row-actions">
							<div class="vmsb-action-stack">
								<button class="vmsb-btn vmsb-btn-gold vmsb-btn-xs" data-vmsb="bulk-issue-action" data-body='{"ids":[<?php echo (int) $issue->id; ?>],"bulk_action":"fix"}'>Auto-Fix</button>
								<?php if ( 'post' === $issue->object_type && $issue->object_id ) : ?>
									<button class="vmsb-mini-btn" data-vmsb="god-fix-90" data-id="<?php echo (int) $issue->object_id; ?>" data-confirm="Trigger intense AI rewrite for 90+ SEO score? This uses premium tokens.">Target 90+</button>
								<?php endif; ?>
								<button class="vmsb-mini-btn" data-vmsb="dismiss" data-id="<?php echo (int) $issue->id; ?>">Dismiss</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>

	<div id="vmsb-output" class="vmsb-output" hidden></div>
</div>

<script>
jQuery(function($) {
	// Issue Search
	$('#vmsb-issue-search').on('input', function() {
		const val = $(this).val().toLowerCase();
		$('#vmsb-issue-table tbody tr').each(function() {
			const text = $(this).text().toLowerCase();
			$(this).toggle(text.indexOf(val) !== -1);
		});
	});

	// Bulk Select for Issues
	$('#vmsb-issue-select-all').on('change', function() {
		$('.vmsb-issue-cb').prop('checked', this.checked);
	});
});
</script>
