( function ( $ ) {
	'use strict';

	let resetTimer = null;
	let burstTimer = null;
	let resultTimer = null;

	function recycleIcon() {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 7v5h-5"></path><path d="M18.2 12A6.5 6.5 0 1 1 16 7.2L19 10"></path></svg>';
	}

	function loadingIcon() {
		return '<svg class="afc-olt-refresh-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle></svg>';
	}

	function checkIcon() {
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.1 4L19 7"></path></svg>';
	}

	function control() {
		return document.querySelector( '.afc-frontend-nav [data-afc-app-panel="optical"] .afc-olt-nav-refresh' );
	}

	function iconNode( button ) {
		return button ? button.querySelector( '.afc-olt-nav-refresh-icon' ) : null;
	}

	function clearTimers() {
		window.clearTimeout( resetTimer );
		window.clearTimeout( burstTimer );
		resetTimer = null;
		burstTimer = null;
	}

	function cleanState( button ) {
		if ( ! button ) return;
		button.classList.remove( 'is-loading', 'is-success', 'is-success-burst' );
		button.setAttribute( 'aria-disabled', 'false' );
	}

	function showReady() {
		const button = control();
		if ( ! button ) return false;
		clearTimers();
		cleanState( button );
		button.dataset.state = 'ready';
		const node = iconNode( button );
		if ( node ) node.innerHTML = recycleIcon();
		return true;
	}

	function showLoading() {
		const button = control();
		if ( ! button ) return;
		clearTimers();
		cleanState( button );
		button.dataset.state = 'loading';
		button.classList.add( 'is-loading' );
		button.setAttribute( 'aria-disabled', 'true' );
		const node = iconNode( button );
		if ( node ) node.innerHTML = loadingIcon();
	}

	function showSuccess() {
		const button = control();
		if ( ! button ) return;
		clearTimers();
		cleanState( button );
		button.dataset.state = 'success';
		button.classList.add( 'is-success' );
		const node = iconNode( button );
		if ( node ) node.innerHTML = checkIcon();

		/* Keep the clean check visible for one second before the confirmation burst. */
		burstTimer = window.setTimeout( function () {
			if ( ! document.body.contains( button ) || button.dataset.state !== 'success' ) return;
			button.classList.add( 'is-success-burst' );
		}, 1000 );

		/* One second of green, then a soft fade back to the normal recycle state. */
		resetTimer = window.setTimeout( function () {
			showReady();
		}, 6000 );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function isForcedOverviewRefresh( settings ) {
		if ( ! requestHasAction( settings, 'afc_get_olt_overview' ) ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return Number( settings.data.refresh || 0 ) === 1;
		return /(?:^|&)refresh=1(?:&|$)/.test( String( settings.data || '' ) );
	}

	function resultFromNode( node ) {
		return node && node.closest ? node.closest( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ) : null;
	}

	function accountFromResult( result ) {
		if ( ! result ) return '';
		return result.getAttribute( 'data-account' ) || result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
	}

	function rxChipFromTarget( target ) {
		return target && target.closest ? target.closest( '.afc-search-rx-refresh' ) : null;
	}

	function findRxChip( live ) {
		if ( ! live ) return null;
		const chips = Array.from( live.querySelectorAll( ':scope > .afc-search-live-chip' ) );
		return chips.find( function ( chip ) {
			return /^RX\s+-?\d/i.test( String( chip.textContent || '' ).trim() );
		} ) || null;
	}

	function decorateResult( result ) {
		if ( ! result ) return;
		result.querySelectorAll( '.afc-search-ajaxify-info' ).forEach( function ( info ) { info.remove(); } );
		result.querySelectorAll( '.afc-search-ajaxify-live > .afc-olt-result-tools' ).forEach( function ( tools ) { tools.remove(); } );

		const live = result.querySelector( '.afc-search-ajaxify-live' );
		const chip = findRxChip( live );
		if ( ! chip || chip.classList.contains( 'afc-search-rx-refresh' ) ) return;

		chip.classList.add( 'afc-search-rx-refresh' );
		chip.setAttribute( 'role', 'button' );
		chip.setAttribute( 'tabindex', '0' );
		chip.setAttribute( 'aria-label', 'Refresh this customer optical RX' );
		chip.setAttribute( 'title', 'Click to refresh this RX only' );

		const icon = document.createElement( 'span' );
		icon.className = 'afc-search-rx-refresh-icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.innerHTML = recycleIcon();
		chip.appendChild( icon );
	}

	function decorateResults() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( decorateResult );
	}

	function scheduleResults() {
		window.clearTimeout( resultTimer );
		resultTimer = window.setTimeout( decorateResults, 20 );
	}

	function syncSearchRecords() {
		if ( ! window.AFCSearchAjaxify || typeof window.AFCSearchAjaxify.refresh !== 'function' ) return;
		window.AFCSearchAjaxify.refresh( 'basic-payment' );
		window.AFCSearchAjaxify.refresh( 'advanced-payment' );
	}

	function applyOpticalToChip( chip, optical ) {
		if ( ! chip || ! optical ) return;
		const label = chip.querySelector( 'b' );
		const rx = Number( optical.rx_power );

		chip.classList.remove( 'is-safe', 'is-warning', 'is-danger', 'is-neutral' );
		if ( optical.status === 'critical' ) chip.classList.add( 'is-danger' );
		else if ( optical.status === 'warning' ) chip.classList.add( 'is-warning' );
		else if ( optical.status === 'offline' ) chip.classList.add( 'is-neutral' );
		else chip.classList.add( 'is-safe' );

		if ( label ) {
			if ( Number.isFinite( rx ) && rx < -1 ) label.textContent = 'RX ' + rx.toFixed( 2 ) + ' dBm';
			else if ( optical.status === 'offline' ) label.textContent = 'OLT OFFLINE';
			else label.textContent = 'RX unavailable';
		}
	}

	function refreshCustomerRx( chip ) {
		const result = resultFromNode( chip );
		const account = accountFromResult( result );
		const refreshCfg = window.afcOLTRefresh || {};
		if ( ! result || ! account || ! refreshCfg.ajaxUrl || ! refreshCfg.nonce || chip.dataset.afcRxRefreshing === '1' ) return;

		chip.dataset.afcRxRefreshing = '1';
		chip.classList.add( 'is-refreshing' );
		chip.setAttribute( 'aria-busy', 'true' );
		const icon = chip.querySelector( '.afc-search-rx-refresh-icon' );
		if ( icon ) icon.innerHTML = loadingIcon();

		$.post( refreshCfg.ajaxUrl, {
			action: 'afc_refresh_customer_optical',
			nonce: refreshCfg.nonce,
			account: account
		} ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data || ! response.data.optical ) return;
			applyOpticalToChip( chip, response.data.optical );
			chip.classList.add( 'is-refresh-success' );
			if ( icon ) icon.innerHTML = checkIcon();
			window.setTimeout( syncSearchRecords, 80 );
		} ).always( function () {
			chip.dataset.afcRxRefreshing = '0';
			chip.classList.remove( 'is-refreshing' );
			chip.removeAttribute( 'aria-busy' );
			window.setTimeout( function () {
				if ( ! document.body.contains( chip ) ) return;
				chip.classList.remove( 'is-refresh-success' );
				const currentIcon = chip.querySelector( '.afc-search-rx-refresh-icon' );
				if ( currentIcon ) currentIcon.innerHTML = recycleIcon();
			}, 900 );
		} );
	}

	$( document ).ajaxSend( function ( event, xhr, settings ) {
		if ( isForcedOverviewRefresh( settings ) ) showLoading();
	} );

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( ! isForcedOverviewRefresh( settings ) ) return;
		if ( xhr.responseJSON && xhr.responseJSON.success ) showSuccess();
		else showReady();
	} );

	function bindResultEvents() {
		document.addEventListener( 'pointerdown', function ( event ) {
			const chip = rxChipFromTarget( event.target );
			if ( ! chip ) return;
			event.preventDefault();
			event.stopPropagation();
		}, true );

		document.addEventListener( 'click', function ( event ) {
			const chip = rxChipFromTarget( event.target );
			if ( ! chip ) return;
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();
			refreshCustomerRx( chip );
		}, true );

		document.addEventListener( 'keydown', function ( event ) {
			const chip = rxChipFromTarget( event.target );
			if ( ! chip || ( event.key !== 'Enter' && event.key !== ' ' ) ) return;
			event.preventDefault();
			event.stopPropagation();
			refreshCustomerRx( chip );
		}, true );

		document.addEventListener( 'afc:search-ajaxify:results', scheduleResults );
		new MutationObserver( scheduleResults ).observe( document.body, { childList: true, subtree: true } );
	}

	function boot() {
		if ( ! showReady() ) {
			let attempts = 0;
			const timer = window.setInterval( function () {
				attempts++;
				if ( showReady() || attempts >= 20 ) window.clearInterval( timer );
			}, 100 );
		}

		bindResultEvents();
		decorateResults();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
