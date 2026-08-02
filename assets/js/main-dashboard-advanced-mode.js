( function () {
	'use strict';

	let observer = null;

	function currentMode() {
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) return 'advanced';
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) return 'basic';
		const app = document.getElementById( 'afc-frontend-app' );
		return app ? app.getAttribute( 'data-afc-mode' ) || 'basic' : 'basic';
	}

	function markDashboardAdvancedOnly() {
		const panel = document.querySelector( '[data-afc-panel="dashboard"]' );
		const nav = document.querySelector( '[data-afc-app-panel="dashboard"]' );
		if ( panel ) panel.classList.add( 'afc-advanced-only' );
		if ( nav ) nav.classList.add( 'afc-advanced-only' );
	}

	function paymentApp() {
		return document.getElementById( 'afc-basic-payment-app' );
	}

	function restoreBasicPaymentApp() {
		const app = paymentApp();
		const operations = document.querySelector( '[data-afc-panel="operations"] .afc-admin-page .container-fluid' );
		if ( ! app || ! operations ) return false;

		app.classList.add( 'afc-basic-only' );
		app.classList.remove( 'is-dashboard-payment' );

		const switcher = document.getElementById( 'afc-admin-mode-switcher' );
		if ( switcher && switcher.parentNode === operations ) {
			switcher.insertAdjacentElement( 'afterend', app );
		} else if ( operations.firstElementChild !== app ) {
			operations.insertBefore( app, operations.firstChild );
		}
		return true;
	}

	function mountAdvancedPaymentApp() {
		const app = paymentApp();
		const mount = document.querySelector( '[data-afc-dashboard-payment-mount]' );
		if ( ! app || ! mount ) return false;
		app.classList.remove( 'afc-basic-only' );
		app.classList.add( 'is-dashboard-payment' );
		if ( ! mount.contains( app ) ) mount.replaceChildren( app );
		return true;
	}

	function syncMode() {
		markDashboardAdvancedOnly();
		if ( 'advanced' === currentMode() ) mountAdvancedPaymentApp();
		else restoreBasicPaymentApp();
	}

	function boot() {
		syncMode();
		document.addEventListener( 'afc:admin-mode-change', function () {
			window.requestAnimationFrame( syncMode );
		} );

		observer = new MutationObserver( function () {
			markDashboardAdvancedOnly();
			if ( 'basic' === currentMode() ) restoreBasicPaymentApp();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () {
			if ( observer ) observer.disconnect();
		}, 12000 );
	}

	if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
