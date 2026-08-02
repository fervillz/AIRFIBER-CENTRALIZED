( function ( $ ) {
	'use strict';

	const config = window.afcDashboardPaymentTool || {};
	let users = [];
	let usersLoaded = false;
	let searchTimer = null;
	let collectionTimer = null;

	function dashboardRoot() {
		return document.getElementById( 'afc-main-dashboard' );
	}

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( text( value ) ).html();
	}

	function plainText( value ) {
		const normalized = text( value ).toLowerCase();
		return 'function' === typeof normalized.normalize
			? normalized.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: normalized;
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && 'object' === typeof settings.data ) return settings.data.action === action;
		return text( settings.data ).includes( 'action=' + action );
	}

	function formatAmount( value ) {
		const amount = Number( value || 0 );
		try {
			return new Intl.NumberFormat( 'en-PH', {
				style: 'currency',
				currency: 'PHP',
				maximumFractionDigits: 2,
			} ).format( Number.isFinite( amount ) ? amount : 0 );
		} catch ( error ) {
			return '₱' + ( Number.isFinite( amount ) ? amount : 0 ).toLocaleString();
		}
	}

	function paymentToolMarkup() {
		return '<div class="afc-dashboard-payment-tool">' +
			'<div class="afc-dashboard-payment-search-row">' +
				'<div class="afc-dashboard-payment-search-box">' +
					'<span class="afc-dashboard-payment-search-icon" aria-hidden="true"></span>' +
					'<label class="screen-reader-text" for="afc-dashboard-payment-search">Search a PPP account to record a payment</label>' +
					'<input id="afc-dashboard-payment-search" type="search" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Search customer name, PPP account, phone or address…">' +
					'<button type="button" class="afc-dashboard-payment-clear" data-afc-dashboard-payment-clear hidden>Clear</button>' +
				'</div>' +
				'<button type="button" class="afc-dashboard-payment-new-ppp" data-afc-dashboard-add-ppp><span aria-hidden="true">+</span> New PPP</button>' +
			'</div>' +
			'<div class="afc-dashboard-payment-help" data-afc-dashboard-payment-help aria-live="polite">Type at least 2 characters to find the correct PPP account.</div>' +
			'<div class="afc-dashboard-payment-results" data-afc-dashboard-payment-results role="listbox" aria-label="PPP payment search results">' +
				'<div class="afc-dashboard-payment-empty">Loading PPP accounts from MikroTik…</div>' +
			'</div>' +
		'</div>';
	}

	function createPaymentTool() {
		const root = dashboardRoot();
		const mount = root && root.querySelector( '[data-afc-dashboard-payment-mount]' );
		if ( ! mount ) return false;
		if ( ! mount.querySelector( '#afc-dashboard-payment-search' ) ) {
			mount.innerHTML = paymentToolMarkup();
			bindPaymentTool( mount );
		}
		renderSearch();
		return true;
	}

	function searchInput() {
		return document.getElementById( 'afc-dashboard-payment-search' );
	}

	function setHelp( message, state ) {
		const target = document.querySelector( '[data-afc-dashboard-payment-help]' );
		if ( ! target ) return;
		target.textContent = message || '';
		target.className = 'afc-dashboard-payment-help' + ( state ? ' is-' + state : '' );
	}

	function searchableValue( user ) {
		return plainText( [
			user.customer_name,
			user.name,
			user.phone,
			user.address_text,
			user.profile,
		].join( ' ' ) );
	}

	function matchRank( user, query ) {
		const customer = plainText( user.customer_name || '' );
		const account = plainText( user.name || '' );
		if ( customer === query || account === query ) return 0;
		if ( customer.startsWith( query ) || account.startsWith( query ) ) return 1;
		return 2;
	}

	function matchingUsers( query ) {
		const normalized = plainText( query );
		return users
			.filter( function ( user ) {
				return ! user.disabled && searchableValue( user ).includes( normalized );
			} )
			.sort( function ( first, second ) {
				return matchRank( first, normalized ) - matchRank( second, normalized ) ||
					text( first.customer_name || first.name ).localeCompare( text( second.customer_name || second.name ) );
			} );
	}

	function resultMarkup( user ) {
		const customer = user.customer_name || user.name || 'Unknown customer';
		const account = user.name || '';
		const contact = [ account, user.phone ].filter( Boolean ).join( ' · ' );
		const lastPayment = user.payment_date ? 'Last paid ' + user.payment_date : 'No payment date';
		const plan = user.actual_profile || user.profile || 'No plan';
		return '<button type="button" class="afc-dashboard-payment-result" role="option" data-afc-dashboard-payment-account="' + escapeHtml( account ) + '">' +
			'<span class="afc-dashboard-payment-result-avatar">' + escapeHtml( customer.charAt( 0 ).toUpperCase() || '?' ) + '</span>' +
			'<span class="afc-dashboard-payment-result-copy"><strong>' + escapeHtml( customer ) + '</strong><small>' + escapeHtml( contact ) + '</small></span>' +
			'<span class="afc-dashboard-payment-result-meta"><strong>' + escapeHtml( lastPayment ) + '</strong><small>' + escapeHtml( plan ) + '</small></span>' +
			'<span class="afc-dashboard-payment-result-action">Record</span>' +
		'</button>';
	}

	function renderSearch() {
		const results = document.querySelector( '[data-afc-dashboard-payment-results]' );
		const input = searchInput();
		const clear = document.querySelector( '[data-afc-dashboard-payment-clear]' );
		if ( ! results || ! input ) return;

		const query = input.value.trim();
		if ( clear ) clear.hidden = ! query;

		if ( ! usersLoaded ) {
			results.innerHTML = '<div class="afc-dashboard-payment-empty">Loading PPP accounts from MikroTik…</div>';
			setHelp( 'Preparing customer accounts for payment search.', '' );
			return;
		}

		if ( query.length < 2 ) {
			results.innerHTML = '<div class="afc-dashboard-payment-empty">Search by customer name, PPP username, phone number or address.</div>';
			setHelp( users.length + ' PPP account' + ( users.length === 1 ? '' : 's' ) + ' available.', '' );
			return;
		}

		const matches = matchingUsers( query );
		const visible = matches.slice( 0, 6 );
		if ( ! visible.length ) {
			results.innerHTML = '<div class="afc-dashboard-payment-empty">No matching PPP account was found.</div>';
			setHelp( 'Try another spelling, PPP username or phone number.', 'error' );
			return;
		}

		results.innerHTML = visible.map( resultMarkup ).join( '' );
		setHelp(
			matches.length > visible.length
				? 'Showing the first ' + visible.length + ' of ' + matches.length + ' matches. Type more to narrow the list.'
				: matches.length + ' matching account' + ( matches.length === 1 ? '' : 's' ) + '.',
			''
		);
	}

	function parseTableUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function paymentButtonForAccount( account ) {
		const rows = document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' );
		for ( let index = 0; index < rows.length; index += 1 ) {
			const user = parseTableUser( rows[ index ] );
			if ( user && text( user.name ) === text( account ) ) return rows[ index ].querySelector( '.afc-pay-today' );
		}
		return null;
	}

	function openPaymentDialog( account, attempt ) {
		const button = paymentButtonForAccount( account );
		if ( button ) {
			button.click();
			setHelp( 'Review the amount and payment method, then confirm.', 'success' );
			return;
		}

		if ( Number( attempt || 0 ) >= 4 ) {
			setHelp( 'The PPP table is still loading. Open Operations and try again.', 'error' );
			return;
		}

		setHelp( 'Refreshing the PPP account before opening payment…', '' );
		const refresh = document.getElementById( 'afc-refresh-ppp' );
		if ( refresh && ! refresh.disabled ) refresh.click();
		window.setTimeout( function () { openPaymentDialog( account, Number( attempt || 0 ) + 1 ); }, 550 );
	}

	function bindPaymentTool( mount ) {
		const input = mount.querySelector( '#afc-dashboard-payment-search' );
		input.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			searchTimer = window.setTimeout( renderSearch, 90 );
		} );
		input.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				input.value = '';
				renderSearch();
			}
		} );
		mount.addEventListener( 'click', function ( event ) {
			const clear = event.target.closest( '[data-afc-dashboard-payment-clear]' );
			if ( clear ) {
				input.value = '';
				renderSearch();
				input.focus();
				return;
			}
			const result = event.target.closest( '[data-afc-dashboard-payment-account]' );
			if ( result ) openPaymentDialog( result.getAttribute( 'data-afc-dashboard-payment-account' ) || '', 0 );
		} );
	}

	function hydrateUsersFromTable() {
		const tableUsers = [];
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = parseTableUser( row );
			if ( user ) tableUsers.push( user );
		} );
		if ( tableUsers.length ) {
			users = tableUsers;
			usersLoaded = true;
			renderSearch();
			return true;
		}
		return false;
	}

	function transformCollectionCard() {
		const root = dashboardRoot();
		const card = root && root.querySelector( '[data-afc-dashboard-widget="new-ppp"]' );
		if ( ! card ) return false;

		card.classList.remove( 'afc-dashboard-new-ppp' );
		card.classList.add( 'afc-dashboard-today-collection' );
		const icon = card.querySelector( '.afc-dashboard-card-icon' );
		const kicker = card.querySelector( '.afc-dashboard-card-title small' );
		const title = card.querySelector( '.afc-dashboard-card-title h2' );
		if ( icon ) icon.textContent = '₱';
		if ( kicker ) kicker.textContent = 'Today';
		if ( title ) title.textContent = 'Collections';

		let body = card.querySelector( '.afc-dashboard-new-ppp-body, .afc-dashboard-today-body' );
		if ( body ) {
			body.className = 'afc-dashboard-today-body';
			body.innerHTML = '<div class="afc-dashboard-today-total">' +
				'<span>Total collected today</span>' +
				'<strong data-afc-dashboard-collection-total>—</strong>' +
				'<small><b data-afc-dashboard-collection-count>—</b> payment(s) recorded</small>' +
			'</div>' +
			'<div class="afc-dashboard-today-split">' +
				'<div><span>Cash</span><strong data-afc-dashboard-collection-cash>—</strong></div>' +
				'<div><span>GCash</span><strong data-afc-dashboard-collection-gcash>—</strong></div>' +
			'</div>' +
			'<div class="afc-dashboard-today-latest" data-afc-dashboard-collection-latest>Loading today’s collection…</div>';
		}

		if ( ! card.querySelector( '.afc-dashboard-card-footer' ) ) {
			const footer = document.createElement( 'footer' );
			footer.className = 'afc-dashboard-card-footer';
			footer.innerHTML = '<span data-afc-dashboard-collection-updated>Live summary</span><button type="button" data-afc-dashboard-open-panel="operations">View payments →</button>';
			card.appendChild( footer );
		}
		return true;
	}

	function collectionRequest() {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_dashboard_today_collection' );
		body.set( 'nonce', config.nonce || '' );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
			if ( ! response || ! response.success ) throw new Error( response && response.data && response.data.message ? response.data.message : 'Could not load today’s collection.' );
			return response.data || {};
		} );
	}

	function setCollectionValue( selector, value ) {
		const node = document.querySelector( selector );
		if ( node ) node.textContent = value;
	}

	function renderCollection( data ) {
		setCollectionValue( '[data-afc-dashboard-collection-total]', formatAmount( data.total ) );
		setCollectionValue( '[data-afc-dashboard-collection-count]', Number( data.count || 0 ) );
		setCollectionValue( '[data-afc-dashboard-collection-cash]', formatAmount( data.cash ) );
		setCollectionValue( '[data-afc-dashboard-collection-gcash]', formatAmount( data.gcash ) );
		setCollectionValue( '[data-afc-dashboard-collection-updated]', 'Updated just now' );

		const latest = document.querySelector( '[data-afc-dashboard-collection-latest]' );
		if ( ! latest ) return;
		if ( ! data.latest ) {
			latest.textContent = 'No payments have been recorded yet today.';
			return;
		}
		latest.innerHTML = 'Latest: <strong>' + escapeHtml( data.latest.customer || data.latest.account || 'Customer' ) + '</strong>' +
			( data.latest.account ? ' · ' + escapeHtml( data.latest.account ) : '' ) +
			' · ' + escapeHtml( formatAmount( data.latest.amount ) );
	}

	function loadCollection() {
		if ( ! transformCollectionCard() ) return;
		collectionRequest().then( renderCollection ).catch( function ( error ) {
			setCollectionValue( '[data-afc-dashboard-collection-updated]', 'Could not update' );
			const latest = document.querySelector( '[data-afc-dashboard-collection-latest]' );
			if ( latest ) latest.textContent = error.message || 'Today’s collection could not be loaded.';
		} );
	}

	function isDashboardVisible() {
		const root = dashboardRoot();
		const panel = root && root.closest( '[data-afc-panel="dashboard"]' );
		return Boolean( panel && ! panel.hidden && panel.getAttribute( 'aria-hidden' ) !== 'true' );
	}

	function startCollectionTimer() {
		window.clearInterval( collectionTimer );
		collectionTimer = window.setInterval( function () {
			if ( isDashboardVisible() ) loadCollection();
		}, 30000 );
	}

	function boot() {
		createPaymentTool();
		transformCollectionCard();
		hydrateUsersFromTable();
		loadCollection();
		startCollectionTimer();

		const root = dashboardRoot();
		if ( root ) {
			root.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '[data-afc-dashboard-refresh]' ) ) window.setTimeout( loadCollection, 80 );
			} );
		}

		window.setTimeout( function () {
			if ( ! hydrateUsersFromTable() ) {
				const refresh = document.getElementById( 'afc-refresh-ppp' );
				if ( refresh && ! refresh.disabled ) refresh.click();
			}
		}, 900 );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			users = xhr.responseJSON.data && Array.isArray( xhr.responseJSON.data.users ) ? xhr.responseJSON.data.users : [];
			usersLoaded = true;
			renderSearch();
		}

		if ( requestHasAction( settings, 'afc_ppp_record_payment' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			const input = searchInput();
			if ( input ) input.value = '';
			setHelp( 'Payment recorded. Today’s collection was updated.', 'success' );
			renderSearch();
			window.setTimeout( loadCollection, 450 );
		}
	} );

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
