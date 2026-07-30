( function ( $ ) {
	'use strict';

	let users = [];

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
			return ! query || [ user.name, user.profile, user.comment, user.address, user.caller_id ]
				.join( ' ' ).toLowerCase().includes( query );
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
				: '<input class="form-check-input afc-user-check" type="checkbox">';

			return '<tr data-user="' + encodeURIComponent( JSON.stringify( user ) ) + '">' +
				'<td>' + checkbox + '</td>' +
				'<td><strong>' + escapeHtml( user.name ) + '</strong>' +
					( user.disabled ? ' <span class="badge bg-danger-lt">Disabled</span>' : '' ) + '</td>' +
				'<td>' + escapeHtml( user.profile ) + '</td>' +
				'<td>' + connection + '</td>' +
				'<td>' + escapeHtml( user.address || user.remote_address ) +
					'<div class="text-secondary">' + escapeHtml( user.caller_id ) + '</div></td>' +
				'<td>' + escapeHtml( user.comment ) + '</td>' +
				'<td>' + importStatus + '</td></tr>';
		} );

		$( '#afc-ppp-table tbody' ).html(
			rows.length ? rows.join( '' ) : '<tr><td colspan="7" class="text-center py-5">No PPP users found.</td></tr>'
		);
	}

	function loadUsers() {
		$( '#afc-refresh-ppp' ).prop( 'disabled', true );
		$( '#afc-ppp-table tbody' ).html( '<tr><td colspan="7" class="text-center py-5">' + afcPPP.loading + '</td></tr>' );

		$.post( afcPPP.ajaxUrl, { action: 'afc_get_ppp_users', nonce: afcPPP.nonce } )
			.done( function ( response ) {
				if ( ! response.success ) {
					notice( response.data.message, 'danger' );
					return;
				}
				users = response.data.users;
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
		$( '#afc-select-all' ).on( 'change', function () {
			$( '.afc-user-check' ).prop( 'checked', this.checked );
		} );
		$( '#afc-import-ppp' ).on( 'click', function () {
			const selected = [];
			$( '.afc-user-check:checked' ).each( function () {
				selected.push( JSON.parse( decodeURIComponent( $( this ).closest( 'tr' ).attr( 'data-user' ) ) ) );
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

