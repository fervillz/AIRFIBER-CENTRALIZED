( function () {
	'use strict';

	const cfg = window.afcAjaxify || {};
	const nativeFetch = window.fetch.bind( window );
	const loadedGroups = new Set();
	const loadingGroups = new Map();
	const loadedPanels = new Set();
	const loadingPanels = new Map();
	const loadedFragments = new Set();
	let dashboardRestPromise = null;
	let dashboardDataGate = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function app() {
		return document.getElementById( 'afc-frontend-app' );
	}

	function residentPanel( panel ) {
		const node = document.querySelector( '[data-afc-panel="' + panel + '"]' );
		return node && ! node.hasAttribute( 'data-afc-ajaxify-panel' ) ? node : null;
	}

	function connectionIsSlow() {
		const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
		if ( ! connection ) return false;
		return Boolean( connection.saveData ) || /(^|-)2g$|3g/i.test( text( connection.effectiveType ) );
	}

	function isMobile() {
		return Boolean( cfg.mobileHint ) || window.matchMedia( '(max-width: 782px)' ).matches || connectionIsSlow();
	}

	function idle( callback, timeout ) {
		if ( 'requestIdleCallback' in window ) {
			window.requestIdleCallback( callback, { timeout: timeout || 1200 } );
		} else {
			window.setTimeout( callback, 180 );
		}
	}

	function requestAction( options ) {
		const body = options && options.body;
		if ( ! body ) return '';
		try {
			const params = body instanceof URLSearchParams ? body : new URLSearchParams( text( body ) );
			return params.get( 'action' ) || '';
		} catch ( error ) {
			return '';
		}
	}

	function waitForDashboardCards() {
		if ( ! document.querySelector( '[data-afc-ajaxify-fragment="dashboard-rest"]' ) ) return Promise.resolve();
		if ( dashboardDataGate ) return dashboardDataGate;

		dashboardDataGate = new Promise( function ( resolve ) {
			let finished = false;
			const finish = function () {
				if ( finished ) return;
				finished = true;
				document.removeEventListener( 'afc:ajaxify-fragment-loaded', onLoaded );
				resolve();
			};
			const onLoaded = function ( event ) {
				if ( event.detail && event.detail.fragment === 'dashboard-rest' ) finish();
			};
			document.addEventListener( 'afc:ajaxify-fragment-loaded', onLoaded );
			/* A failed card fragment must never block the working payment search. */
			window.setTimeout( finish, isMobile() ? 6000 : 4000 );
		} );
		return dashboardDataGate;
	}

	function installDashboardDataGate() {
		if ( window.fetch && window.fetch.__afcAjaxifyGate ) return;
		const gatedFetch = function ( input, options ) {
			if ( requestAction( options ) === 'afc_dashboard_data' ) {
				return waitForDashboardCards().then( function () { return nativeFetch( input, options ); } );
			}
			return nativeFetch( input, options );
		};
		gatedFetch.__afcAjaxifyGate = true;
		window.fetch = gatedFetch;
	}

	function runInline( source ) {
		if ( ! source ) return;
		const script = document.createElement( 'script' );
		script.text = source;
		document.head.appendChild( script );
		script.remove();
	}

	function loadStyle( asset ) {
		if ( ! asset || ! asset.handle ) return Promise.resolve();
		const id = asset.handle + '-css';
		if ( document.getElementById( id ) || ! asset.src ) return Promise.resolve();
		return new Promise( function ( resolve ) {
			const link = document.createElement( 'link' );
			link.id = id;
			link.rel = 'stylesheet';
			link.href = asset.src;
			link.media = asset.media || 'all';
			link.onload = resolve;
			link.onerror = resolve;
			document.head.appendChild( link );
		} );
	}

	function loadScript( asset ) {
		if ( ! asset || ! asset.handle ) return Promise.resolve();
		const id = asset.handle + '-js';
		if ( document.getElementById( id ) ) return Promise.resolve();

		( asset.before || [] ).forEach( runInline );
		runInline( asset.data || '' );

		if ( ! asset.src ) {
			( asset.after || [] ).forEach( runInline );
			return Promise.resolve();
		}

		return new Promise( function ( resolve ) {
			const script = document.createElement( 'script' );
			script.id = id;
			script.src = asset.src;
			script.async = false;
			script.onload = function () {
				( asset.after || [] ).forEach( runInline );
				resolve();
			};
			script.onerror = resolve;
			document.body.appendChild( script );
		} );
	}

	function groupAssets( group ) {
		return cfg.assets && cfg.assets[ group ] ? cfg.assets[ group ] : { styles: [], scripts: [] };
	}

	function loadGroup( group ) {
		if ( ! group || loadedGroups.has( group ) ) return Promise.resolve();
		if ( loadingGroups.has( group ) ) return loadingGroups.get( group );

		const assets = groupAssets( group );
		const promise = Promise.all( ( assets.styles || [] ).map( loadStyle ) ).then( function () {
			return ( assets.scripts || [] ).reduce( function ( chain, asset ) {
				return chain.then( function () { return loadScript( asset ); } );
			}, Promise.resolve() );
		} ).then( function () {
			loadedGroups.add( group );
			document.dispatchEvent( new CustomEvent( 'afc:ajaxify-group-loaded', { detail: { group: group } } ) );
		} ).finally( function () {
			loadingGroups.delete( group );
		} );

		loadingGroups.set( group, promise );
		return promise;
	}

	function requestFragment( fragment ) {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_ajaxify_fragment' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'fragment', fragment );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Invalid Airfiber fragment response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success || ! response.data || ! response.data.html ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Airfiber fragment failed.' );
			}
			return response.data.html;
		} );
	}

	function parseNode( html ) {
		const template = document.createElement( 'template' );
		template.innerHTML = text( html ).trim();
		return template.content.firstElementChild;
	}

	function setPanelBusy( panel, busy, failed ) {
		const node = document.querySelector( '[data-afc-panel="' + panel + '"]' );
		if ( ! node ) return;
		node.classList.toggle( 'is-ajaxifying', Boolean( busy ) );
		if ( typeof failed === 'boolean' ) node.classList.toggle( 'is-ajaxify-failed', failed );
		const placeholder = node.querySelector( '.afc-ajaxify-placeholder small' );
		if ( placeholder ) {
			placeholder.textContent = failed ? ( cfg.labels && cfg.labels.failed || 'Tap to try again.' ) : ( cfg.labels && cfg.labels.loading || 'Loading this tool…' );
		}
	}

	function replacePanel( panel, html ) {
		const current = document.querySelector( '[data-afc-panel="' + panel + '"]' );
		const replacement = parseNode( html );
		if ( ! current || ! replacement ) throw new Error( 'Airfiber panel markup was not found.' );
		current.replaceWith( replacement );
		document.dispatchEvent( new CustomEvent( 'afc:ajaxify-panel-loaded', { detail: { panel: panel, node: replacement } } ) );
		return replacement;
	}

	function panelSettings( panel ) {
		return cfg.panels && cfg.panels[ panel ] ? cfg.panels[ panel ] : { group: panel, server: true };
	}

	function waitForPanel( panel, timeout ) {
		const started = Date.now();
		return new Promise( function ( resolve, reject ) {
			( function poll() {
				const node = document.querySelector( '[data-afc-panel="' + panel + '"]' );
				if ( node && ! node.hasAttribute( 'data-afc-ajaxify-panel' ) ) return resolve( node );
				if ( Date.now() - started > ( timeout || 2500 ) ) return reject( new Error( 'Airfiber panel did not initialize.' ) );
				window.setTimeout( poll, 40 );
			}() );
		} );
	}

	function removeClientPlaceholder( panel ) {
		const node = document.querySelector( '[data-afc-panel="' + panel + '"][data-afc-ajaxify-panel]' );
		if ( node ) node.remove();
	}

	function loadServerPanel( panel ) {
		return requestFragment( panel ).then( function ( html ) { return replacePanel( panel, html ); } );
	}

	function loadDashboardRest() {
		if ( loadedFragments.has( 'dashboard-rest' ) ) return Promise.resolve();
		if ( dashboardRestPromise ) return dashboardRestPromise;
		const slot = document.querySelector( '[data-afc-ajaxify-fragment="dashboard-rest"]' );
		if ( ! slot ) return Promise.resolve();

		dashboardRestPromise = requestFragment( 'dashboard-rest' ).then( function ( html ) {
			const template = document.createElement( 'template' );
			template.innerHTML = text( html ).trim();
			const parent = slot.parentNode;
			parent.insertBefore( template.content, slot );
			slot.remove();
			loadedFragments.add( 'dashboard-rest' );
			dashboardDataGate = Promise.resolve();
			document.dispatchEvent( new CustomEvent( 'afc:ajaxify-fragment-loaded', { detail: { fragment: 'dashboard-rest' } } ) );
			const refresh = document.querySelector( '[data-afc-dashboard-refresh]' );
			if ( refresh ) refresh.click();
		} ).catch( function () {
			dashboardRestPromise = null;
		} );
		return dashboardRestPromise;
	}

	function scheduleDashboardRest() {
		idle( loadDashboardRest, isMobile() ? 1800 : 900 );
	}

	function ensurePanel( panel ) {
		const resident = residentPanel( panel );
		if ( resident ) {
			loadedPanels.add( panel );
			return Promise.resolve( resident );
		}
		if ( loadedPanels.has( panel ) ) return Promise.resolve( document.querySelector( '[data-afc-panel="' + panel + '"]' ) );
		if ( loadingPanels.has( panel ) ) return loadingPanels.get( panel );

		const settings = panelSettings( panel );
		setPanelBusy( panel, true, false );

		const promise = loadGroup( 'workspace' ).then( function () {
			if ( settings.server ) return loadServerPanel( panel );
			removeClientPlaceholder( panel );
			return null;
		} ).then( function () {
			return loadGroup( settings.group || panel );
		} ).then( function () {
			if ( settings.server ) return document.querySelector( '[data-afc-panel="' + panel + '"]' );
			return waitForPanel( panel, 3500 );
		} ).then( function ( node ) {
			loadedPanels.add( panel );
			if ( panel === 'dashboard' ) scheduleDashboardRest();
			setPanelBusy( panel, false, false );
			return node;
		} ).catch( function ( error ) {
			setPanelBusy( panel, false, true );
			throw error;
		} ).finally( function () {
			loadingPanels.delete( panel );
		} );

		loadingPanels.set( panel, promise );
		return promise;
	}

	function panelFromButton( button ) {
		return button && ( button.getAttribute( 'data-afc-app-panel' ) || button.getAttribute( 'data-afc-ws-panel' ) || '' );
	}

	function replayClick( button ) {
		if ( ! button || ! button.isConnected ) return;
		button.disabled = false;
		button.setAttribute( 'data-afc-ajaxify-pass', '1' );
		button.click();
		button.removeAttribute( 'data-afc-ajaxify-pass' );
	}

	function advancedButton() {
		return document.querySelector( '[data-afc-frontend-mode="advanced"]' );
	}

	function showAdvancedAfterReady( button ) {
		return ensurePanel( 'dashboard' ).then( function () {
			replayClick( button || advancedButton() );
		} );
	}

	function intercept() {
		document.addEventListener( 'click', function ( event ) {
			const modeButton = event.target.closest && event.target.closest( '[data-afc-frontend-mode="advanced"]' );
			if ( modeButton && modeButton.getAttribute( 'data-afc-ajaxify-pass' ) !== '1' && ! loadedPanels.has( 'dashboard' ) ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				modeButton.disabled = true;
				showAdvancedAfterReady( modeButton ).catch( function () {
					modeButton.disabled = false;
				} );
				return;
			}

			const panelButton = event.target.closest && event.target.closest( '[data-afc-app-panel], [data-afc-ws-panel]' );
			const panel = panelFromButton( panelButton );
			if ( ! panel || panelButton.getAttribute( 'data-afc-ajaxify-pass' ) === '1' || loadedPanels.has( panel ) ) return;

			/* Optical and any other already-rendered panel must never be AJAX-loaded. */
			if ( residentPanel( panel ) ) {
				loadedPanels.add( panel );
				return;
			}

			const settings = panelSettings( panel );
			if ( ! settings ) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			panelButton.disabled = true;
			ensurePanel( panel ).then( function () {
				let fresh = panelButton;
				if ( ! fresh.isConnected ) {
					fresh = document.querySelector( '[data-afc-app-panel="' + panel + '"], [data-afc-ws-panel="' + panel + '"]' );
				}
				replayClick( fresh );
			} ).catch( function () {
				if ( panelButton.isConnected ) panelButton.disabled = false;
			} );
		}, true );
	}

	function warmDesktop() {
		if ( isMobile() ) return;
		idle( function () {
			ensurePanel( 'dashboard' ).then( function () {
				if ( text( cfg.savedMode ) === 'advanced' ) replayClick( advancedButton() );
			} );
		}, 1800 );
	}

	function boot() {
		if ( ! app() ) return;
		installDashboardDataGate();
		intercept();
		window.addEventListener( 'load', warmDesktop, { once: true } );
		if ( document.readyState === 'complete' ) warmDesktop();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();

	window.AFCAjaxify = {
		loadPanel: ensurePanel,
		loadGroup: loadGroup,
		isMobile: isMobile
	};
}() );
