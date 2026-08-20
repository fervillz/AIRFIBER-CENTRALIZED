( function () {
	'use strict';

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

	function restoreBasicPaymentApp() {
		const app = document.getElementById( 'afc-basic-payment-app' );
		const operations = document.querySelector( '[data-afc-panel="operations"] .afc-admin-page .container-fluid' );
		if ( ! app || ! operations ) return;
		app.classList.add( 'afc-basic-only' );
		app.classList.remove( 'is-dashboard-payment' );
		const switcher = document.getElementById( 'afc-admin-mode-switcher' );
		if ( switcher && switcher.parentNode === operations ) {
			if ( switcher.nextElementSibling !== app ) switcher.insertAdjacentElement( 'afterend', app );
		} else if ( operations.firstElementChild !== app ) {
			operations.insertBefore( app, operations.firstChild );
		}
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

	function forceLightTheme() {
		document.documentElement.setAttribute( 'data-afc-theme', 'light' );
		document.body.setAttribute( 'data-afc-theme', 'light' );
		try { localStorage.removeItem( 'afcDashboardTheme' ); } catch ( error ) {}
	}

	function loadPremiumSafe() {
		if ( document.getElementById( 'afc-dashboard-premium-safe-js' ) ) return;
		const source = document.querySelector( 'script[src*="/assets/js/main-dashboard-advanced-mode.js"]' );
		if ( ! source || ! source.src ) return;
		const marker = '/assets/js/main-dashboard-advanced-mode.js';
		const index = source.src.indexOf( marker );
		if ( index < 0 ) return;
		const script = document.createElement( 'script' );
		script.id = 'afc-dashboard-premium-safe-js';
		script.src = source.src.slice( 0, index ) + '/assets/js/dashboard-premium-safe.js?v=2.8.1';
		script.async = false;
		document.body.appendChild( script );
	}

	function syncMode() {
		revealShell();
		forceLightTheme();
		markDashboardAdvancedOnly();
		restoreBasicPaymentApp();
		if ( currentMode() === 'basic' ) {
			const dashboard = document.querySelector( '[data-afc-panel="dashboard"].is-active' );
			if ( dashboard ) setActivePanel( 'operations' );
		} else {
			loadPremiumSafe();
		}
	}

	function boot() {
		syncMode();
		document.addEventListener( 'click', function ( event ) {
			if ( currentMode() !== 'advanced' ) return;
			if ( event.target.closest( '[data-afc-dashboard-payment-account]' ) ) {
				const dialog = document.getElementById( 'afc-payment-dialog' );
				if ( dialog && dialog.parentNode !== document.body ) document.body.appendChild( dialog );
			}
		}, true );
		document.addEventListener( 'afc:admin-mode-change', function () {
			window.requestAnimationFrame( syncMode );
		} );
		document.addEventListener( 'afc:ajaxify-panel-loaded', function ( event ) {
			if ( event.detail && event.detail.panel === 'dashboard' ) window.setTimeout( syncMode, 20 );
		} );
	}

	forceLightTheme();
	revealShell();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
