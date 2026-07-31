( function () {
	'use strict';

	if ( ! window.afcCommentCenter ) {
		return;
	}

	const required = [ 'billingday', 'paidthrough', 'nextdue', 'cutoffdate' ];
	const $ = ( selector, root ) => ( root || document ).querySelector( selector );
	const $$ = ( selector, root ) => Array.from( ( root || document ).querySelectorAll( selector ) );
	let center = null;

	function menuItem( view, icon, title, detail, badge ) {
		return `<button type="button" class="afc-comment-mega-item" data-afc-comment-view="${ view }" aria-pressed="false">
			<span class="afc-comment-mega-icon" aria-hidden="true">${ icon }</span>
			<span class="afc-comment-mega-copy"><strong>${ title }</strong><small>${ detail }</small></span>
			<span class="afc-comment-mega-badge" data-afc-comment-badge="${ view }">${ badge }</span>
		</button>`;
	}

	function overview() {
		return `<section class="afc-comment-view-panel afc-comment-overview" data-afc-comment-panel="overview">
			<div class="afc-comment-overview-hero">
				<div><span class="afc-comment-center-kicker">Advanced comments workspace</span><h2>PPP Comment Center</h2><p>Open only the task you need. Field setup, billing migration, comment repair, and safety information are separated into compact workspaces.</p></div>
				<div class="afc-comment-health" data-afc-comment-health><span></span><strong>Checking configuration…</strong></div>
			</div>
			<div class="afc-comment-overview-grid">
				<article class="afc-comment-overview-card"><header><span>⌘</span><div><small>Structure</small><h3>Field Schema</h3></div></header><strong class="afc-comment-card-metric" data-afc-schema-metric>0 / 4</strong><p>Manage recognized comment keys, types, defaults, and the live comment preview.</p><div class="afc-comment-field-chips"><code>billingDay</code><code>paidThrough</code><code>nextDue</code><code>cutoffDate</code></div><button type="button" class="btn btn-outline-primary" data-afc-comment-go="schema">Open Field Schema</button></article>
				<article class="afc-comment-overview-card"><header><span>₱</span><div><small>Migration</small><h3>Apply Billing Fields</h3></div></header><strong class="afc-comment-card-metric" data-afc-billing-metric>Not checked</strong><p>Preview all PPP accounts and apply missing billing values to selected safe users.</p><div class="afc-comment-rule-list"><span>Existing values preserved</span><span>15D exact</span><span>Schedulers untouched</span></div><button type="button" class="btn btn-primary" data-afc-comment-action="preview-billing">Preview PPP Updates</button></article>
				<article class="afc-comment-overview-card"><header><span>↵</span><div><small>Formatting</small><h3>Comment Repair</h3></div></header><strong class="afc-comment-card-metric" data-afc-repair-metric>Not checked</strong><p>Find joined fields and incorrect line endings, then rewrite only affected comments.</p><div class="afc-comment-rule-list"><span>One field per line</span><span>CRLF</span><span>Values preserved</span></div><button type="button" class="btn btn-outline-primary" data-afc-comment-action="recheck-comments">Recheck Comments</button></article>
				<article class="afc-comment-overview-card"><header><span>✓</span><div><small>Protection</small><h3>Safety & Backups</h3></div></header><strong class="afc-comment-card-metric"><span data-afc-backup-total>0</span> backups</strong><p>Review exactly what each operation changes and where original comments are stored.</p><div class="afc-comment-rule-list"><span>Latest comment refetched</span><span>Small AJAX batches</span></div><button type="button" class="btn btn-outline-secondary" data-afc-comment-go="safety">View Safety Details</button></article>
			</div>
		</section>`;
	}

	function safety() {
		return `<section class="afc-comment-view-panel afc-comment-safety" data-afc-comment-panel="safety" hidden>
			<div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Protected operations</span><h2>Safety & Backups</h2><p>What Airfiber changes, what remains untouched, and where original comments are kept.</p></div></div>
			<div class="afc-comment-safety-grid">
				<article><span class="afc-safety-icon">✓</span><h3>Migration backups</h3><strong data-afc-migration-backups>0</strong><p>Created before missing billing fields are written.</p><code>afc_comment_migration_backups</code></article>
				<article><span class="afc-safety-icon">↵</span><h3>Formatting backups</h3><strong data-afc-format-backups>0</strong><p>Created before joined or LF-only comments are converted.</p><code>afc_comment_formatting_backups</code></article>
				<article><h3>Billing migration</h3><ul><li>Adds only missing values.</li><li>Does not overwrite existing billing dates.</li><li>Refetches the latest PPP comment before updating.</li><li>Does not change profiles, sessions, or schedulers.</li></ul></article>
				<article><h3>Formatting repair</h3><ul><li>Places each recognized field on its own line.</li><li>Writes RouterOS/Winbox-friendly <code>\r\n</code>.</li><li>Preserves values and creates a backup first.</li><li>Can be rechecked after every repair.</li></ul></article>
			</div>
		</section>`;
	}

	function build( shell ) {
		const labels = afcCommentCenter.labels || {};
		const custom = Number( afcCommentCenter.customFieldCount || 0 );
		const configured = Number( afcCommentCenter.requiredCount || 0 );
		const backups = Number( afcCommentCenter.migrationBackupCount || 0 ) + Number( afcCommentCenter.formatBackupCount || 0 );
		const header = $( '.afc-comment-fields-header', shell );

		if ( header ) {
			const kicker = $( '.afc-comment-fields-kicker', header );
			const title = $( 'h1', header );
			const text = $( 'p', header );
			if ( kicker ) { kicker.textContent = 'Advanced comments'; }
			if ( title ) { title.textContent = 'PPP Comment Center'; }
			if ( text ) { text.textContent = 'Manage schema, billing migration, formatting repair, and safety from one compact workspace.'; }
		}

		shell.insertAdjacentHTML( 'beforeend', `<div class="afc-comment-center" id="afc-comment-center">
			<nav class="afc-comment-mega-menu" aria-label="Comment Center sections">
				${ menuItem( 'overview', '⌂', labels.overview || 'Overview', 'Status and shortcuts', 'Home' ) }
				${ menuItem( 'schema', '⌘', labels.schema || 'Field Schema', 'Keys, types, preview', `${ custom } custom` ) }
				${ menuItem( 'billing', '₱', labels.billing || 'Billing Fields', 'Preview and apply', `${ configured }/4 ready` ) }
				${ menuItem( 'repair', '↵', labels.repair || 'Comment Repair', 'CRLF and joined fields', 'Recheck' ) }
				${ menuItem( 'safety', '✓', labels.safety || 'Safety & Backups', 'Rules and originals', String( backups ) ) }
			</nav>
			<div class="afc-comment-center-workspace">
				${ overview() }
				<section class="afc-comment-view-panel" data-afc-comment-panel="schema" hidden><div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Comment structure</span><h2>Field Schema</h2><p>Saving the schema teaches Airfiber which fields to recognize; it does not write to MikroTik.</p></div><div class="afc-comment-schema-actions"></div></div><div data-afc-comment-schema-content></div></section>
				<section class="afc-comment-view-panel" data-afc-comment-panel="billing" hidden><div data-afc-comment-billing-content></div></section>
				<section class="afc-comment-view-panel" data-afc-comment-panel="repair" hidden><div class="afc-comment-section-heading"><div><span class="afc-comment-center-kicker">Winbox-readable comments</span><h2>Comment Repair</h2><p>Scan first, then repair only comments with joined fields or incorrect line endings.</p></div><div class="afc-comment-repair-actions"></div></div><div class="afc-comment-repair-status"></div><div class="afc-comment-repair-info"><div><strong>Expected format</strong><span>One key:value field per line</span></div><div><strong>Line ending</strong><span>CRLF (\r\n)</span></div><div><strong>Data changes</strong><span>Formatting only</span></div></div></section>
				${ safety() }
			</div>
		</div>` );

		center = document.getElementById( 'afc-comment-center' );
		bind();
		moveTools();
		activate( sessionStorage.getItem( 'afcCommentCenterView' ) || 'overview', false );
	}

	function moveTools() {
		if ( ! center ) { return; }
		const shell = center.closest( '.afc-comment-fields-shell' );
		const moves = [
			[ $( '.afc-comment-fields-layout', shell ), $( '[data-afc-comment-schema-content]', center ) ],
			[ document.getElementById( 'afc-comment-fields-save' ), $( '.afc-comment-schema-actions', center ) ],
			[ document.getElementById( 'afc-comment-fields-notice' ), $( '[data-afc-comment-schema-content]', center ), true ],
			[ document.getElementById( 'afc-comment-migration' ), $( '[data-afc-comment-billing-content]', center ) ],
			[ $( '.afc-comment-formatting-actions', shell ), $( '.afc-comment-repair-actions', center ) ],
			[ document.getElementById( 'afc-comment-formatting-notice' ), $( '.afc-comment-repair-status', center ) ]
		];

		moves.forEach( function ( item ) {
			const source = item[0];
			const target = item[1];
			if ( ! source || ! target || source.parentNode === target ) { return; }
			if ( item[2] ) { target.insertBefore( source, target.firstChild ); }
			else { target.appendChild( source ); }
		} );
	}

	function activate( view, scroll ) {
		if ( ! center || ! $( `[data-afc-comment-panel="${ view }"]`, center ) ) { view = 'overview'; }
		sessionStorage.setItem( 'afcCommentCenterView', view );
		$$( '[data-afc-comment-view]', center ).forEach( function ( button ) {
			const active = button.dataset.afcCommentView === view;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
		$$( '[data-afc-comment-panel]', center ).forEach( panel => { panel.hidden = panel.dataset.afcCommentPanel !== view; } );
		moveTools();
		updateStatus();
		if ( scroll ) { $( '.afc-comment-mega-menu', center ).scrollIntoView( { behavior: 'smooth', block: 'start' } ); }
	}

	function action( name ) {
		if ( 'preview-billing' === name ) {
			activate( 'billing', true );
			setTimeout( function () {
				const button = $( '[data-afc-migration-preview]', center );
				if ( button && ! button.disabled ) { button.click(); }
			}, 100 );
		}
		if ( 'recheck-comments' === name ) {
			activate( 'repair', true );
			setTimeout( function () {
				const button = document.getElementById( 'afc-recheck-comment-lines' );
				if ( button && ! button.disabled ) { button.click(); }
			}, 100 );
		}
	}

	function bind() {
		center.addEventListener( 'click', function ( event ) {
			const tab = event.target.closest( '[data-afc-comment-view]' );
			const go = event.target.closest( '[data-afc-comment-go]' );
			const run = event.target.closest( '[data-afc-comment-action]' );
			if ( tab ) { activate( tab.dataset.afcCommentView, true ); }
			else if ( go ) { activate( go.dataset.afcCommentGo, true ); }
			else if ( run ) { action( run.dataset.afcCommentAction ); }
		} );
	}

	function schemaStatus() {
		const keys = $$( '.afc-comment-field-row.is-custom .afc-comment-field-key input' ).map( input => String( input.value || '' ).trim().toLowerCase() );
		return { custom: keys.filter( Boolean ).length, configured: required.filter( key => keys.includes( key ) ).length };
	}

	function migrationStatus() {
		const summary = $( '.afc-comment-migration-summary' );
		const results = summary ? summary.closest( '[data-afc-migration-results]' ) : null;
		if ( ! summary || ( results && results.hidden ) ) { return null; }
		const number = selector => Number( ( $( selector, summary ) || {} ).textContent || 0 );
		return { safe: number( '.is-safe strong' ), review: number( '.is-review strong' ) };
	}

	function repairStatus() {
		const fix = document.getElementById( 'afc-fix-comment-lines' );
		const notice = document.getElementById( 'afc-comment-formatting-notice' );
		const match = fix ? String( fix.textContent || '' ).match( /Fix\s+(\d+)\s+Comments/i ) : null;
		if ( match ) { return Number( match[1] ); }
		return notice && notice.classList.contains( 'is-success' ) ? 0 : null;
	}

	function setText( selector, text ) {
		const element = $( selector, center );
		if ( element && element.textContent !== String( text ) ) { element.textContent = text; }
	}

	function updateStatus() {
		if ( ! center ) { return; }
		moveTools();
		const schema = schemaStatus();
		const migration = migrationStatus();
		const repair = repairStatus();
		const migrationBackups = Number( afcCommentCenter.migrationBackupCount || 0 );
		const formatBackups = Number( afcCommentCenter.formatBackupCount || 0 );
		const totalBackups = migrationBackups + formatBackups;

		setText( '[data-afc-schema-metric]', `${ schema.configured } / 4 required` );
		setText( '[data-afc-comment-badge="schema"]', `${ schema.custom } custom` );
		setText( '[data-afc-billing-metric]', migration ? `${ migration.safe } ready · ${ migration.review } review` : 'Not checked' );
		setText( '[data-afc-comment-badge="billing"]', migration ? `${ migration.safe } ready` : `${ schema.configured }/4 ready` );
		setText( '[data-afc-repair-metric]', null === repair ? 'Not checked' : ( repair ? `${ repair } need repair` : 'All comments clean' ) );
		setText( '[data-afc-comment-badge="repair"]', null === repair ? 'Recheck' : ( repair ? `${ repair } repair` : 'Clean' ) );
		$$( '[data-afc-backup-total]', center ).forEach( element => { if ( element.textContent !== String( totalBackups ) ) { element.textContent = totalBackups; } } );
		setText( '[data-afc-migration-backups]', migrationBackups );
		setText( '[data-afc-format-backups]', formatBackups );
		setText( '[data-afc-comment-badge="safety"]', totalBackups );

		const health = $( '[data-afc-comment-health]', center );
		if ( health ) {
			const ready = 4 === schema.configured;
			health.classList.toggle( 'is-ready', ready );
			health.classList.toggle( 'is-warning', ! ready );
			const label = $( 'strong', health );
			const missing = 4 - schema.configured;
			const text = ready ? 'Billing schema is ready' : `${ missing } required field${ 1 === missing ? '' : 's' } missing`;
			if ( label.textContent !== text ) { label.textContent = text; }
		}
	}

	function initialize() {
		const panel = $( '[data-afc-panel="comment-fields"]' );
		const shell = panel ? $( '.afc-comment-fields-shell', panel ) : null;
		if ( ! shell ) { return false; }
		if ( ! center ) { build( shell ); }
		moveTools();
		updateStatus();
		return true;
	}

	function boot() {
		initialize();
		const observer = new MutationObserver( function () {
			if ( ! center ) { initialize(); }
			else { moveTools(); }
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		setTimeout( function () { observer.disconnect(); }, 15000 );
		setInterval( updateStatus, 1200 );
	}

	if ( 'loading' === document.readyState ) { document.addEventListener( 'DOMContentLoaded', boot ); }
	else { boot(); }
}() );
