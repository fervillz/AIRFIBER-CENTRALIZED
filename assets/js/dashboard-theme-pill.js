( function () {
	'use strict';

	function currentTheme() {
		return document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'dark' : 'light';
	}

	function applyTheme( theme ) {
		const value = theme === 'dark' ? 'dark' : 'light';
		document.documentElement.setAttribute( 'data-afc-theme', value );
		document.body.setAttribute( 'data-afc-theme', value );
		try { localStorage.setItem( 'afcDashboardTheme', value ); } catch ( error ) {}
	}

	function scriptBase() {
		const source = document.querySelector( 'script[src*="/assets/js/dashboard-theme-pill.js"]' );
		if ( ! source || ! source.src ) return '';
		const marker = '/assets/js/dashboard-theme-pill.js';
		const index = source.src.indexOf( marker );
		return index >= 0 ? source.src.slice( 0, index ) : '';
	}

	function loadPinAssets() {
		if ( ! document.getElementById( 'afc-main-dashboard' ) ) return;
		const base = scriptBase();
		if ( ! base ) return;
		if ( ! document.getElementById( 'afc-dashboard-card-pinning-css' ) ) {
			const link = document.createElement( 'link' );
			link.id = 'afc-dashboard-card-pinning-css';
			link.rel = 'stylesheet';
			link.href = base + '/assets/css/dashboard-card-pinning.css?v=2.8.0';
			document.head.appendChild( link );
		}
		if ( ! document.getElementById( 'afc-dashboard-card-pinning-js' ) ) {
			const script = document.createElement( 'script' );
			script.id = 'afc-dashboard-card-pinning-js';
			script.src = base + '/assets/js/dashboard-card-pinning.js?v=2.8.0';
			script.async = false;
			document.body.appendChild( script );
		}
	}

	function syncButton( button ) {
		if ( ! button ) return;
		const dark = currentTheme() === 'dark';
		button.setAttribute( 'aria-label', dark ? 'Dashboard theme: Dark. Choose Light or Dark.' : 'Dashboard theme: Light. Choose Light or Dark.' );
		button.setAttribute( 'title', dark ? 'Dark mode is active' : 'Light mode is active' );
		button.setAttribute( 'aria-pressed', dark ? 'true' : 'false' );
	}

	function bindDirectChoice( button ) {
		if ( ! button || button.getAttribute( 'data-afc-theme-pill-bound' ) === '1' ) return;
		button.setAttribute( 'data-afc-theme-pill-bound', '1' );
		button.addEventListener( 'click', function ( event ) {
			const choice = event.target.closest && event.target.closest( '.afc-theme-pill-choice' );
			if ( ! choice ) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			applyTheme( choice.classList.contains( 'is-dark' ) ? 'dark' : 'light' );
			syncButton( button );
		}, true );
	}

	function decorate() {
		const button = document.querySelector( '[data-afc-dashboard-theme-toggle]' );
		if ( ! button ) return false;
		if ( button.getAttribute( 'data-afc-theme-pill' ) !== '1' ) {
			button.setAttribute( 'data-afc-theme-pill', '1' );
			const options = document.createElement( 'span' );
			options.className = 'afc-theme-pill-options';
			options.setAttribute( 'aria-hidden', 'true' );
			options.innerHTML =
				'<span class="afc-theme-pill-choice is-light"><span aria-hidden="true">☀</span>Light</span>' +
				'<span class="afc-theme-pill-choice is-dark"><span aria-hidden="true">☾</span>Dark</span>';
			button.appendChild( options );
		}
		bindDirectChoice( button );
		syncButton( button );
		return true;
	}

	function sync() {
		decorate();
		loadPinAssets();
	}

	function boot() {
		sync();
		document.addEventListener( 'afc:ajaxify-panel-loaded', function ( event ) {
			if ( event.detail && event.detail.panel === 'dashboard' ) window.setTimeout( sync, 20 );
		} );
		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			if ( event.detail && event.detail.mode === 'advanced' ) window.setTimeout( sync, 30 );
		} );
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '[data-afc-dashboard-theme-toggle]' ) ) window.setTimeout( function () {
				syncButton( document.querySelector( '[data-afc-dashboard-theme-toggle]' ) );
			}, 0 );
		}, true );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
