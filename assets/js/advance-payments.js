( function ( $ ) {
	'use strict';

	if ( ! window.afcAdvancePayments ) {
		return;
	}

	const users = new Map();
	let settings = Object.assign( {
		presets: [ 1, 3, 6, 12, 24, 60 ],
		max_months: 120,
		auto_amount: 1,
		warning_months: 12
	}, afcAdvancePayments.settings || {} );

	function requestHasAction( requestSettings, action ) {
		if ( ! requestSettings ) {
			return false;
		}
		if ( requestSettings.data && 'object' === typeof requestSettings.data ) {
			return requestSettings.data.action === action;
		}
		return String( requestSettings.data || '' ).includes( 'action=' + action );
	}

	function accountFromDialog( dialog ) {
		const account = dialog && dialog.querySelector( '#afc-quick-payment-account' );
		return account ? account.textContent.trim() : '';
	}

	function currentUser( dialog ) {
		const account = accountFromDialog( dialog );
		return account ? ( users.get( account ) || null ) : null;
	}

	function customValue( user, key ) {
		const fields = user && user.comment_fields && 'object' === typeof user.comment_fields ? user.comment_fields : {};
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

	function parseIso( value ) {
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( String( value || '' ) ) ) {
			return null;
		}
		const parts = String( value ).split( '-' ).map( Number );
		const date = new Date( parts[0], parts[1] - 1, parts[2], 12, 0, 0 );
		return date.getFullYear() === parts[0] && date.getMonth() === parts[1] - 1 && date.getDate() === parts[2] ? date : null;
	}

	function iso( date ) {
		return [
			date.getFullYear(),
			String( date.getMonth() + 1 ).padStart( 2, '0' ),
			String( date.getDate() ).padStart( 2, '0' )
		].join( '-' );
	}

	function monthDate( source, offset, billingDay ) {
		const first = new Date( source.getFullYear(), source.getMonth() + Number( offset || 0 ), 1, 12, 0, 0 );
		const lastDay = new Date( first.getFullYear(), first.getMonth() + 1, 0, 12, 0, 0 ).getDate();
		return new Date( first.getFullYear(), first.getMonth(), Math.min( Math.max( 1, billingDay ), lastDay ), 12, 0, 0 );
	}

	function addDays( source, days ) {
		const date = new Date( source.getTime() );
		date.setDate( date.getDate() + Number( days || 0 ) );
		return date;
	}

	function billingDay( user ) {
		const configured = Number( customValue( user, 'billingDay' ) || 0 );
		if ( configured >= 1 && configured <= 31 ) {
			return configured;
		}
		const installed = parseIso( user && user.installed ? user.installed : '' );
		return installed ? installed.getDate() : 0;
	}

	function nearestDue( source, day ) {
		const candidates = [ -1, 0, 1 ].map( function ( offset ) {
			const date = monthDate( source, offset, day );
			return { date: date, distance: Math.abs( date.getTime() - source.getTime() ) };
		} );
		candidates.sort( function ( first, second ) {
			return first.distance === second.distance ? second.date - first.date : first.distance - second.distance;
		} );
		return candidates[0].date;
	}

	function planRate( user ) {
		const plan = String( user && user.plan ? user.plan : '' );
		const matches = plan.match( /\d+(?:\.\d+)?/g );
		if ( matches && matches.length ) {
			const value = Number( matches[ matches.length - 1 ] );
			if ( value > 0 ) {
				return value;
			}
		}
		const amount = Number( user && user.payment_amount ? user.payment_amount : 0 );
		return Number.isFinite( amount ) && amount > 0 ? amount : 0;
	}

	function monthLabel( months ) {
		months = Number( months || 0 );
		if ( 12 === months ) { return '1 Year'; }
		if ( 24 === months ) { return '2 Years'; }
		if ( 60 === months ) { return '5 Years'; }
		return months + ' Month' + ( 1 === months ? '' : 's' );
	}

	function compactLabel( months ) {
		months = Number( months || 0 );
		return months > 1 ? months + 'M' : 'MTH';
	}

	function setDialogMessage( message, type ) {
		const box = document.getElementById( 'afc-quick-payment-message' );
		if ( ! box ) {
			return;
		}
		box.className = 'afc-quick-payment-message' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		box.textContent = message || '';
	}

	function setPills( dialog, months ) {
		dialog.querySelectorAll( '.afc-cycle-pill' ).forEach( function ( pill ) {
			pill.textContent = compactLabel( months );
		} );
	}

	function previewDates( dialog, months ) {
		const user = currentUser( dialog );
		const day = billingDay( user );
		if ( ! user || ! day ) {
			return null;
		}
		const today = parseIso( afcAdvancePayments.currentDate ) || new Date();
		const existingNext = parseIso( customValue( user, 'nextDue' ) );
		const firstDue = existingNext || nearestDue( today, day );
		const paidThrough = monthDate( firstDue, months - 1, day );
		const nextDue = monthDate( firstDue, months, day );
		const rawGrace = Number( user.grace );
		const grace = Number.isFinite( rawGrace ) ? Math.min( 6, Math.max( 0, rawGrace ) ) : 6;
		const cutoff = addDays( nextDue, grace + 1 );
		return {
			paidThrough: iso( paidThrough ),
			nextDue: iso( nextDue ),
			cutoffDate: iso( cutoff )
		};
	}

	function renderPresetButtons( block ) {
		const wrap = block.querySelector( '[data-afc-advance-presets]' );
		if ( ! wrap ) {
			return;
		}
		wrap.innerHTML = '';
		( settings.presets || [] ).forEach( function ( months ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.dataset.afcAdvancePreset = String( months );
			button.innerHTML = '<strong>' + monthLabel( months ) + '</strong><small>' + months + ' monthly cycle' + ( 1 === Number( months ) ? '' : 's' ) + '</small>';
			wrap.appendChild( button );
		} );
	}

	function updateAdvanceBlock( dialog, block, months, updateAmount ) {
		months = Math.min( Number( settings.max_months || 120 ), Math.max( 1, Number( months || 1 ) ) );
		const input = block.querySelector( '[data-afc-advance-custom]' );
		if ( input ) {
			input.value = String( months );
			input.max = String( settings.max_months || 120 );
		}
		block.querySelectorAll( '[data-afc-advance-preset]' ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', Number( button.dataset.afcAdvancePreset ) === months );
		} );

		const user = currentUser( dialog );
		const dates = previewDates( dialog, months );
		const rate = planRate( user );
		const estimated = rate > 0 ? rate * months : 0;
		const preview = block.querySelector( '[data-afc-advance-preview]' );
		if ( preview ) {
			preview.innerHTML = dates
				? '<div><span>Paid through</span><strong>' + dates.paidThrough + '</strong></div><div><span>Next due</span><strong>' + dates.nextDue + '</strong></div><div><span>Cutoff</span><strong>' + dates.cutoffDate + '</strong></div>'
				: '<p>Billing day is missing, so the dates cannot be previewed yet.</p>';
		}
		const total = block.querySelector( '[data-afc-advance-total]' );
		if ( total ) {
			total.textContent = estimated > 0 ? 'Estimated total: ₱' + estimated.toLocaleString( undefined, { maximumFractionDigits: 2 } ) : 'Enter the payment amount below.';
		}
		if ( updateAmount && Number( settings.auto_amount ) && estimated > 0 ) {
			const amount = dialog.querySelector( '[data-afc-cycle-amount]' );
			if ( amount ) {
				amount.value = String( Math.round( estimated * 100 ) / 100 );
			}
		}

		const warning = block.querySelector( '[data-afc-advance-warning]' );
		if ( warning ) {
			const threshold = Number( settings.warning_months || 12 );
			warning.hidden = months < threshold;
			warning.textContent = months >= threshold
				? monthLabel( months ) + ' will move this customer’s next due date far into the future. Check the preview before recording.'
				: '';
		}
	}

	function monthlySelected( sheet ) {
		const checked = sheet.querySelector( 'input[name="afc-cycle-choice"]:checked' );
		return Boolean( checked && '0' === checked.value );
	}

	function toggleAdvanceAvailability( dialog, sheet ) {
		const block = sheet.querySelector( '.afc-advance-payment-block' );
		if ( ! block ) {
			return;
		}
		const monthly = monthlySelected( sheet );
		block.classList.toggle( 'is-disabled', ! monthly );
		block.querySelectorAll( 'button, input' ).forEach( function ( control ) {
			control.disabled = ! monthly;
		} );
		const note = block.querySelector( '[data-afc-advance-cycle-note]' );
		if ( note ) {
			note.textContent = monthly
				? ( 0 !== currentCycle( currentUser( dialog ) ) ? 'This payment changes the customer back to monthly billing.' : 'Months start from the current nextDue date.' )
				: 'Advance-month presets are available only for Monthly billing.';
		}
	}

	function decorateSheet( sheet ) {
		if ( ! sheet || sheet.dataset.afcAdvanceDecorated ) {
			return;
		}
		sheet.dataset.afcAdvanceDecorated = '1';
		const dialog = sheet.closest( '#afc-quick-payment-dialog' );
		const choices = sheet.querySelector( '.afc-cycle-choice-grid' );
		if ( choices && ! choices.querySelector( 'input[value="0"]' ) ) {
			const monthly = document.createElement( 'label' );
			monthly.className = 'afc-monthly-cycle-choice';
			monthly.innerHTML = '<input type="radio" name="afc-cycle-choice" value="0"><span><strong>MTH</strong><small>Calendar month</small></span>';
			choices.insertBefore( monthly, choices.firstChild );
		}

		const amountField = sheet.querySelector( '.afc-cycle-amount-field' );
		if ( amountField && ! sheet.querySelector( '.afc-advance-payment-block' ) ) {
			const block = document.createElement( 'section' );
			block.className = 'afc-advance-payment-block';
			block.innerHTML = '<header><div><strong>Advance payment</strong><span data-afc-advance-cycle-note>Months start from the current nextDue date.</span></div><small>Manage presets in Advanced → Payment Settings</small></header>' +
				'<div class="afc-advance-presets" data-afc-advance-presets></div>' +
				'<label class="afc-advance-custom"><span>Custom months</span><input type="number" min="1" inputmode="numeric" data-afc-advance-custom value="1"></label>' +
				'<div class="afc-advance-preview" data-afc-advance-preview></div>' +
				'<strong class="afc-advance-total" data-afc-advance-total></strong>' +
				'<p class="afc-advance-warning" data-afc-advance-warning hidden></p>';
			amountField.insertAdjacentElement( 'beforebegin', block );
			renderPresetButtons( block );
			updateAdvanceBlock( dialog, block, 1, false );
		}

		const help = sheet.querySelector( '.afc-cycle-sheet-help' );
		if ( help ) {
			help.textContent = 'Choose Monthly for advance payments. 15D starts on the payment date with no grace; 30D starts on the payment date with normal grace.';
		}

		sheet.addEventListener( 'click', function ( event ) {
			const preset = event.target.closest( '[data-afc-advance-preset]' );
			if ( preset ) {
				const block = preset.closest( '.afc-advance-payment-block' );
				updateAdvanceBlock( dialog, block, Number( preset.dataset.afcAdvancePreset ), true );
			}
		}, false );

		sheet.addEventListener( 'input', function ( event ) {
			if ( event.target.matches( '[data-afc-advance-custom]' ) ) {
				updateAdvanceBlock( dialog, event.target.closest( '.afc-advance-payment-block' ), Number( event.target.value || 1 ), true );
			}
		}, false );

		sheet.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( 'input[name="afc-cycle-choice"]' ) ) {
				toggleAdvanceAvailability( dialog, sheet );
			}
		}, false );

		// Validate and save the monthly advance before the original cycle script
		// handles the same button in the bubble phase.
		sheet.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '[data-afc-apply-override]' ) ) {
				return;
			}
			if ( ! monthlySelected( sheet ) ) {
				delete dialog.dataset.afcAdvanceMode;
				delete dialog.dataset.afcAdvanceMonths;
				return;
			}
			const input = sheet.querySelector( '[data-afc-advance-custom]' );
			const months = Number( input ? input.value : 1 );
			if ( ! Number.isInteger( months ) || months < 1 || months > Number( settings.max_months || 120 ) ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				setDialogMessage( 'Enter advance months from 1 to ' + Number( settings.max_months || 120 ) + '.', 'error' );
				return;
			}
			dialog.dataset.afcAdvanceMode = 'monthly';
			dialog.dataset.afcAdvanceMonths = String( months );
			window.setTimeout( function () {
				setPills( dialog, months );
				setDialogMessage( monthLabel( months ) + ' advance ready. Tap CASH or GCash to record it.', 'info' );
			}, 0 );
		}, true );

		const observer = new MutationObserver( function () {
			if ( sheet.hidden ) {
				return;
			}
			const user = currentUser( dialog );
			const overrideMonthly = 'monthly' === dialog.dataset.afcAdvanceMode;
			const cycle = currentCycle( user );
			const radio = sheet.querySelector( 'input[name="afc-cycle-choice"][value="' + ( overrideMonthly || 0 === cycle ? '0' : String( cycle ) ) + '"]' );
			if ( radio ) {
				radio.checked = true;
			}
			const block = sheet.querySelector( '.afc-advance-payment-block' );
			const months = Number( dialog.dataset.afcAdvanceMonths || 1 );
			if ( block ) {
				renderPresetButtons( block );
				updateAdvanceBlock( dialog, block, months, false );
			}
			toggleAdvanceAvailability( dialog, sheet );
		} );
		observer.observe( sheet, { attributes: true, attributeFilter: [ 'hidden' ] } );
	}

	function observeSheet() {
		const sheet = document.querySelector( '#afc-quick-payment-dialog .afc-cycle-override-sheet' );
		if ( sheet ) {
			decorateSheet( sheet );
			return true;
		}
		return false;
	}

	const previousPost = $.post;
	$.post = function ( url, data, success, dataType ) {
		if ( data && 'afc_ppp_quick_payment' === data.action ) {
			const dialog = document.getElementById( 'afc-quick-payment-dialog' );
			if ( dialog && 'monthly' === dialog.dataset.afcAdvanceMode ) {
				data = $.extend( {}, data, {
					advance_mode: 'monthly',
					advance_months: dialog.dataset.afcAdvanceMonths || '1'
				} );
			}
		}
		return previousPost.call( $, url, data, success, dataType );
	};

	$( document ).ajaxSuccess( function ( event, xhr, requestSettings ) {
		if ( requestHasAction( requestSettings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			( xhr.responseJSON.data.users || [] ).forEach( function ( user ) {
				users.set( String( user.name ), user );
			} );
			return;
		}
		if ( requestHasAction( requestSettings, 'afc_ppp_quick_payment' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			const dialog = document.getElementById( 'afc-quick-payment-dialog' );
			const data = xhr.responseJSON.data || {};
			const user = dialog ? currentUser( dialog ) : null;
			if ( user && data.advanceMonths ) {
				setCustomValue( user, 'billingCycleDays', '' );
				setCustomValue( user, 'paidThrough', data.paidThrough || '' );
				setCustomValue( user, 'nextDue', data.nextDue || '' );
				setCustomValue( user, 'cutoffDate', data.cutoffDate || '' );
			}
			if ( dialog ) {
				delete dialog.dataset.afcAdvanceMode;
				delete dialog.dataset.afcAdvanceMonths;
			}
		}
	} );

	document.addEventListener( 'afc:payment-settings-updated', function ( event ) {
		if ( event.detail && 'object' === typeof event.detail ) {
			settings = Object.assign( {}, settings, event.detail );
			const sheet = document.querySelector( '#afc-quick-payment-dialog .afc-cycle-override-sheet' );
			const block = sheet && sheet.querySelector( '.afc-advance-payment-block' );
			if ( block ) {
				renderPresetButtons( block );
				updateAdvanceBlock( sheet.closest( '#afc-quick-payment-dialog' ), block, Number( block.querySelector( '[data-afc-advance-custom]' ).value || 1 ), false );
			}
		}
	} );

	function boot() {
		if ( ! observeSheet() ) {
			const observer = new MutationObserver( function () {
				if ( observeSheet() ) {
					observer.disconnect();
				}
			} );
			observer.observe( document.body, { childList: true, subtree: true } );
			window.setTimeout( function () { observer.disconnect(); }, 15000 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( jQuery ) );
