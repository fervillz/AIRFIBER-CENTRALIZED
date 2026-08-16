( function () {
	'use strict';

	let activePanel = 'operations';

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

	function defaultPanel() {
		const root = app();
		if ( 'advanced' === currentMode() && root && root.querySelector( '[data-afc-panel="dashboard"]' ) ) {
			return 'dashboard';
		}
		return 'operations';
	}

	function installPanelLinkStyles() {
		if ( document.getElementById( 'afc-native-panel-link-style' ) ) {
			return;
		}

		const style = document.createElement( 'style' );
		style.id = 'afc-native-panel-link-style';
		style.textContent =
			'.afc-frontend-nav a.afc-native-panel-link{' +
			'display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:.55rem .9rem;' +
			'border-radius:.7rem;background:transparent;color:#667382;font:inherit;font-size:.78rem;' +
			'text-decoration:none!important;cursor:pointer;pointer-events:auto;position:relative;z-index:4;' +
			'touch-action:manipulation;transition:color .16s ease,background-color .16s ease,transform .16s ease}' +
			'.afc-frontend-nav a.afc-native-panel-link:hover,.afc-frontend-nav a.afc-native-panel-link:focus-visible{' +
			'background:#eef4fb;color:#206bc4;outline:none}' +
			'.afc-frontend-nav a.afc-native-panel-link.is-active{' +
			'background:#e8f1fc;color:#206bc4}' +
			'.afc-frontend-nav a.afc-native-panel-link:active{transform:scale(.97)}';
		document.head.appendChild( style );
	}

	function installNativePanelLinks( root ) {
		if ( ! root ) {
			return;
		}

		installPanelLinkStyles();
		root.querySelectorAll( '.afc-frontend-nav [data-afc-app-panel]' ).forEach( function ( item ) {
			const panel = item.getAttribute( 'data-afc-app-panel' ) || '';
			if ( ! panel || item.tagName === 'A' ) {
				return;
			}

			const link = document.createElement( 'a' );
			link.href = '#' + panel;
			link.className = ( item.className ? item.className + ' ' : '' ) + 'afc-native-panel-link';
			link.setAttribute( 'data-afc-app-panel', panel );
			link.setAttribute( 'aria-pressed', item.getAttribute( 'aria-pressed' ) || 'false' );
			link.textContent = item.textContent;
			item.replaceWith( link );
		} );
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
			panel = defaultPanel();
			target = root.querySelector( '[data-afc-panel="' + panel + '"]' ) || root.querySelector( '[data-afc-panel="operations"]' );
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

		if ( updateUrl ) {
			if ( window.history && window.history.replaceState ) {
				const url = new URL( window.location.href );
				if ( panel === defaultPanel() ) {
					url.hash = '';
				} else {
					url.hash = panel;
				}
				window.history.replaceState( {}, '', url.toString() );
			} else {
				window.location.hash = panel === defaultPanel() ? '' : panel;
			}
		}

		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function initialPanel() {
		const root = app();
		const hash = String( window.location.hash || '' ).replace( /^#/, '' );
		if ( hash && root ) {
			const target = root.querySelector( '[data-afc-panel="' + hash + '"]' );
			if ( target && ( ! target.classList.contains( 'afc-advanced-only' ) || 'advanced' === currentMode() ) ) {
				return hash;
			}
		}

		if ( 'advanced' !== currentMode() ) {
			return 'operations';
		}

		try {
			const stored = window.sessionStorage.getItem( 'afcFrontendPanel' ) || '';
			const target = stored && root ? root.querySelector( '[data-afc-panel="' + stored + '"]' ) : null;
			if ( target && ( ! target.classList.contains( 'afc-advanced-only' ) || 'advanced' === currentMode() ) ) {
				return stored;
			}
		} catch ( error ) {
			// Use the Advanced dashboard default below.
		}
		return defaultPanel();
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
				const panel = panelButton.getAttribute( 'data-afc-app-panel' ) || defaultPanel();
				setPanel( panel, true );
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

		installNativePanelLinks( root );
		bindNavigation( root );
		syncModeButtons( currentMode() );
		setPanel( initialPanel(), false );
		revealApp( root );

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			const mode = event.detail && event.detail.mode ? event.detail.mode : currentMode();
			syncModeButtons( mode );
			const activeTarget = root.querySelector( '[data-afc-panel="' + activePanel + '"]' );
			if ( 'basic' === mode ) {
				if ( ! activeTarget || activeTarget.classList.contains( 'afc-advanced-only' ) ) {
					setPanel( 'operations', true );
				}
			} else if ( 'advanced' === mode && 'operations' === activePanel && root.querySelector( '[data-afc-panel="dashboard"]' ) ) {
				setPanel( 'dashboard', true );
			}
		} );

		window.addEventListener( 'hashchange', function () {
			setPanel( String( window.location.hash || '' ).replace( /^#/, '' ) || defaultPanel(), false );
		} );
	} );
}() );
