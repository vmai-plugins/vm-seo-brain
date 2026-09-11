/* VM SEO Brain — High-End Admin Intelligence. */
( function ( $ ) {
	'use strict';

	const out = () => document.getElementById( 'vmsb-output' );

	// Server/AI-derived text is untrusted (it can carry entities, titles, or
	// bot names sourced from scraped/third-party content) - always escape
	// before it goes into an HTML string.
	function esc( str ) {
		return String( str ?? '' ).replace( /[&<>"']/g, ( c ) => ( {
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
		} )[ c ] );
	}

	function say( text, append ) {
		const box = out();
		if ( ! box ) { return; }
		box.hidden = false;
		box.textContent = append ? box.textContent + '\n' + text : text;
		box.scrollTop = box.scrollHeight;
	}

	function stamp() {
		return new Date().toLocaleTimeString();
	}

	async function call( route, body ) {
		const res = await fetch( VMSB.root + route, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': VMSB.nonce
			},
			body: JSON.stringify( body || {} )
		} );

		const data = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( data.error || data.message || ( 'Request failed with status ' + res.status ) );
		}
		return data;
	}

	function summarise( route, data ) {
		if ( route === 'god-fix' ) {
			const failed = ( data.report || [] ).filter( r => ! r.ok );
			let line = data.fixed + ' issues fixed.';
			if ( failed.length ) {
				line += ' ' + failed.length + ' could not be fixed automatically:';
				failed.slice( 0, 8 ).forEach( r => { line += '\n  · ' + r.rule + ' — ' + r.message; } );
			}
			return line;
		}
		if ( route === 'god-fix-90' ) { return 'Deep optimization complete. Post content and meta updated for 90+ Rank Math score.'; }
		if ( route === 'scan' )       { return data.found + ' issues open after the scan.'; }
		if ( route === 'research' )   { return data.found + ' keywords touched.'; }
		if ( route === 'improve-post' ) { return data.pending_review ? 'Revision drafted and parked for review.' : 'Page revised.'; }
		if ( route === 'keyword-dismiss' ) { return 'Keyword dismissed.'; }
		if ( route === 'keyword-merge-cluster' ) { return data.merged + ' keyword(s) moved into the target cluster.'; }
		if ( route === 'plan' )       { return data.planned + ' pieces planned.'; }
		if ( route === 'push-sheet' ) { return data.pushed + ' rows pushed to the sheet.'; }
		if ( route === 'pull-sheet' ) { return data.pulled + ' rows pulled from the sheet.'; }
		if ( route === 'import-topics' || route === 'pull-bulk-topics' ) {
			return data.imported + ' topic(s) turned into plan rows' + ( data.skipped ? ', ' + data.skipped + ' skipped (duplicate or unusable).' : '.' );
		}
		if ( route === 'cluster-architect' ) {
			return 'Authority Cluster Created: "' + data.cluster + '" with ' + data.count + ' pieces pushed to pipeline.';
		}
		if ( route === 'gap-discovery' ) {
			const list = $('#vmsb-gap-list');
			list.empty();
			data.gaps.forEach(g => {
				list.append(`
					<tr>
						<td><strong>${esc(g.title)}</strong><br><small>Keyword: ${esc(g.keyword)}</small></td>
						<td><span class="vmsb-tag vmsb-tag-blue">${esc(g.type)}</span></td>
						<td><p class="vmsb-note" style="max-width:250px;">${esc(g.reasoning)}</p></td>
						<td class="vmsb-row-actions">
							<button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="plan" data-body='{"keyword":"${esc(g.keyword)}", "count":1}'>Push to Pipeline</button>
						</td>
					</tr>
				`);
			});
			$('#vmsb-gap-results').fadeIn();
			return 'Gap Radar Scan Complete. Found ' + data.gaps.length + ' high-leverage opportunities.';
		}
		if ( route === 'produce' )    { return 'Written. Open it: ' + data.edit_url; }
		if ( route === 'understand' ) { return 'Business profile updated: ' + ( data.type || 'no type detected' ); }
		if ( route === 'silo-map' )   { return ( data.silos || [] ).length + ' silos mapped.'; }
		if ( route === 'silo-push-gaps' ) { return data.pushed + ' topics pushed to the pipeline.'; }
		if ( route === 'index-vectors' || route === 'rebuild-index' ) {
			return data.indexed + ' indexed, ' + data.skipped + ' already current, ' + data.failed + ' failed. ' + data.remaining + ' remaining.';
		}
		if ( route === 'measure-outcomes' ) {
			return data.short + ' measured at 7 days, ' + data.long + ' at 28 days.';
		}
		if ( route === 'competitor-scan' )    { return data.checked + ' competitors checked, ' + data.gaps + ' gaps found.'; }
		if ( route === 'competitor-add' )     { return 'Added.'; }
		if ( route === 'competitor-remove' )  { return data.removed ? 'Removed.' : 'Could not remove.'; }
		if ( route === 'competitor-duel' ) {
			if ( data.gaps ) {
				let msg = 'Hijack Opportunities Found:';
				data.gaps.forEach( g => { msg += '\n· ' + g.topic + ' — Strategy: ' + g.hijack_angle; } );
				return msg;
			}
			return 'Verdict: ' + ( data.verdict || 'unknown' ) + '. ' + ( data.recommended_additions || [] ).length + ' additions suggested.';
		}
		if ( route === 'backlink-discover' )  { return data.found + ' prospects found.'; }
		if ( route === 'backlink-discover-recent' ) { return data.processed + ' of ' + data.checked + ' posts checked for prospects.'; }
		if ( route === 'backlink-draft' )     { return 'Draft ready: ' + ( data.subject || '' ); }
		if ( route === 'backlink-send' )      { return data.sent ? 'Sent.' : 'Not sent.'; }
		if ( route === 'backlink-shield' )    { return data.checked + ' links checked, ' + data.flagged + ' flagged.'; }
		if ( route === 'aeo-audit' )          { return 'AEO score ' + data.score + '. ' + ( data.issues || [] ).length + ' issues.'; }
		if ( route === 'aeo-apply' )          { return data.inserted ? 'Direct-answer block inserted.' : ( data.skipped || 'No change.' ); }
		if ( route === 'entity-audit' )       { return 'Entity score ' + data.score + '. ' + ( data.missing || [] ).length + ' entities missing.'; }
		if ( route === 'entity-inject' )      { return ( data.added || [] ).length + ' entities woven in.'; }
		if ( route === 'programmatic-build' ) { return data.queued + ' pages queued. ' + data.remaining_today + ' remaining today.'; }
		if ( route === 'roi-scan' )      { return data.checked + ' pages checked, ' + data.leaks + ' leaks found.'; }
		if ( route === 'roi-cta' )       { return data.inserted ? 'CTA inserted.' : ( data.skipped || 'No change.' ); }
		if ( route === 'roi-forecast' )  { return 'Est. ' + data.conversions_at_current + '/mo at current traffic, ' + data.conversions_at_plus25pct + '/mo at +25%.'; }
		if ( route === 'ctr-start' )     { return 'Test started: "' + data.variant + '" for ' + data.concludes_in_days + ' days.'; }
		if ( route === 'ctr-conclude' )  { return data.concluded + ' experiments concluded.'; }
		if ( route === 'news-scout' )    { return data.proposed + ' timely angles proposed - verify before writing.'; }
		if ( route === 'quantum-heist' ) { return data.stolen + ' takedown articles queued to steal competitor rankings.'; }
		if ( route === 'vulture-strike' ) { return data.strikes + ' predatory takeovers launched against decaying competitor rankings.'; }
		if ( route === 'quantum-blast' ) { return data.queued + ' programmatic pages launched across 3 strategic niches.'; }
		if ( route === 'battle-roadmap' ) { return 'Battle Roadmap Generated. View it in the Roadmap tab.'; }
		if ( route === 'growth-scan' ) { return data.found + ' new suggestion(s) queued for review.'; }
		if ( route === 'growth-suggestion-approve' ) { return data.approved ? 'Approved — moved into the production pipeline.' : 'Could not approve (already actioned?).'; }
		if ( route === 'growth-suggestion-reject' ) { return data.rejected ? 'Rejected.' : 'Could not reject (already actioned?).'; }
		if ( route === 'niche-plan' )   { return data.planned + ' pieces planned for expansion.'; }
		if ( route === 'clear-rejected' ) { return data.deleted + ' rejected items removed.'; }
		if ( route === 'replan-rejected' ) { return data.updated + ' items reset for re-planning.'; }
		if ( route === 'approve-all' )    { return data.updated + ' items marked as approved.'; }
		if ( route === 'bulk-action' )   { return data.count + ' items processed via ' + data.action; }
		if ( route === 'bulk-issue-action' ) { return data.count ? data.count + ' issue(s) ' + ( data.action === 'fix' ? 'fixed.' : data.action + 'd.' ) : 'Nothing automatable for this issue yet - it needs a manual look.'; }
		if ( route === 'traffic-forecast' ) { return data.trend + ' — ~' + data.projected_30d + ' clicks/30d projected (' + data.confidence + ' confidence).'; }
		if ( route === 'schema-faq' )    { return data.added ? data.added + ' FAQ pairs added.' : ( data.skipped || 'No change.' ); }
		if ( route === 'schema-graph' )  { return data.linked_peers ? data.linked_peers + ' peers linked.' : ( data.skipped || 'No change.' ); }
		if ( route === 'global-expand' ) { return data.queued + ' localised pages queued for: ' + ( data.locations || [] ).join( ', ' ); }
		if ( route === 'plan-dedupe' ) {
			var t = data.totals || {};
			if ( data.dry_run ) {
				if ( ! t.removable ) { return 'No duplicate topics found — the queue is clean.'; }
				return 'Found ' + t.removable + ' duplicate rows across ' + t.groups + ' topics.\n' +
					'The plan would go from ' + t.rows_scanned + ' to ' + ( t.rows_scanned - t.removable ) + ' rows.\n' +
					'Nothing has been changed. Worst offenders:\n  ' +
					( data.worst || [] ).slice( 0, 8 ).map( function ( g ) {
						return g.keyword + ' — keeping 1, removing ' + g.removes;
					} ).join( '\n  ' ) +
					'\n\nUse "Merge Duplicates" to apply this.';
			}
			return 'Merged: removed ' + data.removed + ' duplicate rows across ' + t.groups + ' topics. Reload to see the updated queue.';
		}
		if ( route === 'geo-save-map' ) { return 'Map saved: ' + data.states + ' states, ' + data.cities + ' cities. Reload to see the matrix.'; }
		if ( route === 'geo-expand' ) {
			if ( ! data.queued ) { return 'Nothing queued — every cell matching that filter is already covered.'; }
			return data.queued + ' location page(s) queued as suggestions:\n  ' +
				( data.items || [] ).map( function ( i ) { return i.title; } ).join( '\n  ' );
		}
		if ( route === 'health-check' ) {
			const checks = Object.values( data.checks || data );
			const ok = checks.filter( c => c.ok ).length;
			return ok + ' of ' + checks.length + ' systems OK.';
		}
		if ( route === 'market-assess' ) { return 'Market read: ' + ( data.saturation || 'unknown' ) + '. ' + ( data.recommendation || '' ); }
		if ( route === 'strategist-preview' ) {
			if ( ! data.plan || ! data.plan.length ) { return 'Nothing to do right now — every optional task scored zero impact.'; }
			return data.plan.map( p => p.task + '  (score ' + p.score + ') — ' + p.reason ).join( '\n' );
		}
		if ( route === 'tasks-process' ) { return data.ran + ' task(s) run: ' + Object.keys( data.results || {} ).join( ', ' ); }
		if ( route === 'agents-run-strategist' ) {
			if ( ! data.queued ) { return 'Nothing to queue right now — every eligible agent scored zero impact.'; }
			return data.queued + ' agent(s) queued: ' + data.plan.map( p => p.task ).join( ', ' ) + '. They\'ll run on the next queue drain, or click "Process Queue Now".';
		}
		if ( route === 'test-provider' ) { return data.ok ? 'Success: ' + data.message : 'Failed: ' + data.message; }
		if ( route === 'webhook-test' ) { return ( data.ok ? 'Success: ' : 'Failed: ' ) + data.message; }
		if ( route === 'health-reset' ) { return 'Circuit breakers reset.'; }
		if ( route === 'test-image-provider' ) { return data.ok ? 'Success: Image generated with ' + data.provider : 'Failed: ' + data.error; }
		if ( route === 'revert' )          { return 'Reverted. The page is back to how it was before the fix.'; }
		if ( route === 'pending-approve' ) { return 'Draft approved and published.'; }
		if ( route === 'pending-reject' )  { return 'Draft discarded — issue reopened.'; }
		if ( route === 'action-rollback' ) { return 'Action rolled back successfully.'; }
		if ( route === 'rollback-recent' ) { return data.reverted + ' action(s) reverted to their previous state.'; }
		if ( route === 'license-verify' )  { return 'Success! Your ' + data.plan + ' plan is now active.'; }
		if ( route === 'opportunity-scan' ) { return 'Found ' + data.found + ' opportunities. Refreshing...'; }
		if ( route === 'evaluate-pivot' ) { return 'Strategic Pivot decided: ' + data.pivot_name; }
		if ( route === 'video-to-blog' ) { return 'Video transformed. New pillar post queued in Content Factory.'; }
		if ( route === 'retry-critique' ) {
			return data.queued ? 'Critique task queued. The agent will rewrite in the background.' : ( data.post_id ? 'Success! Redirecting to draft...' : 'Retry started.' );
		}
		return JSON.stringify( data, null, 2 );
	}

	function reloadIfNeeded( route ) {
		const routes = [ 'scan', 'god-fix', 'god-fix-90', 'dismiss', 'bulk-issue-action', 'import-topics', 'pull-bulk-topics', 'improve-post', 'keyword-dismiss', 'keyword-merge-cluster', 'ctr-start', 'plan', 'research', 'silo-map', 'silo-push-gaps', 'produce', 'rebuild-index', 'measure-outcomes', 'competitor-add', 'competitor-remove', 'competitor-scan', 'backlink-discover', 'backlink-discover-recent', 'backlink-shield', 'programmatic-build', 'roi-scan', 'ctr-conclude', 'news-scout', 'traffic-forecast', 'global-expand', 'health-check', 'market-assess', 'tasks-process', 'health-reset', 'niche-plan', 'clear-rejected', 'replan-rejected', 'approve-all', 'bulk-action', 'taxonomy-audit', 'taxonomy-propose', 'pending-approve', 'pending-reject', 'revert', 'license-verify', 'cluster-architect', 'battle-roadmap', 'growth-scan', 'growth-suggestion-approve', 'growth-suggestion-reject', 'agents-run-strategist', 'opportunity-scan', 'evaluate-pivot', 'action-rollback', 'video-to-blog', 'task-retry', 'task-cancel', 'execute-opportunity', 'graph-sync', 'retry-critique', 'geo-save-map', 'geo-expand', 'plan-dedupe' ];
		if ( routes.indexOf( route ) !== -1 ) {
			const msg = ( route === 'import-topics' || route === 'pull-bulk-topics' ) ? '&vmsb_msg=import_done' : ( route === 'license-verify' ? '&vmsb_msg=license_active' : '' );
			setTimeout( () => {
				if ( msg ) {
					window.location.href = window.location.href.split('&')[0] + msg;
				} else {
					window.location.reload();
				}
			}, 1600 );
		}
	}

	// Main Button Handler
	$( document ).on( 'click', '[data-vmsb]', async function ( event ) {
		const btn = $( this );
		if ( btn.closest( '#vmsb-commander-root' ).length ) return; // Skip commander buttons here

		event.preventDefault();

		const route = btn.data( 'vmsb' );
		const confirmText = btn.data( 'confirm' );
		if ( confirmText && ! window.confirm( confirmText ) ) return;

		let body = {};
		const raw = btn.attr( 'data-body' );
		if ( raw ) { try { body = JSON.parse( raw ); } catch ( e ) { body = {}; } }
		if ( btn.attr( 'data-id' ) ) body.id = parseInt( btn.attr( 'data-id' ), 10 );

		const formSel = btn.data( 'vmsb-form' );
		if ( formSel ) {
			$( '#' + formSel ).find( '[name]' ).each( function () {
				const field = $( this );
				const name = field.attr( 'name' );
				let value = field.val();
				if ( field.is( 'textarea' ) && field.attr( 'data-list' ) !== undefined ) {
					// Split on any common line-ending, not just \n - pasted
					// text (Windows sources, some editors) can carry \r\n or
					// a lone \r, which a bare \n split leaves as one line
					// with a trailing \r that .trim() hides, or worse,
					// merges what looked like several lines into one.
					value = value.split( /\r\n|\r|\n/ ).map( s => s.trim() ).filter( Boolean );
				}
				if ( name.includes( '.' ) ) {
					const [ parent, child ] = name.split( '.' );
					body[ parent ] = body[ parent ] || {};
					body[ parent ][ child ] = value;
				} else { body[ name ] = value; }
			} );
		}

		const label = btn.text();
		btn.prop( 'disabled', true ).text( 'Working…' );
		const startedMsg = route === 'research'
			? 'Sync & Discover started: pulling Search Console, expanding via autocomplete, then clustering with AI. This can take close to a minute on a full run - hang tight.'
			: route + ' started. This can take a minute.';
		say( stamp() + '  ' + startedMsg, true );

		try {
			const data = await call( route, body );
			say( stamp() + '  ' + summarise( route, data ), true );
			if ( route === 'index-vectors' && data.remaining > 0 ) {
				btn.text( 'Indexing… ' + data.remaining + ' left' );
				setTimeout( () => btn.click(), 400 );
				return;
			}
			reloadIfNeeded( route );
		} catch ( error ) {
			say( stamp() + '  Failed: ' + error.message, true );
		} finally {
			btn.prop( 'disabled', false ).text( label );
		}
	} );

	// Bulk Actions - Plan
	const selectAll = document.getElementById( 'vmsb-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			document.querySelectorAll( '.vmsb-row-cb' ).forEach( cb => cb.checked = this.checked );
		} );
	}

	const bulkApply = document.getElementById( 'vmsb-bulk-apply' );
	if ( bulkApply ) {
		bulkApply.addEventListener( 'click', async function () {
			const action = document.getElementById( 'vmsb-bulk-select' ).value;
			if ( ! action ) { alert( 'Select an action first.' ); return; }

			const ids = Array.from( document.querySelectorAll( '.vmsb-row-cb:checked' ) ).map( cb => cb.value );
			if ( ! ids.length ) { alert( 'No items selected.' ); return; }

			if ( ! confirm( 'Apply ' + action + ' to ' + ids.length + ' items?' ) ) { return; }

			bulkApply.disabled = true;
			bulkApply.textContent = 'Applying…';

			try {
				const data = await call( 'bulk-action', { ids: ids, bulk_action: action } );
				say( stamp() + '  Bulk action complete: ' + data.count + ' items affected.', true );
				reloadIfNeeded( 'bulk-action' );
			} catch ( e ) {
				alert( 'Bulk action failed: ' + e.message );
			} finally {
				bulkApply.disabled = false;
				bulkApply.textContent = 'Apply';
			}
		} );
	}

	// Bulk Actions - Issues
	const issueSelectAll = document.getElementById( 'vmsb-issue-select-all' );
	if ( issueSelectAll ) {
		issueSelectAll.addEventListener( 'change', function () {
			document.querySelectorAll( '.vmsb-issue-cb' ).forEach( cb => cb.checked = this.checked );
		} );
	}

	const issueBulkApply = document.getElementById( 'vmsb-issue-bulk-apply' );
	if ( issueBulkApply ) {
		issueBulkApply.addEventListener( 'click', async function () {
			const action = document.getElementById( 'vmsb-issue-bulk-select' ).value;
			if ( ! action ) { alert( 'Select an action first.' ); return; }

			const ids = Array.from( document.querySelectorAll( '.vmsb-issue-cb:checked' ) ).map( cb => cb.value );
			if ( ! ids.length ) { alert( 'No items selected.' ); return; }

			if ( ! confirm( 'Apply ' + action + ' to ' + ids.length + ' issues? This can take some time.' ) ) { return; }

			issueBulkApply.disabled = true;
			issueBulkApply.textContent = 'Applying…';
			say( stamp() + '  Bulk issue action started. Processing ' + ids.length + ' items...', true );

			try {
				const data = await call( 'bulk-issue-action', { ids: ids, bulk_action: action } );
				say( stamp() + '  Bulk action complete: ' + data.count + ' issues ' + (action === 'fix' ? 'fixed' : 'dismissed') + '.', true );
				reloadIfNeeded( 'bulk-action' ); // reuse same reload delay
			} catch ( e ) {
				alert( 'Bulk action failed: ' + e.message );
			} finally {
				issueBulkApply.disabled = false;
				issueBulkApply.textContent = 'Apply to Selected';
			}
		} );
	}

	// Bulk Actions - Keywords (three separate tab panels share these
	// classes, so everything here is scoped to the closest .vmsb-panel
	// rather than a single fixed ID like the Issues/Plan bulk bars use).
	$( document ).on( 'change', '.vmsb-kw-select-all', function () {
		$( this ).closest( '.vmsb-panel' ).find( '.vmsb-kw-row-cb' ).prop( 'checked', this.checked );
	} );

	$( document ).on( 'click', '.vmsb-kw-bulk-apply', async function () {
		const btn    = $( this );
		const panel  = btn.closest( '.vmsb-panel' );
		const action = panel.find( '.vmsb-kw-bulk-select' ).val();
		if ( ! action ) { alert( 'Select an action first.' ); return; }

		const keywords = panel.find( '.vmsb-kw-row-cb:checked' ).map( function () { return this.value; } ).get();
		if ( ! keywords.length ) { alert( 'No keywords selected.' ); return; }
		if ( ! confirm( 'Apply ' + action + ' to ' + keywords.length + ' keyword(s)?' ) ) return;

		const label = btn.text();
		btn.prop( 'disabled', true ).text( 'Working…' );
		say( stamp() + '  Bulk ' + action + ' started for ' + keywords.length + ' keyword(s)...', true );

		let ok = 0;
		try {
			for ( const kw of keywords ) {
				try {
					if ( action === 'dismiss' ) {
						await call( 'keyword-dismiss', { keyword: kw } );
					} else if ( action === 'plan' ) {
						await call( 'plan', { count: 1, keyword: kw } );
					}
					ok++;
				} catch ( e ) { /* keep going - report the tally below */ }
			}
			say( stamp() + '  Bulk ' + action + ' complete: ' + ok + ' of ' + keywords.length + ' succeeded.', true );
			setTimeout( () => window.location.reload(), 1200 );
		} finally {
			btn.prop( 'disabled', false ).text( label );
		}
	} );

	// Merge/rename a cluster: fold every keyword tagged with one cluster
	// name into another, so an AI-assigned near-duplicate cluster name
	// doesn't permanently fragment a silo.
	$( document ).on( 'click', '#vmsb-cluster-merge-apply', async function () {
		const btn  = $( this );
		const from = $( '#vmsb-cluster-merge-from' ).val();
		const to   = ( $( '#vmsb-cluster-merge-to' ).val() || '' ).trim();
		if ( ! from ) { alert( 'Choose a cluster to merge.' ); return; }
		if ( ! to )   { alert( 'Enter the target cluster name.' ); return; }
		if ( ! confirm( 'Move every keyword in "' + from + '" into "' + to + '"?' ) ) return;

		const label = btn.text();
		btn.prop( 'disabled', true ).text( 'Merging…' );
		try {
			const data = await call( 'keyword-merge-cluster', { from: from, to: to } );
			say( stamp() + '  ' + data.merged + ' keyword(s) moved from "' + from + '" into "' + to + '".', true );
			setTimeout( () => window.location.reload(), 1200 );
		} catch ( e ) {
			alert( 'Merge failed: ' + e.message );
		} finally {
			btn.prop( 'disabled', false ).text( label );
		}
	} );

	// AI-suggested cluster merges: scan all existing cluster names for likely
	// duplicates and let the user apply each suggestion with one click,
	// reusing the same keyword-merge-cluster route the manual tool above uses.
	$( document ).on( 'click', '#vmsb-cluster-suggest-merges', async function () {
		const btn   = $( this );
		const box   = $( '#vmsb-cluster-merge-suggestions' );
		const label = btn.text();
		btn.prop( 'disabled', true ).text( 'Scanning…' );
		try {
			const data        = await call( 'keyword-suggest-merges', {} );
			const suggestions = data.suggestions || [];
			if ( ! suggestions.length ) {
				box.html( '<p class="vmsb-note" style="margin-top:10px;">No confident duplicates found among your current clusters.</p>' );
				return;
			}
			let html = '<div class="vmsb-note" style="margin:10px 0 6px;">' + suggestions.length + ' likely duplicate(s) found:</div>';
			suggestions.forEach( function ( s ) {
				html += '<div class="vmsb-inline-form" style="align-items:center; gap:10px; margin-bottom:6px;">'
					+ '<span>' + esc( s.from ) + ' &rarr; <strong>' + esc( s.to ) + '</strong></span>'
					+ '<button class="vmsb-mini-btn vmsb-suggested-merge-apply" data-from="' + esc( s.from ) + '" data-to="' + esc( s.to ) + '">Apply</button>'
					+ '</div>';
			} );
			box.html( html );
		} catch ( e ) {
			box.html( '<p class="vmsb-error" style="margin-top:10px;">Failed: ' + esc( e.message ) + '</p>' );
		} finally {
			btn.prop( 'disabled', false ).text( label );
		}
	} );

	$( document ).on( 'click', '.vmsb-suggested-merge-apply', async function () {
		const btn  = $( this );
		const from = btn.data( 'from' );
		const to   = btn.data( 'to' );
		if ( ! confirm( 'Move every keyword in "' + from + '" into "' + to + '"?' ) ) return;

		btn.prop( 'disabled', true ).text( 'Merging…' );
		try {
			const data = await call( 'keyword-merge-cluster', { from: from, to: to } );
			say( stamp() + '  ' + data.merged + ' keyword(s) moved from "' + from + '" into "' + to + '".', true );
			setTimeout( () => window.location.reload(), 1200 );
		} catch ( e ) {
			alert( 'Merge failed: ' + e.message );
			btn.prop( 'disabled', false ).text( 'Apply' );
		}
	} );

	// Global Search Handler
	let searchTimeout;
	$( document ).on( 'input', '.vmsb-global-search input', function() {
		const q = $(this).val();
		const wrapper = $(this).parent();
		clearTimeout(searchTimeout);

		if ( q.length < 3 ) {
			$('.vmsb-search-results').remove();
			return;
		}

		searchTimeout = setTimeout( async () => {
			try {
				const results = await call('global-search', { q: q });
				$('.vmsb-search-results').remove();

				if ( results.length ) {
					let html = '<div class="vmsb-search-results" style="position:absolute; top:100%; left:0; right:0; background:var(--panel); border:1px solid var(--line); border-radius:12px; margin-top:10px; z-index:1000; box-shadow:0 10px 30px rgba(0,0,0,0.5); overflow:hidden;">';
					results.forEach( r => {
						html += `<a href="${r.url}" style="display:block; padding:12px 15px; text-decoration:none; color:var(--text); border-bottom:1px solid rgba(255,255,255,0.03);">
							<span class="vmsb-tag" style="font-size:9px; margin-bottom:5px;">${esc(r.type)}</span>
							<strong style="display:block; font-size:13px;">${esc(r.label)}</strong>
							<small class="vmsb-note">${esc(r.note)}</small>
						</a>`;
					});
					html += '</div>';
					wrapper.append(html);
				}
			} catch (e) {}
		}, 300);
	});

	$(document).on('click', function(e) {
		if ( ! $(e.target).closest('.vmsb-global-search').length ) {
			$('.vmsb-search-results').remove();
		}
		if ( ! $(e.target).closest('#vmsb-notifications-trigger').length ) {
			$('.vmsb-notification-dropdown').remove();
		}
	});

	// Notifications Hub
	async function loadNotifications() {
		try {
			const notes = await call('notifications', {});
			const trigger = $('#vmsb-notifications-trigger');
			const badge = trigger.find('.vmsb-count');

			if ( notes.length ) {
				badge.text(notes.length).prop('hidden', false);
			} else {
				badge.prop('hidden', true);
			}
		} catch (e) {}
	}

	$( document ).on( 'click', '#vmsb-notifications-trigger', async function() {
		const trigger = $(this);
		if ( $('.vmsb-notification-dropdown').length ) {
			$('.vmsb-notification-dropdown').remove();
			return;
		}

		try {
			const notes = await call('notifications', {});
			let html = '<div class="vmsb-notification-dropdown">';

			if ( ! notes.length ) {
				html += '<div style="padding:30px; text-align:center;"><p class="vmsb-note">No active alerts.</p></div>';
			} else {
				notes.forEach( n => {
					html += `<a href="${n.url}" class="vmsb-note-item level-${n.level}">
						<strong>${esc(n.title)}</strong>
						<p>${esc(n.message)}</p>
					</a>`;
				});
			}
			html += '</div>';
			trigger.parent().css('position', 'relative').append(html);
		} catch (e) {}
	});

	loadNotifications();
	setInterval(loadNotifications, 60000);

	// Modern Popup Chat Handler
	const popupRoot = $( '#vmsb-commander-root' );
	if ( popupRoot.length ) {
		const popupInput = $( '#vmsb-popup-chat-input' );
		const popupSend = $( '#vmsb-popup-chat-send' );
		const popupHistory = $( '#vmsb-popup-chat-history' );
		const popupWindow = $( '#vmsb-commander-popup' );
		const popupTrigger = $( '#vmsb-commander-trigger' );
		const popupClose = $( '#vmsb-commander-close' );
		const chatClear = $( '#vmsb-chat-clear' );

		// Process existing history if any (convert stored markdown to HTML)
		popupHistory.find('.vmsb-msg-ai .vmsb-chat-bubble').each(function() {
			const raw = $(this).text();
			if ( raw.includes('#') || raw.includes('*') || raw.includes('`') ) {
				$(this).html( renderMarkdown(raw) );
			}
		});

		// Initialize Scroll
		popupHistory.scrollTop( popupHistory[0].scrollHeight );

		// The Commander's replies come back as markdown (the AI is prompted
		// as a strategist and writes ###/**bold**/bullet lists), but the
		// bubble was inserting the escaped string verbatim - so the user
		// read raw "### 1. Market Research" and "* **Analyze Demand:**"
		// with every newline collapsed into one wall of text. Renders a
		// deliberately small subset, applied AFTER escaping so nothing the
		// model emits can inject markup.
		function renderMarkdown( text ) {
			const lines = esc( text ).split( /\r?\n/ );
			let out = '', inList = false;

			const inline = ( s ) => s
				.replace( /`([^`]+)`/g, '<code>$1</code>' )
				.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
				.replace( /(^|[\s(])\*([^*\n]+)\*(?=[\s.,;:!?)]|$)/g, '$1<em>$2</em>' );

			for ( let line of lines ) {
				const trimmed = line.trim();
				const bullet  = trimmed.match( /^[*-]\s+(.*)$/ );
				const heading = trimmed.match( /^(#{1,6})\s+(.*)$/ );

				if ( bullet ) {
					if ( ! inList ) { out += '<ul>'; inList = true; }
					out += `<li>${inline( bullet[1] )}</li>`;
					continue;
				}
				if ( inList ) { out += '</ul>'; inList = false; }

				if ( heading ) {
					const level = Math.min( 6, heading[1].length + 2 );
					out += `<h${level}>${inline( heading[2] )}</h${level}>`;
				} else if ( trimmed ) {
					out += `<p>${inline( trimmed )}</p>`;
				}
			}
			if ( inList ) out += '</ul>';
			return out || esc( text );
		}

		function appendPopupMsg( text, type ) {
			// Only the AI writes markdown; user text stays literal so typing
			// something like *hello* shows exactly what was typed.
			const body = type === 'ai' ? renderMarkdown( text ) : esc( text );
			const html = `
				<div class="vmsb-chat-msg vmsb-msg-${type}">
					<div class="vmsb-chat-bubble">${body}</div>
				</div>
			`;
			popupHistory.append( html );
			popupHistory.animate( { scrollTop: popupHistory[0].scrollHeight }, 400 );
		}

		function showTyping() {
			popupHistory.append( `
				<div id="vmsb-typing" class="vmsb-typing">
					<span></span><span></span><span></span>
				</div>
			` );
			popupHistory.animate( { scrollTop: popupHistory[0].scrollHeight }, 200 );
		}

		function hideTyping() { $( '#vmsb-typing' ).remove(); }

		async function sendPopupMessage( textOverride ) {
			const text = textOverride || popupInput.val().trim();
			if ( ! text ) return;

			if ( ! textOverride ) popupInput.val( '' );
			appendPopupMsg( text, 'user' );
			showTyping();

			try {
				const response = await fetch( VMSB.root + 'command', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': VMSB.nonce },
					body: JSON.stringify( { input: text } )
				} );
				const data = await response.json();
				hideTyping();
				if ( data.reply ) appendPopupMsg( data.reply, 'ai' );
			} catch ( e ) {
				hideTyping();
				appendPopupMsg( 'Strategic connection error.', 'ai' );
			}
		}

		popupTrigger.on( 'click', () => {
			popupWindow.fadeIn( 300 ).css( 'display', 'flex' );
			popupTrigger.fadeOut( 200 );
			popupInput.focus();
		} );

		popupClose.on( 'click', ( e ) => {
			e.stopPropagation();
			popupWindow.fadeOut( 300 );
			popupTrigger.fadeIn( 400 );
		} );
		chatClear.on( 'click', ( e ) => {
			e.stopPropagation();
			if ( confirm( 'Reset strategic conversation?' ) ) popupHistory.find( '.vmsb-chat-msg:not(:first-child)' ).remove();
		} );

		popupSend.on( 'click', () => sendPopupMessage() );
		popupInput.on( 'keypress', ( e ) => { if ( e.which === 13 ) sendPopupMessage(); } );
		$( document ).on( 'click', '#vmsb-commander-popup [data-cmd]', function () { sendPopupMessage( $( this ).data( 'cmd' ) ); } );
	}

	// Post Insight Handler
	$( document ).on( 'click', '[data-vmsb-insight]', async function() {
		const id = $(this).data('vmsb-insight');
		const btn = $(this);

		btn.text('⏳');

		try {
			const data = await call( 'post-insight', { id: id } );

			let html = `<div class="vmsb-insight-overlay">
				<div class="vmsb-insight-modal">
					<header>
						<h3>Strategic Insights: ${esc(data.title)}</h3>
						<button class="vmsb-modal-close">✕</button>
					</header>
					<div class="vmsb-insight-body">
						<div class="vmsb-insight-grid">
							<div class="vmsb-insight-card">
								<span class="vmsb-label">Quality Score</span>
								<div class="vmsb-big-score ${data.score >= 80 ? 'good' : 'med'}">${esc(data.score)}</div>
								<p class="vmsb-note">${esc(data.report ? data.report.summary : 'Pending full audit.')}</p>
							</div>
							<div class="vmsb-insight-card">
								<span class="vmsb-label">Missing Entities</span>
								<ul class="vmsb-entity-list">
									${data.entities.length ? data.entities.map(e => `<li>${esc(e)}</li>`).join('') : '<li>✅ Optimized</li>'}
								</ul>
							</div>
						</div>

						<h4>Semantic Link Opportunities</h4>
						<ul class="vmsb-link-ops">
							${data.links.length ? data.links.map(l => `<li><strong>${esc(l.title)}</strong><br><small>${esc(l.url)}</small></li>`).join('') : '<li>No new internal link opportunities found.</li>'}
						</ul>

						<h4>Social Distribution</h4>
						<div class="vmsb-social-preview">
							${data.social ? `<p><strong>LinkedIn:</strong> ${esc(data.social.linkedin.post.substring(0, 100))}...</p>` : '<p class="vmsb-note">Social pack not generated yet.</p>'}
						</div>
					</div>
				</div>
			</div>`;

			$('body').append(html);
		} catch (e) {
			alert('Failed to load insights.');
		} finally {
			btn.text('🧠');
		}
	});

	// Task Detail View
	$( document ).on( 'click', '[data-vmsb-task-view]', async function() {
		const id = $( this ).data( 'vmsb-task-view' );
		const btn = $( this );
		const label = btn.text();
		btn.text( '…' );

		try {
			const data = await call( 'task-detail', { id: id } );

			const timelineHtml = data.timeline.map( t => `
				<div class="vmsb-timeline-item" style="display:flex; gap:15px; margin-bottom:15px; padding-bottom:10px; border-bottom:1px solid rgba(255,255,255,0.03);">
					<span class="vmsb-note" style="width:140px; flex-shrink:0;">${t.time}</span>
					<div style="flex:1;">
						<strong style="display:block; font-size:13px; color:var(--gold-soft);">${esc(t.event)}</strong>
						<p class="vmsb-note" style="margin:4px 0 0;">${esc(t.note)}</p>
					</div>
				</div>
			` ).join('');

			const html = `<div class="vmsb-insight-overlay">
				<div class="vmsb-insight-modal">
					<header>
						<h3>Task Detail: #${data.id} — ${esc(data.type)}</h3>
						<button class="vmsb-modal-close">✕</button>
					</header>
					<div class="vmsb-insight-body">
						<div class="vmsb-flex-space" style="margin-bottom:25px;">
							<div>
								<span class="vmsb-note">Status</span>
								<div class="vmsb-tag ${data.status === 'done' ? 'vmsb-tag-good' : (data.status === 'failed' ? 'vmsb-tag-crit' : 'vmsb-tag-blue')}">${esc(data.status.toUpperCase())}</div>
							</div>
							<div style="text-align:right;">
								<span class="vmsb-note">Priority Score</span>
								<div style="font-size:24px; font-weight:800; font-family:var(--serif); color:var(--gold);">${data.score}</div>
							</div>
						</div>

						<h4 style="margin-bottom:15px; font-size:14px; text-transform:uppercase;">Execution Timeline</h4>
						<div class="vmsb-timeline-container" style="max-height:400px; overflow-y:auto; padding-right:10px;">
							${timelineHtml || '<p class="vmsb-note">No timeline events recorded.</p>'}
						</div>

						${data.error ? `
							<div class="vmsb-alert" style="margin-top:20px; border-color:var(--crit); background:rgba(255,77,77,0.05);">
								<strong>Last Error:</strong><br>${esc(data.error)}
							</div>
						` : ''}
					</div>
				</div>
			</div>`;

			$( 'body' ).append( html );
		} catch ( e ) {
			alert( 'Failed to load task details.' );
		} finally {
			btn.text( label );
		}
	} );

	// Pending-Review Draft Preview
	$( document ).on( 'click', '[data-vmsb-pending-view]', async function() {
		const postId = $( this ).data( 'vmsb-pending-view' );
		const btn = $( this );
		const label = btn.text();
		btn.text( '…' );

		try {
			const list = await call( 'pending-list', {} );
			const item = ( list || [] ).find( p => parseInt( p.post_id, 10 ) === parseInt( postId, 10 ) );
			if ( ! item ) { alert( 'This draft is no longer pending.' ); return; }

			const strip = ( html ) => $( '<div>' ).html( html || '' ).text();

			const html = `<div class="vmsb-insight-overlay">
				<div class="vmsb-insight-modal">
					<header>
						<h3>Pending Draft: ${esc(item.title)}</h3>
						<button class="vmsb-modal-close">✕</button>
					</header>
					<div class="vmsb-insight-body">
						${item.reason ? `<p class="vmsb-note">${esc(item.reason)}</p>` : ''}
						<h4>Proposed</h4>
						<div class="vmsb-link-ops" style="max-height:260px; overflow:auto; white-space:pre-wrap; font-size:12.5px;">${esc(strip(item.proposed))}</div>
						<h4>Current (live)</h4>
						<div class="vmsb-link-ops" style="max-height:180px; overflow:auto; white-space:pre-wrap; font-size:12.5px; opacity:.7;">${esc(strip(item.current))}</div>
					</div>
				</div>
			</div>`;

			$( 'body' ).append( html );
		} catch ( e ) {
			alert( 'Failed to load draft.' );
		} finally {
			btn.text( label );
		}
	} );

	$( document ).on('click', '.vmsb-modal-close, .vmsb-insight-overlay', function(e) {
		if (e.target === this) $('.vmsb-insight-overlay').remove();
	});

	// Tabs logic
	$( document ).on( 'click', '.vmsb-tab, .vmsb-nav-item', function () {
		const tab = $( this );
		const name = tab.data( 'tab' );

		// Settings sidebar nav is its own layout, scope to it as before.
		if ( tab.closest( '.vmsb-settings-layout' ).length ) {
			const container = '.vmsb-settings-layout';
			$(container).find( '.vmsb-tab, .vmsb-nav-item' ).removeClass( 'is-active' );
			tab.addClass( 'is-active' );
			$(container).find( '.vmsb-panel' ).removeClass( 'is-active' );
			$(container).find( `[data-panel="${name}"]` ).addClass( 'is-active' );
			return;
		}

		// Header tabs: scope to this tab bar's own group, not the whole
		// page. Production's own tabs wrap the Content Factory panel,
		// which embeds Pipeline's entire tab group inside it - both reuse
		// "queue" as a data-tab/data-panel name. Matching against `body`
		// cleared every group sharing a name at once, including the
		// ancestor "content" panel of whichever group was just clicked,
		// which made the whole tab go blank the moment a sub-tab other
		// than the one coincidentally named "queue" was clicked.
		const tabsBar = tab.closest( '.vmsb-tabs' );
		tabsBar.find( '.vmsb-tab' ).removeClass( 'is-active' );
		tab.addClass( 'is-active' );

		const shownPanel = tabsBar.siblings( '.vmsb-panel' ).removeClass( 'is-active' )
			.filter( `[data-panel="${name}"]` ).addClass( 'is-active' );

		// A panel just went from display:none to visible. Anything inside it
		// that measured its own size on page load (the D3 knowledge-graph
		// visualizer, in particular) measured 0x0 at that point regardless
		// of what it'll actually be once shown - trigger a custom event so
		// that kind of content can (re-)measure and (re-)initialize now that
		// it has real dimensions, instead of only ever working after a
		// manual refresh click.
		shownPanel.trigger( 'vmsb:shown' );
	} );

	// AI Model Syncing
	$( document ).on( 'click', '.vmsb-sync-models', async function() {
		const btn = $(this);
		const provider = btn.data('provider');
		const label = btn.text();
		const selector = btn.closest('label').find('.vmsb-model-selector');

		btn.prop('disabled', true).text('Syncing...');

		try {
			const data = await call('sync-models', { provider: provider });
			const models = data[provider] || [];

			if ( models.length ) {
				selector.empty().append('<option value="">Select a model...</option>');
				models.forEach( m => {
					selector.append(`<option value="${esc(m.id)}">${esc(m.name)}</option>`);
				});
				selector.fadeIn();
				say( stamp() + `  Synced ${models.length} models for ${provider}.`, true );
			} else {
				alert(`No models found for ${provider}. Check your API key.`);
			}
		} catch (e) {
			alert('Sync failed: ' + e.message);
		} finally {
			btn.prop('disabled', false).text(label);
		}
	});

	// Global Sync All
	$( document ).on( 'click', '#vmsb-sync-all-models', async function() {
		const btn = $(this);
		const label = btn.text();
		btn.prop('disabled', true).text('Syncing All...');

		try {
			const data = await call('sync-models', {});
			alert('Global model synchronization complete. Refreshing page...');
			window.location.reload();
		} catch (e) {
			alert('Global sync failed: ' + e.message);
		} finally {
			btn.prop('disabled', false).text(label);
		}
	});

	// Test Image Engine
	$( document ).on( 'click', '#vmsb-test-image-engine', async function() {
		const btn = $(this);
		const label = btn.text();

		btn.prop('disabled', true).text('Testing Visuals...');
		say( stamp() + '  Testing Image Engine generation chain...', true );

		try {
			const data = await call('test-image-provider', {});
			if ( data.url ) {
				alert(`Success! Image generated via ${data.provider}. URL: ${data.url}`);
				say( stamp() + `  Visual Test Passed: Image ready at ${data.url} using ${data.provider}`, true );
				window.open( data.url, '_blank' );
			} else {
				alert(`Visual test failed: ${data.error || 'Unknown error'}`);
				say( stamp() + `  Visual Test Failed: ${data.error || 'Check logs'}`, true );
			}
		} catch (e) {
			alert('Visual test error: ' + e.message);
		} finally {
			btn.prop('disabled', false).text(label);
		}
	});

	// Update Input on Selection
	$( document ).on( 'change', '.vmsb-model-selector', function() {
		const sel = $(this);
		const targetId = sel.data('target');
		if ( targetId && sel.val() ) {
			$(`#${targetId}`).val( sel.val() );
		}
	});

	// Bot Syncing
	$( document ).on( 'click', '#vmsb-sync-bots', async function() {
		const btn = $(this);
		const label = btn.text();
		btn.prop('disabled', true).text('Syncing...');

		try {
			const res = await fetch( VMSB.root + 'aipuffer-bots', {
				method: 'POST',
				headers: { 'X-WP-Nonce': VMSB.nonce }
			});
			const data = await res.json();
			const selector = $('#vmsb-bot-selector');

			if ( data && data.length ) {
				selector.empty().append('<option value="">Select a bot...</option>');
				data.forEach( b => {
					selector.append(`<option value="${esc(b.id)}">${esc(b.name)}</option>`);
				});
				selector.fadeIn();
			} else {
				alert('No bots found. Check AI Puffer URL/Key.');
			}
		} catch (e) {
			alert('Sync failed: ' + e.message);
		} finally {
			btn.prop('disabled', false).text(label);
		}
	});

	$( document ).on( 'change', '#vmsb-bot-selector', function() {
		const val = $(this).val();
		if ( val ) $('#vmsb-aipuffer-bot-id').val(val);
	});

	// Provider Testing
	$( document ).on( 'click', '.vmsb-test-provider', async function() {
		const btn = $(this);
		const provider = btn.data('provider');
		const label = btn.text();

		btn.prop('disabled', true).text('Testing...');
		say( stamp() + `  Testing connection to ${provider}...`, true );

		try {
			const data = await call('test-provider', { provider: provider });
			if ( data.ok ) {
				alert(`Success! ${provider} is online. Response: ${data.message}`);
				say( stamp() + `  ${provider} test passed: ${data.message}`, true );
			} else {
				alert(`Test failed for ${provider}: ${data.message}`);
				say( stamp() + `  ${provider} test failed: ${data.message}`, true );
			}
		} catch (e) {
			alert('Test error: ' + e.message);
		} finally {
			btn.prop('disabled', false).text(label);
		}
	});

	/* ------------------------------------------------------------ pipeline dashboard
	 *
	 * The pipeline screen was a server-rendered table with a client-side text
	 * filter: it could not show work moving, it searched only the 200 rows that
	 * happened to be in the DOM, and when a query returned nothing it rendered
	 * a header with no body and no explanation. This owns the rows instead, so
	 * there is one source of truth for what a row looks like, every empty
	 * result says why it is empty, and a row that is being written updates
	 * itself while you watch it.
	 */
	( function pipelineDashboard() {
		const body = document.getElementById( 'vmsb-pipe-body' );
		if ( ! body ) { return; }

		const flow     = document.getElementById( 'vmsb-flow' );
		const searchEl = document.getElementById( 'vmsb-pipeline-search' );
		const stampEl  = document.getElementById( 'vmsb-pipe-stamp' );
		const liveEl   = document.getElementById( 'vmsb-pipe-live' );
		const liveTxt  = document.getElementById( 'vmsb-pipe-live-text' );
		const refreshEl= document.getElementById( 'vmsb-pipe-refresh' );

		const FAST = 6000;   // something is actively being written
		const SLOW = 30000;  // nothing moving; just stay roughly current

		let stage    = 'all';
		let query    = '';
		let timer    = null;
		let inFlight = false;
		let signature= '';
		let searchSeq= 0;

		// Why a given stage can legitimately be empty, and what to do about it.
		// A blank table that does not say this is the whole complaint.
		const EMPTY = {
			all:       [ 'Nothing in the pipeline yet', 'Plan a topic from Authority Discovery, import a list in bulk, or let the strategist queue work on its next cycle.' ],
			planned:   [ 'No planned topics', 'Planned topics are ideas the brain has captured but you have not cleared for writing yet.' ],
			approved:  [ 'Nothing approved and waiting', 'Approved topics sit here until a writer slot frees up. Approve something from Planned to fill this.' ],
			writing:   [ 'No agent is writing right now', 'This fills while a draft is being generated. Run a production batch to put something through.' ],
			drafted:   [ 'No drafts waiting on you', 'Finished drafts land here for review before they go live.' ],
			published: [ 'Nothing published yet', 'Posts appear here once they reach a live URL.' ],
			failed:    [ 'Nothing has failed', 'Good news — no run stopped on an error.' ],
			rejected:  [ 'Nothing rejected', 'Topics you discard end up here and can be re-planned.' ]
		};

		function scoreClass( n ) { return n >= 85 ? 'good' : ( n >= 70 ? 'med' : 'low' ); }

		function cell( r ) {
			let s = '<span class="vmsb-tag state-' + esc( r.status ) + '">'
				+ esc( r.status.charAt( 0 ).toUpperCase() + r.status.slice( 1 ) ) + '</span>';

			// A writing row is the only one worth watching, so say what it is
			// doing rather than just that it is busy.
			if ( r.status === 'writing' ) {
				s += '<div class="vmsb-agent-line"><span class="vmsb-dot vmsb-dot-gold"></span>'
					+ '<small>' + esc( r.agent || 'Reasoning…' ) + '</small></div>';
			}
			return s;
		}

		function rowHtml( r ) {
			let topic = '<strong>' + esc( r.title ) + '</strong>';
			if ( r.pillar ) { topic += ' <span class="vmsb-tag vmsb-tag-purple vmsb-tag-xs">Pillar</span>'; }
			if ( r.keyword ) { topic += '<div class="vmsb-pipe-kw"><code>' + esc( r.keyword ) + '</code></div>'; }
			if ( r.note )  { topic += '<div class="vmsb-editor-note">✍️ ' + esc( r.note ) + '</div>'; }
			if ( r.error ) { topic += '<div class="vmsb-error-box">⚠️ ' + esc( r.error ) + '</div>'; }

			const quality = r.quality > 0
				? '<div class="vmsb-tiny-score ' + scoreClass( r.quality ) + '"><span>' + r.quality + '</span></div>'
				: '<span class="vmsb-note">—</span>';

			let actions = '<button type="button" class="vmsb-mini-btn vmsb-pipe-note" data-id="' + r.id
				+ '" data-note="' + esc( r.note ) + '">Note</button>';

			if ( r.status === 'failed' ) {
				actions += ' <button class="vmsb-mini-btn vmsb-btn-gold" data-vmsb="retry-critique" data-id="' + r.id + '">Retry</button>';
			} else if ( r.status === 'planned' || r.status === 'suggested' ) {
				actions += ' <button class="vmsb-mini-btn" data-vmsb="bulk-action" data-body=\'{"ids":[' + r.id + '],"bulk_action":"bulk-approve"}\'>Approve</button>';
			}
			if ( r.edit_url ) {
				actions += ' <a href="' + esc( r.edit_url ) + '" class="vmsb-mini-btn vmsb-btn-ghost">Edit</a>';
			}
			if ( r.status === 'published' && r.view_url ) {
				actions += ' <a href="' + esc( r.view_url ) + '" target="_blank" rel="noopener" class="vmsb-mini-btn vmsb-btn-ghost">View</a>';
			}

			return '<tr class="state-row-' + esc( r.status ) + '">'
				+ '<td><input type="checkbox" class="vmsb-row-cb" value="' + r.id + '"></td>'
				+ '<td>' + cell( r ) + '</td>'
				+ '<td class="vmsb-pipe-topic">' + topic + '</td>'
				+ '<td>' + quality + '</td>'
				+ '<td><span class="vmsb-note">' + esc( r.age ) + ( r.age ? ' ago' : '' ) + '</span></td>'
				+ '<td><div class="vmsb-bar vmsb-mini-bar"><span style="width:'
					+ Math.max( 0, Math.min( 100, r.priority * 10 ) ) + '%"></span></div></td>'
				+ '<td class="vmsb-row-actions">' + actions + '</td>'
				+ '</tr>';
		}

		function notice( title, note, kind ) {
			return '<tr class="vmsb-pipe-notice"><td colspan="7"><div class="vmsb-empty-state'
				+ ( kind ? ' is-' + kind : '' ) + '">'
				+ '<p class="vmsb-empty-title">' + esc( title ) + '</p>'
				+ '<p class="vmsb-note">' + esc( note ) + '</p>'
				+ '</div></td></tr>';
		}

		function render( data ) {
			// Preserve any selection across a background refresh - a poll
			// firing must not silently empty a bulk selection you were part
			// way through making.
			const checked = new Set(
				Array.from( body.querySelectorAll( '.vmsb-row-cb:checked' ) ).map( cb => cb.value )
			);

			const rows = data.rows || [];
			let html;

			if ( ! rows.length ) {
				if ( query ) {
					html = notice( 'No match for “' + query + '”',
						'Nothing in ' + ( stage === 'all' ? 'the pipeline' : 'this stage' ) + ' matches that. This searches every row in the database, not just the ones on screen.' );
				} else {
					const copy = EMPTY[ stage ] || EMPTY.all;
					html = notice( copy[ 0 ], copy[ 1 ] );
				}
			} else {
				html = rows.map( rowHtml ).join( '' );
				if ( rows.length >= data.limit ) {
					html += '<tr class="vmsb-pipe-notice"><td colspan="7"><span class="vmsb-note">'
						+ 'Showing the top ' + rows.length + ' by priority. Filter by stage or search to narrow it down.'
						+ '</span></td></tr>';
				}
			}

			body.innerHTML = html;

			if ( checked.size ) {
				body.querySelectorAll( '.vmsb-row-cb' ).forEach( cb => {
					if ( checked.has( cb.value ) ) { cb.checked = true; }
				} );
			}
		}

		const POSTURE_LABEL = {
			ahead:       'Ahead of pace',
			on_track:    'On pace',
			behind:      'Behind pace',
			unreachable: 'Not reachable in this window'
		};

		function paintGoal( goal ) {
			const box = document.getElementById( 'vmsb-goal' );
			if ( ! box ) { return; }

			if ( ! goal || ! goal.active ) { box.hidden = true; return; }
			box.hidden = false;

			const posture = goal.posture || 'on_track';
			box.setAttribute( 'data-posture', posture );

			document.getElementById( 'vmsb-goal-phase' ).textContent   = 'Phase ' + goal.phase;
			document.getElementById( 'vmsb-goal-posture' ).textContent = POSTURE_LABEL[ posture ] || posture;
			document.getElementById( 'vmsb-goal-achieved' ).textContent = Number( goal.achieved || 0 ).toLocaleString();
			document.getElementById( 'vmsb-goal-target' ).textContent   = Number( goal.target || 0 ).toLocaleString() + ' sessions';
			document.getElementById( 'vmsb-goal-days' ).textContent =
				' · day ' + goal.day + ', ' + goal.days_left + ' left';

			const pct = Math.max( 0, Math.min( 100, Number( goal.pct_of_target || 0 ) ) );
			document.getElementById( 'vmsb-goal-fill' ).style.width = pct + '%';
			document.getElementById( 'vmsb-goal-note' ).textContent = goal.note || '';

			// The levers that can actually move the number inside the window.
			const focus = Array.isArray( goal.focus ) ? goal.focus : [];
			document.getElementById( 'vmsb-goal-focus' ).innerHTML = focus.length
				? '<span class="vmsb-goal-focus-title">Biggest levers</span>' + focus.map( f =>
					'<div class="vmsb-goal-lever"><span>' + esc( f.lever ) + '</span>'
					+ '<strong>+' + Number( f.upside || 0 ).toLocaleString() + '</strong></div>'
				  ).join( '' )
				: '<span class="vmsb-goal-focus-title">Biggest levers</span>'
				  + '<p class="vmsb-note">Not enough Search Console history yet to size them.</p>';
		}

		function paintCounts( stats ) {
			if ( ! flow ) { return; }
			let total = 0;
			Object.keys( stats || {} ).forEach( k => { total += stats[ k ]; } );

			flow.querySelectorAll( '[data-count]' ).forEach( el => {
				const key = el.getAttribute( 'data-count' );
				el.textContent = key === 'all' ? total : ( stats[ key ] || 0 );
			} );
		}

		function setLive( busy ) {
			if ( ! liveEl ) { return; }
			liveEl.hidden = ! busy;
			if ( busy && liveTxt ) { liveTxt.textContent = 'Agent working — live'; }
		}

		function schedule( ms ) {
			clearTimeout( timer );
			timer = setTimeout( poll, ms );
		}

		// A scheduled refresh. Skips a screen nobody is looking at - but only
		// a *refresh*: the first paint and anything the operator asked for
		// still run, or a page opened in a background tab would sit on its
		// skeleton until the tab was focused, showing nothing at all.
		function poll() { load( false ); }

		async function load( force ) {
			if ( inFlight ) { return; }

			// offsetParent covers every reason the table can be hidden,
			// including the nested case where this panel is itself active but
			// the Production tab wrapping it is not.
			if ( ! force && ( document.hidden || body.offsetParent === null ) ) {
				schedule( SLOW );
				return;
			}

			inFlight = true;
			const seq = ++searchSeq;

			try {
				const data = await call( 'pipeline-feed', { stage: stage, search: query, limit: 60 } );

				// A slower earlier request must not overwrite a newer one.
				if ( seq !== searchSeq ) { return; }

				const sig = JSON.stringify( data.rows ) + JSON.stringify( data.stats ) + JSON.stringify( data.goal );
				if ( sig !== signature ) {
					signature = sig;
					render( data );
					paintCounts( data.stats );
					paintGoal( data.goal );
				}

				const busy = ( data.rows || [] ).some( r => r.status === 'writing' )
					|| ( data.tasks || [] ).some( t => t.status === 'running' || t.status === 'retrying' );

				setLive( busy );
				if ( stampEl ) { stampEl.textContent = 'Updated ' + new Date().toLocaleTimeString(); }
				schedule( busy ? FAST : SLOW );
			} catch ( e ) {
				// Never fail to a blank table - that is indistinguishable from
				// "you have no content", which is the wrong conclusion.
				body.innerHTML = notice( 'Could not load the pipeline', e.message, 'error' );
				setLive( false );
				schedule( SLOW );
			} finally {
				inFlight = false;
			}
		}

		if ( flow ) {
			flow.addEventListener( 'click', function ( e ) {
				const chip = e.target.closest( '.vmsb-flow-chip' );
				if ( ! chip ) { return; }
				flow.querySelectorAll( '.vmsb-flow-chip' ).forEach( c => c.classList.remove( 'is-active' ) );
				chip.classList.add( 'is-active' );
				stage = chip.getAttribute( 'data-stage' ) || 'all';
				signature = '';
				load( true );
			} );
		}

		if ( searchEl ) {
			let debounce = null;
			searchEl.addEventListener( 'input', function () {
				clearTimeout( debounce );
				debounce = setTimeout( function () {
					query = searchEl.value.trim();
					signature = '';
					load( true );
				}, 300 );
			} );
		}

		if ( refreshEl ) {
			refreshEl.addEventListener( 'click', function () { signature = ''; load( true ); } );
		}

		// Editor note, without an inline onclick carrying an escaped string.
		body.addEventListener( 'click', async function ( e ) {
			const btn = e.target.closest( '.vmsb-pipe-note' );
			if ( ! btn ) { return; }

			const note = window.prompt( 'Editor note:', btn.getAttribute( 'data-note' ) || '' );
			if ( note === null ) { return; }

			try {
				await call( 'save-editor-note', { id: parseInt( btn.getAttribute( 'data-id' ), 10 ), note: note } );
				signature = '';
				load( true );
			} catch ( err ) {
				alert( 'Could not save the note: ' + err.message );
			}
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) { load( true ); }
		} );

		// Revealing the table by switching tabs should show current data, not
		// whatever was true when the page loaded. load() no-ops when the table
		// is still hidden, so this is safe to fire on any tab.
		$( document ).on( 'click', '.vmsb-tab', function () { setTimeout( function () { load( true ); }, 0 ); } );

		load( true );
	} )();

	/* ------------------------------------------------------------ setup & onboarding wizard */
	( function wizardController() {
		const wizardForm = $( '#vmsb-wizard-form' );
		if ( ! wizardForm.length ) { return; }

		const steps = $( '.vmsb-wizard-step' );
		const nodes = $( '.vmsb-step-node' );

		function goToStep( stepNum ) {
			steps.removeClass( 'is-active' ).filter( `[data-step="${stepNum}"]` ).addClass( 'is-active' );
			nodes.each( function() {
				const nodeStep = parseInt( $( this ).data( 'step' ), 10 );
				$( this ).toggleClass( 'is-active', nodeStep === stepNum );
				$( this ).toggleClass( 'is-completed', nodeStep < stepNum );
			} );

			// Update summary in step 4
			if ( stepNum === 4 ) {
				const selProvider = $( 'input[name="wizard_ai_primary"]:checked' ).val() || 'gemini';
				const bizName = $( '#wizard_biz_name' ).val() || 'Your Website';
				const autonomy = $( 'input[name="wizard_autonomy_mode"]:checked' ).val() === 'autopilot' ? '⚡ Full Autopilot' : '🛡️ Assisted Mode';

				const providerNames = {
					gemini: 'Google Gemini',
					openai: 'OpenAI (GPT-4o)',
					openrouter: 'OpenRouter',
					aipuffer: 'AI Puffer',
					ollama: 'Ollama (Local)'
				};

				$( '#summary-ai-provider' ).text( providerNames[ selProvider ] || selProvider );
				$( '#summary-biz-name' ).text( bizName );
				$( '#summary-autonomy' ).text( autonomy );
			}

			window.scrollTo( { top: 0, behavior: 'smooth' } );
		}

		// Step Next / Prev Navigation
		$( document ).on( 'click', '.vmsb-step-next', function() {
			const nextStep = parseInt( $( this ).data( 'next' ), 10 );
			// Basic validation
			if ( nextStep === 2 ) {
				const key = $( '#wizard_api_key' ).val().trim();
				const provider = $( 'input[name="wizard_ai_primary"]:checked' ).val();
				if ( provider !== 'ollama' && ! key ) {
					alert( 'Please enter an API Key for your selected AI provider.' );
					$( '#wizard_api_key' ).focus();
					return;
				}
			}
			if ( nextStep === 3 ) {
				if ( ! $( '#wizard_biz_name' ).val().trim() || ! $( '#wizard_biz_type' ).val().trim() ) {
					alert( 'Please fill in your Business Name and Niche/Type.' );
					return;
				}
			}
			goToStep( nextStep );
		} );

		$( document ).on( 'click', '.vmsb-step-prev', function() {
			const prevStep = parseInt( $( this ).data( 'prev' ), 10 );
			goToStep( prevStep );
		} );

		// Provider Radio Card Selection
		$( document ).on( 'click', '.vmsb-provider-card', function() {
			$( '.vmsb-provider-card' ).removeClass( 'is-selected' );
			$( this ).addClass( 'is-selected' );
			const provider = $( this ).find( 'input[type="radio"]' ).val();

			const hints = {
				gemini: 'Get your free Gemini API key from <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.',
				openai: 'Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform</a>.',
				openrouter: 'Get your API key from <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">OpenRouter</a>.',
				aipuffer: 'Enter your AI Puffer API key or bot token.',
				ollama: 'Enter your local Ollama URL (e.g. http://localhost:11434).'
			};

			const defaultModels = {
				gemini: 'gemini-1.5-flash',
				openai: 'gpt-4o-mini',
				openrouter: 'anthropic/claude-3.5-sonnet',
				aipuffer: '',
				ollama: 'llama3.1'
			};

			$( '#wizard-key-hint' ).html( hints[ provider ] || '' );
			$( '#wizard_model' ).val( defaultModels[ provider ] || '' );
			$( '#vmsb-wizard-test-result' ).prop( 'hidden', true );
		} );

		// Autonomy Radio Card Selection
		$( document ).on( 'click', '.vmsb-autonomy-card', function() {
			$( '.vmsb-autonomy-card' ).removeClass( 'is-selected' );
			$( this ).addClass( 'is-selected' );
		} );

		// Step 1: Live Connection Tester
		$( document ).on( 'click', '#vmsb-wizard-test-ai', async function() {
			const btn = $( this );
			const provider = $( 'input[name="wizard_ai_primary"]:checked' ).val() || 'gemini';
			const key = $( '#wizard_api_key' ).val().trim();
			const resBox = $( '#vmsb-wizard-test-result' );

			btn.prop( 'disabled', true ).text( 'Testing…' );
			resBox.prop( 'hidden', false ).removeClass( 'is-ok is-err' ).text( 'Pinging ' + provider + '…' );

			try {
				const data = await call( 'test-provider', { provider: provider, key: key } );
				if ( data.ok ) {
					resBox.addClass( 'is-ok' ).text( '✅ Connection Successful: ' + data.message );
				} else {
					resBox.addClass( 'is-err' ).text( '❌ Connection Failed: ' + data.message );
				}
			} catch ( err ) {
				resBox.addClass( 'is-err' ).text( '❌ Test error: ' + err.message );
			} finally {
				btn.prop( 'disabled', false ).text( '⚡ Test Connection' );
			}
		} );

		// Step 4: Launch SEO Brain & Calibration
		$( document ).on( 'click', '#vmsb-wizard-launch-btn', async function() {
			const launchBtn = $( this );
			launchBtn.prop( 'disabled', true ).text( 'Calibrating…' );
			$( '#vmsb-launch-actions' ).hide();
			$( '#vmsb-calibration-box' ).prop( 'hidden', false );

			const payload = {
				ai_primary:     $( 'input[name="wizard_ai_primary"]:checked' ).val() || 'gemini',
				api_key:        $( '#wizard_api_key' ).val().trim(),
				model:          $( '#wizard_model' ).val().trim(),
				business_name:  $( '#wizard_biz_name' ).val().trim(),
				business_type:  $( '#wizard_biz_type' ).val().trim(),
				description:    $( '#wizard_biz_desc' ).val().trim(),
				services:       $( '#wizard_services' ).val().trim(),
				audience:       $( '#wizard_audience' ).val().trim(),
				tone:           $( '#wizard_tone' ).val(),
				autonomy_mode:  $( 'input[name="wizard_autonomy_mode"]:checked' ).val() || 'assisted'
			};

			// Step 1 Animation
			setTimeout( () => {
				$( '#cal-step-1' ).addClass( 'is-done' ).html( '✔ Configuration & AI model credentials saved.' );
			}, 600 );

			try {
				await call( 'wizard-save', payload );

				// Step 2 Animation
				setTimeout( () => {
					$( '#cal-step-2' ).addClass( 'is-done' ).html( '✔ Internal Link graph & PageRank index mapped.' );
				}, 1300 );

				// Step 3 Animation
				setTimeout( () => {
					$( '#cal-step-3' ).addClass( 'is-done' ).html( '✔ Business DNA & Topical Silos synthesized.' );
				}, 2000 );

				// Step 4 Animation & Trigger initial discovery
				setTimeout( async () => {
					try { call( 'opportunity-scan', {} ); } catch(e){}
					$( '#cal-step-4' ).addClass( 'is-done' ).html( '✔ Initial keyword gap scan dispatched.' );

					setTimeout( () => {
						$( '#vmsb-calibration-box' ).slideUp( 300 );
						$( '#vmsb-calibration-success' ).prop( 'hidden', false ).fadeIn( 400 );
					}, 700 );
				}, 2800 );

			} catch ( err ) {
				alert( 'Setup save error: ' + err.message );
				launchBtn.prop( 'disabled', false ).text( '🚀 Retry Launch' );
				$( '#vmsb-launch-actions' ).show();
				$( '#vmsb-calibration-box' ).prop( 'hidden', true );
			}
		} );
	} )();

	/* ------------------------------------------------------------ theme toggle & command hotkey */
	$( document ).on( 'click', '#vmsb-theme-toggle', function() {
		const body = $( 'body' );
		const isLite = body.hasClass( 'vmsb-mode-lite' );
		const newMode = isLite ? 'dark' : 'lite';

		body.toggleClass( 'vmsb-mode-lite', ! isLite );
		body.toggleClass( 'vmsb-mode-dark', isLite );
		$( this ).text( isLite ? '☀️' : '🌙' );

		try {
			localStorage.setItem( 'vmsb_theme_mode', newMode );
		} catch(e) {}
	} );

	// Command Palette Trigger & Ctrl+K / Cmd+K
	$( document ).on( 'click', '#vmsb-open-commander', function() {
		const trigger = $( '#vmsb-commander-trigger' );
		if ( trigger.length ) {
			trigger.click();
		} else {
			const popup = $( '#vmsb-commander-popup' );
			if ( popup.length ) popup.fadeIn( 300 ).css( 'display', 'flex' );
		}
	} );

	$( document ).on( 'keydown', function( e ) {
		if ( ( e.metaKey || e.ctrlKey ) && e.key === 'k' ) {
			e.preventDefault();
			$( '#vmsb-open-commander' ).click();
		}
	} );

	// Expose for inline usage
	VMSB.call = call;
	VMSB.api  = call;

} )( jQuery );
