( function () {
	'use strict';

	let observer = null;
	let scheduled = false;

	function text( value ) {
		return value == null ? '' : String( value ).replace( /\s+/g, ' ' ).trim();
	}

	function helpLabel( source ) {
		const container = source.closest( '.card-header, .page-header, .mb-3, .form-group, label' ) || source.parentElement;
		const title = container && container.querySelector( 'h1, h2, h3, h4, .card-title, .page-title, .form-label' );
		return title && text( title.textContent ) ? 'About ' + text( title.textContent ) : 'More information';
	}

	function anchorFor( source ) {
		const cardHeader = source.closest( '.card-header' );
		if ( cardHeader ) return cardHeader.querySelector( 'h1, h2, h3, h4, .card-title' );

		const pageHeader = source.closest( '.page-header' );
		if ( pageHeader ) return pageHeader.querySelector( 'h1, h2, .page-title' );

		const field = source.closest( '.mb-3, .form-group' );
		if ( field ) return field.querySelector( '.form-label' );

		return null;
	}

	function attachTooltip( button, message ) {
		button.title = message;
		if ( ! window.AFCTooltip || 'function' !== typeof window.AFCTooltip.attach ) return;

		button.removeAttribute( 'title' );
		window.AFCTooltip.attach( button, {
			content: message,
			placement: 'bottom',
			className: 'afc-context-tooltip',
			hideOnClick: false
		} );
		button.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			event.stopPropagation();
			window.AFCTooltip.show( button );
		} );
	}

	function compact( source ) {
		if ( ! source || source.dataset.afcHelpReady === '1' ) return;
		const message = text( source.textContent );
		if ( ! message ) return;

		source.dataset.afcHelpReady = '1';
		source.classList.add( 'afc-context-help-source' );
		source.setAttribute( 'aria-hidden', 'true' );

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-context-help';
		button.textContent = 'i';
		button.setAttribute( 'aria-label', helpLabel( source ) );

		const anchor = anchorFor( source );
		if ( anchor ) {
			anchor.appendChild( button );
		} else {
			button.dataset.afcHelpLocation = 'standalone';
			source.parentNode.insertBefore( button, source );
		}

		attachTooltip( button, message );
	}

	function cleanSidebar() {
		document.querySelectorAll( '.afc-workspace-menu-item' ).forEach( function ( item ) {
			const heading = item.querySelector( 'strong' );
			const helper = item.querySelector( 'small' );
			const title = text( heading && heading.textContent );
			const short = text( helper && helper.textContent );
			const detail = text( item.getAttribute( 'data-afc-ws-description' ) );
			const tooltip = [ title, short, detail ].filter( Boolean ).join( ' — ' );
			if ( tooltip ) item.title = tooltip;
		} );
	}

	function clean() {
		const app = document.getElementById( 'afc-frontend-app' );
		if ( ! app ) return;
		forceLightTheme();

		app.querySelectorAll(
			'.card-header .card-subtitle, ' +
			'.form-hint:not(.text-success):not([data-afc-keep-visible]), ' +
			'[data-afc-help-source]'
		).forEach( compact );
		cleanSidebar();
	}

	function schedule() {
		if ( scheduled ) return;
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			clean();
		} );
	}

	function forceLightTheme() {
		document.documentElement.setAttribute( 'data-afc-theme', 'light' );
		if ( document.body ) document.body.setAttribute( 'data-afc-theme', 'light' );
		try { localStorage.setItem( 'afcDashboardTheme', 'light' ); } catch ( error ) {}
		document.querySelectorAll( '[data-afc-dashboard-theme-toggle]' ).forEach( function ( button ) {
			button.remove();
		} );
	}

	function boot() {
		forceLightTheme();
		clean();
		const app = document.getElementById( 'afc-frontend-app' );
		if ( app ) {
			observer = new MutationObserver( schedule );
			observer.observe( app, { childList: true, subtree: true } );
		}
		document.addEventListener( 'afc:admin-mode-change', function () {
			forceLightTheme();
			schedule();
		} );
		document.addEventListener( 'afc:ajaxify-panel-loaded', schedule );
	}

	forceLightTheme();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
