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
		const olt = workspaceButton( 'olt' );
		if ( mikrotik ) mikrotik.hidden = true;
		if ( optical ) optical.hidden = true;
		if ( olt ) olt.hidden = true;

		$$( '.afc-connection-open' ).forEach( function ( button ) {
			if ( button.textContent !== 'Settings' ) button.textContent = 'Settings';
		} );
	}

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
