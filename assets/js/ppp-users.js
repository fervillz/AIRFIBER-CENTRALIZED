( function ( $ ) {
	'use strict';

	let users = [];
	let paymentUser = null;
	const selectedNames = new Set();
	const sortState = { key: 'name', direction: 'asc' };

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function notice( message, type ) {
		$( '#afc-ppp-notice' ).html(
			$( '<div>', { class: 'alert alert-' + type, text: message } )
		);
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function getError( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	function isExpired( user ) {
		return String( user.actual_profile || '' ).toLowerCase() === 'expired';
	}

	function updateSummary() {
		$( '[data-summary="total"]' ).text( users.length );
		$( '[data-summary="online"]' ).text( users.filter( function ( user ) { return user.active; } ).length );
		$( '[data-summary="expired"]' ).text( users.filter( isExpired ).length );
		$( '[data-summary="imported"]' ).text( users.filter( function ( user ) { return user.imported; } ).length );
	}

	function sortUsers( visible ) {
		return visible.sort( function ( first, second ) {
			let a = first[ sortState.key ];
			let b = second[ sortState.key ];

			if ( 'active' === sortState.key ) {
				a = a ? 1 : 0;
				b = b ? 1 : 0;
			} else {
				a = String( a || '' ).toLowerCase();
				b = String( b || '' ).toLowerCase();
			}

			if ( a < b ) {
				return 'asc' === sortState.direction ? -1 : 1;
			}
			if ( a > b ) {
				return 'asc' === sortState.direction ? 1 : -1;
			}
			return String( first.name ).localeCompare( String( second.name ) );
		} );
	}

	function rowHtml( user ) {
		const expired = isExpired( user );
		const checkbox = user.imported
			? ''
			: '<input class="form-check-input afc-user-check" type="checkbox"' +
				( selectedNames.has( user.name ) ? ' checked' : '' ) + '>';
		const imported = user.imported
			? '<span class="badge bg-blue-lt">In WordPress</span>'
			: '<span class="badge bg-yellow-lt">Not imported</span>';
		const payment = user.payment_date
			? '<strong>' + escapeHtml( user.payment_date ) + '</strong>'
			: '<span class="text-danger">No date</span>';
		const service = expired
			? '<span class="badge bg-danger-lt">Expired</span>'
			: '<span class="badge bg-primary-lt">' + escapeHtml( user.actual_profile || user.profile ) + '</span>';
		const connection = user.active
			? '<span class="badge bg-success-lt">Online</span><div class="small text-secondary">' + escapeHtml( user.uptime ) + '</div>'
			: '<span class="badge bg-secondary-lt">Offline</span>';
		const details = [
			'Installed: ' + ( user.installed || 'N/A' ),
			'Grace: ' + ( user.grace || '0' ) + ' day(s)',
			'Wi-Fi: ' + ( user.wifi || 'N/A' ),
			'Address: ' + ( user.address_text || 'N/A' )
		].join( '\n' );
		const serviceTitle = expired
			? 'Planned profile: ' + ( user.profile || 'Not available' )
			: 'Current RouterOS profile: ' + ( user.actual_profile || user.profile );
		const secondaryAction = expired
			? '<button class="btn btn-sm btn-outline-primary afc-reconnect" type="button">Reconnect</button>'
			: '<button class="btn btn-sm btn-outline-danger afc-expire" type="button">Expire</button>';

		return '<tr data-user="' + encodeURIComponent( JSON.stringify( user ) ) + '">' +
			'<td>' + checkbox + '</td>' +
			'<td><div class="fw-bold">' + escapeHtml( user.name ) + '</div>' +
				'<div class="small text-secondary">' + escapeHtml( user.customer_name || 'Customer name missing' ) + '</div>' +
				'<div class="mt-1">' + imported + ( user.disabled ? ' <span class="badge bg-danger-lt">Disabled</span>' : '' ) + '</div></td>' +
			'<td><div>' + payment + '</div>' +
				'<div class="small text-secondary">₱' + escapeHtml( user.payment_amount || '0' ) + ' · ' + escapeHtml( user.payment_method || 'cash' ) + '</div></td>' +
			'<td title="' + escapeHtml( serviceTitle ) + '">' + service +
				( expired && user.profile ? '<div class="small text-secondary mt-1">Restore: ' + escapeHtml( user.profile ) + '</div>' : '' ) + '</td>' +
			'<td>' + connection + '</td>' +
			'<td title="' + escapeHtml( details ) + '"><div>' + escapeHtml( user.phone || 'No phone' ) + '</div>' +
				'<div class="small text-secondary text-truncate afc-location">' + escapeHtml( user.address_text || user.wifi || 'No address' ) + '</div>' +
				'<span class="small text-primary">Hover for details</span></td>' +
			'<td class="text-end"><div class="afc-row-actions">' +
				'<button class="btn btn-sm btn-success afc-pay-today" type="button">Paid Today</button>' +
				secondaryAction +
			'</div></td></tr>';
	}

	function render() {
		const query = String( $( '#afc-ppp-search' ).val() || '' ).toLowerCase();
		const filter = $( '#afc-service-filter' ).val();
		let visible = users.filter( function ( user ) {
			const matchesSearch = ! query || [
				user.name, user.customer_name, user.phone, user.profile,
				user.actual_profile, user.comment, user.wifi, user.address_text
			].join( ' ' ).toLowerCase().includes( query );
			const matchesFilter =
				! filter ||
				( 'online' === filter && user.active ) ||
				( 'offline' === filter && ! user.active ) ||
				( 'expired' === filter && isExpired( user ) );
			return matchesSearch && matchesFilter;
		} );

		visible = sortUsers( visible );
		$( '#afc-ppp-table tbody' ).html(
			visible.length
				? visible.map( rowHtml ).join( '' )
				: '<tr><td colspan="7" class="text-center py-5">No matching PPP accounts.</td></tr>'
		);
		$( '.afc-sort-indicator' ).text( '' );
		$( '.afc-sort[data-sort="' + sortState.key + '"] .afc-sort-indicator' )
			.text( 'asc' === sortState.direction ? '▲' : '▼' );
	}

	function loadUsers() {
		$( '#afc-refresh-ppp' ).prop( 'disabled', true );
		$( '#afc-ppp-table tbody' ).html( '<tr><td colspan="7" class="text-center py-5">' + afcPPP.loading + '</td></tr>' );
		$.post( afcPPP.ajaxUrl, { action: 'afc_get_ppp_users', nonce: afcPPP.nonce } )
			.done( function ( response ) {
				if ( ! response.success ) {
					notice( getError( response, 'Could not load PPP accounts.' ), 'danger' );
					return;
				}
				users = response.data.users;
				selectedNames.clear();
				updateSummary();
				render();
			} )
			.fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'Could not load PPP accounts from MikroTik.' ), 'danger' );
			} )
			.always( function () {
				$( '#afc-refresh-ppp' ).prop( 'disabled', false );
			} );
	}

	function userFromButton( button ) {
		return JSON.parse( decodeURIComponent( $( button ).closest( 'tr' ).attr( 'data-user' ) ) );
	}

	function changeService( user, change, button ) {
		const wording = 'expire' === change
			? 'Set ' + user.name + ' to the Expired profile? Their current online session will not be disconnected.'
			: 'Restore ' + user.name + ' to ' + user.profile + ' and reconnect their expired session?';
		if ( ! window.confirm( wording ) ) {
			return;
		}

		$( button ).prop( 'disabled', true );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_ppp_change_service',
			nonce: afcPPP.nonce,
			change: change,
			user: user
		} ).done( function ( response ) {
			notice( getError( response, 'PPP service updated.' ), response.success ? 'success' : 'danger' );
			if ( response.success ) {
				loadUsers();
			}
		} ).fail( function ( xhr ) {
			notice( getError( xhr.responseJSON, 'The PPP service update failed.' ), 'danger' );
		} ).always( function () {
			$( button ).prop( 'disabled', false );
		} );
	}

	$( function () {
		const dialog = document.getElementById( 'afc-payment-dialog' );
		loadUsers();

		$( '#afc-refresh-ppp' ).on( 'click', loadUsers );
		$( '#afc-ppp-search, #afc-service-filter' ).on( 'input change', render );
		$( '.afc-sort' ).on( 'click', function () {
			const key = $( this ).data( 'sort' );
			if ( sortState.key === key ) {
				sortState.direction = 'asc' === sortState.direction ? 'desc' : 'asc';
			} else {
				sortState.key = key;
				sortState.direction = 'asc';
			}
			render();
		} );
		$( '#afc-ppp-table' )
			.on( 'change', '.afc-user-check', function () {
				const user = userFromButton( this );
				if ( this.checked ) {
					selectedNames.add( user.name );
				} else {
					selectedNames.delete( user.name );
				}
			} )
			.on( 'click', '.afc-pay-today', function () {
				paymentUser = userFromButton( this );
				$( '#afc-payment-customer' ).text( paymentUser.customer_name || paymentUser.name );
				$( '#afc-payment-amount' ).val( paymentUser.payment_amount || '' );
				$( '#afc-payment-method' ).val( String( paymentUser.payment_method || 'cash' ).toLowerCase() );
				dialog.showModal();
			} )
			.on( 'click', '.afc-expire', function () {
				changeService( userFromButton( this ), 'expire', this );
			} )
			.on( 'click', '.afc-reconnect', function () {
				changeService( userFromButton( this ), 'reconnect', this );
			} );

		$( '#afc-select-all' ).on( 'change', function () {
			const checked = this.checked;
			$( '.afc-user-check' ).each( function () {
				const user = userFromButton( this );
				$( this ).prop( 'checked', checked );
				if ( checked ) {
					selectedNames.add( user.name );
				} else {
					selectedNames.delete( user.name );
				}
			} );
		} );

		$( '#afc-confirm-payment' ).on( 'click', function () {
			const button = this;
			const amount = $( '#afc-payment-amount' ).val();
			const method = $( '#afc-payment-method' ).val();
			if ( ! paymentUser || '' === amount ) {
				return;
			}
			$( button ).prop( 'disabled', true ).text( 'Recording...' );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_ppp_record_payment',
				nonce: afcPPP.nonce,
				user: paymentUser,
				amount: amount,
				method: method
			} ).done( function ( response ) {
				dialog.close();
				notice( getError( response, 'Payment recorded.' ), response.success ? 'success' : 'danger' );
				if ( response.success ) {
					loadUsers();
				}
			} ).fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'The payment could not be recorded.' ), 'danger' );
			} ).always( function () {
				$( button ).prop( 'disabled', false ).text( 'Confirm Paid Today' );
			} );
		} );

		$( '#afc-import-ppp' ).on( 'click', function () {
			const button = this;
			const selected = users.filter( function ( user ) { return selectedNames.has( user.name ); } );
			if ( ! selected.length ) {
				notice( afcPPP.noSelection, 'warning' );
				return;
			}
			$( button ).prop( 'disabled', true ).text( afcPPP.importing );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_import_ppp_users',
				nonce: afcPPP.nonce,
				users: selected
			} ).done( function ( response ) {
				notice( getError( response, 'Import complete.' ), response.success ? 'success' : 'danger' );
				if ( response.success ) {
					loadUsers();
				}
			} ).fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'The import request failed.' ), 'danger' );
			} ).always( function () {
				$( button ).prop( 'disabled', false ).text( 'Import Selected' );
			} );
		} );
	} );
}( jQuery ) );
