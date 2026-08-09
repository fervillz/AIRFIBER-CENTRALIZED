( function ( $ ) {
	'use strict';

	const cfg = window.afcPppPresence || {};
	const presence = new Map();
	const activeNames = new Set();
	let snapshotReady = false;
	let snapshotLoading = null;
	let decorateTimer = null;
	let refreshTimer = null;

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

	function stateForAccount( account ) {
		const accountKey = key( account );
		if ( ! accountKey ) return null;
		if ( presence.has( accountKey ) ) return presence.get( accountKey );
		if ( snapshotReady ) return activeNames.has( accountKey );
		return null;
	}

	function decorateAdvanced() {
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const state = stateForAccount( result.getAttribute( 'data-afc-dashboard-payment-account' ) );
			if ( state === null ) return;
			setBadge( result.querySelector( '.afc-dashboard-payment-result-copy > strong' ), state );
		} );
	}

	function decorateBasic() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const state = stateForAccount( result.getAttribute( 'data-account' ) );
			if ( state === null ) return;
			setBadge( result.querySelector( '.afc-basic-customer-main > strong' ), state );
		} );
	}

	function decorateGenericPaymentResults() {
		document.querySelectorAll( '[data-account], [data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			if ( result.matches( '.afc-basic-customer-result, .afc-dashboard-payment-result' ) ) return;
			const account = result.getAttribute( 'data-account' ) || result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
			const state = stateForAccount( account );
			if ( state === null ) return;
			const nameNode = result.querySelector( 'strong, .customer-name, .afc-customer-name' );
			if ( nameNode ) setBadge( nameNode, state );
		} );
	}

	function decorate() {
		hydrateFromOperationsTable();
		decorateAdvanced();
		decorateBasic();
		decorateGenericPaymentResults();
	}

	function scheduleDecorate() {
		window.clearTimeout( decorateTimer );
		decorateTimer = window.setTimeout( decorate, 25 );
	}

	function hasSearchResults() {
		return Boolean( document.querySelector(
			'.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]'
		) );
	}

	function loadSnapshot( force ) {
		if ( snapshotLoading ) return snapshotLoading;
		if ( ! cfg.ajaxUrl || ! cfg.nonce ) return Promise.resolve();
		if ( snapshotReady && ! force ) return Promise.resolve();

		const body = new URLSearchParams();
		body.set( 'action', 'afc_ppp_presence_snapshot' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'refresh', force ? '1' : '0' );

		snapshotLoading = window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) return;
			activeNames.clear();
			( response.data && Array.isArray( response.data.active ) ? response.data.active : [] ).forEach( function ( name ) {
				activeNames.add( key( name ) );
			} );
			snapshotReady = true;
			/* The snapshot is the latest live truth. Remove older per-user states so
			 * accounts missing from /ppp/active correctly become OFFLINE. */
			presence.clear();
			decorate();
		} ).catch( function () {
			// Existing AFC_PPP_Users data can still provide presence if available.
		} ).finally( function () {
			snapshotLoading = null;
		} );
		return snapshotLoading;
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

	function requestPresenceForSearch() {
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function () {
			loadSnapshot( false ).then( scheduleDecorate );
		}, 60 );
	}

	function boot() {
		decorate();

		new MutationObserver( function () {
			scheduleDecorate();
			if ( hasSearchResults() && ! snapshotReady ) requestPresenceForSearch();
		} ).observe( document.body, {
			childList: true,
			subtree: true,
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( ! event.target ) return;
			if ( event.target.id === 'afc-basic-payment-search' || event.target.id === 'afc-dashboard-payment-search' ) {
				requestPresenceForSearch();
			}
		}, true );

		/* On an already-filled search (browser restore/back navigation), fetch once
		 * without waiting for another keypress. */
		window.setTimeout( function () {
			if ( hasSearchResults() ) requestPresenceForSearch();
		}, 180 );

		/* Keep ONLINE/OFFLINE reasonably fresh without hammering MikroTik. The
		 * server also caches the tiny active snapshot for 15 seconds. */
		window.setInterval( function () {
			if ( hasSearchResults() ) loadSnapshot( true );
		}, 30000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
