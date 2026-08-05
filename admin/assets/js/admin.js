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
		if ( route === 'produce' )    { return 'Written. Open it: ' + data.edit_url; }
		if ( route === 'understand' ) { return 'Business profile updated: ' + ( data.type || 'no type detected' ); }
		if ( route === 'silo-map' )   { return ( data.silos || [] ).length + ' silos mapped.'; }
		if ( route === 'silo-push-gaps' ) { return data.pushed + ' topics pushed to the content plan.'; }
		if ( route === 'index-vectors' || route === 'rebuild-index' ) {
			return data.indexed + ' indexed, ' + data.skipped + ' already current, ' + data.failed + ' failed. ' + data.remaining + ' remaining.';
		}
		if ( route === 'measure-outcomes' ) {
			return data.short + ' measured at 7 days, ' + data.long + ' at 28 days.';
		}
		if ( route === 'competitor-scan' )    { return data.checked + ' competitors checked, ' + data.gaps + ' gaps found.'; }
		if ( route === 'competitor-add' )     { return 'Added.'; }
		if ( route === 'competitor-duel' ) {
			if ( data.gaps ) {
				let msg = 'Hijack Opportunities Found:';
				data.gaps.forEach( g => { msg += '\n· ' + g.topic + ' — Strategy: ' + g.hijack_angle; } );
				return msg;
			}
			return 'Verdict: ' + ( data.verdict || 'unknown' ) + '. ' + ( data.recommended_additions || [] ).length + ' additions suggested.';
		}
		if ( route === 'backlink-discover' )  { return data.found + ' prospects found.'; }
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
		if ( route === 'test-provider' ) { return data.ok ? 'Success: ' + data.message : 'Failed: ' + data.message; }
		if ( route === 'webhook-test' ) { return ( data.ok ? 'Success: ' : 'Failed: ' ) + data.message; }
		if ( route === 'health-reset' ) { return 'Circuit breakers reset.'; }
		if ( route === 'test-image-provider' ) { return data.ok ? 'Success: Image generated with ' + data.provider : 'Failed: ' + data.error; }
		if ( route === 'pending-approve' ) { return 'Draft approved and published.'; }
		if ( route === 'pending-reject' )  { return 'Draft discarded — issue reopened.'; }
		return JSON.stringify( data, null, 2 );
	}

	function reloadIfNeeded( route ) {
		const routes = [ 'scan', 'god-fix', 'god-fix-90', 'dismiss', 'bulk-issue-action', 'import-topics', 'pull-bulk-topics', 'improve-post', 'keyword-dismiss', 'keyword-merge-cluster', 'ctr-start', 'plan', 'research', 'silo-map', 'silo-push-gaps', 'produce', 'rebuild-index', 'measure-outcomes', 'competitor-add', 'competitor-remove', 'competitor-scan', 'backlink-discover', 'backlink-shield', 'programmatic-build', 'roi-scan', 'ctr-conclude', 'news-scout', 'traffic-forecast', 'global-expand', 'health-check', 'market-assess', 'tasks-process', 'health-reset', 'niche-plan', 'clear-rejected', 'replan-rejected', 'approve-all', 'bulk-action', 'taxonomy-audit', 'taxonomy-propose', 'pending-approve', 'pending-reject' ];
		if ( routes.indexOf( route ) !== -1 ) {
			setTimeout( () => window.location.reload(), 1600 );
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

		function appendPopupMsg( text, type ) {
			const html = `
				<div class="vmsb-chat-msg vmsb-msg-${type}">
					<div class="vmsb-chat-bubble">${esc(text)}</div>
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

		// Handle both header tabs and settings sidebar nav
		const container = tab.closest('.vmsb-settings-layout').length ? '.vmsb-settings-layout' : 'body';

		$(container).find( '.vmsb-tab, .vmsb-nav-item' ).removeClass( 'is-active' );
		tab.addClass( 'is-active' );

		$(container).find( '.vmsb-panel' ).removeClass( 'is-active' );
		$(container).find( `[data-panel="${name}"]` ).addClass( 'is-active' );
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

} )( jQuery );
