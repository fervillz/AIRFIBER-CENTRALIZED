( function () {
	'use strict';

	if ( ! window.afcCommentCenter ) {
		return;
	}

	const requiredKeys = [ 'billingday', 'paidthrough', 'nextdue', 'cutoffdate' ];
	let center = null;
	let observer = null;

	function q( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qa( selector, root ) {
		return Array.from( ( root || document ).querySelectorAll( selector ) );
	}

	function icon( name ) {
		const paths = {
			overview: '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
			schema: '<path d="M8 4h8M9 4v4h6V4M6 9h12v11H6zM9 13h6M9 16h4"/>',
			billing: '<path d="M5 3h14v18l-3-2-4 2-4-2-3 2zM8 8h8M8 12h8M8 16h5"/>',
			repair: '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-3-3z"/>',
			safety: '<path d="M12 3l8 4v5c0 5-3.4 8-8 10-4.6-2-8-5-8-10V7zM9 12l2 2 4-4"/>'
		};
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' + ( paths[ name ] || paths.overview ) + '</svg>';
	}

	function menuButton( view, title, description, badge ) {
		return '<button type="button" class="afc-comment-mega-item" data-afc-comment-view="' + view + '" aria-pressed="false">' +
			'<span class="afc-comment-mega-icon">' + icon( view ) + '</span>' +
			'<span class="afc-comment-mega-copy"><strong>' + title + '</strong><small>' + description + '</small></span>' +
			'<span class="afc-comment-mega-badge" data-afc-comment-badge="' + view + '">' + badge + '</span>' +
		'</button>';
	}

	function overviewMarkup() {
		return '' +
			'<section class="afc-comment-view-panel afc-comment-overview" data-afc-comment-panel="overview">' +
				'<div class="afc-comment-overview-hero">' +
					'<div><span class="afc-comment-center-kicker">Advanced comments workspace</span><h2>Manage PPP comments without scrolling through one long page</h2><p>Choose a task above or use the shortcuts below. Billing migration, line repair, schema editing, and safety information now live in separate workspaces.</p></div>' +
					'<div class="afc-comment-health" data-afc-comment-health><span></span><strong>Checking configuration…</strong></div>' +
				'</div>' +
				'<div class="afc-comment-overview-grid">' +
					'<article class="afc-comment-overview-card is-schema">' +
						'<header><span>' + icon( 'schema' ) + '</span><div><small>Structure</small><h3>Field Schema</h3></div></header>' +
						'<strong class="afc-comment-card-metric" data-afc-schema-metric>0 / 4</strong>' +
						'<p>The four billing fields must be configured before applying calculations.</p>' +
						'<div class="afc-comment-field-chips"><code>billingDay</code><code>paidThrough</code><code>nextDue</code><code>cutoffDate</code></div>' +
						'<button type="button" class="btn btn-outline-primary" data-afc-comment-go="schema">Open Field Schema</button>' +
					'</article>' +
					'<article class="afc-comment-overview-card is-billing">' +
						'<header><span>' + icon( 'billing' ) + '</span><div><small>Migration</small><h3>Apply Billing Fields</h3></div></header>' +
						'<strong class="afc-comment-card-metric" data-afc-billing-metric>Not checked</strong>' +
						'<p>Preview every PPP user, select safe accounts, and add only missing billing values.</p>' +
						'<div class="afc-comment-rule-list"><span>Monthly: nearest due date</span><span>15D: exact, no grace</span><span>Schedulers untouched</span></div>' +
						'<button type="button" class="btn btn-primary" data-afc-comment-action="preview-billing">Preview PPP Updates</button>' +
					'</article>' +
					'<article class="afc-comment-overview-card is-repair">' +
						'<header><span>' + icon( 'repair' ) + '</span><div><small>Formatting</small><h3>Comment Repair</h3></div></header>' +
						'<strong class="afc-comment-card-metric" data-afc-repair-metric>Not checked</strong>' +
						'<p>Find joined fields and LF-only comments, then rewrite them using Winbox-friendly CRLF.</p>' +
						'<div class="afc-comment-rule-list"><span>One field per line</span><span>Uses CRLF</span><span>Values preserved</span></div>' +
						'<button type="button" class="btn btn-outline-primary" data-afc-comment-action="recheck-comments">Recheck Comments</button>' +
					'</article>' +
					'<article class="afc-comment-overview-card is-safety">' +
						'<header><span>' + icon( 'safety' ) + '</span><div><small>Protection</small><h3>Safety & Backups</h3></div></header>' +
						'<strong class="afc-comment-card-metric"><span data-afc-backup-total>0</span> backups</strong>' +
						'<p>Original comments are stored before migrations or formatting repairs are written to MikroTik.</p>' +
						'<div class="afc-comment-rule-list"><span>Latest comment refetched</span><span>Existing billing values preserved</span><span>Batch processing</span></div>' +
						'<button type="button" class="btn btn-outline-secondary" data-afc-comment-go="safety">View Safety Details</button>' +
					'</article>' +
				'</div>' +
			'</section>';
	}

	function safetyMarkup() {
		return '' +
			'<section class="afc-comment-view-panel afc-comment-safety" data-afc-comment-panel="safety" hidden>' +
				'<div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Protected operations</span><h2>Safety & Backups</h2><p>What Airfiber changes, what it preserves, and where the original comments are stored.</p></div></div>' +
				'<div class="afc-comment-safety-grid">' +
					'<article><span class="afc-safety-icon">' + icon( 'safety' ) + '</span><h3>Migration backups</h3><strong data-afc-migration-backups>0</strong><p>Created before missing billing fields are applied.</p><code>afc_comment_migration_backups</code></article>' +
					'<article><span class="afc-safety-icon">' + icon( 'repair' ) + '</span><h3>Formatting backups</h3><strong data-afc-format-backups>0</strong><p>Created before joined or LF-only comments are converted to CRLF.</p><code>afc_comment_formatting_backups</code></article>' +
					'<article class="is-wide"><h3>Billing migration rules</h3><ul><li>Only missing values are added.</li><li>Existing non-empty billing values are not overwritten.</li><li>The latest PPP comment is fetched again before each update.</li><li>Profiles, active sessions, and MikroTik schedulers are not changed.</li></ul></article>' +
					'<article class="is-wide"><h3>Comment formatting rules</h3><ul><li>Every recognized field is placed on its own line.</li><li>RouterOS output uses <code>\\r\\n</code> line endings.</li><li>Field values and order are preserved as much as possible.</li><li>Repairs run in small AJAX batches and can be rechecked afterward.</li></ul></article>' +
				'</div>' +
			'</section>';
	}

	function createCenter( shell ) {
		const labels = afcCommentCenter.labels || {};
		const customCount = Number( afcCommentCenter.customFieldCount || 0 );
		const requiredCount = Number( afcCommentCenter.requiredCount || 0 );
		const markup = '' +
			'<div class="afc-comment-center" id="afc-comment-center">' +
				'<nav class="afc-comment-mega-menu" aria-label="Comment Center sections">' +
					menuButton( 'overview', labels.overview || 'Overview', 'Status and shortcuts', 'Home' ) +
					menuButton( 'schema', labels.schema || 'Field Schema', 'Keys, types, preview', customCount + ' custom' ) +
					menuButton( 'billing', labels.billing || 'Billing Fields', 'Preview and apply', requiredCount + '/4 ready' ) +
					menuButton( 'repair', labels.repair || 'Comment Repair', 'CRLF and joined fields', 'Recheck' ) +
					menuButton( 'safety', labels.safety || 'Safety & Backups', 'Rules and saved originals', String( Number( afcCommentCenter.migrationBackupCount || 0 ) + Number( afcCommentCenter.formatBackupCount || 0 ) ) ) +
				'</nav>' +
				'<div class="afc-comment-center-workspace">' +
					overviewMarkup() +
					'<section class="afc-comment-view-panel" data-afc-comment-panel="schema" hidden><div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Comment structure</span><h2>Field Schema</h2><p>Define which key:value fields Airfiber recognizes. Saving the schema does not write to MikroTik.</p></div><div class="afc-comment-schema-actions"></div></div><div data-afc-comment-schema-content></div></section>' +
					'<section class="afc-comment-view-panel" data-afc-comment-panel="billing" hidden><div data-afc-comment-billing-content></div></section>' +
					'<section class="afc-comment-view-panel" data-afc-comment-panel="repair" hidden><div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Winbox-readable comments</span><h2>Comment Repair</h2><p>Scan for joined fields or incorrect line endings, then repair only the affected PPP comments.</p></div><div class="afc-comment-repair-actions"></div></div><div class="afc-comment-repair-status"></div><div class="afc-comment-repair-info"><div><strong>Expected format</strong><span>One key:value field per line</span></div><div><strong>Line ending</strong><span>CRLF (\\r\\n)</span></div><div><strong>Data changes</strong><span>Formatting only</span></div></div></section>' +
					safetyMarkup() +
				'</div>' +
			'</div>';

		const header = q( '.afc-comment-fields-header', shell );
		if ( header ) {
			const kicker = q( '.afc-comment-fields-kicker', header );
			const title = q( 'h1', header );
			const description = q( 'p', header );
			if ( kicker ) { kicker.textContent = 'Advanced comments'; }
			if ( title ) { title.textContent = 'PPP Comment Center'; }
			if ( description ) { description.textContent = 'Manage comment fields, billing migration, formatting repair, and safety tools from one compact workspace.'; }
		}

		shell.insertAdjacentHTML( 'beforeend', markup );
		center = document.getElementById( 'afc-comment-center' );
		bindCenterEvents();
		moveExistingContent();
		updateStatus();
		activateView( sessionStorage.getItem( 'afcCommentCenterView' ) || 'overview', false );
	}

	function moveExistingContent() {
		if ( ! center ) {
			return;
		}

		const shell = center.closest( '.afc-comment-fields-shell' );
		const schemaTarget = q( '[data-afc-comment-schema-content]', center );
		const schemaActions = q( '.afc-comment-schema-actions', center );
		const billingTarget = q( '[data-afc-comment-billing-content]', center );
		const repairActions = q( '.afc-comment-repair-actions', center );
		const repairStatus = q( '.afc-comment-repair-status', center );
		const layout = q( '.afc-comment-fields-layout', shell );
		const save = document.getElementById( 'afc-comment-fields-save' );
		const fieldNotice = document.getElementById( 'afc-comment-fields-notice' );
		const migration = document.getElementById( 'afc-comment-migration' );
		const formattingActions = q( '.afc-comment-formatting-actions', shell );
		const formattingNotice = document.getElementById( 'afc-comment-formatting-notice' );

		if ( layout && schemaTarget && layout.parentNode !== schemaTarget ) {
			schemaTarget.appendChild( layout );
		}
		if ( save && schemaActions && save.parentNode !== schemaActions ) {
			schemaActions.appendChild( save );
		}
		if ( fieldNotice && schemaTarget && fieldNotice.parentNode !== schemaTarget ) {
			schemaTarget.insertBefore( fieldNotice, schemaTarget.firstChild );
		}
		if ( migration && billingTarget && migration.parentNode !== billingTarget ) {
			billingTarget.appendChild( migration );
		}
		if ( formattingActions && repairActions && formattingActions.parentNode !== repairActions ) {
			repairActions.appendChild( formattingActions );
		}
		if ( formattingNotice && repairStatus && formattingNotice.parentNode !== repairStatus ) {
			repairStatus.appendChild( formattingNotice );
		}
	}

	function activateView( view, shouldScroll ) {
		if ( ! center || ! q( '[data-afc-comment-panel="' + view + '"]', center ) ) {
			view = 'overview';
		}
		sessionStorage.setItem( 'afcCommentCenterView', view );

		qa( '[data-afc-comment-view]', center ).forEach( function ( button ) {
			const active = button.dataset.afcCommentView === view;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
		qa( '[data-afc-comment-panel]', center ).forEach( function ( panel ) {
			panel.hidden = panel.dataset.afcCommentPanel !== view;
		} );

		moveExistingContent();
		updateStatus();
		if ( shouldScroll ) {
			const menu = q( '.afc-comment-mega-menu', center );
			if ( menu ) {
				menu.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}
	}

	function runAction( action ) {
		if ( 'preview-billing' === action ) {
			activateView( 'billing', true );
			window.setTimeout( function () {
				const button = q( '[data-afc-migration-preview]', center );
				if ( button && ! button.disabled ) { button.click(); }
			}, 120 );
		}
		if ( 'recheck-comments' === action ) {
			activateView( 'repair', true );
			window.setTimeout( function () {
				const button = document.getElementById( 'afc-recheck-comment-lines' );
				if ( button && ! button.disabled ) { button.click(); }
			}, 120 );
		}
	}

	function bindCenterEvents() {
		center.addEventListener( 'click', function ( event ) {
			const viewButton = event.target.closest( '[data-afc-comment-view]' );
			if ( viewButton ) {
				activateView( viewButton.dataset.afcCommentView, true );
				return;
			}
			const go = event.target.closest( '[data-afc-comment-go]' );
			if ( go ) {
				activateView( go.dataset.afcCommentGo, true );
				return;
			}
			const action = event.target.closest( '[data-afc-comment-action]' );
			if ( action ) {
				runAction( action.dataset.afcCommentAction );
			}
		} );

		center.addEventListener( 'keydown', function ( event ) {
			const current = event.target.closest( '[data-afc-comment-view]' );
			if ( ! current || ! [ 'ArrowLeft', 'ArrowRight' ].includes( event.key ) ) {
				return;
			}
			const buttons = qa( '[data-afc-comment-view]', center );
			const index = buttons.indexOf( current );
			const direction = 'ArrowRight' === event.key ? 1 : -1;
			const next = buttons[ ( index + direction + buttons.length ) % buttons.length ];
			event.preventDefault();
			next.focus();
			next.click();
		} );
	}

	function schemaState() {
		const keys = qa( '.afc-comment-field-row.is-custom .afc-comment-field-key input' ).map( function ( input ) {
			return String( input.value || '' ).trim().toLowerCase();
		} );
		const configured = requiredKeys.filter( function ( key ) { return keys.includes( key ); } ).length;
		return { configured: configured, custom: keys.filter( Boolean ).length };
	}

	function migrationState() {
		const summary = q( '.afc-comment-migration-summary' );
		if ( ! summary || summary.closest( '[hidden]' ) ) {
			return null;
		}
		const safe = q( '.is-safe strong', summary );
		const review = q( '.is-review strong', summary );
		const complete = q( '.is-complete strong', summary );
		return {
			safe: safe ? Number( safe.textContent || 0 ) : 0,
			review: review ? Number( review.textContent || 0 ) : 0,
			complete: complete ? Number( complete.textContent || 0 ) : 0
		};
	}

	function repairState() {
		const fix = document.getElementById( 'afc-fix-comment-lines' );
		const notice = document.getElementById( 'afc-comment-formatting-notice' );
		if ( fix && ! fix.disabled ) {
			const match = String( fix.textContent || '' ).match( /Fix\s+(\d+)\s+Comments/i );
			if ( match ) { return Number( match[1] ); }
		}
		if ( notice && notice.classList.contains( 'is-success' ) ) {
			return 0;
		}
		return null;
	}

	function updateStatus() {
		if ( ! center ) {
			return;
		}
		moveExistingContent();
		const schema = schemaState();
		const migration = migrationState();
		const repair = repairState();
		const migrationBackups = Number( afcCommentCenter.migrationBackupCount || 0 );
		const formatBackups = Number( afcCommentCenter.formatBackupCount || 0 );
		const backupTotal = migrationBackups + formatBackups;

		const schemaMetric = q( '[data-afc-schema-metric]', center );
		const schemaBadge = q( '[data-afc-comment-badge="schema"]', center );
		const billingMetric = q( '[data-afc-billing-metric]', center );
		const billingBadge = q( '[data-afc-comment-badge="billing"]', center );
		const repairMetric = q( '[data-afc-repair-metric]', center );
		const repairBadge = q( '[data-afc-comment-badge="repair"]', center );
		const health = q( '[data-afc-comment-health]', center );

		if ( schemaMetric ) { schemaMetric.textContent = schema.configured + ' / 4 required'; }
		if ( schemaBadge ) { schemaBadge.textContent = schema.custom + ' custom'; }
		if ( migration ) {
			if ( billingMetric ) { billingMetric.textContent = migration.safe + ' ready · ' + migration.review + ' review'; }
			if ( billingBadge ) { billingBadge.textContent = migration.safe + ' ready'; }
		} else {
			if ( billingMetric ) { billingMetric.textContent = 'Not checked'; }
			if ( billingBadge ) { billingBadge.textContent = schema.configured + '/4 ready'; }
		}
		if ( null !== repair ) {
			if ( repairMetric ) { repairMetric.textContent = repair ? repair + ' need repair' : 'All comments clean'; }
			if ( repairBadge ) { repairBadge.textContent = repair ? repair + ' repair' : 'Clean'; }
		} else {
			if ( repairMetric ) { repairMetric.textContent = 'Not checked'; }
			if ( repairBadge ) { repairBadge.textContent = 'Recheck'; }
		}

		qa( '[data-afc-backup-total]', center ).forEach( function ( item ) { item.textContent = backupTotal; } );
		qa( '[data-afc-migration-backups]', center ).forEach( function ( item ) { item.textContent = migrationBackups; } );
		qa( '[data-afc-format-backups]', center ).forEach( function ( item ) { item.textContent = formatBackups; } );
		const safetyBadge = q( '[data-afc-comment-badge="safety"]', center );
		if ( safetyBadge ) { safetyBadge.textContent = String( backupTotal ); }

		if ( health ) {
			const ready = 4 === schema.configured;
			health.classList.toggle( 'is-ready', ready );
			health.classList.toggle( 'is-warning', ! ready );
			const text = q( 'strong', health );
			if ( text ) { text.textContent = ready ? 'Billing schema is ready' : ( 4 - schema.configured ) + ' required field' + ( 1 === 4 - schema.configured ? '' : 's' ) + ' missing'; }
		}
	}

	function initialize() {
		const panel = q( '[data-afc-panel="comment-fields"]' );
		const shell = panel ? q( '.afc-comment-fields-shell', panel ) : null;
		if ( ! shell ) {
			return false;
		}
		if ( ! center ) {
			createCenter( shell );
		}
		moveExistingContent();
		updateStatus();
		return true;
	}

	function boot() {
		initialize();
		observer = new MutationObserver( initialize );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setInterval( updateStatus, 1200 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
