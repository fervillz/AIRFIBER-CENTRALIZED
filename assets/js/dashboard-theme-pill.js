( function () {
	'use strict';

	let observer = null;
	let themeObserver = null;

	function currentTheme() {
		return document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'dark' : 'light';
	}

	function syncButton( button ) {
		if ( ! button ) return;
		const dark = currentTheme() === 'dark';
		button.setAttribute( 'aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode' );
		button.setAttribute( 'title', dark ? 'Switch to light mode' : 'Switch to dark mode' );
		button.setAttribute( 'aria-pressed', dark ? 'true' : 'false' );
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

		syncButton( button );
		return true;
	}

	function boot() {
		decorate();

		observer = new MutationObserver( function () {
			decorate();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		themeObserver = new MutationObserver( function () {
			syncButton( document.querySelector( '[data-afc-dashboard-theme-toggle]' ) );
		} );
		themeObserver.observe( document.documentElement, { attributes: true, attributeFilter: [ 'data-afc-theme' ] } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
