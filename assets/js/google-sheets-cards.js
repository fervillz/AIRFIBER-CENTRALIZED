( function () {
	'use strict';

	let frame = 0;
	let settingsDialog = null;
	let library = null;
	let cardObserver = null;

	function sheetIcon() {
		return '<svg class="afc-google-sheet-svg" viewBox="0 0 36 36" aria-hidden="true"><path fill="currentColor" d="M9 3h13l7 7v23H9a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Z"/><path fill="#fff" opacity=".9" d="M22 3v8h7z"/><path fill="#fff" d="M11 15h13v12H11zm2 2v2h3v-2zm5 0v2h4v-2zm-5 4v2h3v-2zm5 0v2h4v-2zm-5 4v1h3v-1zm5 0v1h4v-1z"/></svg>';
	}

	function setText( node, value ) {
		if ( node && node.textContent !== value ) node.textContent = value;
	}

	function setAttribute( node, name, value ) {
		if ( node && node.getAttribute( name ) !== value ) node.setAttribute( name, value );
	}

	function panel() {
		return document.querySelector( '[data-afc-panel="integrations"]' );
	}

	function openSettings() {
		if ( ! settingsDialog ) return;
		if ( settingsDialog.open ) settingsDialog.close();
		settingsDialog.showModal();
	}

	function connected() {
		const state = document.querySelector( '[data-afc-google-status]' );
		return Boolean( state && /connected/i.test( state.textContent || '' ) && ! /not connected/i.test( state.textContent || '' ) );
	}

	function sheetId() {
		const input = document.getElementById( 'afc-google-spreadsheet-id' );
		return input ? String( input.value || '' ).trim() : '';
	}

	function syncLibrary() {
		if ( ! library ) return;
		const isConnected = connected();
		const titleNode = document.querySelector( '[data-afc-google-title]' );
		const lastNode = document.querySelector( '[data-afc-google-last]' );
		const emailNode = document.querySelector( '[data-afc-google-email]' );
		const card = library.querySelector( '[data-afc-sheet-card]' );
		const title = library.querySelector( '[data-afc-sheet-card-title]' );
		const meta = library.querySelector( '[data-afc-sheet-card-meta]' );
		const last = library.querySelector( '[data-afc-sheet-card-last]' );
		const view = library.querySelector( '[data-afc-sheet-view]' );
		const dot = library.querySelector( '[data-afc-sheet-dot]' );
		const add = library.querySelector( '[data-afc-sheet-add]' );
		const cardTitle = isConnected && titleNode && titleNode.textContent && titleNode.textContent !== 'Not tested' ? titleNode.textContent : 'Primary Google Sheet';
		const cardMeta = isConnected ? ( emailNode && emailNode.textContent !== '—' ? emailNode.textContent : 'Connected to Airfiber' ) : 'Needs setup or connection test';
		const cardLast = isConnected ? ( lastNode && lastNode.textContent ? 'Last test · ' + lastNode.textContent : 'Connected' ) : 'Not connected';

		if ( card ) card.classList.toggle( 'is-connected', isConnected );
		setText( title, cardTitle );
		setText( meta, cardMeta );
		setText( last, cardLast );
		if ( view && view.hidden === isConnected ) view.hidden = ! isConnected;
		setAttribute( dot, 'aria-label', isConnected ? 'Connected' : 'Not connected' );
		if ( add ) {
			add.classList.toggle( 'is-future', isConnected );
			setText( add.querySelector( 'small' ), isConnected ? 'More sheets coming next' : 'Connect your first Sheet' );
		}
	}

	function updateSidebarIcon() {
		const item = document.querySelector( '.afc-workspace-menu-item[data-afc-ws-panel="integrations"]' );
		if ( ! item ) return;
		const icon = item.querySelector( ':scope > span' );
		const title = item.querySelector( 'b strong' );
		const small = item.querySelector( 'b small' );
		if ( icon && ! icon.querySelector( '.afc-google-sheet-svg' ) ) icon.innerHTML = sheetIcon();
		setText( title, 'Google Sheets' );
		setText( small, 'Reporting & sync' );
		item.classList.add( 'afc-google-sheets-menu-item' );
	}

	function toast( message ) {
		if ( ! library ) return;
		let node = library.querySelector( '.afc-sheet-library-note' );
		if ( ! node ) {
			node = document.createElement( 'div' );
			node.className = 'afc-sheet-library-note';
			library.prepend( node );
		}
		setText( node, message );
		node.hidden = false;
		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( function () { node.hidden = true; }, 4200 );
	}

	function watchGoogleCard( googleCard ) {
		if ( cardObserver ) cardObserver.disconnect();
		cardObserver = new MutationObserver( syncLibrary );
		cardObserver.observe( googleCard, { childList: true, subtree: true, characterData: true, attributes: true } );
	}

	function build() {
		frame = 0;
		updateSidebarIcon();
		const root = panel();
		if ( ! root ) return;
		const page = root.querySelector( '.afc-integrations-page' );
		const googleCard = root.querySelector( '.afc-google-card' );
		if ( ! page || ! googleCard ) return;

		if ( root.querySelector( '.afc-sheet-library' ) ) {
			library = root.querySelector( '.afc-sheet-library' );
			if ( ! settingsDialog || ! settingsDialog.isConnected ) settingsDialog = root.querySelector( '.afc-google-sheet-settings-dialog' );
			syncLibrary();
			return;
		}

		const oldHeader = page.querySelector( '.afc-integrations-header' );
		if ( oldHeader ) oldHeader.hidden = true;
		const oldGrid = page.querySelector( '.afc-integrations-grid' );
		if ( oldGrid ) oldGrid.classList.add( 'afc-integrations-grid-legacy' );
		const messenger = page.querySelector( '.afc-messenger-card' );
		if ( messenger ) messenger.hidden = true;

		library = document.createElement( 'section' );
		library.className = 'afc-sheet-library';
		library.innerHTML =
			'<div class="afc-sheet-library-head"><div><small>CONNECTED SHEETS</small><h2>Google Sheets</h2></div><button type="button" class="afc-sheet-help" data-afc-google-help aria-label="Google Sheets help">?</button></div>' +
			'<div class="afc-sheet-library-note" hidden></div>' +
			'<div class="afc-sheet-card-grid">' +
				'<button type="button" class="afc-sheet-add-card" data-afc-sheet-add>' +
					'<span class="afc-sheet-add-plus">+</span><strong>Add Sheet</strong><small>Connect your first Sheet</small>' +
				'</button>' +
				'<article class="afc-sheet-card" data-afc-sheet-card tabindex="0">' +
					'<span class="afc-sheet-status-dot" data-afc-sheet-dot aria-label="Not connected"></span>' +
					'<div class="afc-sheet-card-icon">' + sheetIcon() + '</div>' +
					'<div class="afc-sheet-card-copy"><h3 data-afc-sheet-card-title>Primary Google Sheet</h3><p data-afc-sheet-card-meta>Needs setup or connection test</p><small data-afc-sheet-card-last>Not connected</small></div>' +
					'<div class="afc-sheet-card-actions"><button type="button" class="btn btn-sm btn-light" data-afc-sheet-view hidden>View</button><button type="button" class="btn btn-sm btn-light" data-afc-sheet-settings aria-label="Google Sheet settings">⚙ Settings</button></div>' +
				'</article>' +
			'</div>';
		page.insertBefore( library, oldGrid || page.firstChild );

		settingsDialog = document.createElement( 'dialog' );
		settingsDialog.className = 'afc-google-sheet-settings-dialog';
		settingsDialog.innerHTML = '<div class="afc-google-sheet-settings-shell"><header><div><small>GOOGLE SHEETS</small><h2>Connection & sync settings</h2></div><button type="button" data-afc-sheet-settings-close aria-label="Close settings">×</button></header><div class="afc-google-sheet-settings-content"></div></div>';
		root.appendChild( settingsDialog );
		settingsDialog.querySelector( '.afc-google-sheet-settings-content' ).appendChild( googleCard );
		googleCard.classList.add( 'is-sheet-settings-card' );
		watchGoogleCard( googleCard );

		library.addEventListener( 'click', function ( event ) {
			const add = event.target.closest( '[data-afc-sheet-add]' );
			const settings = event.target.closest( '[data-afc-sheet-settings]' );
			const view = event.target.closest( '[data-afc-sheet-view]' );
			if ( add ) {
				if ( connected() ) toast( 'Additional Google Sheet connections will use this + card in a future update. Your current Sheet stays unchanged.' );
				else openSettings();
				return;
			}
			if ( settings ) {
				openSettings();
				return;
			}
			if ( view && connected() && sheetId() ) {
				window.open( 'https://docs.google.com/spreadsheets/d/' + encodeURIComponent( sheetId() ) + '/edit', '_blank', 'noopener,noreferrer' );
			}
		} );

		settingsDialog.addEventListener( 'click', function ( event ) {
			if ( event.target === settingsDialog || event.target.closest( '[data-afc-sheet-settings-close]' ) ) settingsDialog.close();
		} );
		settingsDialog.addEventListener( 'close', syncLibrary );
		syncLibrary();
	}

	function queue() {
		if ( frame ) return;
		frame = window.requestAnimationFrame( build );
	}

	function boot() {
		queue();
		const observer = new MutationObserver( queue );
		observer.observe( document.body, { childList: true, subtree: true } );
		document.addEventListener( 'afc:ajaxify-panel-loaded', queue );
		document.addEventListener( 'afc:admin-mode-change', queue );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
