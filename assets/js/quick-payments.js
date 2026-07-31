( function ( $ ) {
	'use strict';

	let users = [];
	let activeUser = null;
	let busy = false;
	let paymentRecorded = false;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) {
			return false;
		}
		if ( settings.data && 'object' === typeof settings.data ) {
			return settings.data.action === action;
		}
		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function isExpired( user ) {
		return String( user && user.actual_profile || '' ).toLowerCase() === 'expired';
	}

	function userByAccount( account ) {
		return users.find( function ( user ) {
			return String( user.name ) === String( account );
		} ) || null;
	}

	function userFromRow( element ) {
		const row = element && element.closest ? element.closest( 'tr[data-user]' ) : null;
		if ( ! row ) {
			return null;
		}
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function createDialog() {
		let dialog = document.getElementById( 'afc-quick-payment-dialog' );
		if ( dialog ) {
			return dialog;
		}

		const host = document.querySelector( '.afc-admin-page' ) || document.body;
		dialog = document.createElement( 'dialog' );
		dialog.id = 'afc-quick-payment-dialog';
		dialog.className = 'afc-dialog afc-quick-payment-dialog';
		dialog.innerHTML =
			'<form method="dialog" class="afc-quick-payment-form">' +
			'<div class="afc-dialog-header">' +
			'<div><div class="text-secondary small">Quick customer action</div>' +
			'<h3 class="mb-0" id="afc-quick-payment-customer"></h3></div>' +
			'<button class="btn-close afc-quick-payment-close" value="cancel" aria-label="Close"></button></div>' +
			'<div class="afc-dialog-body">' +
			'<div class="afc-quick-payment-summary">' +
			'<div><span>PPP account</span><strong id="afc-quick-payment-account"></strong></div>' +
			'<div><span>Plan</span><strong id="afc-quick-payment-plan"></strong></div>' +
			'<div><span>Status</span><strong id="afc-quick-payment-status"></strong></div>' +
			'</div>' +
			'<div class="afc-quick-payment-message" id="afc-quick-payment-message" aria-live="polite"></div>' +
			'<button class="afc-quick-action afc-quick-cash" type="button" data-payment-method="cash">' +
			'<span class="afc-button-spinner" aria-hidden="true"></span><span class="afc-button-label">CASH</span></button>' +
			'<div class="afc-quick-secondary-actions">' +
			'<button class="afc-quick-action afc-quick-gcash" type="button" data-payment-method="gcash">' +
			'<span class="afc-button-spinner" aria-hidden="true"></span><span class="afc-button-label">GCash</span></button>' +
			'<button class="afc-quick-action afc-quick-reconnect" type="button" hidden>' +
			'<span class="afc-button-spinner" aria-hidden="true"></span><span class="afc-button-label">Reconnect</span></button>' +
			'</div>' +
			'<div class="afc-quick-payment-meta"><span>Payment date: <strong id="afc-quick-payment-date"></strong></span>' +
			'<span class="afc-quick-gcash-note">GCash reference: <strong>XXXX</strong></span></div>' +
			'</div></form>';
		host.appendChild( dialog );

		dialog.addEventListener( 'cancel', function ( event ) {
			if ( busy ) {
				event.preventDefault();
			}
		} );

		dialog.addEventListener( 'click', function ( event ) {
			const paymentButton = event.target.closest( '[data-payment-method]' );
			if ( paymentButton ) {
				event.preventDefault();
				recordPayment( paymentButton.getAttribute( 'data-payment-method' ), paymentButton );
				return;
			}
			const reconnectButton = event.target.closest( '.afc-quick-reconnect' );
			if ( reconnectButton ) {
				event.preventDefault();
				reconnectAccount( reconnectButton );
			}
		} );

		return dialog;
	}

	function setMessage( message, type ) {
		const box = document.getElementById( 'afc-quick-payment-message' );
		if ( ! box ) {
			return;
		}
		box.className = 'afc-quick-payment-message' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		box.textContent = message || '';
	}

	function setBusy( button, state, label ) {
		busy = state;
		const dialog = document.getElementById( 'afc-quick-payment-dialog' );
		if ( ! dialog ) {
			return;
		}

		dialog.querySelectorAll( '.afc-quick-action, .afc-quick-payment-close' ).forEach( function ( action ) {
			action.disabled = state || ( paymentRecorded && action.hasAttribute( 'data-payment-method' ) );
		} );

		if ( button ) {
			button.classList.toggle( 'is-loading', state );
			const text = button.querySelector( '.afc-button-label' );
			if ( text ) {
				if ( state ) {
					button.setAttribute( 'data-original-label', text.textContent );
					text.textContent = label || 'Working…';
				} else if ( button.hasAttribute( 'data-original-label' ) ) {
					text.textContent = button.getAttribute( 'data-original-label' );
					button.removeAttribute( 'data-original-label' );
				}
			}
		}
	}

	function showPageNotice( message, type ) {
		const notice = document.getElementById( 'afc-ppp-notice' );
		if ( notice ) {
			notice.innerHTML = '<div class="alert alert-' + escapeHtml( type || 'success' ) + '">' + escapeHtml( message ) + '</div>';
		}

		const basicMessage = document.getElementById( 'afc-basic-payment-message' );
		if ( basicMessage ) {
			basicMessage.className = 'afc-basic-payment-message is-visible is-' + ( type || 'success' );
			basicMessage.textContent = message;
		}
	}

	function refreshAccountLists() {
		$( '#afc-refresh-ppp' ).trigger( 'click' );
	}

	function clearBasicSearch() {
		const clear = document.getElementById( 'afc-basic-payment-clear' );
		if ( clear ) {
			clear.click();
		}
	}

	function openForUser( user ) {
		if ( ! user ) {
			return;
		}

		activeUser = user;
		paymentRecorded = false;
		const dialog = createDialog();
		const expired = isExpired( user );
		const customer = user.customer_name || user.name;
		const plan = user.profile || user.actual_profile || 'Not set';

		document.getElementById( 'afc-quick-payment-customer' ).textContent = customer;
		document.getElementById( 'afc-quick-payment-account' ).textContent = user.name;
		document.getElementById( 'afc-quick-payment-plan' ).textContent = plan;
		document.getElementById( 'afc-quick-payment-status' ).textContent = expired ? 'Expired' : 'Active';
		document.getElementById( 'afc-quick-payment-status' ).className = expired ? 'text-danger' : 'text-success';
		document.getElementById( 'afc-quick-payment-date' ).textContent = window.afcQuickPayments ? afcQuickPayments.currentDate : '';

		const reconnect = dialog.querySelector( '.afc-quick-reconnect' );
		reconnect.hidden = ! expired;
		dialog.querySelector( '.afc-quick-secondary-actions' ).classList.toggle( 'has-reconnect', expired );
		dialog.querySelectorAll( '.afc-quick-action, .afc-quick-payment-close' ).forEach( function ( button ) {
			button.disabled = false;
			button.classList.remove( 'is-loading' );
		} );
		setMessage( expired ? 'Payment and reconnection are separate. Record the payment first, then reconnect when ready.' : '', 'info' );

		if ( ! dialog.open ) {
			dialog.showModal();
		}
		window.setTimeout( function () {
			dialog.querySelector( '.afc-quick-cash' ).focus( { preventScroll: true } );
		}, 60 );
	}

	function recordPayment( method, button ) {
		if ( busy || paymentRecorded || ! activeUser || ! window.afcQuickPayments ) {
			return;
		}

		setMessage( '', '' );
		setBusy( button, true, 'Recording…' );
		$.post( afcQuickPayments.ajaxUrl, {
			action: 'afc_ppp_quick_payment',
			nonce: afcQuickPayments.nonce,
			id: activeUser.id,
			name: activeUser.name,
			method: method
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				setMessage( response && response.data && response.data.message ? response.data.message : 'The payment could not be recorded.', 'danger' );
				return;
			}

			paymentRecorded = true;
			activeUser.payment_date = response.data.date;
			activeUser.payment_method = response.data.method;
			setMessage(
				isExpired( activeUser )
					? response.data.message + ' The account remains expired until Reconnect is clicked.'
					: response.data.message,
				'success'
			);
			showPageNotice( response.data.message, 'success' );
			refreshAccountLists();
			clearBasicSearch();

			if ( isExpired( activeUser ) ) {
				const label = button.querySelector( '.afc-button-label' );
				if ( label ) {
					label.textContent = 'Recorded ✓';
				}
			} else {
				window.setTimeout( function () {
					const dialog = document.getElementById( 'afc-quick-payment-dialog' );
					if ( dialog && dialog.open ) {
						dialog.close();
					}
				}, 650 );
			}
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
				? xhr.responseJSON.data.message
				: 'The payment request failed.';
			setMessage( message, 'danger' );
		} ).always( function () {
			setBusy( button, false );
		} );
	}

	function reconnectAccount( button ) {
		if ( busy || ! activeUser || ! window.afcPPP ) {
			return;
		}

		setMessage( '', '' );
		setBusy( button, true, 'Reconnecting…' );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_ppp_change_service',
			nonce: afcPPP.nonce,
			change: 'reconnect',
			user: activeUser
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				setMessage( response && response.data && response.data.message ? response.data.message : 'The account could not be reconnected.', 'danger' );
				return;
			}

			setMessage( response.data.message || 'Service reconnected.', 'success' );
			showPageNotice( response.data.message || 'Service reconnected.', 'success' );
			refreshAccountLists();
			window.setTimeout( function () {
				const dialog = document.getElementById( 'afc-quick-payment-dialog' );
				if ( dialog && dialog.open ) {
					dialog.close();
				}
			}, 650 );
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
				? xhr.responseJSON.data.message
				: 'The reconnect request failed.';
			setMessage( message, 'danger' );
		} ).always( function () {
			setBusy( button, false );
		} );
	}

	function enhanceTableNames() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = userFromRow( row );
			if ( ! user ) {
				return;
			}
			const nameCell = row.querySelector( 'td:nth-child(2)' );
			if ( ! nameCell ) {
				return;
			}
			const primary = nameCell.querySelector( ':scope > .fw-bold' );
			const secondary = nameCell.querySelector( ':scope > .small.text-secondary' );
			[ primary, secondary ].forEach( function ( element ) {
				if ( ! element ) {
					return;
				}
				element.classList.add( 'afc-customer-action-trigger' );
				element.setAttribute( 'data-afc-account', user.name );
				element.setAttribute( 'role', 'button' );
				element.setAttribute( 'tabindex', '0' );
				element.setAttribute( 'title', 'Open payment and service actions for ' + ( user.customer_name || user.name ) );
			} );
		} );
	}

	function accountFromBasicSelection() {
		const account = document.querySelector( '#afc-basic-payment-selected .afc-basic-selected-account strong' );
		return account ? account.textContent.trim() : '';
	}

	function openFromTrigger( trigger ) {
		let user = null;
		if ( trigger.matches( '.afc-basic-customer-result' ) ) {
			user = userByAccount( trigger.getAttribute( 'data-account' ) || '' );
		} else if ( trigger.matches( '.afc-basic-pay-today' ) ) {
			user = userByAccount( accountFromBasicSelection() );
		} else if ( trigger.matches( '.afc-customer-action-trigger' ) ) {
			user = userByAccount( trigger.getAttribute( 'data-afc-account' ) || '' ) || userFromRow( trigger );
		} else {
			user = userFromRow( trigger );
		}
		openForUser( user );
	}

	$( function () {
		createDialog();
		enhanceTableNames();

		document.addEventListener( 'click', function ( event ) {
			const trigger = event.target.closest( '.afc-basic-customer-result, .afc-basic-pay-today, .afc-pay-today, .afc-customer-action-trigger' );
			if ( ! trigger ) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			openFromTrigger( trigger );
		}, true );

		document.addEventListener( 'keydown', function ( event ) {
			const trigger = event.target.closest( '.afc-customer-action-trigger' );
			if ( ! trigger || ( 'Enter' !== event.key && ' ' !== event.key ) ) {
				return;
			}
			event.preventDefault();
			openFromTrigger( trigger );
		} );

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				users = xhr.responseJSON.data.users || [];
				window.setTimeout( enhanceTableNames, 0 );
			}
		} );

		const table = document.getElementById( 'afc-ppp-table' );
		if ( table ) {
			new MutationObserver( function () {
				window.requestAnimationFrame( enhanceTableNames );
			} ).observe( table, { childList: true, subtree: true } );
		}
	} );
}( jQuery ) );
