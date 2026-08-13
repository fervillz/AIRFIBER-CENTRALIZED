( function () {
	'use strict';

	let observer = null;
	let themeObserver = null;

	function currentTheme() {
		return document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'dark' : 'light';
	}

	function applyTheme( theme ) {
		const value = theme === 'dark' ? 'dark' : 'light';
		document.documentElement.setAttribute( 'data-afc-theme', value );
		document.body.setAttribute( 'data-afc-theme', value );
		try { localStorage.setItem( 'afcDashboardTheme', value ); } catch ( error ) {}
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
