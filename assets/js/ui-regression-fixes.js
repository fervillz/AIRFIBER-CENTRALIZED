( function () {
	'use strict';

	let timer = null;

	function exactExpired( node ) {
		if ( ! node || ! node.textContent ) return false;
		const value = node.textContent.trim().toLowerCase();
		return /^expired\b/.test( value ) && value.length <= 40;
	}

	function markExpiredText( root ) {
		const scope = root && root.querySelectorAll ? root : document;
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

		scope.querySelectorAll( selectors ).forEach( function ( node ) {
			const expired = node.matches( '[data-status="expired"]' ) || exactExpired( node );
			node.classList.toggle( 'afc-expired-copy', expired );
		} );
	}

	function fixBasicAddButton() {
		const button = document.getElementById( 'afc-basic-add-ppp' );
		if ( ! button ) return;

		button.setAttribute( 'data-afc-no-auto-icon', '' );
		button.classList.remove( 'afc-has-ui-icon' );
		delete button.dataset.afcIconDone;

		Array.from( button.children ).forEach( function ( child ) {
			if ( child.matches && child.matches( 'svg.afc-ui-icon' ) ) child.remove();
		} );

		const pluses = Array.from( button.querySelectorAll( ':scope > span[aria-hidden="true"]' ) );
		pluses.slice( 1 ).forEach( function ( node ) { node.remove(); } );
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

	function decorate( root ) {
		fixBasicAddButton();
		bindAccountOptions();
		markExpiredText( root || document );
	}

	function schedule( root ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () { decorate( root ); }, 40 );
	}

	function boot() {
		decorate( document );
		new MutationObserver( function ( mutations ) {
			let root = document;
			for ( let index = 0; index < mutations.length; index++ ) {
				const target = mutations[ index ].target;
				if ( target && target.nodeType === 1 ) {
					root = target;
					break;
				}
			}
			schedule( root );
		} ).observe( document.body, { childList: true, subtree: true, characterData: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
