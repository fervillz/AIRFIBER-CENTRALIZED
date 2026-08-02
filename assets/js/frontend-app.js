( function () {
	'use strict';

	let activePanel = 'dashboard';

	function app() {
		return document.getElementById( 'afc-frontend-app' );
	}

	function currentMode() {
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) {
			return 'advanced';
		}
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			return 'basic';
		}
		const root = app();
		return root ? root.getAttribute( 'data-afc-mode' ) || 'basic' : 'basic';
	}

	function syncModeButtons( mode ) {
		document.querySelectorAll( '[data-afc-frontend-mode]' ).forEach( function ( button ) {
			const active = button.getAttribute( 'data-afc-frontend-mode' ) === mode;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		const root = app();
		if ( root ) {
			root.setAttribute( 'data-afc-mode', mode );
		}
	}

	function setPanel( panel, updateUrl ) {
		const root = app();
		if ( ! root ) {
			return;
		}

		let target = root.querySelector( '[data-afc-panel="' + panel + '"]' );
		if ( ! target || ( target.classList.contains( 'afc-advanced-only' ) && 'advanced' !== currentMode() ) ) {
			panel = 'dashboard';
			target = root.querySelector( '[data-afc-panel="dashboard"]' ) || root.querySelector( '[data-afc-panel="operations"]' );
		}

		activePanel = panel;
		root.querySelectorAll( '[data-afc-panel]' ).forEach( function ( section ) {
			const active = section.getAttribute( 'data-afc-panel' ) === panel;
			section.classList.toggle( 'is-active', active );
			section.hidden = ! active;
			section.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
		} );

		root.querySelectorAll( '[data-afc-app-panel]' ).forEach( function ( button ) {
			const active = button.getAttribute( 'data-afc-app-panel' ) === panel;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		try {
			window.sessionStorage.setItem( 'afcFrontendPanel', panel );
		} catch ( error ) {
			// Storage may be blocked by the browser. Navigation still works.
		}

		if ( updateUrl && window.history && window.history.replaceState ) {
			const url = new URL( window.location.href );
			if ( 'dashboard' === panel ) {
				url.hash = '';
			} else {
				url.hash = panel;
			}
			window.history.replaceState( {}, '', url.toString() );
		}

		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function initialPanel() {
		const root = app();
		const hash = String( window.location.hash || '' ).replace( /^#/, '' );
		if ( hash && root && root.querySelector( '[data-afc-panel="' + hash + '"]' ) ) {
			return hash;
		}
		try {
			const stored = window.sessionStorage.getItem( 'afcFrontendPanel' ) || 'dashboard';
			return root && root.querySelector( '[data-afc-panel="' + stored + '"]' ) ? stored : 'dashboard';
		} catch ( error ) {
			return 'dashboard';
		}
	}

	function requestMode( mode ) {
		const original = document.querySelector( '#afc-admin-mode-switcher [data-afc-admin-mode="' + mode + '"]' );
		if ( original ) {
			original.click();
			return;
		}

		// The original switcher is created by admin-mode.js. A very fast click
		// during initial rendering is retried once instead of silently failing.
		window.setTimeout( function () {
			const retry = document.querySelector( '#afc-admin-mode-switcher [data-afc-admin-mode="' + mode + '"]' );
			if ( retry ) {
				retry.click();
			}
		}, 120 );
	}

	function bindNavigation( root ) {
		root.addEventListener( 'click', function ( event ) {
			const panelButton = event.target.closest( '[data-afc-app-panel]' );
			if ( panelButton ) {
				event.preventDefault();
				setPanel( panelButton.getAttribute( 'data-afc-app-panel' ) || 'dashboard', true );
				return;
			}

			const modeButton = event.target.closest( '[data-afc-frontend-mode]' );
			if ( modeButton ) {
				event.preventDefault();
				const mode = modeButton.getAttribute( 'data-afc-frontend-mode' ) || 'basic';
				if ( mode !== currentMode() ) {
					requestMode( mode );
				}
			}
		} );
	}

	function revealApp( root ) {
		window.requestAnimationFrame( function () {
			root.classList.add( 'is-ready' );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const root = app();
		if ( ! root ) {
			return;
		}

		bindNavigation( root );
		syncModeButtons( currentMode() );
		setPanel( initialPanel(), false );
		revealApp( root );

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			const mode = event.detail && event.detail.mode ? event.detail.mode : currentMode();
			syncModeButtons( mode );
			const activeTarget = root.querySelector( '[data-afc-panel="' + activePanel + '"]' );
			if ( 'basic' === mode && activeTarget && activeTarget.classList.contains( 'afc-advanced-only' ) ) {
				setPanel( 'dashboard', true );
			}
		} );

		window.addEventListener( 'hashchange', function () {
			setPanel( String( window.location.hash || '' ).replace( /^#/, '' ) || 'dashboard', false );
		} );
	} );
}() );
