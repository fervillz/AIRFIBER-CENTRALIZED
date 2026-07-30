( function ( $ ) {
	'use strict';

	let users = [];
	const selectedNames = new Set();
	const sortState = {
		key: 'name',
		direction: 'asc'
	};

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function notice( message, type ) {
		$( '#afc-ppp-notice' ).html(
			$( '<div>', { class: 'alert alert-' + type, text: message } )
		);
	}

	function render( filter ) {
		const query = ( filter || '' ).toLowerCase();
		const visible = users.filter( function ( user ) {
			return ! query || [ user.name, user.customer_name, user.phone, user.profile, user.comment, user.address, user.caller_id, user.wifi, user.address_text ]
				.join( ' ' ).toLowerCase().includes( query );
		} ).sort( function ( first, second ) {
			let a = first[ sortState.key ];
			let b = second[ sortState.key ];

			if ( 'payment_amount' === sortState.key || 'grace' === sortState.key ) {
				a = parseFloat( a ) || 0;
				b = parseFloat( b ) || 0;
			} else if ( 'active' === sortState.key ) {
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
		const rows = visible.map( function ( user ) {
			const connection = user.active
				? '<span class="badge bg-success-lt">Online</span> ' + escapeHtml( user.uptime )
				: '<span class="badge bg-secondary-lt">Offline</span>';
			const importStatus = user.imported
				? '<span class="badge bg-blue-lt">Imported</span>'
				: '<span class="badge bg-yellow-lt">Not imported</span>';
			const checkbox = user.imported
				? ''
				: '<input class="form-check-input afc-user-check" type="checkbox"' +
					( selectedNames.has( user.name ) ? ' checked' : '' ) + '>';

			return '<tr data-user="' + encodeURIComponent( JSON.stringify( user ) ) + '">' +
				'<td>' + checkbox + '</td>' +
				'<td><strong>' + escapeHtml( user.name ) + '</strong>' +
					( user.disabled ? ' <span class="badge bg-danger-lt">Disabled</span>' : '' ) + '</td>' +
				'<td>' + escapeHtml( user.customer_name || '—' ) + '</td>' +
				'<td>' + escapeHtml( user.phone || '—' ) + '</td>' +
				'<td>' + escapeHtml( user.profile ) + '</td>' +
				'<td>' + escapeHtml( user.installed || '—' ) + '</td>' +
				'<td>' + escapeHtml( user.payment_date || '—' ) + '</td>' +
				'<td>' + ( user.payment_amount ? '₱' + escapeHtml( user.payment_amount ) : '—' ) + '</td>' +
				'<td>' + escapeHtml( user.payment_method || '—' ) + '</td>' +
				'<td>' + escapeHtml( user.grace || '—' ) + '</td>' +
				'<td>' + connection + '</td>' +
				'<td>' + escapeHtml( user.wifi || '—' ) +
					'<div class="text-secondary">' + escapeHtml( user.address_text || '—' ) + '</div></td>' +
				'<td>' + importStatus + '</td></tr>';
		} );

		$( '#afc-ppp-table tbody' ).html(
			rows.length ? rows.join( '' ) : '<tr><td colspan="14" class="text-center py-5">No PPP users found.</td></tr>'
		);
		$( '.afc-sort-indicator' ).text( '' );
		$( '.afc-sort[data-sort="' + sortState.key + '"] .afc-sort-indicator' )
			.text( 'asc' === sortState.direction ? '▲' : '▼' );
	}

	function loadUsers() {
		$( '#afc-refresh-ppp' ).prop( 'disabled', true );
		$( '#afc-ppp-table tbody' ).html( '<tr><td colspan="14" class="text-center py-5">' + afcPPP.loading + '</td></tr>' );

		$.post( afcPPP.ajaxUrl, { action: 'afc_get_ppp_users', nonce: afcPPP.nonce } )
			.done( function ( response ) {
				if ( ! response.success ) {
					notice( response.data.message, 'danger' );
					return;
				}
				users = response.data.users;
				selectedNames.clear();
				render( $( '#afc-ppp-search' ).val() );
				notice( 'Loaded ' + response.data.count + ' PPP user(s) from MikroTik.', 'success' );
			} )
			.fail( function () {
				notice( 'Could not load PPP users from MikroTik.', 'danger' );
			} )
			.always( function () {
				$( '#afc-refresh-ppp' ).prop( 'disabled', false );
			} );
	}

	$( function () {
		loadUsers();
		$( '#afc-refresh-ppp' ).on( 'click', loadUsers );
		$( '#afc-ppp-search' ).on( 'input', function () { render( this.value ); } );
		$( '.afc-sort' ).on( 'click', function () {
			const key = $( this ).data( 'sort' );
			if ( sortState.key === key ) {
				sortState.direction = 'asc' === sortState.direction ? 'desc' : 'asc';
			} else {
				sortState.key = key;
				sortState.direction = 'asc';
			}
			render( $( '#afc-ppp-search' ).val() );
		} );
		$( '#afc-ppp-table' ).on( 'change', '.afc-user-check', function () {
			const user = JSON.parse( decodeURIComponent( $( this ).closest( 'tr' ).attr( 'data-user' ) ) );
			if ( this.checked ) {
				selectedNames.add( user.name );
			} else {
				selectedNames.delete( user.name );
			}
		} );
		$( '#afc-select-all' ).on( 'change', function () {
			const checked = this.checked;
			$( '.afc-user-check' ).each( function () {
				const user = JSON.parse( decodeURIComponent( $( this ).closest( 'tr' ).attr( 'data-user' ) ) );
				$( this ).prop( 'checked', checked );
				if ( checked ) {
					selectedNames.add( user.name );
				} else {
					selectedNames.delete( user.name );
				}
			} );
		} );
		$( '#afc-import-ppp' ).on( 'click', function () {
			const selected = users.filter( function ( user ) {
				return selectedNames.has( user.name );
			} );
			if ( ! selected.length ) {
				notice( afcPPP.noSelection, 'warning' );
				return;
			}
			$( this ).prop( 'disabled', true ).text( afcPPP.importing );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_import_ppp_users',
				nonce: afcPPP.nonce,
				users: selected
			} ).done( function ( response ) {
				notice( response.data.message, response.success ? 'success' : 'danger' );
				if ( response.success ) {
					selectedNames.clear();
					loadUsers();
				}
			} ).fail( function () {
				notice( 'The import request failed.', 'danger' );
			} ).always( function () {
				$( '#afc-import-ppp' ).prop( 'disabled', false ).text( 'Import Selected' );
			} );
		} );
	} );
}( jQuery ) );
