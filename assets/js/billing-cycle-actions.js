( function ( $ ) {
	'use strict';

	if ( ! window.afcBillingCycles ) {
		return;
	}

	const users = new Map();
	let activeUser = null;
	let pressTimer = null;
	let suppressClickUntil = 0;
	let pressedButton = null;

	function requestHasAction( settings, action ) {
		if ( ! settings ) {
			return false;
		}
		if ( settings.data && 'object' === typeof settings.data ) {
			return settings.data.action === action;
		}
		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function customValue( user, key ) {
		const fields = user && user.comment_fields && 'object' === typeof user.comment_fields
			? user.comment_fields
			: {};
		const wanted = String( key ).toLowerCase();
		const match = Object.keys( fields ).find( function ( fieldKey ) {
			return String( fieldKey ).toLowerCase() === wanted;
		} );
		return match ? String( fields[ match ] || '' ).trim() : '';
	}

	function setCustomValue( user, key, value ) {
		if ( ! user ) {
			return;
		}
		if ( ! user.comment_fields || 'object' !== typeof user.comment_fields ) {
			user.comment_fields = {};
		}
		const wanted = String( key ).toLowerCase();
		const match = Object.keys( user.comment_fields ).find( function ( fieldKey ) {
			return String( fieldKey ).toLowerCase() === wanted;
		} );
		user.comment_fields[ match || key ] = value;
	}

	function currentCycle( user ) {
		const value = Number( customValue( user, 'billingCycleDays' ) || 0 );
		return 15 === value || 30 === value ? value : 0;
	}

	function cycleLabel( cycle ) {
		return 15 === Number( cycle ) ? '15D' : ( 30 === Number( cycle ) ? '30D' : 'MTH' );
	}

	function setDialogMessage( message, type ) {
		const box = document.getElementById( 'afc-quick-payment-message' );
		if ( ! box ) {
			return;
		}
		box.className = 'afc-quick-payment-message' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		box.textContent = message || '';
	}

	function accountFromDialog( dialog ) {
		const account = dialog && dialog.querySelector( '#afc-quick-payment-account' );
		return account ? account.textContent.trim() : '';
	}

	function ensureSheet( dialog ) {
		let sheet = dialog.querySelector( '.afc-cycle-override-sheet' );
		if ( sheet ) {
			return sheet;
		}

		sheet = document.createElement( 'div' );
		sheet.className = 'afc-cycle-override-sheet';
		sheet.hidden = true;
		sheet.innerHTML =
			'<button type="button" class="afc-cycle-sheet-backdrop" data-afc-cycle-close aria-label="Close options"></button>' +
			'<section class="afc-cycle-sheet-card" role="dialog" aria-modal="true" aria-labelledby="afc-cycle-sheet-title">' +
				'<header>' +
					'<div><span>Payment override</span><h4 id="afc-cycle-sheet-title">Cycle and amount</h4></div>' +
					'<button type="button" class="btn-close" data-afc-cycle-close aria-label="Close"></button>' +
				'</header>' +
				'<p class="afc-cycle-sheet-help">15D starts on the payment date and has no grace. 30D also starts on the payment date. The choice is used when CASH or GCash is recorded.</p>' +
				'<div class="afc-cycle-choice-grid">' +
					'<label><input type="radio" name="afc-cycle-choice" value="15"><span><strong>15D</strong><small>Exact 15 days</small></span></label>' +
					'<label><input type="radio" name="afc-cycle-choice" value="30"><span><strong>30D</strong><small>Exact 30 days</small></span></label>' +
				'</div>' +
				'<label class="afc-cycle-amount-field"><span>Payment amount <small>optional</small></span>' +
					'<div><b>₱</b><input type="number" min="0" step="0.01" inputmode="decimal" data-afc-cycle-amount placeholder="Use saved amount">' +
					'<button type="button" data-afc-amount-1000>₱1,000</button></div>' +
				'</label>' +
				'<div class="afc-cycle-sheet-actions">' +
					'<button type="button" class="btn btn-primary" data-afc-apply-override>Use for Next Payment</button>' +
					'<button type="button" class="btn btn-outline-secondary" data-afc-cycle-close>Cancel</button>' +
				'</div>' +
				'<div class="afc-promise-block">' +
					'<div><strong>Promise to pay</strong><span>Use an exact date instead of increasing grace above 6.</span></div>' +
					'<label><span>Promised date</span><input type="date" data-afc-promise-date></label>' +
					'<div class="afc-promise-actions">' +
						'<button type="button" class="btn btn-warning" data-afc-save-promise>Save Promise</button>' +
						'<button type="button" class="btn btn-outline-secondary" data-afc-clear-promise>Clear</button>' +
					'</div>' +
					'<p data-afc-promise-message aria-live="polite"></p>' +
				'</div>' +
			'</section>';

		dialog.appendChild( sheet );

		sheet.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-cycle-close]' ) ) {
				closeSheet( dialog );
				return;
			}
			if ( event.target.closest( '[data-afc-amount-1000]' ) ) {
				sheet.querySelector( '[data-afc-cycle-amount]' ).value = '1000';
				return;
			}
			if ( event.target.closest( '[data-afc-apply-override]' ) ) {
				applyOverride( dialog );
				return;
			}
			if ( event.target.closest( '[data-afc-save-promise]' ) ) {
				savePromise( dialog, false );
				return;
			}
			if ( event.target.closest( '[data-afc-clear-promise]' ) ) {
				savePromise( dialog, true );
			}
		} );

		return sheet;
	}

	function ensureDecorated( dialog ) {
		if ( ! dialog ) {
			return;
		}

		dialog.querySelectorAll( '.afc-quick-action' ).forEach( function ( button ) {
			if ( ! button.querySelector( '.afc-cycle-pill' ) ) {
				const pill = document.createElement( 'span' );
				pill.className = 'afc-cycle-pill';
				pill.setAttribute( 'aria-hidden', 'true' );
				button.appendChild( pill );
			}
			if ( ! button.dataset.afcCycleLongPressBound ) {
				button.dataset.afcCycleLongPressBound = '1';
				bindLongPress( button );
			}
		} );

		if ( ! dialog.querySelector( '.afc-cycle-hold-hint' ) ) {
			const meta = dialog.querySelector( '.afc-quick-payment-meta' );
			if ( meta ) {
				const hint = document.createElement( 'small' );
				hint.className = 'afc-cycle-hold-hint';
				hint.textContent = afcBillingCycles.labels.holdHint;
				meta.insertAdjacentElement( 'afterend', hint );
			}
		}

		ensureSheet( dialog );
		syncDialog( dialog );
	}

	function syncPills( dialog, cycle ) {
		dialog.querySelectorAll( '.afc-cycle-pill' ).forEach( function ( pill ) {
			pill.textContent = cycleLabel( cycle );
		} );
		dialog.querySelectorAll( '.afc-quick-action' ).forEach( function ( button ) {
			button.title = 'Hold to change cycle or amount. Current: ' + cycleLabel( cycle );
		} );
	}

	function syncDialog( dialog ) {
		if ( ! dialog ) {
			return;
		}
		const account = accountFromDialog( dialog );
		activeUser = account ? ( users.get( account ) || null ) : null;
		const pending = dialog.dataset.afcCycleDays ? Number( dialog.dataset.afcCycleDays ) : 0;
		const cycle = pending || currentCycle( activeUser );
		syncPills( dialog, cycle );

		const sheet = ensureSheet( dialog );
		const promise = sheet.querySelector( '[data-afc-promise-date]' );
		if ( promise && activeUser ) {
			promise.value = customValue( activeUser, 'promisedPayDate' );
		}
		const clear = sheet.querySelector( '[data-afc-clear-promise]' );
		if ( clear ) {
			clear.disabled = ! activeUser || ! customValue( activeUser, 'promisedPayDate' );
		}
	}

	function openSheet( button ) {
		const dialog = button.closest( '#afc-quick-payment-dialog' );
		if ( ! dialog || button.disabled ) {
			return;
		}
		syncDialog( dialog );
		const sheet = ensureSheet( dialog );
		const cycle = Number( dialog.dataset.afcCycleDays || currentCycle( activeUser ) || 15 );
		const radio = sheet.querySelector( 'input[name="afc-cycle-choice"][value="' + ( 30 === cycle ? '30' : '15' ) + '"]' );
		if ( radio ) {
			radio.checked = true;
		}
		sheet.querySelector( '[data-afc-cycle-amount]' ).value = dialog.dataset.afcPaymentAmount || '';
		sheet.hidden = false;
		dialog.classList.add( 'afc-cycle-sheet-open' );
		window.setTimeout( function () {
			const first = sheet.querySelector( 'input[name="afc-cycle-choice"]:checked' );
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}, 40 );
	}

	function closeSheet( dialog ) {
		const sheet = dialog && dialog.querySelector( '.afc-cycle-override-sheet' );
		if ( sheet ) {
			sheet.hidden = true;
		}
		if ( dialog ) {
			dialog.classList.remove( 'afc-cycle-sheet-open' );
		}
	}

	function applyOverride( dialog ) {
		const sheet = ensureSheet( dialog );
		const selected = sheet.querySelector( 'input[name="afc-cycle-choice"]:checked' );
		const amount = sheet.querySelector( '[data-afc-cycle-amount]' ).value.trim();
		if ( ! selected ) {
			return;
		}

		dialog.dataset.afcCycleDays = selected.value;
		dialog.dataset.afcOverrideActive = '1';
		if ( amount ) {
			dialog.dataset.afcPaymentAmount = amount;
		} else {
			delete dialog.dataset.afcPaymentAmount;
		}
		syncPills( dialog, Number( selected.value ) );
		closeSheet( dialog );

		const amountText = amount ? ' · ₱' + Number( amount ).toLocaleString() : '';
		setDialogMessage( cycleLabel( selected.value ) + amountText + '. ' + afcBillingCycles.labels.overrideReady, 'info' );
	}

	function setPromiseMessage( sheet, message, type ) {
		const box = sheet.querySelector( '[data-afc-promise-message]' );
		box.className = message ? 'is-visible is-' + ( type || 'info' ) : '';
		box.textContent = message || '';
	}

	function savePromise( dialog, clear ) {
		const sheet = ensureSheet( dialog );
		syncDialog( dialog );
		if ( ! activeUser ) {
			setPromiseMessage( sheet, 'Customer data is still loading. Close and reopen this customer.', 'error' );
			return;
		}

		const input = sheet.querySelector( '[data-afc-promise-date]' );
		const date = clear ? '' : input.value;
		if ( ! clear && ! date ) {
			setPromiseMessage( sheet, 'Choose the promised payment date.', 'error' );
			return;
		}

		const save = sheet.querySelector( '[data-afc-save-promise]' );
		const clearButton = sheet.querySelector( '[data-afc-clear-promise]' );
		save.disabled = true;
		clearButton.disabled = true;
		setPromiseMessage( sheet, clear ? 'Clearing…' : 'Saving…', 'info' );

		$.post( afcBillingCycles.ajaxUrl, {
			action: 'afc_ppp_set_promise_date',
			nonce: afcBillingCycles.nonce,
			id: activeUser.id,
			name: activeUser.name,
			promise_date: date,
			clear: clear ? 1 : 0
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				setPromiseMessage( sheet, response && response.data && response.data.message ? response.data.message : afcBillingCycles.labels.promiseFailed, 'error' );
				return;
			}
			setCustomValue( activeUser, 'promisedPayDate', response.data.promiseDate || '' );
			setCustomValue( activeUser, 'cutoffDate', response.data.cutoffDate || '' );
			input.value = response.data.promiseDate || '';
			setPromiseMessage( sheet, response.data.message || afcBillingCycles.labels.promiseSaved, 'success' );
			setDialogMessage(
				response.data.promiseDate
					? 'Promise saved until ' + response.data.promiseDate + '. Cutoff: ' + response.data.cutoffDate + '.'
					: 'Promise cleared. Cutoff: ' + response.data.cutoffDate + '.',
				'success'
			);
			clearButton.disabled = ! response.data.promiseDate;
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
				? xhr.responseJSON.data.message
				: afcBillingCycles.labels.promiseFailed;
			setPromiseMessage( sheet, message, 'error' );
		} ).always( function () {
			save.disabled = false;
			clearButton.disabled = ! customValue( activeUser, 'promisedPayDate' );
		} );
	}

	function cancelPress() {
		if ( pressTimer ) {
			window.clearTimeout( pressTimer );
			pressTimer = null;
		}
		if ( pressedButton ) {
			pressedButton.classList.remove( 'is-holding' );
			pressedButton = null;
		}
	}

	function bindLongPress( button ) {
		button.addEventListener( 'pointerdown', function ( event ) {
			if ( button.disabled || 2 === event.button ) {
				return;
			}
			cancelPress();
			pressedButton = button;
			button.classList.add( 'is-holding' );
			pressTimer = window.setTimeout( function () {
				pressTimer = null;
				suppressClickUntil = Date.now() + 900;
				button.classList.remove( 'is-holding' );
				if ( navigator.vibrate ) {
					navigator.vibrate( 30 );
				}
				openSheet( button );
			}, Number( afcBillingCycles.longPressMs || 620 ) );
		} );
		[ 'pointerup', 'pointercancel', 'pointerleave' ].forEach( function ( eventName ) {
			button.addEventListener( eventName, cancelPress );
		} );
		button.addEventListener( 'contextmenu', function ( event ) {
			event.preventDefault();
			suppressClickUntil = Date.now() + 900;
			openSheet( button );
		} );
	}

	const originalPost = $.post;
	$.post = function ( url, data, success, dataType ) {
		if ( data && 'afc_ppp_quick_payment' === data.action ) {
			const dialog = document.getElementById( 'afc-quick-payment-dialog' );
			if ( dialog && '1' === dialog.dataset.afcOverrideActive ) {
				data = $.extend( {}, data, {
					cycle_days: dialog.dataset.afcCycleDays || '',
					amount: dialog.dataset.afcPaymentAmount || ''
				} );
			}
		}
		return originalPost.call( $, url, data, success, dataType );
	};

	document.addEventListener( 'click', function ( event ) {
		if ( Date.now() < suppressClickUntil && event.target.closest( '#afc-quick-payment-dialog .afc-quick-action' ) ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			( xhr.responseJSON.data.users || [] ).forEach( function ( user ) {
				users.set( String( user.name ), user );
			} );
			const dialog = document.getElementById( 'afc-quick-payment-dialog' );
			if ( dialog && dialog.open ) {
				syncDialog( dialog );
			}
			return;
		}

		if ( requestHasAction( settings, 'afc_ppp_quick_payment' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			const dialog = document.getElementById( 'afc-quick-payment-dialog' );
			const data = xhr.responseJSON.data || {};
			if ( activeUser ) {
				activeUser.payment_date = data.date || activeUser.payment_date;
				activeUser.payment_method = data.method || activeUser.payment_method;
				activeUser.payment_amount = data.amount;
				setCustomValue( activeUser, 'billingCycleDays', data.cycleDays ? String( data.cycleDays ) : '' );
				setCustomValue( activeUser, 'paidThrough', data.paidThrough || '' );
				setCustomValue( activeUser, 'nextDue', data.nextDue || '' );
				setCustomValue( activeUser, 'cutoffDate', data.cutoffDate || '' );
				setCustomValue( activeUser, 'promisedPayDate', '' );
			}
			if ( dialog ) {
				delete dialog.dataset.afcOverrideActive;
				delete dialog.dataset.afcPaymentAmount;
				dialog.dataset.afcCycleDays = data.cycleDays ? String( data.cycleDays ) : '';
				syncPills( dialog, Number( data.cycleDays || 0 ) );
			}
		}
	} );

	function observeDialog() {
		const existing = document.getElementById( 'afc-quick-payment-dialog' );
		if ( existing ) {
			ensureDecorated( existing );
			const observer = new MutationObserver( function ( mutations ) {
				mutations.forEach( function ( mutation ) {
					if ( 'attributes' === mutation.type && 'open' === mutation.attributeName && existing.open ) {
						window.setTimeout( function () {
							ensureDecorated( existing );
							syncDialog( existing );
						}, 0 );
					}
				} );
			} );
			observer.observe( existing, { attributes: true, childList: true, subtree: true } );
			existing.addEventListener( 'close', function () {
				cancelPress();
				closeSheet( existing );
				delete existing.dataset.afcOverrideActive;
				delete existing.dataset.afcCycleDays;
				delete existing.dataset.afcPaymentAmount;
			} );
			return true;
		}
		return false;
	}

	function boot() {
		if ( observeDialog() ) {
			return;
		}
		const observer = new MutationObserver( function () {
			if ( observeDialog() ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () {
			observer.disconnect();
		}, 12000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( jQuery ) );
