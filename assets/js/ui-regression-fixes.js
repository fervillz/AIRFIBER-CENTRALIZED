( function () {
	'use strict';

	let timer = null;

	function exactExpired( node ) {
		if ( ! node || ! node.textContent ) return false;
		const value = node.textContent.trim().toLowerCase();
		return /^expired\b/.test( value ) && value.length <= 40;
	}

	function markExpiredText() {
		const selectors = [
			'.afc-dashboard-payment-result-meta small',
			'.afc-basic-customer-side span',
			'.afc-basic-selected-details strong',
			'.afc-ppp-status',
			'.afc-account-facts dd',
			'.badge',
			'[data-status="expired"]',
			'td > span',
			'td > small'
		].join( ',' );

		/* Always scan the complete app. Search results are replaced dynamically,
		 * and a MutationObserver target may itself be the status node. */
		document.querySelectorAll( selectors ).forEach( function ( node ) {
			const expired = node.matches( '[data-status="expired"]' ) || exactExpired( node );
			node.classList.toggle( 'afc-expired-copy', expired );
			if ( expired ) node.setAttribute( 'data-afc-expired', '1' );
			else node.removeAttribute( 'data-afc-expired' );
		} );
	}

	function cleanAddButton( button ) {
		if ( ! button ) return;

		button.setAttribute( 'data-afc-no-auto-icon', '' );
		button.classList.remove( 'afc-has-ui-icon' );
		delete button.dataset.afcIconDone;

		Array.from( button.children ).forEach( function ( child ) {
			if ( child.matches && child.matches( 'svg.afc-ui-icon, .afc-ui-icon:not(span[aria-hidden="true"])' ) ) child.remove();
		} );

		const pluses = Array.from( button.querySelectorAll( ':scope > span[aria-hidden="true"]' ) );
		pluses.slice( 1 ).forEach( function ( node ) { node.remove(); } );
	}

	function fixAddButtons() {
		cleanAddButton( document.getElementById( 'afc-basic-add-ppp' ) );
		document.querySelectorAll( '[data-afc-dashboard-add-ppp]' ).forEach( cleanAddButton );
	}

	function syncAccountOptionsState( details ) {
		const summary = details.querySelector( ':scope > summary' );
		if ( ! summary ) return;
		summary.setAttribute( 'aria-expanded', details.open ? 'true' : 'false' );
		details.classList.toggle( 'is-open', details.open );
	}

	function bindAccountOptions() {
		document.querySelectorAll( 'details.afc-payment-account-options' ).forEach( function ( details ) {
			const summary = details.querySelector( ':scope > summary' );
			if ( ! summary ) return;

			summary.setAttribute( 'role', 'button' );
			summary.setAttribute( 'tabindex', '0' );
			syncAccountOptionsState( details );

			if ( details.dataset.afcOptionsToggleBound === '1' ) return;
			details.dataset.afcOptionsToggleBound = '1';

			summary.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				details.open = ! details.open;
				syncAccountOptionsState( details );
			}, true );

			summary.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'Enter' && event.key !== ' ' ) return;
				event.preventDefault();
				details.open = ! details.open;
				syncAccountOptionsState( details );
			} );

			details.addEventListener( 'toggle', function () {
				syncAccountOptionsState( details );
			} );
		} );
	}

	function decorate() {
		fixAddButtons();
		bindAccountOptions();
		markExpiredText();
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( decorate, 30 );
	}

	function boot() {
		decorate();
		new MutationObserver( schedule ).observe( document.body, {
			childList: true,
			subtree: true,
			characterData: true
		} );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
