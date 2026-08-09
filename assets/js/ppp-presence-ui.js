( function ( $ ) {
	'use strict';

	const cfg = window.afcPppPresence || {};
	const presence = new Map();
	const liveDetails = new Map();
	let decorateTimer = null;
	let searchModule = null;
	let tooltip = null;
	let tooltipTarget = null;
	let tooltipHideTimer = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function key( value ) {
		return text( value ).trim().toLowerCase();
	}

	function esc( value ) {
		const node = document.createElement( 'div' );
		node.textContent = text( value );
		return node.innerHTML;
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

	function accountFromResult( result ) {
		if ( ! result ) return '';
		return text(
			result.getAttribute( 'data-account' ) ||
			result.getAttribute( 'data-afc-dashboard-payment-account' ) ||
			''
		).trim();
	}

	function resultNodes() {
		return Array.from( document.querySelectorAll(
			'.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]'
		) );
	}

	function visibleSearchAccounts() {
		const accounts = new Set();
		resultNodes().forEach( function ( result ) {
			const account = accountFromResult( result );
			if ( account ) accounts.add( account );
		} );
		return Array.from( accounts ).slice( 0, 50 );
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

	function shortDate( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
		if ( ! match ) return text( value );
		const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
		return date.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
	}

	function dueChip( detail ) {
		if ( ! detail ) return '';
		const due = detail.nextDue || detail.cutoffDate || '';
		const days = detail.daysToDue == null ? null : Number( detail.daysToDue );
		if ( detail.dueState === 'expired' ) {
			return '<span class="afc-ppp-live-chip is-expired"><i></i>EXPIRED</span>';
		}
		if ( detail.dueState === 'due' ) {
			const label = days != null && days < 0 ? Math.abs( days ) + 'D LATE' : 'DUE TODAY';
			return '<span class="afc-ppp-live-chip is-due"><i></i>' + esc( label ) + '</span>';
		}
		if ( detail.dueState === 'soon' ) {
			return '<span class="afc-ppp-live-chip is-due"><i></i>DUE ' + esc( shortDate( due ) ) + '</span>';
		}
		if ( detail.dueState === 'safe' && due ) {
			return '<span class="afc-ppp-live-chip is-safe"><i></i>DUE ' + esc( shortDate( due ) ) + '</span>';
		}
		return '';
	}

	function liveMeta( result, detail ) {
		const copy = result.matches( '.afc-basic-customer-result' )
			? result.querySelector( '.afc-basic-customer-main' )
			: result.querySelector( '.afc-dashboard-payment-result-copy' );
		if ( ! copy ) return;

		let row = copy.querySelector( ':scope > .afc-ppp-live-meta' );
		if ( ! detail ) {
			if ( row ) row.remove();
			return;
		}
		if ( ! row ) {
			row = document.createElement( 'span' );
			row.className = 'afc-ppp-live-meta';
			copy.appendChild( row );
		}

		row.innerHTML = dueChip( detail ) +
			'<span class="afc-ppp-live-info" aria-hidden="true">ⓘ PPP</span>';
		result.classList.add( 'afc-has-live-ppp-detail' );
		result.setAttribute( 'data-afc-live-ppp-account', detail.account || accountFromResult( result ) );
	}

	function decorateResult( result ) {
		const account = accountFromResult( result );
		if ( ! account ) return;
		const state = stateForAccount( account );
		const detail = liveDetails.get( key( account ) );
		const nameNode = result.matches( '.afc-basic-customer-result' )
			? result.querySelector( '.afc-basic-customer-main > strong' )
			: result.querySelector( '.afc-dashboard-payment-result-copy > strong' );
		if ( state !== null ) setBadge( nameNode, state );
		liveMeta( result, detail );
	}

	function decorateGenericPaymentResults() {
		document.querySelectorAll( '[data-account], [data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			if ( result.matches( '.afc-basic-customer-result, .afc-dashboard-payment-result' ) ) return;
			const account = accountFromResult( result );
			const state = stateForAccount( account );
			if ( state === null ) return;
			const nameNode = result.querySelector( 'strong, .customer-name, .afc-customer-name' );
			if ( nameNode ) setBadge( nameNode, state );
		} );
	}

	function decorate() {
		hydrateFromOperationsTable();
		resultNodes().forEach( decorateResult );
		decorateGenericPaymentResults();
	}

	function scheduleDecorate() {
		window.clearTimeout( decorateTimer );
		decorateTimer = window.setTimeout( decorate, 25 );
	}

	function markLoading() {
		resultNodes().forEach( function ( result ) {
			result.classList.add( 'is-afc-live-checking' );
		} );
	}

	function clearLoading() {
		resultNodes().forEach( function ( result ) {
			result.classList.remove( 'is-afc-live-checking' );
		} );
	}

	function applyLiveResponse( response ) {
		clearLoading();
		if ( ! response || ! response.success || ! response.data || ! response.data.accounts ) return;
		Object.keys( response.data.accounts ).forEach( function ( account ) {
			const detail = response.data.accounts[ account ] || {};
			liveDetails.set( key( account ), detail );
			presence.set( key( account ), isOnline( detail.online ) );
		} );
		decorate();
	}

	function registerSearchAjax() {
		if ( ! window.AFCSearchAjax || searchModule ) return;
		searchModule = window.AFCSearchAjax.register( 'ppp-live-details', {
			selector: '#afc-basic-payment-search, #afc-dashboard-payment-search',
			minChars: Number( cfg.minSearch || 3 ),
			delay: Number( cfg.searchDelayMs || 1000 ),
			ajaxUrl: cfg.ajaxUrl,
			nonce: cfg.nonce,
			action: 'afc_ppp_search_live_details',
			collect: function () {
				const accounts = visibleSearchAccounts();
				return accounts.length ? { accounts: accounts } : false;
			},
			onStart: markLoading,
			onSuccess: applyLiveResponse,
			onError: clearLoading,
			onClear: function () {
				clearLoading();
				hideTooltip();
			},
		} );
	}

	function fieldValue( detail, wantedKey ) {
		const fields = Array.isArray( detail && detail.commentFields ) ? detail.commentFields : [];
		const found = fields.find( function ( field ) { return key( field.key ) === key( wantedKey ); } );
		return found ? text( found.value ) : '';
	}

	function statusLabel( detail ) {
		if ( detail.dueState === 'expired' ) return 'Expired';
		if ( detail.dueState === 'due' ) return 'Due';
		if ( detail.dueState === 'soon' ) return 'Due soon';
		return detail.online ? 'Online' : 'Offline';
	}

	function tooltipHtml( result, detail ) {
		const customer = fieldValue( detail, 'name' ) ||
			text( result.querySelector( 'strong' ) && result.querySelector( 'strong' ).childNodes[0] && result.querySelector( 'strong' ).childNodes[0].textContent ).trim() ||
			detail.account;
		const fields = Array.isArray( detail.commentFields ) ? detail.commentFields.filter( function ( field ) {
			return text( field.value ).trim() !== '';
		} ) : [];
		const coreRows = [
			[ 'Status', statusLabel( detail ) ],
			[ 'PPP Profile', detail.profile || '—' ],
			[ 'Next Due', detail.nextDue || '—' ],
			[ 'Cutoff', detail.cutoffDate || '—' ],
		];
		if ( detail.online ) {
			coreRows.push( [ 'IP Address', detail.ip || '—' ] );
			coreRows.push( [ 'Uptime', detail.uptime || '—' ] );
		}

		const core = coreRows.map( function ( row ) {
			return '<div><dt>' + esc( row[0] ) + '</dt><dd>' + esc( row[1] ) + '</dd></div>';
		} ).join( '' );
		const commentRows = fields.map( function ( field ) {
			return '<div><dt>' + esc( field.label || field.key ) + '</dt><dd>' + esc( field.value ) + '</dd></div>';
		} ).join( '' );

		return '<div class="afc-ppp-tooltip-head">' +
			'<div><small>LIVE MIKROTIK PPP</small><strong>' + esc( customer ) + '</strong><span>' + esc( detail.account ) + '</span></div>' +
			'<b class="' + ( detail.online ? 'is-online' : 'is-offline' ) + '">' + ( detail.online ? 'ONLINE' : 'OFFLINE' ) + '</b>' +
			'</div>' +
			'<dl class="afc-ppp-tooltip-grid">' + core + '</dl>' +
			'<div class="afc-ppp-tooltip-section"><h5>PPP comment</h5>' +
			( commentRows ? '<dl class="afc-ppp-tooltip-grid is-comment">' + commentRows + '</dl>' : '<p>No structured PPP comment fields found.</p>' ) +
			'</div>';
	}

	function ensureTooltip() {
		if ( tooltip ) return tooltip;
		tooltip = document.createElement( 'aside' );
		tooltip.className = 'afc-ppp-live-tooltip';
		tooltip.setAttribute( 'role', 'tooltip' );
		tooltip.hidden = true;
		document.body.appendChild( tooltip );
		return tooltip;
	}

	function positionTooltip( result ) {
		if ( ! tooltip || tooltip.hidden || ! result ) return;
		const rect = result.getBoundingClientRect();
		const margin = 10;
		const width = Math.min( 390, window.innerWidth - margin * 2 );
		tooltip.style.width = width + 'px';
		const tooltipRect = tooltip.getBoundingClientRect();
		let left = rect.right + margin;
		if ( left + width > window.innerWidth - margin ) left = Math.max( margin, rect.left - width - margin );
		let top = rect.top;
		if ( top + tooltipRect.height > window.innerHeight - margin ) top = Math.max( margin, window.innerHeight - tooltipRect.height - margin );
		tooltip.style.left = Math.round( left ) + 'px';
		tooltip.style.top = Math.round( top ) + 'px';
	}

	function showTooltip( result ) {
		window.clearTimeout( tooltipHideTimer );
		const account = accountFromResult( result );
		const detail = liveDetails.get( key( account ) );
		if ( ! detail ) return;
		const box = ensureTooltip();
		tooltipTarget = result;
		box.innerHTML = tooltipHtml( result, detail );
		box.hidden = false;
		window.requestAnimationFrame( function () { positionTooltip( result ); } );
	}

	function hideTooltip() {
		window.clearTimeout( tooltipHideTimer );
		tooltipTarget = null;
		if ( tooltip ) tooltip.hidden = true;
	}

	function delayedHideTooltip() {
		window.clearTimeout( tooltipHideTimer );
		tooltipHideTimer = window.setTimeout( hideTooltip, 90 );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return text( settings.data ).includes( 'action=' + action );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! requestHasAction( settings, 'afc_get_ppp_users' ) ) return;
		if ( ! xhr.responseJSON || ! xhr.responseJSON.success ) return;
		const users = xhr.responseJSON.data && Array.isArray( xhr.responseJSON.data.users ) ? xhr.responseJSON.data.users : [];
		rememberUsers( users );
		scheduleDecorate();
	} );

	function boot() {
		decorate();
		registerSearchAjax();

		new MutationObserver( scheduleDecorate ).observe( document.body, {
			childList: true,
			subtree: true,
		} );

		document.addEventListener( 'mouseover', function ( event ) {
			const result = event.target.closest && event.target.closest( '.afc-has-live-ppp-detail' );
			if ( result && result !== tooltipTarget ) showTooltip( result );
		}, true );
		document.addEventListener( 'mouseout', function ( event ) {
			const result = event.target.closest && event.target.closest( '.afc-has-live-ppp-detail' );
			if ( result && ( ! event.relatedTarget || ! result.contains( event.relatedTarget ) ) ) delayedHideTooltip();
		}, true );
		document.addEventListener( 'focusin', function ( event ) {
			const result = event.target.closest && event.target.closest( '.afc-has-live-ppp-detail' );
			if ( result ) showTooltip( result );
		}, true );
		document.addEventListener( 'focusout', function ( event ) {
			const result = event.target.closest && event.target.closest( '.afc-has-live-ppp-detail' );
			if ( result ) delayedHideTooltip();
		}, true );
		window.addEventListener( 'scroll', function () {
			if ( tooltipTarget ) positionTooltip( tooltipTarget );
		}, true );
		window.addEventListener( 'resize', function () {
			if ( tooltipTarget ) positionTooltip( tooltipTarget );
		} );

		/* Browser restore/back can repopulate the field without firing input. */
		window.setTimeout( function () {
			if ( searchModule ) searchModule.refresh();
		}, 300 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
