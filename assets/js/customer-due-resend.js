( function () {
	'use strict';

	const cfg = window.afcCustomerDueResend || {};
	let timer = null;

	function accountFor( button ) {
		const inline = button.closest( '.afc-payment-account-options' );
		if ( inline ) return ( inline.getAttribute( 'data-account' ) || '' ).trim();
		const dialog = button.closest( '#afc-customer-actions-dialog' );
		const node = dialog && dialog.querySelector( '[data-afc-actions-account]' );
		return node ? node.textContent.trim() : '';
	}

	function messageNode( button ) {
		const inline = button.closest( '.afc-payment-account-options' );
		if ( inline ) return inline.querySelector( '[data-afc-inline-message]' );
		const dialog = button.closest( '#afc-customer-actions-dialog' );
		return dialog ? dialog.querySelector( '[data-afc-actions-message]' ) : null;
	}

	function showMessage( button, value, type ) {
		const node = messageNode( button );
		if ( ! node ) return;
		node.hidden = ! value;
		node.className = node.hasAttribute( 'data-afc-inline-message' )
			? 'afc-inline-action-message' + ( type ? ' is-' + type : '' )
			: 'afc-customer-actions-message' + ( type ? ' is-' + type : '' );
		node.textContent = value || '';
	}

	function request( account ) {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_sms_resend_due' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'account', account );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Airfiber returned an invalid response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'The due SMS could not be queued.' );
			}
			return response.data || {};
		} );
	}

	function inject() {
		document.querySelectorAll( '.afc-account-option-actions' ).forEach( function ( actions ) {
			if ( actions.querySelector( '[data-afc-resend-due]' ) ) return;
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-due-resend-button';
			button.setAttribute( 'data-afc-resend-due', '' );
			button.setAttribute( 'data-afc-no-auto-icon', '' );
			button.innerHTML = '<span aria-hidden="true">↻</span><b>Resend due SMS</b>';
			actions.appendChild( button );
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( inject, 30 );
	}

	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '[data-afc-resend-due]' );
		if ( ! button ) return;
		event.preventDefault();
		event.stopPropagation();
		const account = accountFor( button );
		if ( ! account ) {
			showMessage( button, 'Choose a PPP account first.', 'error' );
			return;
		}
		if ( ! window.confirm( 'Resend the due SMS for ' + account + '?' ) ) return;
		const original = button.innerHTML;
		button.disabled = true;
		button.textContent = 'Queueing…';
		showMessage( button, '', '' );
		request( account ).then( function ( data ) {
			showMessage( button, data.message || 'Due SMS queued again.', 'success' );
			document.dispatchEvent( new CustomEvent( 'afc:sms-activity-refresh', { detail: { account: account, jobId: data.jobId || 0 } } ) );
		} ).catch( function ( error ) {
			showMessage( button, error.message, 'error' );
		} ).finally( function () {
			button.disabled = false;
			button.innerHTML = original;
		} );
	}, true );

	function boot() {
		inject();
		new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
