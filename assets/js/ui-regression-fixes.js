( function () {
	'use strict';

	let timer = null;
	let burstTimers = [];

	function exactExpired( node ) {
		if ( ! node || ! node.textContent ) return false;
		const value = node.textContent.trim().toLowerCase();
		return /^expired\b/.test( value ) && value.length <= 40;
	}

	function expiredIcon() {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="8.5"></circle><path d="M12 7.5v5l3 1.8"></path></svg>';
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

		document.querySelectorAll( selectors ).forEach( function ( node ) {
			const expired = node.matches( '[data-status="expired"]' ) || exactExpired( node );
			node.classList.toggle( 'afc-expired-copy', expired );
			if ( expired ) node.setAttribute( 'data-afc-expired', '1' );
			else node.removeAttribute( 'data-afc-expired' );
		} );
	}

	function hasLoadedExpiredChip( result ) {
		return Boolean( result.querySelector(
			'.afc-polished-signal.is-expired, .afc-signal-chip.is-due-expired, .afc-v262-chip.is-expired'
		) );
	}

	function renderImmediateExpiredSignals() {
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const meta = result.querySelector( '.afc-dashboard-payment-result-meta small' );
			const copy = result.querySelector( '.afc-dashboard-payment-result-copy' );
			if ( ! copy ) return;

			const expired = exactExpired( meta ) || result.getAttribute( 'data-afc-service-state' ) === 'expired';
			let immediate = copy.querySelector( '.afc-immediate-expired-status' );

			if ( ! expired || hasLoadedExpiredChip( result ) ) {
				if ( immediate ) immediate.remove();
				return;
			}

			if ( ! immediate ) {
				immediate = document.createElement( 'span' );
				immediate.className = 'afc-immediate-expired-status';
				immediate.setAttribute( 'data-afc-live-expired', '1' );
				immediate.innerHTML = expiredIcon() + '<b>EXPIRED</b>';
				copy.appendChild( immediate );
			}
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

			if ( details.dataset.afcOptionsInitialised !== '1' ) {
				details.dataset.afcOptionsInitialised = '1';
				details.open = false;
			}

			summary.setAttribute( 'role', 'button' );
			summary.setAttribute( 'tabindex', '0' );
			syncAccountOptionsState( details );

			if ( details.dataset.afcOptionsToggleBound === '1' ) return;
			details.dataset.afcOptionsToggleBound = '1';
			details.addEventListener( 'toggle', function () {
				syncAccountOptionsState( details );
			} );
		} );
	}

	function repairEmptyAccountOptions() {
		const dialog = document.getElementById( 'afc-dashboard-direct-payment-dialog' );
		if ( ! dialog ) return;
		const accountNode = dialog.querySelector( '[data-afc-dashboard-direct-payment-account]' );
		const body = dialog.querySelector( '.afc-dashboard-direct-payment-body' );
		const account = accountNode ? accountNode.textContent.trim() : '';
		if ( ! account || ! body ) return;

		const details = body.querySelector( '.afc-payment-account-options' );
		const inline = details && details.querySelector( '[data-afc-inline-options]' );
		if ( details && inline && ! inline.children.length && ! inline.textContent.trim() ) {
			details.remove();
		}

		if ( body.dataset.afcOptionsPoked === account ) return;
		body.dataset.afcOptionsPoked = account;
		const marker = document.createElement( 'span' );
		marker.hidden = true;
		marker.setAttribute( 'data-afc-options-refresh', account );
		body.appendChild( marker );
		window.requestAnimationFrame( function () {
			if ( marker.parentNode ) marker.remove();
		} );
	}

	function decorate() {
		fixAddButtons();
		markExpiredText();
		renderImmediateExpiredSignals();
		repairEmptyAccountOptions();
		bindAccountOptions();
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( decorate, 20 );
	}

	function burst() {
		burstTimers.forEach( window.clearTimeout );
		burstTimers = [ 0, 40, 100, 180, 320, 600 ].map( function ( delay ) {
			return window.setTimeout( decorate, delay );
		} );
	}

	function boot() {
		decorate();
		new MutationObserver( schedule ).observe( document.body, {
			childList: true,
			subtree: true,
			characterData: true
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.id === 'afc-dashboard-payment-search' ) burst();
		}, true );
		document.addEventListener( 'afc:payment-dialog-user', burst );
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest && event.target.closest( '[data-afc-dashboard-payment-account], summary' ) ) burst();
		}, true );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
