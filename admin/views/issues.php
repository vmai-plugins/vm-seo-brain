<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nested-safe Issues View.
 */
$vmsb_is_nested = defined('VMSB_NESTED') && VMSB_NESTED;

global $wpdb;
$fixer   = new VMSB_Fixer();
$counts  = $fixer->counts();
$pending = ( new VMSB_Content() )->pending_reviews( 50 );
$fixed   = $fixer->fixed_issues( 25 );

// God Mode's autonomous overnight pass runs through the same task queue
// every other agent uses, and VMSB_Task_Runner already stores the full
// result (fixed count + per-issue report) on every row - it was just
// never decoded anywhere, so a nightly auto_fix_queue run left no trace
// beyond a generic "done" status. "Recently Fixed" above shows individual
// fixes but never which ones came from the same run.
$fix_runs = array();
$run_issue_types = array();
if ( class_exists( 'VMSB_Task_Runner' ) ) {
	$referenced_ids = array();
	foreach ( VMSB_Task_Runner::recent( 40 ) as $t ) {
		if ( 'auto_fix_queue' === $t->task_type && in_array( $t->status, array( 'done', 'failed' ), true ) ) {
			$fix_runs[] = $t;
			$decoded = json_decode( (string) $t->result, true );
			foreach ( ( isset( $decoded['report'] ) ? (array) $decoded['report'] : array() ) as $r ) {
				if ( ! empty( $r['id'] ) ) {
					$referenced_ids[] = (int) $r['id'];
				}
			}
			if ( count( $fix_runs ) >= 8 ) {
				break;
			}
		}
	}
	// $r['object'] is the numeric ID of whatever the issue targeted, and its
	// meaning depends entirely on the issue's object_type - for a term-scoped
	// rule like empty_archive, it's a term ID, not a post ID. Blindly calling
	// get_post() on it can coincidentally match a real, unrelated post whose
	// ID happens to equal that term ID, producing a wrong link to a random
	// page. Look up the real object_type per issue instead of guessing.
	if ( $referenced_ids ) {
		$rows = $wpdb->get_results(
			"SELECT id, object_type FROM {$wpdb->prefix}vmsb_issues WHERE id IN (" . implode( ',', array_unique( $referenced_ids ) ) . ")"
		);
		foreach ( $rows as $row ) {
			$run_issue_types[ (int) $row->id ] = $row->object_type;
		}
	}
}

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
<?php if ( ! $vmsb_is_nested ) : ?>
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
	<span class="wp-header-end"></span>
<?php endif; ?>

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

	<?php if ( $fixed ) : ?>
	<section class="vmsb-card" style="margin-bottom:24px; border-left: 3px solid var(--good);">
		<div class="vmsb-flex-space" style="margin-bottom: 14px;">
			<h2 style="margin:0;">Recently Fixed <span class="vmsb-tag vmsb-tag-good" style="margin-left:8px;"><?php echo count( $fixed ); ?></span></h2>
			<p class="vmsb-note" style="margin:0;">Auto-fixes God Fix or Auto-Fix applied. Every "Continue?" confirm on this page has always promised these can be reverted from here.</p>
		</div>
		<div class="vmsb-table-wrap">
			<table class="vmsb-table">
				<thead><tr><th>Page / Term</th><th>What was fixed</th><th>When</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $fixed as $f ) :
					$object_label = '—';
					$object_url   = '';
					if ( 'post' === $f->object_type && $f->object_id && get_post( $f->object_id ) ) {
						$object_label = get_the_title( $f->object_id );
						$object_url   = get_edit_post_link( $f->object_id );
					} elseif ( 'term' === $f->object_type && $f->object_id ) {
						$term = get_term( $f->object_id );
						if ( $term && ! is_wp_error( $term ) ) {
							$object_label = $term->name;
							$object_url   = get_edit_term_link( $f->object_id, $term->taxonomy );
						}
					} elseif ( 'site' === $f->object_type ) {
						$object_label = 'Site-wide';
					}
				?>
					<tr>
						<td><?php if ( $object_url ) : ?><a href="<?php echo esc_url( $object_url ); ?>" target="_blank"><?php echo esc_html( $object_label ); ?></a><?php else : ?><?php echo esc_html( $object_label ); ?><?php endif; ?></td>
						<td class="vmsb-issue-detail"><?php echo esc_html( $fixer->get_rule_explanation( $f->rule ) ?: $f->rule ); ?></td>
						<td class="vmsb-note"><?php echo esc_html( $f->fixed_at ? human_time_diff( strtotime( $f->fixed_at ) ) . ' ago' : '—' ); ?></td>
						<td class="vmsb-row-actions">
							<button class="vmsb-mini-btn" data-vmsb="revert" data-id="<?php echo (int) $f->id; ?>" data-confirm="Undo this fix and restore the previous version?">Revert</button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</section>
	<?php endif; ?>

	<?php if ( $fix_runs ) : ?>
	<section class="vmsb-card" style="margin-bottom:24px; border-left: 3px solid var(--accent-purple);">
		<div class="vmsb-flex-space" style="margin-bottom: 14px;">
			<h2 style="margin:0;">God Mode Run History <span class="vmsb-tag vmsb-tag-purple" style="margin-left:8px;"><?php echo count( $fix_runs ); ?></span></h2>
			<p class="vmsb-note" style="margin:0;">What the autonomous overnight fix pass actually did each time it ran, not just a status badge.</p>
		</div>
		<?php foreach ( $fix_runs as $run ) :
			$result = json_decode( (string) $run->result, true );
			$result = is_array( $result ) ? $result : array();
			$when   = $run->ran_at ? human_time_diff( strtotime( $run->ran_at ) ) . ' ago' : '—';
			$report = isset( $result['report'] ) ? (array) $result['report'] : array();
		?>
			<div style="padding:12px 0; border-bottom:1px solid var(--line);">
				<?php if ( isset( $result['error'] ) ) : ?>
					<div class="vmsb-flex-space">
						<strong style="color:var(--crit);">Run failed</strong>
						<span class="vmsb-note"><?php echo esc_html( $when ); ?></span>
					</div>
					<p class="vmsb-note" style="margin:4px 0 0;"><?php echo esc_html( $result['error'] ); ?></p>
				<?php elseif ( empty( $report ) && ! empty( $result['message'] ) ) : ?>
					<div class="vmsb-flex-space">
						<span>Skipped</span>
						<span class="vmsb-note"><?php echo esc_html( $when ); ?></span>
					</div>
					<p class="vmsb-note" style="margin:4px 0 0;"><?php echo esc_html( $result['message'] ); ?></p>
				<?php else :
					$fixed_n  = (int) ( $result['fixed'] ?? count( array_filter( $report, static fn( $r ) => ! empty( $r['ok'] ) ) ) );
					$failed_n = count( array_filter( $report, static fn( $r ) => empty( $r['ok'] ) ) );
				?>
					<div class="vmsb-flex-space" style="margin-bottom:8px;">
						<strong><?php echo $fixed_n; ?> fixed<?php echo $failed_n ? ', ' . $failed_n . ' failed' : ''; ?></strong>
						<span class="vmsb-note"><?php echo esc_html( $when ); ?></span>
					</div>
					<?php if ( $report ) : ?>
						<ul style="margin:0; padding-left:18px; font-size:12px; color:var(--muted);">
							<?php foreach ( $report as $r ) :
								$label      = esc_html( ucfirst( str_replace( '_', ' ', isset( $r['rule'] ) ? $r['rule'] : '' ) ) );
								$obj_link   = '';
								$issue_type = isset( $r['id'], $run_issue_types[ (int) $r['id'] ] ) ? $run_issue_types[ (int) $r['id'] ] : '';
								if ( ! empty( $r['object'] ) ) {
									if ( 'post' === $issue_type && get_post( $r['object'] ) ) {
										$obj_link = ' — <a href="' . esc_url( get_edit_post_link( $r['object'] ) ) . '" target="_blank">' . esc_html( get_the_title( $r['object'] ) ) . '</a>';
									} elseif ( 'term' === $issue_type ) {
										$term = get_term( $r['object'] );
										if ( $term && ! is_wp_error( $term ) ) {
											$obj_link = ' — <a href="' . esc_url( get_edit_term_link( $r['object'], $term->taxonomy ) ) . '" target="_blank">' . esc_html( $term->name ) . '</a>';
										}
									}
								}
							?>
								<li style="margin-bottom:4px;">
									<?php echo ! empty( $r['ok'] ) ? '✅' : '❌'; ?>
									<?php echo $label; ?><?php echo $obj_link; // phpcs:ignore -- built above from esc_url()/esc_html() only ?>
									<?php if ( ! empty( $r['pending_review'] ) ) : ?><span class="vmsb-tag vmsb-tag-gold" style="margin-left:4px;">Pending Review</span><?php endif; ?>
									<?php if ( empty( $r['ok'] ) && ! empty( $r['message'] ) ) : ?> — <span class="vmsb-note"><?php echo esc_html( $r['message'] ); ?></span><?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
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

	<?php if ( ! $vmsb_is_nested ) : ?>
	<div id="vmsb-output" class="vmsb-output" hidden></div>
<?php endif; ?>


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
