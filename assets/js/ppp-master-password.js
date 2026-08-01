( function ( $ ) {
	'use strict';

	function mode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) {
			return shell.dataset.afcMode;
		}
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			return 'basic';
		}
		return 'advanced';
	}

	function statusText() {
		return afcPPPMasterPassword.custom
			? 'Custom master password is active'
			: 'Using default ' + afcPPPMasterPassword.defaultPassword;
	}

	function refreshStatus() {
		$( '#afc-ppp-password-status-text' ).text( statusText() );
	}

	function showNotice( message, type, errors ) {
		let html = '<div class="alert alert-' + ( type || 'info' ) + '">' + $( '<div>' ).text( message ).html();
		if ( Array.isArray( errors ) && errors.length ) {
			html += '<ul class="mb-0 mt-2">' + errors.map( function ( error ) {
				return '<li>' + $( '<div>' ).text( error ).html() + '</li>';
			} ).join( '' ) + '</ul>';
		}
		html += '</div>';
		$( '#afc-ppp-password-notice' ).html( html );
	}

	function ensureButton() {
		const $actions = $( '.afc-ppp-header-actions' ).first();
		if ( ! $actions.length || $( '#afc-open-ppp-settings' ).length ) {
			return;
		}
		const $button = $( '<button>', {
			id: 'afc-open-ppp-settings',
			type: 'button',
			class: 'btn btn-outline-primary afc-advanced-only',
			text: 'PPP Settings'
		} );
		const $serviceAreas = $actions.find( '#afc-manage-service-areas' );
		if ( $serviceAreas.length ) {
			$button.insertAfter( $serviceAreas );
		} else {
			$button.insertBefore( $actions.find( '#afc-refresh-ppp' ) );
		}
	}

	function openDialog() {
		if ( 'basic' === mode() ) {
			return;
		}
		const dialog = document.getElementById( 'afc-ppp-master-password-dialog' );
		if ( ! dialog ) {
			return;
		}
		$( '#afc-ppp-master-password-input' ).val( '' ).attr( 'type', 'password' );
		$( '#afc-toggle-master-password' ).text( 'Show' );
		$( '#afc-apply-master-password-existing' ).prop( 'checked', false );
		$( '#afc-ppp-password-notice' ).empty();
		refreshStatus();
		dialog.showModal();
	}

	function closeDialog() {
		const dialog = document.getElementById( 'afc-ppp-master-password-dialog' );
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	}

	function saveSettings() {
		const password = String( $( '#afc-ppp-master-password-input' ).val() || '' );
		const applyExisting = $( '#afc-apply-master-password-existing' ).is( ':checked' );
		if ( password && password.length < 8 ) {
			showNotice( 'The master PPP password must contain at least 8 characters.', 'warning' );
			return;
		}
		if ( applyExisting ) {
			const chosen = password || afcPPPMasterPassword.defaultPassword;
			if ( ! window.confirm( 'Change all existing customer PPP router passwords to "' + chosen + '" now? Connected routers may need their saved PPP password updated.' ) ) {
				return;
			}
		}

		const $button = $( '#afc-save-master-ppp-password' );
		$button.prop( 'disabled', true ).text( applyExisting ? 'Saving and updating…' : 'Saving…' );
		$( '#afc-ppp-password-notice' ).empty();

		$.post( afcPPPMasterPassword.ajaxUrl, {
			action: 'afc_ppp_save_master_password',
			nonce: afcPPPMasterPassword.nonce,
			master_password: password,
			apply_existing: applyExisting ? 1 : 0
		} ).done( function ( response ) {
			if ( ! response.success ) {
				showNotice( response.data && response.data.message ? response.data.message : 'The PPP password setting could not be saved.', 'danger' );
				return;
			}
			afcPPPMasterPassword.custom = Boolean( response.data.custom );
			refreshStatus();
			$( '#afc-ppp-master-password-input' ).val( '' ).attr( 'type', 'password' );
			$( '#afc-toggle-master-password' ).text( 'Show' );
			$( '#afc-apply-master-password-existing' ).prop( 'checked', false );
			showNotice( response.data.message, response.data.errors && response.data.errors.length ? 'warning' : 'success', response.data.errors );
		} ).fail( function () {
			showNotice( 'The request failed. Check the WordPress and MikroTik connection.', 'danger' );
		} ).always( function () {
			$button.prop( 'disabled', false ).text( 'Save PPP Password' );
		} );
	}

	$( document ).on( 'click', '#afc-open-ppp-settings', openDialog );
	$( document ).on( 'click', '[data-afc-ppp-password-close]', closeDialog );
	$( document ).on( 'click', '#afc-toggle-master-password', function () {
		const $input = $( '#afc-ppp-master-password-input' );
		const showing = 'text' === $input.attr( 'type' );
		$input.attr( 'type', showing ? 'password' : 'text' );
		$( this ).text( showing ? 'Show' : 'Hide' );
	} );
	$( document ).on( 'click', '#afc-save-master-ppp-password', saveSettings );
	$( document ).on( 'submit', '#afc-ppp-master-password-form', function ( event ) {
		event.preventDefault();
		saveSettings();
	} );

	$( function () {
		ensureButton();
		refreshStatus();
	} );
} )( jQuery );
