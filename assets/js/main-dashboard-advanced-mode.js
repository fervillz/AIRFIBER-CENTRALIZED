( function () {
	'use strict';

	let observer = null;

	function shell() {
		return document.getElementById( 'afc-frontend-app' );
	}

	function currentMode() {
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) return 'advanced';
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) return 'basic';
		const app = shell();
		return app ? app.getAttribute( 'data-afc-mode' ) || 'basic' : 'basic';
	}

	function revealShell() {
		const app = shell();
		if ( app ) app.classList.add( 'is-ready' );
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

	function operationsContainer() {
		return document.querySelector( '[data-afc-panel="operations"] .afc-admin-page .container-fluid' );
	}

	function setActivePanel( panelName ) {
		const app = shell();
		if ( ! app ) return;

		app.querySelectorAll( '[data-afc-panel]' ).forEach( function ( panel ) {
			const active = panel.getAttribute( 'data-afc-panel' ) === panelName;
			panel.classList.toggle( 'is-active', active );
			panel.hidden = ! active;
			panel.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
		} );

		app.querySelectorAll( '[data-afc-app-panel]' ).forEach( function ( button ) {
			const active = button.getAttribute( 'data-afc-app-panel' ) === panelName;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function restoreBasicPaymentApp() {
		const app = paymentApp();
		const operations = operationsContainer();
		if ( ! app || ! operations ) return false;

		app.classList.add( 'afc-basic-only' );
		app.classList.remove( 'is-dashboard-payment' );

		const switcher = document.getElementById( 'afc-admin-mode-switcher' );
		if ( switcher && switcher.parentNode === operations ) {
			if ( switcher.nextElementSibling !== app ) switcher.insertAdjacentElement( 'afterend', app );
		} else if ( operations.firstElementChild !== app ) {
			operations.insertBefore( app, operations.firstChild );
		}
		return true;
	}

	function movePaymentDialogToBody() {
		const dialog = document.getElementById( 'afc-payment-dialog' );
		if ( dialog && dialog.parentNode !== document.body ) document.body.appendChild( dialog );
	}

	function syncMode() {
		revealShell();
		markDashboardAdvancedOnly();
		// The original Basic payment app always stays in Operations. Advanced
		// uses a separate dashboard payment search and never moves this element.
		restoreBasicPaymentApp();

		if ( 'basic' === currentMode() ) setActivePanel( 'operations' );
	}

	function boot() {
		revealShell();
		syncMode();

		document.addEventListener( 'click', function ( event ) {
			if ( 'advanced' === currentMode() && event.target.closest( '[data-afc-dashboard-payment-account]' ) ) {
				movePaymentDialogToBody();
			}
		}, true );

		document.addEventListener( 'afc:admin-mode-change', function () {
			window.requestAnimationFrame( syncMode );
		} );

		observer = new MutationObserver( function () {
			revealShell();
			markDashboardAdvancedOnly();
			restoreBasicPaymentApp();
			if ( 'basic' === currentMode() ) {
				const active = document.querySelector( '[data-afc-panel="dashboard"].is-active' );
				if ( active ) setActivePanel( 'operations' );
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () {
			if ( observer ) observer.disconnect();
			revealShell();
			syncMode();
		}, 12000 );
	}

	revealShell();
	if ( 'loading' === document.readyState ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );