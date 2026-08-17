( function ( $ ) {
	'use strict';

	let resetTimer = null;
	let burstTimer = null;

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

	$( document ).ajaxSend( function ( event, xhr, settings ) {
		if ( isForcedOverviewRefresh( settings ) ) showLoading();
	} );

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( ! isForcedOverviewRefresh( settings ) ) return;
		if ( xhr.responseJSON && xhr.responseJSON.success ) showSuccess();
		else showReady();
	} );

	function boot() {
		if ( showReady() ) return;
		let attempts = 0;
		const timer = window.setInterval( function () {
			attempts++;
			if ( showReady() || attempts >= 20 ) window.clearInterval( timer );
		}, 100 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
