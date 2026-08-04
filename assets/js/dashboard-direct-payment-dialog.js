( function ( $ ) {
	'use strict';

	const dialogId = 'afc-dashboard-direct-payment-dialog';
	let selectedUser = null;
	let returnFocus = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function paymentHelp( message, state ) {
		const target = document.querySelector( '[data-afc-dashboard-payment-help]' );
		if ( ! target ) return;
		target.textContent = message || '';
		target.className = 'afc-dashboard-payment-help' + ( state ? ' is-' + state : '' );
	}

	function parseRowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function userFromTable( account ) {
		const rows = document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' );
		for ( let index = 0; index < rows.length; index += 1 ) {
			const user = parseRowUser( rows[ index ] );
			if ( user && text( user.name ) === text( account ) ) return user;
		}
		return null;
	}

	function createDialog() {
		let dialog = document.getElementById( dialogId );
		if ( dialog ) return dialog;

		dialog = document.createElement( 'dialog' );
		dialog.id = dialogId;
		dialog.className = 'afc-dashboard-direct-payment-dialog';
		dialog.innerHTML =
			'<form method="dialog" data-afc-dashboard-direct-payment-form>' +
				'<header>' +
					'<div><small>Record payment today</small><h2 data-afc-dashboard-direct-payment-name>Customer</h2><p data-afc-dashboard-direct-payment-account></p></div>' +
					'<button type="button" class="afc-dashboard-direct-payment-close" data-afc-dashboard-direct-payment-close aria-label="Close">×</button>' +
				'</header>' +
				'<div class="afc-dashboard-direct-payment-body">' +
					'<div class="afc-dashboard-direct-payment-note">This updates MikroTik, records the WordPress payment, and queues the Google Sheet update.</div>' +
					'<div class="afc-dashboard-direct-payment-alert" data-afc-dashboard-direct-payment-alert hidden></div>' +
					'<label for="afc-dashboard-direct-payment-amount">Amount</label>' +
					'<div class="afc-dashboard-direct-payment-amount"><span>₱</span><input id="afc-dashboard-direct-payment-amount" type="number" min="0" step="0.01" required></div>' +
					'<label for="afc-dashboard-direct-payment-method">Payment method</label>' +
					'<select id="afc-dashboard-direct-payment-method"><option value="cash">Cash</option><option value="gcash">GCash</option></select>' +
				'</div>' +
				'<footer>' +
					'<button type="button" class="afc-dashboard-direct-payment-cancel" data-afc-dashboard-direct-payment-close>Cancel</button>' +
					'<button type="submit" class="afc-dashboard-direct-payment-confirm">Confirm Paid Today</button>' +
				'</footer>' +
			'</form>';

		document.body.appendChild( dialog );

		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog || event.target.closest( '[data-afc-dashboard-direct-payment-close]' ) ) {
				event.preventDefault();
				if ( dialog.open ) dialog.close();
			}
		} );

		dialog.addEventListener( 'close', function () {
			selectedUser = null;
			const focusTarget = returnFocus;
			returnFocus = null;
			if ( focusTarget && document.contains( focusTarget ) ) {
				window.setTimeout( function () { focusTarget.focus(); }, 0 );
			}
		} );

		dialog.querySelector( '[data-afc-dashboard-direct-payment-form]' ).addEventListener( 'submit', submitPayment );
		return dialog;
	}

	function showAlert( message ) {
		const dialog = createDialog();
		const alert = dialog.querySelector( '[data-afc-dashboard-direct-payment-alert]' );
		alert.textContent = message || '';
		alert.hidden = ! message;
	}

	function refreshAccountOptions( dialog, user ) {
		const body = dialog.querySelector( '.afc-dashboard-direct-payment-body' );
		if ( ! body ) return;

		const section = body.querySelector( '.afc-payment-account-options' );
		const inline = section && section.querySelector( '[data-afc-inline-options]' );
		if ( section && inline && ! inline.children.length && ! inline.textContent.trim() ) {
			section.remove();
		}

		/* Customer actions listens for body mutations. This marker guarantees a
		 * refresh after the account text is populated, instead of leaving the
		 * empty options shell created while the dialog was still blank. */
		const marker = document.createElement( 'span' );
		marker.hidden = true;
		marker.setAttribute( 'data-afc-payment-user-ready', user.name || '' );
		body.appendChild( marker );
		window.requestAnimationFrame( function () {
			if ( marker.parentNode ) marker.parentNode.removeChild( marker );
		} );

		document.dispatchEvent( new CustomEvent( 'afc:payment-dialog-user', {
			detail: { dialog: dialog, user: user }
		} ) );
	}

	function openForUser( user, trigger ) {
		if ( ! user ) return;
		const dialog = createDialog();
		selectedUser = user;
		returnFocus = trigger || document.activeElement;
		showAlert( '' );

		dialog.querySelector( '[data-afc-dashboard-direct-payment-name]' ).textContent = user.customer_name || user.name || 'Customer';
		dialog.querySelector( '[data-afc-dashboard-direct-payment-account]' ).textContent = user.name || '';
		dialog.querySelector( '#afc-dashboard-direct-payment-amount' ).value = user.payment_amount || '';
		dialog.querySelector( '#afc-dashboard-direct-payment-method' ).value = text( user.payment_method || 'cash' ).toLowerCase();
		refreshAccountOptions( dialog, user );

		if ( dialog.open ) dialog.close();
		try {
			dialog.showModal();
		} catch ( error ) {
			dialog.setAttribute( 'open', 'open' );
		}
		window.requestAnimationFrame( function () {
			dialog.querySelector( '#afc-dashboard-direct-payment-amount' ).focus();
		} );
	}

	function fetchUser( account, trigger ) {
		if ( ! window.afcPPP || ! afcPPP.ajaxUrl || ! afcPPP.nonce ) {
			paymentHelp( 'The payment tool is not ready. Refresh the page and try again.', 'error' );
			return;
		}

		paymentHelp( 'Opening the payment form…', '' );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_get_ppp_users',
			nonce: afcPPP.nonce,
		} ).done( function ( response ) {
			const list = response && response.success && response.data && Array.isArray( response.data.users ) ? response.data.users : [];
			const user = list.find( function ( item ) { return text( item.name ) === text( account ); } );
			if ( user ) {
				openForUser( user, trigger );
				paymentHelp( 'Review the amount and payment method, then confirm.', 'success' );
				return;
			}
			paymentHelp( 'The selected PPP account could not be loaded.', 'error' );
		} ).fail( function () {
			paymentHelp( 'The selected PPP account could not be loaded.', 'error' );
		} );
	}

	function submitPayment( event ) {
		event.preventDefault();
		const dialog = createDialog();
		const amount = dialog.querySelector( '#afc-dashboard-direct-payment-amount' ).value;
		const method = dialog.querySelector( '#afc-dashboard-direct-payment-method' ).value;
		const button = dialog.querySelector( '.afc-dashboard-direct-payment-confirm' );

		if ( ! selectedUser || '' === amount ) {
			showAlert( 'Enter the payment amount.' );
			return;
		}
		if ( ! window.afcPPP || ! afcPPP.ajaxUrl || ! afcPPP.nonce ) {
			showAlert( 'The payment service is not ready. Refresh the page and try again.' );
			return;
		}

		button.disabled = true;
		button.textContent = 'Recording…';
		showAlert( '' );

		$.post( afcPPP.ajaxUrl, {
			action: 'afc_ppp_record_payment',
			nonce: afcPPP.nonce,
			user: selectedUser,
			amount: amount,
			method: method,
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showAlert( response && response.data && response.data.message ? response.data.message : 'The payment could not be recorded.' );
				return;
			}

			if ( dialog.open ) dialog.close();
			const search = document.getElementById( 'afc-dashboard-payment-search' );
			if ( search ) search.value = '';
			paymentHelp( response.data && response.data.message ? response.data.message : 'Payment recorded successfully.', 'success' );

			const refresh = document.getElementById( 'afc-refresh-ppp' );
			if ( refresh && ! refresh.disabled ) {
				window.setTimeout( function () { refresh.click(); }, 150 );
			}
		} ).fail( function ( xhr ) {
			showAlert( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'The payment request failed.' );
		} ).always( function () {
			button.disabled = false;
			button.textContent = 'Confirm Paid Today';
		} );
	}

	/**
	 * Capture the dashboard result click before the older payment tool proxies it
	 * to the hidden Operations table. Opening a dedicated body-level dialog avoids
	 * the invisible modal backdrop that made the whole dashboard unclickable.
	 */
	document.addEventListener( 'click', function ( event ) {
		const result = event.target.closest && event.target.closest( '[data-afc-dashboard-payment-account]' );
		if ( ! result ) return;

		event.preventDefault();
		event.stopPropagation();
		event.stopImmediatePropagation();

		const account = result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
		const user = userFromTable( account );
		if ( user ) {
			openForUser( user, result );
			paymentHelp( 'Review the amount and payment method, then confirm.', 'success' );
			return;
		}
		fetchUser( account, result );
	}, true );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', createDialog );
	} else {
		createDialog();
	}
}( jQuery ) );
