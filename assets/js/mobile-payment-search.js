( function () {
	'use strict';

	const mobileQuery = window.matchMedia( '(max-width: 640px)' );
	let app = null;
	let input = null;
	let searchWrap = null;
	let results = null;
	let resizeObserver = null;
	let resultsObserver = null;
	let blurTimer = null;
	let measureFrame = null;
	let resultsFrame = null;
	let forceClosed = false;

	function isBasicMobile() {
		return mobileQuery.matches && document.body.classList.contains( 'afc-admin-mode-basic' );
	}

	function numericProperty( name ) {
		const value = window.getComputedStyle( document.body ).getPropertyValue( name );
		return Number.parseFloat( value ) || 0;
	}

	function setProperty( name, value ) {
		document.body.style.setProperty( name, value );
	}

	function queryLength() {
		return input ? input.value.trim().length : 0;
	}

	function minimumCharacters() {
		return Number( window.afcBasicPayments && afcBasicPayments.minCharacters || 3 );
	}

	function keyboardLikelyVisible( lift ) {
		if ( ! input || document.activeElement !== input ) {
			return false;
		}

		if ( lift > 40 ) {
			return true;
		}

		if ( window.visualViewport && window.screen && window.screen.height ) {
			return window.visualViewport.height < window.screen.height * 0.72;
		}

		return true;
	}

	function calculateResultsTop() {
		const header = document.querySelector( '.afc-frontend-header' );
		if ( header ) {
			const rect = header.getBoundingClientRect();
			if ( rect.bottom > 0 ) {
				return Math.max( 8, Math.round( rect.bottom + 8 ) );
			}
		}

		const adminBar = document.getElementById( 'wpadminbar' );
		if ( adminBar ) {
			return Math.max( 8, Math.round( adminBar.getBoundingClientRect().bottom + 8 ) );
		}

		return 8;
	}

	function measureViewport() {
		measureFrame = null;
		if ( ! isBasicMobile() || ! searchWrap ) {
			setProperty( '--afc-mobile-keyboard-lift', '0px' );
			document.body.classList.remove( 'afc-mobile-keyboard-visible' );
			return;
		}

		const wrapRect = searchWrap.getBoundingClientRect();
		const currentLift = numericProperty( '--afc-mobile-keyboard-lift' );
		const viewport = window.visualViewport;
		const visibleBottom = viewport
			? viewport.offsetTop + viewport.height
			: window.innerHeight;

		/* Add the previous lift back to get the undocked baseline before measuring overlap. */
		const baselineBottom = wrapRect.bottom + currentLift;
		const lift = Math.max( 0, Math.ceil( baselineBottom - visibleBottom + 8 ) );
		const dockHeight = Math.max( 76, Math.ceil( wrapRect.height + 8 ) );
		const resultsTop = calculateResultsTop();

		setProperty( '--afc-mobile-keyboard-lift', lift + 'px' );
		setProperty( '--afc-mobile-search-dock-height', dockHeight + 'px' );
		setProperty( '--afc-mobile-results-top', resultsTop + 'px' );
		document.body.classList.toggle( 'afc-mobile-keyboard-visible', keyboardLikelyVisible( lift ) );
	}

	function queueMeasure() {
		if ( measureFrame ) {
			window.cancelAnimationFrame( measureFrame );
		}
		measureFrame = window.requestAnimationFrame( measureViewport );
	}

	function syncResultsLayout() {
		resultsFrame = null;
		if ( ! results ) {
			return;
		}

		const cards = Array.from( results.querySelectorAll( '.afc-basic-customer-result' ) );
		const active = isBasicMobile() && cards.length > 0;
		results.classList.toggle( 'afc-mobile-has-results', active );

		cards.forEach( function ( card, index ) {
			/* The highest-ranked first match stays nearest the bottom search field. */
			card.style.order = active ? String( cards.length - index ) : '';
		} );

		if ( active && app && app.classList.contains( 'afc-mobile-search-open' ) ) {
			window.requestAnimationFrame( function () {
				results.scrollTop = results.scrollHeight;
			} );
		}
	}

	function queueResultsLayout() {
		if ( resultsFrame ) {
			window.cancelAnimationFrame( resultsFrame );
		}
		resultsFrame = window.requestAnimationFrame( syncResultsLayout );
	}

	function setOpen( open ) {
		if ( ! app ) {
			return;
		}

		app.classList.toggle( 'afc-mobile-search-open', Boolean( open && isBasicMobile() ) );
		if ( open ) {
			queueMeasure();
			queueResultsLayout();
		}
	}

	function updateOpenState() {
		if ( ! isBasicMobile() || forceClosed ) {
			setOpen( false );
			return;
		}

		const focused = document.activeElement === input;
		setOpen( focused || queryLength() >= minimumCharacters() );
	}

	function closeForSelection() {
		forceClosed = true;
		if ( input ) {
			input.blur();
		}
		setOpen( false );
		document.body.classList.remove( 'afc-mobile-keyboard-visible' );
		window.setTimeout( queueMeasure, 80 );
	}

	function bind() {
		app = document.getElementById( 'afc-basic-payment-app' );
		if ( ! app || app.dataset.afcMobileSearchBound ) {
			return false;
		}

		input = app.querySelector( '#afc-basic-payment-search' );
		searchWrap = app.querySelector( '.afc-basic-payment-search-wrap' );
		results = app.querySelector( '#afc-basic-payment-results' );
		if ( ! input || ! searchWrap || ! results ) {
			return false;
		}

		app.dataset.afcMobileSearchBound = '1';
		input.setAttribute( 'inputmode', 'search' );
		input.setAttribute( 'enterkeyhint', 'search' );

		input.addEventListener( 'focus', function () {
			window.clearTimeout( blurTimer );
			forceClosed = false;
			setOpen( true );
			queueMeasure();
			queueResultsLayout();
			window.setTimeout( queueMeasure, 80 );
			window.setTimeout( queueMeasure, 260 );
		} );

		input.addEventListener( 'input', function () {
			forceClosed = false;
			updateOpenState();
			queueMeasure();
			queueResultsLayout();
		} );

		input.addEventListener( 'blur', function () {
			window.clearTimeout( blurTimer );
			blurTimer = window.setTimeout( function () {
				updateOpenState();
				queueMeasure();
			}, 180 );
		} );

		/* pointerdown runs before the payment dialog's captured click handler. */
		app.addEventListener( 'pointerdown', function ( event ) {
			if ( event.target.closest( '.afc-basic-customer-result' ) ) {
				closeForSelection();
			}
		}, true );

		app.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-basic-payment-clear' ) ) {
				forceClosed = false;
				window.setTimeout( function () {
					setOpen( true );
					queueMeasure();
					queueResultsLayout();
				}, 0 );
			}
		} );

		if ( 'ResizeObserver' in window ) {
			resizeObserver = new ResizeObserver( queueMeasure );
			resizeObserver.observe( searchWrap );
		}

		resultsObserver = new MutationObserver( function () {
			queueResultsLayout();
			queueMeasure();
		} );
		resultsObserver.observe( results, { childList: true, subtree: true } );

		if ( window.visualViewport ) {
			window.visualViewport.addEventListener( 'resize', queueMeasure );
			window.visualViewport.addEventListener( 'scroll', queueMeasure );
		}
		window.addEventListener( 'resize', function () {
			queueMeasure();
			queueResultsLayout();
		} );
		window.addEventListener( 'orientationchange', function () {
			window.setTimeout( queueMeasure, 180 );
			window.setTimeout( queueResultsLayout, 180 );
		} );

		document.addEventListener( 'afc:admin-mode-change', function () {
			window.setTimeout( function () {
				forceClosed = false;
				updateOpenState();
				queueMeasure();
				queueResultsLayout();
			}, 20 );
		} );

		if ( 'function' === typeof mobileQuery.addEventListener ) {
			mobileQuery.addEventListener( 'change', function () {
				forceClosed = false;
				updateOpenState();
				queueMeasure();
				queueResultsLayout();
			} );
		}

		updateOpenState();
		queueMeasure();
		queueResultsLayout();
		return true;
	}

	function initialize() {
		if ( bind() ) {
			return;
		}

		const observer = new MutationObserver( function () {
			if ( bind() ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () { observer.disconnect(); }, 10000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );