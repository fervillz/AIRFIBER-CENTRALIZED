( function ( $ ) {
	'use strict';

	const cfg = window.afcPppPresence || {};
	const presence = new Map();
	let decorateTimer = null;
	let searchTimer = null;
	let searchGeneration = 0;
	let targetedRequest = null;

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
		return accountKey && presence.has( accountKey ) ? presence.get( accountKey ) : null;
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

	function searchInputs() {
		return Array.from( document.querySelectorAll( '#afc-basic-payment-search, #afc-dashboard-payment-search' ) );
	}

	function activeSearchInput() {
		const focused = document.activeElement;
		if ( focused && ( focused.id === 'afc-basic-payment-search' || focused.id === 'afc-dashboard-payment-search' ) ) {
			return focused;
		}
		return searchInputs().find( function ( input ) { return text( input.value ).trim().length >= Number( cfg.minSearch || 3 ); } ) || null;
	}

	function visibleSearchAccounts() {
		const accounts = new Set();
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const account = text( result.getAttribute( 'data-account' ) ).trim();
			if ( account ) accounts.add( account );
		} );
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = text( result.getAttribute( 'data-afc-dashboard-payment-account' ) ).trim();
			if ( account ) accounts.add( account );
		} );
		return Array.from( accounts ).slice( 0, 50 );
	}

	function targetedPresenceCheck( generation ) {
		const input = activeSearchInput();
		const minSearch = Number( cfg.minSearch || 3 );
		if ( ! input || text( input.value ).trim().length < minSearch ) return Promise.resolve();

		const accounts = visibleSearchAccounts();
		if ( ! accounts.length || ! cfg.ajaxUrl || ! cfg.nonce ) return Promise.resolve();

		const body = new URLSearchParams();
		body.set( 'action', 'afc_ppp_presence_check' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'accounts', JSON.stringify( accounts ) );

		targetedRequest = window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			/* Ignore an older response if the user has typed again. */
			if ( generation !== searchGeneration || ! response || ! response.success ) return;
			const states = response.data && response.data.states ? response.data.states : {};
			Object.keys( states ).forEach( function ( account ) {
				presence.set( key( account ), isOnline( states[ account ] ) );
			} );
			decorate();
		} ).catch( function () {
			/* Keep the presence values from the normal full PPP load if the small
			 * refresh cannot reach MikroTik. */
		} ).finally( function () {
			targetedRequest = null;
		} );
		return targetedRequest;
	}

	function scheduleTargetedSearchCheck( input ) {
		window.clearTimeout( searchTimer );
		searchGeneration += 1;
		const generation = searchGeneration;
		const minSearch = Number( cfg.minSearch || 3 );
		const value = text( input && input.value ).trim();

		if ( value.length < minSearch ) return;

		searchTimer = window.setTimeout( function () {
			/* The one-second pause also gives the payment search renderer enough
			 * time to finish replacing its result cards before we collect accounts. */
			targetedPresenceCheck( generation );
		}, Number( cfg.searchDelayMs || 1000 ) );
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
		/* On reload the normal PPP fetch remains the first source of presence.
		 * We only make the extra MikroTik AJAX request after an actual search. */
		decorate();

		new MutationObserver( scheduleDecorate ).observe( document.body, {
			childList: true,
			subtree: true,
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( ! event.target ) return;
			if ( event.target.id === 'afc-basic-payment-search' || event.target.id === 'afc-dashboard-payment-search' ) {
				scheduleTargetedSearchCheck( event.target );
			}
		}, true );

		/* Browser restore/back navigation can reopen a page with a search value
		 * already present. Treat that exactly like a paused search. */
		window.setTimeout( function () {
			const input = activeSearchInput();
			if ( input ) scheduleTargetedSearchCheck( input );
		}, 250 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
