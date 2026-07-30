( function ( $ ) {
	'use strict';

	let users = [];
	let selectedUser = null;
	let loaded = false;
	let searchTimer = null;
	let flashMessage = '';
	let flashTimer = null;

	function labels() {
		return window.afcBasicPayments && afcBasicPayments.labels ? afcBasicPayments.labels : {};
	}

	function minCharacters() {
		return Number( window.afcBasicPayments && afcBasicPayments.minCharacters || 3 );
	}

	function maxResults() {
		return Number( window.afcBasicPayments && afcBasicPayments.maxResults || 10 );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function plainText( value ) {
		const text = String( value || '' ).toLowerCase();
		return 'function' === typeof text.normalize
			? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: text;
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

	function createApp() {
		let app = document.getElementById( 'afc-basic-payment-app' );
		if ( app ) {
			return app;
		}

		const page = document.querySelector( '.afc-admin-page .container-fluid' );
		if ( ! page ) {
			return null;
		}

		app = document.createElement( 'section' );
		app.id = 'afc-basic-payment-app';
		app.className = 'afc-basic-payment-app afc-basic-only';
		app.innerHTML =
			'<div class="afc-basic-payment-heading">' +
			'<span class="afc-basic-payment-kicker">Daily payment tool</span>' +
			'<h1>' + escapeHtml( labels().title || 'Record a payment' ) + '</h1>' +
			'<p>' + escapeHtml( labels().description || 'Search for a customer and record today’s payment.' ) + '</p>' +
			'</div>' +
			'<div class="afc-basic-payment-search-wrap">' +
			'<label class="screen-reader-text" for="afc-basic-payment-search">Search customers</label>' +
			'<div class="afc-basic-payment-search-box">' +
			'<span class="afc-basic-payment-search-icon" aria-hidden="true"></span>' +
			'<input id="afc-basic-payment-search" type="search" autocomplete="off" autocapitalize="none" spellcheck="false" ' +
			'placeholder="' + escapeHtml( labels().placeholder || 'Type customer name or PPP account…' ) + '">' +
			'<button id="afc-basic-payment-clear" type="button" aria-label="Clear search">Clear</button>' +
			'</div>' +
			'<div class="afc-basic-payment-search-help" id="afc-basic-payment-search-help" aria-live="polite"></div>' +
			'</div>' +
			'<div class="afc-basic-payment-message" id="afc-basic-payment-message" aria-live="polite"></div>' +
			'<div class="afc-basic-payment-results" id="afc-basic-payment-results" role="listbox" aria-label="Customer search results"></div>' +
			'<div class="afc-basic-payment-selected" id="afc-basic-payment-selected" hidden></div>';

		const switcher = document.getElementById( 'afc-admin-mode-switcher' );
		if ( switcher && switcher.parentNode === page ) {
			switcher.insertAdjacentElement( 'afterend', app );
		} else {
			page.insertBefore( app, page.firstChild );
		}

		bindAppEvents( app );
		return app;
	}

	function currentQuery() {
		const input = document.getElementById( 'afc-basic-payment-search' );
		return input ? input.value.trim() : '';
	}

	function setHelp( message ) {
		const help = document.getElementById( 'afc-basic-payment-search-help' );
		if ( help ) {
			help.textContent = message || '';
		}
	}

	function setMessage( message, type ) {
		const box = document.getElementById( 'afc-basic-payment-message' );
		if ( ! box ) {
			return;
		}
		box.className = 'afc-basic-payment-message' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		box.textContent = message || '';
	}

	function searchableValue( user ) {
		return plainText( [
			user.customer_name,
			user.name,
			user.phone,
			user.address_text
		].join( ' ' ) );
	}

	function matchRank( user, query ) {
		const name = plainText( user.customer_name || '' );
		const account = plainText( user.name || '' );
		if ( name === query || account === query ) {
			return 0;
		}
		if ( name.startsWith( query ) || account.startsWith( query ) ) {
			return 1;
		}
		return 2;
	}

	function matchingUsers( query ) {
		const normalized = plainText( query );
		return users
			.filter( function ( user ) {
				return ! user.disabled && searchableValue( user ).includes( normalized );
			} )
			.sort( function ( first, second ) {
				return matchRank( first, normalized ) - matchRank( second, normalized ) ||
					String( first.customer_name || first.name ).localeCompare( String( second.customer_name || second.name ) );
			} );
	}

	function resultHtml( user ) {
		const customer = user.customer_name || user.name;
		const lastPayment = user.payment_date || 'No payment date';
		const location = user.address_text || 'Location not set';
		const status = String( user.actual_profile || '' ).toLowerCase() === 'expired' ? 'Expired' : ( user.actual_profile || user.profile || 'Active' );

		return '<button class="afc-basic-customer-result" type="button" role="option" data-account="' + escapeHtml( user.name ) + '">' +
			'<span class="afc-basic-customer-main"><strong>' + escapeHtml( customer ) + '</strong>' +
			'<span>' + escapeHtml( user.name ) + ( user.phone ? ' · ' + escapeHtml( user.phone ) : '' ) + '</span></span>' +
			'<span class="afc-basic-customer-side"><strong>' + escapeHtml( lastPayment ) + '</strong>' +
			'<span>' + escapeHtml( status ) + '</span></span>' +
			'<span class="afc-basic-customer-location">' + escapeHtml( location ) + '</span>' +
			'<span class="afc-basic-customer-chevron" aria-hidden="true">›</span></button>';
	}

	function renderSelected() {
		const results = document.getElementById( 'afc-basic-payment-results' );
		const selected = document.getElementById( 'afc-basic-payment-selected' );
		if ( ! results || ! selected ) {
			return;
		}

		if ( ! selectedUser ) {
			selected.hidden = true;
			selected.innerHTML = '';
			return;
		}

		results.innerHTML = '';
		const customer = selectedUser.customer_name || selectedUser.name;
		const lastPayment = selectedUser.payment_date || 'No payment recorded';
		const plan = selectedUser.profile || selectedUser.actual_profile || 'No plan';
		const amount = Number( selectedUser.payment_amount || 0 );

		selected.hidden = false;
		selected.innerHTML =
			'<div class="afc-basic-selected-card">' +
			'<span class="afc-basic-selected-label">Selected customer</span>' +
			'<h2>' + escapeHtml( customer ) + '</h2>' +
			'<div class="afc-basic-selected-account">' + escapeHtml( labels().account || 'PPP account' ) + ': <strong>' + escapeHtml( selectedUser.name ) + '</strong></div>' +
			'<div class="afc-basic-selected-details">' +
			'<div><span>' + escapeHtml( labels().lastPayment || 'Last payment' ) + '</span><strong>' + escapeHtml( lastPayment ) + '</strong></div>' +
			'<div><span>' + escapeHtml( labels().plannedPlan || 'Plan' ) + '</span><strong>' + escapeHtml( plan ) + '</strong></div>' +
			( amount ? '<div><span>Usual amount</span><strong>₱' + escapeHtml( amount.toLocaleString() ) + '</strong></div>' : '' ) +
			'</div>' +
			'<div class="afc-basic-selected-actions">' +
			'<button class="afc-basic-pay-today" type="button">' + escapeHtml( labels().payToday || 'Pay Today' ) + '</button>' +
			'<button class="afc-basic-cancel-selection" type="button">' + escapeHtml( labels().cancel || 'Cancel' ) + '</button>' +
			'</div></div>';
	}

	function renderSearch() {
		const results = document.getElementById( 'afc-basic-payment-results' );
		if ( ! results ) {
			return;
		}

		if ( selectedUser ) {
			renderSelected();
			return;
		}

		const query = currentQuery();
		if ( ! loaded ) {
			results.innerHTML = '<div class="afc-basic-payment-empty is-loading"><span></span>' + escapeHtml( labels().loading || 'Loading customers from MikroTik…' ) + '</div>';
			setHelp( '' );
			return;
		}

		if ( query.length < minCharacters() ) {
			results.innerHTML = '<div class="afc-basic-payment-empty">' + escapeHtml( labels().startTyping || 'Type at least 3 letters to search.' ) + '</div>';
			setHelp( '' );
			return;
		}

		const matches = matchingUsers( query );
		const visible = matches.slice( 0, maxResults() );
		if ( ! visible.length ) {
			results.innerHTML = '<div class="afc-basic-payment-empty">' + escapeHtml( labels().noResults || 'No matching customer was found.' ) + '</div>';
			setHelp( 'Try the PPP account name, phone number or another spelling.' );
			return;
		}

		results.innerHTML = visible.map( resultHtml ).join( '' );
		setHelp( matches.length > visible.length
			? 'Showing the first ' + visible.length + ' of ' + matches.length + ' matches. Type more letters to narrow the results.'
			: matches.length + ' matching customer' + ( 1 === matches.length ? '' : 's' ) + '.' );
	}

	function selectUser( account ) {
		selectedUser = users.find( function ( user ) { return String( user.name ) === String( account ); } ) || null;
		setMessage( '', '' );
		renderSelected();
		if ( selectedUser ) {
			const payButton = document.querySelector( '.afc-basic-pay-today' );
			if ( payButton ) {
				payButton.focus( { preventScroll: true } );
			}
		}
	}

	function findExistingPaymentButton( account ) {
		const rows = document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' );
		for ( let index = 0; index < rows.length; index++ ) {
			try {
				const user = JSON.parse( decodeURIComponent( rows[ index ].getAttribute( 'data-user' ) || '' ) );
				if ( String( user.name ) === String( account ) ) {
					return rows[ index ].querySelector( '.afc-pay-today' );
				}
			} catch ( error ) {
				// Ignore malformed legacy table rows and continue searching.
			}
		}
		return null;
	}

	function openPaymentDialog() {
		if ( ! selectedUser ) {
			return;
		}
		const button = findExistingPaymentButton( selectedUser.name );
		if ( ! button ) {
			setMessage( 'The customer list is still refreshing. Please try again in a moment.', 'warning' );
			$( '#afc-refresh-ppp' ).trigger( 'click' );
			return;
		}
		button.click();
	}

	function clearSelection( keepQuery ) {
		selectedUser = null;
		const selected = document.getElementById( 'afc-basic-payment-selected' );
		if ( selected ) {
			selected.hidden = true;
			selected.innerHTML = '';
		}
		if ( ! keepQuery ) {
			const input = document.getElementById( 'afc-basic-payment-search' );
			if ( input ) {
				input.value = '';
			}
		}
		renderSearch();
	}

	function showFlash( message ) {
		window.clearTimeout( flashTimer );
		flashMessage = message || 'Payment recorded.';
		setMessage( flashMessage, 'success' );
		flashTimer = window.setTimeout( function () {
			flashMessage = '';
			setMessage( '', '' );
		}, 4200 );
	}

	function bindAppEvents( app ) {
		const input = app.querySelector( '#afc-basic-payment-search' );
		input.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );
			selectedUser = null;
			const selected = document.getElementById( 'afc-basic-payment-selected' );
			if ( selected ) {
				selected.hidden = true;
			}
			searchTimer = window.setTimeout( renderSearch, 110 );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				clearSelection( false );
				input.focus();
			}
		} );

		app.addEventListener( 'click', function ( event ) {
			const result = event.target.closest( '.afc-basic-customer-result' );
			if ( result ) {
				selectUser( result.getAttribute( 'data-account' ) || '' );
				return;
			}
			if ( event.target.closest( '#afc-basic-payment-clear' ) ) {
				clearSelection( false );
				input.focus();
				return;
			}
			if ( event.target.closest( '.afc-basic-cancel-selection' ) ) {
				clearSelection( true );
				input.focus();
				return;
			}
			if ( event.target.closest( '.afc-basic-pay-today' ) ) {
				openPaymentDialog();
			}
		} );
	}

	function focusSearch() {
		const input = document.getElementById( 'afc-basic-payment-search' );
		if ( input && document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			window.setTimeout( function () { input.focus( { preventScroll: true } ); }, 180 );
		}
	}

	$( function () {
		createApp();
		renderSearch();
		focusSearch();

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			if ( event.detail && 'basic' === event.detail.mode ) {
				focusSearch();
			}
		} );

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				users = xhr.responseJSON.data.users || [];
				loaded = true;
				if ( selectedUser ) {
					selectedUser = users.find( function ( user ) { return user.name === selectedUser.name; } ) || null;
				}
				renderSearch();
			}

			if ( requestHasAction( settings, 'afc_ppp_record_payment' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				const message = xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Payment recorded.';
				clearSelection( false );
				showFlash( message );
				focusSearch();
			}
		} );
	} );
}( jQuery ) );
