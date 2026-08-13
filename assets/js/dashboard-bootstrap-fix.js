( function () {
	'use strict';

	const ADVANCED_INTENT_MS = 12000;
	let advancedIntentUntil = 0;
	let restRequest = null;
	let restWatchTimer = null;

	function text( value ) {
		return value == null ? '' : String( value ).trim();
	}

	function isAdvancedModeButton( node ) {
		return Boolean( node && node.matches && node.matches( '[data-afc-frontend-mode="advanced"], [data-afc-admin-mode="advanced"]' ) );
	}

	function closestAdvancedButton( target ) {
		return target && target.closest ? target.closest( '[data-afc-frontend-mode="advanced"], [data-afc-admin-mode="advanced"]' ) : null;
	}

	function setAdvancedIntent( target ) {
		if ( ! closestAdvancedButton( target ) ) return;
		advancedIntentUntil = Date.now() + ADVANCED_INTENT_MS;
		window.setTimeout( ensureAdvancedDashboard, 20 );
	}

	function rememberPointerIntent( event ) {
		setAdvancedIntent( event.target );
	}

	function rememberKeyboardIntent( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) return;
		setAdvancedIntent( event.target );
	}

	function guardAdvancedSyntheticClick( event ) {
		const button = closestAdvancedButton( event.target );
		if ( ! button ) return;

		if ( event.isTrusted ) {
			setAdvancedIntent( button );
			return;
		}

		/*
		 * Desktop used to restore the previously saved Advanced mode by firing a
		 * synthetic click after Basic had loaded. Ignore that background click.
		 * Ajaxify also uses a synthetic click after a REAL user request, so pointer
		 * and keyboard intent above grant a short replay window for that click.
		 */
		if ( Date.now() > advancedIntentUntil && isAdvancedModeButton( button ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}

	function pluginBase() {
		const source = document.querySelector( 'script[src*="/assets/js/dashboard-bootstrap-fix.js"]' );
		if ( source && source.src ) {
			const marker = '/assets/js/dashboard-bootstrap-fix.js';
			const index = source.src.indexOf( marker );
			if ( index >= 0 ) return source.src.slice( 0, index );
		}
		const admin = document.querySelector( 'script[src*="/assets/js/admin-mode.js"]' );
		if ( admin && admin.src ) {
			const marker = '/assets/js/admin-mode.js';
			const index = admin.src.indexOf( marker );
			if ( index >= 0 ) return admin.src.slice( 0, index );
		}
		return '';
	}

	function loadThemePillAssets() {
		const base = pluginBase();
		if ( ! base ) return;

		if ( ! document.getElementById( 'afc-dashboard-theme-pill-css' ) ) {
			const link = document.createElement( 'link' );
			link.id = 'afc-dashboard-theme-pill-css';
			link.rel = 'stylesheet';
			link.href = base + '/assets/css/dashboard-theme-pill.css?v=2.7.15';
			document.head.appendChild( link );
		}

		if ( ! document.getElementById( 'afc-dashboard-theme-pill-js' ) ) {
			const script = document.createElement( 'script' );
			script.id = 'afc-dashboard-theme-pill-js';
			script.src = base + '/assets/js/dashboard-theme-pill.js?v=2.7.15';
			script.async = false;
			document.body.appendChild( script );
		}
	}

	function ajaxConfig() {
		return window.afcAjaxify || {};
	}

	function dashboardRestSlot() {
		return document.querySelector( '[data-afc-ajaxify-fragment="dashboard-rest"]' );
	}

	function dashboardHasRestCards() {
		const grid = document.querySelector( '#afc-main-dashboard [data-afc-dashboard-grid]' );
		if ( ! grid ) return false;
		return Boolean(
			grid.querySelector( '[data-afc-dashboard-widget="due-soon"], [data-afc-dashboard-widget="recent-expired"], [data-afc-dashboard-widget="network"]' )
		);
	}

	function dispatchRestLoaded() {
		document.dispatchEvent( new CustomEvent( 'afc:ajaxify-fragment-loaded', { detail: { fragment: 'dashboard-rest' } } ) );
	}

	function refreshDashboardData() {
		const refresh = document.querySelector( '#afc-main-dashboard [data-afc-dashboard-refresh]' );
		if ( refresh ) refresh.click();
	}

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = text( value );
		return node.innerHTML;
	}

	function showRestError( slot, message ) {
		if ( ! slot || ! slot.isConnected ) return;
		slot.classList.add( 'is-afc-dashboard-rest-error' );
		slot.innerHTML =
			'<div class="afc-dashboard-rest-retry" role="status">' +
				'<strong>Dashboard cards did not finish loading.</strong>' +
				'<small>' + escapeHtml( message || 'The dashboard card request failed.' ) + '</small>' +
				'<button type="button" data-afc-dashboard-rest-retry>Retry cards</button>' +
			'</div>';
	}

	function directLoadDashboardRest( attempt ) {
		const slot = dashboardRestSlot();
		if ( ! slot ) {
			if ( dashboardHasRestCards() ) dispatchRestLoaded();
			return Promise.resolve();
		}
		if ( restRequest ) return restRequest;

		const cfg = ajaxConfig();
		if ( ! cfg.ajaxUrl || ! cfg.nonce ) {
			showRestError( slot, 'Ajaxify is not ready yet.' );
			return Promise.resolve();
		}

		const body = new URLSearchParams();
		body.set( 'action', 'afc_ajaxify_fragment' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'fragment', 'dashboard-rest' );

		restRequest = window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Invalid dashboard card response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success || ! response.data || ! response.data.html ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Dashboard card request failed.' );
			}

			const liveSlot = dashboardRestSlot();
			if ( ! liveSlot ) return;
			const template = document.createElement( 'template' );
			template.innerHTML = text( response.data.html );
			const parent = liveSlot.parentNode;
			if ( ! parent ) return;
			parent.insertBefore( template.content, liveSlot );
			liveSlot.remove();
			dispatchRestLoaded();
			window.setTimeout( refreshDashboardData, 30 );
		} ).catch( function ( error ) {
			const nextAttempt = Number( attempt || 0 ) + 1;
			if ( nextAttempt < 3 ) {
				restRequest = null;
				return new Promise( function ( resolve ) {
					window.setTimeout( function () {
						directLoadDashboardRest( nextAttempt ).then( resolve );
					}, 350 * nextAttempt );
				} );
			}
			showRestError( dashboardRestSlot(), error && error.message ? error.message : 'Dashboard card request failed.' );
			dispatchRestLoaded();
		} ).finally( function () {
			restRequest = null;
		} );

		return restRequest;
	}

	function watchRestCompletion() {
		window.clearTimeout( restWatchTimer );
		restWatchTimer = window.setTimeout( function () {
			if ( dashboardRestSlot() && ! dashboardHasRestCards() ) directLoadDashboardRest( 0 );
		}, 2300 );
	}

	function ensureAdvancedDashboard() {
		loadThemePillAssets();
		const ajaxify = window.AFCAjaxify;
		if ( ajaxify && typeof ajaxify.loadPanel === 'function' ) {
			Promise.resolve( ajaxify.loadPanel( 'dashboard' ) ).then( function () {
				watchRestCompletion();
			} ).catch( function () {
				watchRestCompletion();
			} );
		} else {
			watchRestCompletion();
		}
	}

	function forceBasicAtStartup() {
		/* Fresh page loads are always Basic. Advanced remains available on demand. */
		const root = document.getElementById( 'afc-frontend-app' );
		if ( root ) root.setAttribute( 'data-afc-mode', 'basic' );
	}

	function bind() {
		document.addEventListener( 'pointerdown', rememberPointerIntent, true );
		document.addEventListener( 'keydown', rememberKeyboardIntent, true );
		document.addEventListener( 'click', guardAdvancedSyntheticClick, true );

		document.addEventListener( 'click', function ( event ) {
			const retry = event.target.closest && event.target.closest( '[data-afc-dashboard-rest-retry]' );
			if ( retry ) {
				event.preventDefault();
				const slot = dashboardRestSlot();
				if ( slot ) {
					slot.classList.remove( 'is-afc-dashboard-rest-error' );
					slot.innerHTML = '<span class="afc-ajaxify-spinner" aria-hidden="true"></span> Loading dashboard cards…';
				}
				directLoadDashboardRest( 0 );
			}
		}, true );

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			const mode = event.detail && event.detail.mode ? event.detail.mode : '';
			if ( mode === 'advanced' ) ensureAdvancedDashboard();
		} );

		document.addEventListener( 'afc:ajaxify-panel-loaded', function ( event ) {
			if ( event.detail && event.detail.panel === 'dashboard' ) watchRestCompletion();
		} );
	}

	function boot() {
		forceBasicAtStartup();
		loadThemePillAssets();
		bind();
	}

	forceBasicAtStartup();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
