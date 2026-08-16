( function () {
	'use strict';

	function baseUrl() {
		const source = document.querySelector( 'script[src*="/assets/js/dashboard-premium-safe.js"]' );
		if ( ! source || ! source.src ) return '';
		const marker = '/assets/js/dashboard-premium-safe.js';
		const index = source.src.indexOf( marker );
		return index >= 0 ? source.src.slice( 0, index ) : '';
	}

	function loadStyle() {
		if ( document.getElementById( 'afc-dashboard-premium-v2-style' ) ) return;
		const base = baseUrl();
		if ( ! base ) return;
		const link = document.createElement( 'link' );
		link.id = 'afc-dashboard-premium-v2-style';
		link.rel = 'stylesheet';
		link.href = base + '/assets/css/dashboard-premium-v2.css?v=2.8.0';
		document.head.appendChild( link );
	}

	function loadThemeController() {
		if ( document.getElementById( 'afc-dashboard-theme-pill-v216-js' ) ) return;
		const base = baseUrl();
		if ( ! base ) return;
		const script = document.createElement( 'script' );
		script.id = 'afc-dashboard-theme-pill-v216-js';
		script.src = base + '/assets/js/dashboard-theme-pill.js?v=2.8.0';
		script.async = false;
		document.body.appendChild( script );
	}

	function ensureThemeToggle() {
		const root = document.getElementById( 'afc-main-dashboard' );
		const actions = root && root.querySelector( '.afc-dashboard-header-actions' );
		if ( ! actions || actions.querySelector( '[data-afc-dashboard-theme-toggle]' ) ) return;
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-dashboard-theme-toggle';
		button.setAttribute( 'data-afc-dashboard-theme-toggle', '' );
		button.innerHTML = '<span aria-hidden="true">☾</span><b>Theme</b>';
		button.addEventListener( 'click', function () {
			const next = document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'light' : 'dark';
			document.documentElement.setAttribute( 'data-afc-theme', next );
			document.body.setAttribute( 'data-afc-theme', next );
			try { localStorage.setItem( 'afcDashboardTheme', next ); } catch ( error ) {}
		} );
		const refresh = actions.querySelector( '[data-afc-dashboard-refresh]' );
		actions.insertBefore( button, refresh || null );
	}

	function sync() {
		loadStyle();
		ensureThemeToggle();
		loadThemeController();
	}

	sync();
	document.addEventListener( 'afc:ajaxify-panel-loaded', function ( event ) {
		if ( event.detail && event.detail.panel === 'dashboard' ) window.setTimeout( sync, 20 );
	} );
}() );
