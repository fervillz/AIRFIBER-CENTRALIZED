( function () {
	'use strict';

	function baseUrl() {
		const source = document.querySelector( 'script[src*="/assets/js/dashboard-premium-safe.js"]' );
		if ( ! source || ! source.src ) return '';
		const marker = '/assets/js/dashboard-premium-safe.js';
		const index = source.src.indexOf( marker );
		return index >= 0 ? source.src.slice( 0, index ) : '';
	}

	function forceLight() {
		document.documentElement.setAttribute( 'data-afc-theme', 'light' );
		document.body.setAttribute( 'data-afc-theme', 'light' );
		try { localStorage.removeItem( 'afcDashboardTheme' ); } catch ( error ) {}
		document.querySelectorAll( '[data-afc-dashboard-theme-toggle], #afc-dashboard-theme-pill-css, #afc-dashboard-theme-pill-js, #afc-dashboard-theme-pill-v216-js' ).forEach( function ( node ) {
			node.remove();
		} );
	}

	function loadStyle( id, path, version ) {
		if ( document.getElementById( id ) ) return;
		const base = baseUrl();
		if ( ! base ) return;
		const link = document.createElement( 'link' );
		link.id = id;
		link.rel = 'stylesheet';
		link.href = base + path + '?v=' + version;
		document.head.appendChild( link );
	}

	function sync() {
		forceLight();
		loadStyle( 'afc-dashboard-premium-v2-style', '/assets/css/dashboard-premium-v2.css', '2.8.1' );
		loadStyle( 'afc-advanced-ui-cleanup-style', '/assets/css/advanced-ui-cleanup.css', '2.7.17' );
	}

	sync();
	document.addEventListener( 'afc:admin-mode-change', function ( event ) {
		if ( event.detail && event.detail.mode === 'advanced' ) sync();
	} );
	document.addEventListener( 'afc:ajaxify-panel-loaded', function () {
		window.setTimeout( sync, 20 );
	} );
}() );
