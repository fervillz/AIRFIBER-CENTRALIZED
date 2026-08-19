( function ( $ ) {
	'use strict';

	if ( ! window.afcPPPOperationsUX ) {
		return;
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function plainText( value ) {
		const text = String( value || '' ).toLowerCase();
		return 'function' === typeof text.normalize
			? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: text;
	}

	function waitFor( test, callback, attempts ) {
		attempts = undefined === attempts ? 30 : attempts;
		const result = test();
		if ( result ) {
			callback( result );
			return;
		}
		if ( attempts <= 0 ) {
			return;
		}
		window.setTimeout( function () {
			waitFor( test, callback, attempts - 1 );
		}, 100 );
	}

	function ensureBasicAddButton() {
		const app = document.getElementById( 'afc-basic-payment-app' );
		const heading = app && app.querySelector( '.afc-basic-payment-heading' );
		if ( ! heading ) {
			return;
		}

		/* The native Add PPP control is now moved into the Basic header by the
		 * unified PPP/Billing UX layer. Keep the older helper only as a fallback
		 * for installs where that native control is genuinely unavailable. */
		const source = document.getElementById( 'afc-add-ppp-account' );
		const legacy = document.getElementById( 'afc-basic-add-ppp' );
		if ( source ) {
			if ( legacy ) {
				legacy.remove();
			}
			return;
		}
		if ( legacy ) {
			return;
		}

		const button = document.createElement( 'button' );
		button.id = 'afc-basic-add-ppp';
		button.type = 'button';
		button.className = 'afc-basic-add-ppp';
		button.title = 'Add PPP Account';
		button.setAttribute( 'aria-label', 'Add PPP Account' );
		button.innerHTML = '<span aria-hidden="true">+</span><small>Add PPP</small>';
		button.addEventListener( 'click', function () {
			const dialog = document.getElementById( 'afc-ppp-create-dialog' );
			if ( dialog && ! dialog.open ) {
				dialog.showModal();
			}
		} );
		heading.appendChild( button );
	}

	function rowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function searchableUser( user ) {
		return plainText( [
			user.customer_name,
			user.name,
			user.phone,
			user.address_text,
			user.address,
			user.profile,
		].join( ' ' ) );
	}

	function supplementalResultHtml( user ) {
		const customer = user.customer_name || user.name || 'Unnamed customer';
		const lastPayment = user.payment_date || 'No payment date';
		const location = user.address_text || user.address || 'Location not set';
		let status = user.actual_profile || user.profile || 'Active';
		if ( user.disabled ) {
			status = 'Disabled';
		} else if ( 'expired' === String( user.actual_profile || '' ).toLowerCase() ) {
			status = 'Expired';
		}
		return '<button class="afc-basic-customer-result afc-basic-supplemental-result" type="button" role="option" data-account="' + escapeHtml( user.name ) + '">' +
			'<span class="afc-basic-customer-main"><strong>' + escapeHtml( customer ) + '</strong>' +
			'<span>' + escapeHtml( user.name ) + ( user.phone ? ' · ' + escapeHtml( user.phone ) : '' ) + '</span></span>' +
			'<span class="afc-basic-customer-side"><strong>' + escapeHtml( lastPayment ) + '</strong>' +
			'<span>' + escapeHtml( status ) + '</span></span>' +
			'<span class="afc-basic-customer-location">' + escapeHtml( location ) + '</span>' +
			'<span class="afc-basic-customer-chevron" aria-hidden="true">›</span></button>';
	}

	function supplementBasicSearch() {
		const input = document.getElementById( 'afc-basic-payment-search' );
		const results = document.getElementById( 'afc-basic-payment-results' );
		if ( ! input || ! results || input.value.trim().length < 3 || ! document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			return;
		}

		const query = plainText( input.value.trim() );
		const current = new Set();
		results.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( button ) {
			current.add( String( button.getAttribute( 'data-account' ) || '' ) );
		} );

		const matches = [];
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = rowUser( row );
			if ( ! user || ! user.name || current.has( String( user.name ) ) || ! searchableUser( user ).includes( query ) ) {
				return;
			}
			matches.push( user );
		} );

		if ( ! matches.length ) {
			return;
		}
		matches.sort( function ( first, second ) {
			return String( first.customer_name || first.name ).localeCompare( String( second.customer_name || second.name ) );
		} );
		if ( ! results.querySelector( '.afc-basic-customer-result' ) ) {
			results.innerHTML = '';
		}
		results.insertAdjacentHTML( 'beforeend', matches.slice( 0, Math.max( 0, 10 - current.size ) ).map( supplementalResultHtml ).join( '' ) );
		const help = document.getElementById( 'afc-basic-payment-search-help' );
		if ( help ) {
			help.textContent = 'Showing matching active, expired, and disabled PPP accounts.';
		}
	}

	function scheduleBasicSupplement() {
		window.clearTimeout( scheduleBasicSupplement.timer );
		scheduleBasicSupplement.timer = window.setTimeout( supplementBasicSearch, 190 );
	}

	function ensureDeleteButton() {
		const footer = document.querySelector( '#afc-ppp-manage-dialog .afc-dialog-footer' );
		if ( ! footer || document.getElementById( 'afc-delete-ppp-account' ) ) {
			return;
		}
		const button = document.createElement( 'button' );
		button.id = 'afc-delete-ppp-account';
		button.type = 'button';
		button.className = 'btn btn-outline-danger afc-delete-ppp-account';
		button.textContent = 'Delete PPP';
		button.disabled = true;
		footer.insertBefore( button, footer.firstChild );
	}

	function selectedPPP() {
		return {
			id: String( $( '#afc-edit-ppp-id' ).val() || '' ),
			username: String( $( '#afc-edit-heading-username' ).text() || '' ).trim(),
		};
	}

	function updateDeleteButton() {
		const button = document.getElementById( 'afc-delete-ppp-account' );
		const selected = selectedPPP();
		if ( button ) {
			button.disabled = ! selected.id || ! selected.username;
		}
	}

	function deleteSelectedPPP() {
		const selected = selectedPPP();
		const button = document.getElementById( 'afc-delete-ppp-account' );
		if ( ! selected.id || ! selected.username || ! button ) {
			return;
		}
		const confirmation = window.prompt(
			'Delete ' + selected.username + '?\n\nThis removes the MikroTik PPP secret, disconnects the customer, removes its scheduler, and trashes the linked Airfiber customer record.\n\nType the exact PPP username to continue:'
		);
		if ( null === confirmation ) {
			return;
		}
		if ( confirmation.trim() !== selected.username ) {
			window.alert( 'The PPP username did not match. Nothing was deleted.' );
			return;
		}

		button.disabled = true;
		button.textContent = 'Deleting…';
		$.post( afcPPPOperationsUX.ajaxUrl, {
			action: 'afc_ppp_delete_account',
			nonce: afcPPPOperationsUX.nonce,
			id: selected.id,
			username: selected.username,
			confirmation: confirmation.trim(),
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				window.alert( response && response.data && response.data.message ? response.data.message : 'The PPP account could not be deleted.' );
				return;
			}
			window.alert( response.data.message );
			window.location.reload();
		} ).fail( function () {
			window.alert( 'The delete request failed. Check the MikroTik connection.' );
		} ).always( function () {
			button.disabled = false;
			button.textContent = 'Delete PPP';
		} );
	}

	function migrationUsername( row ) {
		const checkbox = row.querySelector( '[data-afc-migration-row-check]' );
		const aria = checkbox ? String( checkbox.getAttribute( 'aria-label' ) || '' ) : '';
		if ( 0 === aria.indexOf( 'Select ' ) ) {
			return aria.substring( 7 ).trim();
		}
		const small = row.querySelector( 'td:nth-child(2) small' );
		return small ? small.textContent.split( ' · ' )[ 0 ].trim() : '';
	}

	function focusRepairField( message ) {
		const text = plainText( message );
		let selector = '#afc-edit-ppp-installed';
		if ( text.includes( 'payment' ) || text.includes( 'paidthrough' ) ) {
			selector = '#afc-edit-ppp-payment-date';
		} else if ( text.includes( 'grace' ) ) {
			selector = '#afc-edit-ppp-grace';
		} else if ( text.includes( 'billingday' ) ) {
			selector = '#afc-edit-ppp-billing-day';
		} else if ( text.includes( 'nextdue' ) ) {
			selector = '#afc-edit-ppp-next-due';
		} else if ( text.includes( 'cutoffdate' ) ) {
			selector = '#afc-edit-ppp-cutoff';
		}
		const field = document.querySelector( selector );
		if ( field ) {
			field.focus();
			field.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}
	}

	function openPPPForRepair( username, message ) {
		if ( ! username ) {
			return;
		}
		const advanced = document.querySelector( '[data-afc-admin-mode="advanced"]' );
		if ( advanced && document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			advanced.click();
		}
		const opener = document.getElementById( 'afc-find-edit-ppp' );
		if ( opener ) {
			opener.click();
		}
		waitFor(
			function () {
				const dialog = document.getElementById( 'afc-ppp-manage-dialog' );
				return dialog && dialog.open ? document.getElementById( 'afc-ppp-manager-search' ) : null;
			},
			function ( search ) {
				search.value = username;
				search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				waitFor(
					function () {
						return Array.from( document.querySelectorAll( '.afc-ppp-manager-person' ) ).find( function ( button ) {
							const account = button.querySelector( 'span' );
							return account && account.textContent.split( ' · ' )[ 0].trim() === username;
						} );
					},
					function ( button ) {
						button.click();
						window.setTimeout( function () { focusRepairField( message ); }, 180 );
					},
					40
				);
			},
			40
		);
	}

	function syncMigrationHeaderCheckbox() {
		const table = document.querySelector( '.afc-comment-migration-table' );
		const heading = table && table.querySelector( '[data-afc-migration-head-check]' );
		if ( ! table || ! heading ) {
			return;
		}
		const safe = Array.from( table.querySelectorAll( 'tbody tr[data-status="safe"] [data-afc-migration-row-check]' ) );
		const checked = safe.filter( function ( checkbox ) { return checkbox.checked; } ).length;
		heading.checked = safe.length > 0 && checked === safe.length;
		heading.indeterminate = checked > 0 && checked < safe.length;
		heading.disabled = 0 === safe.length;
	}

	function enhanceMigration() {
		const table = document.querySelector( '.afc-comment-migration-table' );
		if ( ! table ) {
			return;
		}
		const firstHeading = table.querySelector( 'thead th:first-child' );
		if ( firstHeading && ! firstHeading.querySelector( '[data-afc-migration-head-check]' ) ) {
			firstHeading.innerHTML = '<input type="checkbox" data-afc-migration-head-check title="Select all safe PPP accounts" aria-label="Select all safe PPP accounts">';
			firstHeading.querySelector( '[data-afc-migration-head-check]' ).addEventListener( 'change', function () {
				const toolbar = document.querySelector( '[data-afc-migration-select-all]' );
				if ( toolbar ) {
					toolbar.checked = this.checked;
					toolbar.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				window.setTimeout( syncMigrationHeaderCheckbox, 20 );
			} );
		}

		table.querySelectorAll( 'tbody tr[data-status="review"]' ).forEach( function ( row ) {
			const statusCell = row.querySelector( 'td:last-child' );
			if ( ! statusCell || statusCell.querySelector( '[data-afc-review-fix]' ) ) {
				return;
			}
			const username = migrationUsername( row );
			const messageNode = statusCell.querySelector( 'p' );
			const message = messageNode ? messageNode.textContent : '';
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'btn btn-sm btn-outline-primary afc-migration-fix-button';
			button.setAttribute( 'data-afc-review-fix', '' );
			button.textContent = 'Fix data';
			button.addEventListener( 'click', function () {
				openPPPForRepair( username, message );
			} );
			statusCell.appendChild( button );
			const help = document.createElement( 'small' );
			help.className = 'afc-migration-fix-help';
			help.textContent = 'Save the corrected PPP details, then preview again.';
			statusCell.appendChild( help );
		} );
		syncMigrationHeaderCheckbox();
	}

	function initialize() {
		ensureBasicAddButton();
		ensureDeleteButton();
		enhanceMigration();

		const observer = new MutationObserver( function () {
			ensureBasicAddButton();
			ensureDeleteButton();
			enhanceMigration();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		$( document ).on( 'input', '#afc-basic-payment-search', scheduleBasicSupplement );
		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			const data = settings && settings.data ? String( settings.data ) : '';
			if ( data.includes( 'afc_get_ppp_users' ) ) {
				window.setTimeout( scheduleBasicSupplement, 50 );
			}
		} );
		$( document ).on( 'click', '.afc-ppp-manager-person', function () {
			window.setTimeout( updateDeleteButton, 30 );
		} );
		$( document ).on( 'click', '#afc-delete-ppp-account', deleteSelectedPPP );
		$( document ).on( 'change', '[data-afc-migration-row-check], [data-afc-migration-select-all]', function () {
			window.setTimeout( syncMigrationHeaderCheckbox, 20 );
		} );
		$( document ).on( 'close', '#afc-ppp-manage-dialog', function () {
			const button = document.getElementById( 'afc-delete-ppp-account' );
			if ( button ) {
				button.disabled = true;
			}
		} );
	}

	$( initialize );
}( jQuery ) );