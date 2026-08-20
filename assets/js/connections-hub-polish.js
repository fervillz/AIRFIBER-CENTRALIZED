( function () {
	'use strict';

	const $ = function ( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	};

	function advanced() {
		return document.body.classList.contains( 'afc-admin-mode-advanced' );
	}

	function waitFor( selector, callback, attempts ) {
		const node = $( selector );
		if ( node ) {
			callback( node );
			return;
		}
		if ( attempts <= 0 ) return;
		window.setTimeout( function () {
			waitFor( selector, callback, attempts - 1 );
		}, 100 );
	}

	function workspaceButton( key ) {
		return $( '.afc-workspace-menu-item[data-afc-ws-panel="' + key + '"]' );
	}

	function headerButton( key ) {
		return $( '[data-afc-app-panel="' + key + '"]' );
	}

	function openPanel( key, after ) {
		const workspace = workspaceButton( key );
		const header = headerButton( key );

		/* Advanced Workspace owns panel routing. Programmatic clicks still work
		 * when a source menu item is visually hidden by the Connections hub. */
		if ( workspace ) {
			workspace.click();
		} else if ( header ) {
			header.click();
		}

		if ( after ) {
			window.setTimeout( after, 120 );
		}
	}

	function openConnection( card ) {
		if ( ! card ) return;
		const type = card.getAttribute( 'data-afc-connection-type' ) || '';
		const id = card.getAttribute( 'data-afc-connection-id' ) || '';

		card.classList.add( 'is-opening' );
		window.setTimeout( function () { card.classList.remove( 'is-opening' ); }, 360 );

		if ( type === 'olt' ) {
			openPanel( 'optical', function () {
				waitFor( '[data-afc-olt-card="' + CSS.escape( id ) + '"]', function ( target ) {
					target.click();
				}, 35 );
			} );
			return;
		}

		if ( type === 'mikrotik' ) {
			openPanel( 'mikrotik', function () {
				waitFor( '[data-afc-mikrotik-card]', function ( target ) {
					target.click();
				}, 35 );
			} );
			return;
		}

		if ( type === 'sheet' ) {
			openPanel( 'integrations', function () {
				waitFor( '[data-afc-sheet-settings]', function ( target ) {
					target.click();
				}, 35 );
			} );
		}
	}

	/* Capture the card activation before the older hub handler. This avoids the
	 * old fallback route, which only knew about the original top navigation and
	 * could leave a card looking clickable without opening its hidden panel. */
	document.addEventListener( 'click', function ( event ) {
		const card = event.target.closest && event.target.closest( '.afc-connection-card[data-afc-connection-key]' );
		if ( ! card || ! advanced() ) return;
		if ( event.target.closest( '[data-afc-connection-drag]' ) ) return;

		event.preventDefault();
		event.stopImmediatePropagation();
		openConnection( card );
	}, true );

	document.addEventListener( 'keydown', function ( event ) {
		if ( ! advanced() || ( event.key !== 'Enter' && event.key !== ' ' ) ) return;
		const card = event.target.closest && event.target.closest( '.afc-connection-card[data-afc-connection-key]' );
		if ( ! card || event.target.closest( '[data-afc-connection-drag]' ) ) return;

		event.preventDefault();
		event.stopImmediatePropagation();
		openConnection( card );
	}, true );

	function leaveOpticalWhenBasic() {
		if ( advanced() ) return;
		const optical = $( '[data-afc-panel="optical"].is-active:not([hidden])' );
		if ( ! optical ) return;
		const operations = headerButton( 'operations' );
		if ( operations ) operations.click();
	}

	function boot() {
		leaveOpticalWhenBasic();
		document.addEventListener( 'afc:admin-mode-change', function () {
			window.setTimeout( leaveOpticalWhenBasic, 0 );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
