( function ( $ ) {
	'use strict';

	let library = null;
	let dialog = null;
	let card = null;
	let frame = 0;

	function loadCardStyles() {
		if ( document.getElementById( 'afc-mikrotik-cards-runtime' ) ) return;
		const script = document.querySelector( 'script[src*="/assets/js/mikrotik-settings.js"]' );
		if ( ! script || ! script.src ) return;
		const marker = '/assets/js/mikrotik-settings.js';
		const index = script.src.indexOf( marker );
		if ( index < 0 ) return;
		const link = document.createElement( 'link' );
		link.id = 'afc-mikrotik-cards-runtime';
		link.rel = 'stylesheet';
		link.href = script.src.slice( 0, index ) + '/assets/css/mikrotik-cards.css?v=' + encodeURIComponent( script.src.split( '?v=' )[ 1 ] || Date.now() );
		document.head.appendChild( link );
	}

	function panel() {
		return document.querySelector( '[data-afc-panel="mikrotik"]' );
	}

	function value( selector, root ) {
		const node = ( root || document ).querySelector( selector );
		return node ? String( node.value || node.textContent || '' ).trim() : '';
	}

	function state() {
		const root = panel();
		if ( ! root ) return 'untested';
		const live = root.querySelector( '#afc-mikrotik-test-result .alert-success, #afc-mikrotik-test-result .alert-danger' );
		if ( live ) return live.classList.contains( 'alert-success' ) ? 'online' : 'error';
		const badge = root.querySelector( '.col-lg-4 .badge' );
		if ( badge ) {
			const text = String( badge.textContent || '' ).toLowerCase();
			if ( text.indexOf( 'success' ) >= 0 ) return 'online';
			if ( text.indexOf( 'error' ) >= 0 || text.indexOf( 'failed' ) >= 0 ) return 'error';
		}
		return 'untested';
	}

	function configured() {
		const root = panel();
		if ( ! root ) return false;
		return Boolean( value( '#afc-router-host', root ) && value( '#afc-router-user', root ) && value( '#afc-router-password', root ) );
	}

	function currentData() {
		const root = panel();
		const protocol = value( '#afc-router-protocol', root ) === 'api-ssl' ? 'API-SSL' : 'API';
		return {
			name: value( '#afc-router-name', root ) || 'Main Router',
			host: value( '#afc-router-host', root ) || 'Not configured',
			port: value( '#afc-router-port', root ) || ( protocol === 'API-SSL' ? '8729' : '8728' ),
			user: value( '#afc-router-user', root ) || 'No username',
			protocol: protocol,
			state: state()
		};
	}

	function statusLabel( current ) {
		if ( current.state === 'online' ) return 'Connected';
		if ( current.state === 'error' ) return 'Connection problem';
		return 'Not tested';
	}

	function syncCard() {
		if ( ! card ) return;
		const current = currentData();
		card.classList.remove( 'is-online', 'is-error', 'is-untested' );
		card.classList.add( 'is-' + current.state );
		const title = card.querySelector( '[data-afc-mikrotik-name]' );
		const endpoint = card.querySelector( '[data-afc-mikrotik-endpoint]' );
		const user = card.querySelector( '[data-afc-mikrotik-user]' );
		const status = card.querySelector( '[data-afc-mikrotik-status-label]' );
		if ( title ) { title.textContent = current.name; title.title = current.name; }
		if ( endpoint ) endpoint.textContent = current.host + ':' + current.port;
		if ( user ) user.textContent = current.user;
		if ( status ) status.textContent = statusLabel( current );
	}

	function openSettings() {
		if ( ! dialog ) return;
		if ( dialog.open ) dialog.close();
		dialog.showModal();
	}

	function toast( message ) {
		if ( ! library ) return;
		let note = library.querySelector( '.afc-mikrotik-note' );
		if ( ! note ) {
			note = document.createElement( 'div' );
			note.className = 'afc-mikrotik-note';
			library.insertBefore( note, library.querySelector( '.afc-mikrotik-grid' ) );
		}
		note.textContent = message;
		note.hidden = false;
		window.clearTimeout( toast.timer );
		toast.timer = window.setTimeout( function () { note.hidden = true; }, 4200 );
	}

	function buildCards() {
		frame = 0;
		if ( ! document.body.classList.contains( 'afc-admin-mode-advanced' ) ) return;
		const root = panel();
		if ( ! root ) return;
		const container = root.querySelector( '.container-xl' );
		const row = container && container.querySelector( ':scope > .row' );
		if ( ! container || ! row ) {
			if ( root.querySelector( '.afc-mikrotik-library' ) ) syncCard();
			return;
		}

		const oldHead = container.querySelector( ':scope > .page-header' );
		if ( oldHead ) oldHead.hidden = true;

		library = document.createElement( 'section' );
		library.className = 'afc-mikrotik-library';
		library.innerHTML =
			'<div class="afc-mikrotik-library-head"><div><small>NETWORK DEVICES</small><h2>MikroTik</h2></div></div>' +
			'<div class="afc-mikrotik-grid">' +
				'<button type="button" class="afc-mikrotik-add" data-afc-mikrotik-add data-afc-no-auto-icon><span class="afc-mikrotik-add-plus">+</span><strong>Add MikroTik</strong><small>Connect another router</small></button>' +
				'<article class="afc-mikrotik-card is-untested" data-afc-mikrotik-card tabindex="0">' +
					'<span class="afc-mikrotik-status" aria-hidden="true"></span>' +
					'<div class="afc-mikrotik-center"><h3 data-afc-mikrotik-name>Main Router</h3><p><span data-afc-mikrotik-protocol>RouterOS</span><b data-afc-mikrotik-endpoint></b></p></div>' +
					'<div class="afc-mikrotik-details"><div><span data-afc-mikrotik-user></span><strong data-afc-mikrotik-status-label>Not tested</strong></div><small>RouterOS API connection</small><button type="button" class="afc-mikrotik-action" data-afc-mikrotik-settings data-afc-no-auto-icon>Settings</button></div>' +
				'</article>' +
			'</div>';
		container.insertBefore( library, row );
		card = library.querySelector( '[data-afc-mikrotik-card]' );

		dialog = document.createElement( 'dialog' );
		dialog.className = 'afc-mikrotik-settings-dialog';
		dialog.innerHTML = '<div class="afc-mikrotik-settings-shell"><header><div><small>MIKROTIK</small><h2>Connection settings</h2></div><button type="button" class="afc-mikrotik-settings-close" data-afc-mikrotik-close data-afc-no-auto-icon aria-label="Close settings">×</button></header><div class="afc-mikrotik-settings-content"></div></div>';
		root.appendChild( dialog );
		dialog.querySelector( '.afc-mikrotik-settings-content' ).appendChild( row );

		library.addEventListener( 'click', function ( event ) {
			const add = event.target.closest( '[data-afc-mikrotik-add]' );
			const settings = event.target.closest( '[data-afc-mikrotik-settings]' );
			const currentCard = event.target.closest( '[data-afc-mikrotik-card]' );
			if ( add ) {
				if ( configured() ) toast( 'Additional MikroTik connections will use this + card in a future update. Your current router stays unchanged.' );
				else openSettings();
				return;
			}
			if ( settings || currentCard ) openSettings();
		} );
		card.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Enter' || event.key === ' ' ) { event.preventDefault(); openSettings(); } } );
		dialog.addEventListener( 'click', function ( event ) { if ( event.target === dialog || event.target.closest( '[data-afc-mikrotik-close]' ) ) dialog.close(); } );
		dialog.addEventListener( 'close', syncCard );
		syncCard();
	}

	function queueCards() {
		if ( frame ) return;
		frame = window.requestAnimationFrame( buildCards );
	}

	$( function () {
		loadCardStyles();
		const $button = $( '#afc-test-mikrotik' );
		const $result = $( '#afc-mikrotik-test-result' );
		const $password = $( '#afc-router-password' );

		$( document ).on( 'click', '#afc-change-password', function () {
			$password.prop( 'disabled', false ).val( '' ).trigger( 'focus' );
			$( this ).remove();
			$( '#afc-password-status' ).removeClass( 'text-success' ).addClass( 'text-secondary' ).text( 'Enter the new password, then save the connection.' );
		} );

		$( document ).on( 'click', '#afc-test-mikrotik', function () {
			const $liveButton = $( this );
			const $liveResult = $( '#afc-mikrotik-test-result' );
			$liveButton.prop( 'disabled', true ).text( afcMikroTik.testing );
			$liveResult.html( '<div class="alert alert-info">Connecting to RouterOS…</div>' );

			$.post( afcMikroTik.ajaxUrl, { action: 'afc_test_mikrotik', nonce: afcMikroTik.nonce } )
				.done( function ( response ) {
					const successful = response && response.success;
					const message = response && response.data && response.data.message ? response.data.message : 'The router returned an unexpected response.';
					$liveResult.html( $( '<div>', { class: 'alert ' + ( successful ? 'alert-success' : 'alert-danger' ), text: message } ) );
				} )
				.fail( function ( xhr ) {
					let message = 'The connection test request failed.';
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) message = xhr.responseJSON.data.message;
					$liveResult.html( $( '<div>', { class: 'alert alert-danger', text: message } ) );
				} )
				.always( function () { $liveButton.prop( 'disabled', false ).text( afcMikroTik.button ); syncCard(); } );
		} );

		queueCards();
		document.addEventListener( 'afc:admin-mode-change', queueCards );
		document.addEventListener( 'afc:ajaxify-panel-loaded', queueCards );
		const observer = new MutationObserver( function ( mutations ) {
			if ( mutations.some( function ( mutation ) { return Array.from( mutation.addedNodes || [] ).some( function ( node ) { return node.nodeType === 1 && ! node.closest?.( '.afc-mikrotik-library, .afc-mikrotik-settings-dialog' ); } ); } ) ) queueCards();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	} );
}( jQuery ) );
