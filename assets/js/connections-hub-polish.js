( function () {
	'use strict';

	const $ = function ( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	};

	const $$ = function ( selector, scope ) {
		return Array.from( ( scope || document ).querySelectorAll( selector ) );
	};

	let sidebarObserver = null;

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

	function syncConnectionsUi() {
		if ( ! advanced() ) return;

		const connections = workspaceButton( 'integrations' );
		if ( connections ) {
			connections.hidden = false;
			connections.classList.remove( 'afc-google-sheets-menu-item' );
			connections.classList.add( 'afc-connections-menu-item' );

			const title = $( 'b strong', connections );
			const small = $( 'b small', connections );
			const icon = $( ':scope > span', connections );

			if ( title && title.textContent !== 'Connections' ) title.textContent = 'Connections';
			if ( small && small.textContent !== 'Devices & integrations' ) small.textContent = 'Devices & integrations';
			if ( icon && icon.textContent !== '⌁' ) icon.textContent = '⌁';
		}

		const mikrotik = workspaceButton( 'mikrotik' );
		const optical = workspaceButton( 'optical' );
		if ( mikrotik ) mikrotik.hidden = true;
		if ( optical ) optical.hidden = true;

		$$( '.afc-connection-open' ).forEach( function ( button ) {
			if ( button.textContent !== 'Settings' ) button.textContent = 'Settings';
		} );
	}

	function openPanel( key, after ) {
		const workspace = workspaceButton( key );
		const header = headerButton( key );

		/* Advanced Workspace owns panel routing. Hidden source menu buttons are
		 * still valid programmatic routes for the underlying connection editors. */
		if ( workspace ) {
			workspace.click();
		} else if ( header ) {
			header.click();
		}

		if ( after ) window.setTimeout( after, 120 );
	}

	function returnToConnections() {
		if ( ! advanced() ) return;
		window.setTimeout( function () {
			openPanel( 'integrations', syncConnectionsUi );
		}, 40 );
	}

	function returnAfterOltClose( modal ) {
		if ( ! modal ) return;
		let opened = ! modal.hidden || modal.classList.contains( 'is-open' );
		const observer = new MutationObserver( function () {
			if ( ! modal.hidden || modal.classList.contains( 'is-open' ) ) opened = true;
			if ( opened && modal.hidden ) {
				observer.disconnect();
				returnToConnections();
			}
		} );
		observer.observe( modal, { attributes: true, attributeFilter: [ 'hidden', 'class' ] } );
	}

	function returnAfterDialogClose( dialog ) {
		if ( ! dialog ) return;
		dialog.addEventListener( 'close', returnToConnections, { once: true } );
	}

	function openOltSettings( id ) {
		openPanel( 'optical', function () {
			waitFor( '[data-afc-olt-card="' + CSS.escape( id ) + '"]', function ( target ) {
				target.click();
				waitFor( '[data-afc-olt-modal]', returnAfterOltClose, 25 );
			}, 40 );
		} );
	}

	function openMikrotikSettings() {
		openPanel( 'mikrotik', function () {
			waitFor( '[data-afc-mikrotik-card]', function ( target ) {
				target.click();
				waitFor( '.afc-mikrotik-settings-dialog', returnAfterDialogClose, 25 );
			}, 40 );
		} );
	}

	function openSheetSettings() {
		/* Sheets already live inside the Connections source panel, so no visible
		 * navigation is needed: just open its existing settings dialog. */
		waitFor( '[data-afc-sheet-settings]', function ( target ) {
			target.click();
		}, 40 );
	}

	function openConnection( card ) {
		if ( ! card ) return;
		const type = card.getAttribute( 'data-afc-connection-type' ) || '';
		const id = card.getAttribute( 'data-afc-connection-id' ) || '';

		card.classList.add( 'is-opening' );
		window.setTimeout( function () { card.classList.remove( 'is-opening' ); }, 360 );

		if ( type === 'olt' ) {
			openOltSettings( id );
			return;
		}

		if ( type === 'mikrotik' ) {
			openMikrotikSettings();
			return;
		}

		if ( type === 'sheet' ) openSheetSettings();
	}

	/* Whole-card activation: the drag handle is the only part that does not open
	 * settings. Capture phase prevents older card handlers from stealing clicks. */
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
		syncConnectionsUi();

		document.addEventListener( 'afc:admin-mode-change', function () {
			window.setTimeout( function () {
				leaveOpticalWhenBasic();
				syncConnectionsUi();
			}, 0 );
		} );
		document.addEventListener( 'afc:ajaxify-panel-loaded', syncConnectionsUi );

		/* Google Sheets has its own legacy sidebar enhancer. Keep Connections as
		 * the final Advanced label even when that older enhancer rebuilds the menu. */
		sidebarObserver = new MutationObserver( function () {
			syncConnectionsUi();
		} );
		sidebarObserver.observe( document.body, { childList: true, subtree: true, characterData: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
