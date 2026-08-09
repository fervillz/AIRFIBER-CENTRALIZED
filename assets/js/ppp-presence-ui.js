( function ( $ ) {
	'use strict';

	const presence = new Map();
	let decorateTimer = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function key( value ) {
		return text( value ).trim().toLowerCase();
	}

	function isOnline( value ) {
		return value === true || value === 1 || value === '1' || String( value ).toLowerCase() === 'true';
	}

	function rememberUser( user ) {
		if ( ! user || ! user.name ) return;
		presence.set( key( user.name ), isOnline( user.active ) );
	}

	function rememberUsers( users ) {
		( users || [] ).forEach( rememberUser );
	}

	function parseRowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function hydrateFromOperationsTable() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			rememberUser( parseRowUser( row ) );
		} );
	}

	function setBadge( nameNode, online ) {
		if ( ! nameNode ) return;

		nameNode.classList.add( 'afc-has-ppp-presence' );
		let badge = nameNode.querySelector( ':scope > .afc-ppp-presence' );
		if ( ! badge ) {
			badge = document.createElement( 'span' );
			badge.className = 'afc-ppp-presence';
			badge.innerHTML = '<i aria-hidden="true"></i><b></b>';
			nameNode.appendChild( badge );
		}

		const state = online ? 'online' : 'offline';
		if ( badge.dataset.afcPresenceState === state ) return;

		badge.dataset.afcPresenceState = state;
		badge.className = 'afc-ppp-presence is-' + state;
		badge.querySelector( 'b' ).textContent = online ? 'ONLINE' : 'OFFLINE';
		badge.title = online
			? 'PPP session is currently active on MikroTik.'
			: 'PPP account is not currently listed in MikroTik PPP Active.';
	}

	function decorateAdvanced() {
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = key( result.getAttribute( 'data-afc-dashboard-payment-account' ) );
			if ( ! account || ! presence.has( account ) ) return;
			setBadge( result.querySelector( '.afc-dashboard-payment-result-copy > strong' ), presence.get( account ) );
		} );
	}

	function decorateBasic() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const account = key( result.getAttribute( 'data-account' ) );
			if ( ! account || ! presence.has( account ) ) return;
			setBadge( result.querySelector( '.afc-basic-customer-main > strong' ), presence.get( account ) );
		} );
	}

	function decorate() {
		hydrateFromOperationsTable();
		decorateAdvanced();
		decorateBasic();
	}

	function scheduleDecorate() {
		window.clearTimeout( decorateTimer );
		decorateTimer = window.setTimeout( decorate, 35 );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return text( settings.data ).includes( 'action=' + action );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! requestHasAction( settings, 'afc_get_ppp_users' ) ) return;
		if ( ! xhr.responseJSON || ! xhr.responseJSON.success ) return;

		const users = xhr.responseJSON.data && Array.isArray( xhr.responseJSON.data.users )
			? xhr.responseJSON.data.users
			: [];
		rememberUsers( users );
		scheduleDecorate();
	} );

	function boot() {
		decorate();
		new MutationObserver( scheduleDecorate ).observe( document.body, {
			childList: true,
			subtree: true,
		} );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
