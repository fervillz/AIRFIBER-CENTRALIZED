( function ( $ ) {
	'use strict';

	const draft = {
		customerName: '',
		phone: '',
		wifi: '',
		wifiPassword: '',
	};
	let installerLogin = '';

	function currentMode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) return shell.dataset.afcMode;
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) return 'advanced';
		return 'basic';
	}

	function fieldValue( selector ) {
		const field = document.querySelector( selector );
		return field ? String( field.value || '' ).trim() : '';
	}

	function readDraft() {
		const customerName = fieldValue( '#afc-ios-customer-name' );
		const phone = fieldValue( '#afc-ios-customer-phone' );
		const wifi = fieldValue( '#afc-ios-wifi-name' );
		const wifiPassword = fieldValue( '#afc-ios-wifi-password' );
		if ( customerName ) draft.customerName = customerName;
		if ( phone ) draft.phone = phone;
		if ( wifi ) draft.wifi = wifi;
		if ( wifiPassword ) draft.wifiPassword = wifiPassword;
	}

	function setDraftField( id, value ) {
		value = String( value || '' ).trim();
		if ( 'afc-ios-customer-name' === id ) draft.customerName = value;
		else if ( 'afc-ios-customer-phone' === id ) draft.phone = value;
		else if ( 'afc-ios-wifi-name' === id ) draft.wifi = value;
		else if ( 'afc-ios-wifi-password' === id ) draft.wifiPassword = value;
	}

	function selectedBasicPlan() {
		const selected = document.querySelector( '#afc-ios-plan-cards [data-afc-ios-plan].is-selected' );
		return selected ? String( selected.getAttribute( 'data-afc-ios-plan' ) || '' ).trim() : '';
	}

	function showDetailsNotice( message ) {
		const notice = document.getElementById( 'afc-ios-details-notice' );
		if ( ! notice ) return;
		notice.textContent = message || '';
		notice.hidden = ! message;
	}

	function returnToMissingBasicField( step, message ) {
		showDetailsNotice( message );
		const back = document.querySelector( '[data-afc-ios-details-back]' );
		if ( back ) {
			back.click();
			window.setTimeout( function () {
				const firstBack = document.querySelector( '[data-afc-ios-first-back]' );
				let attempts = 0;
				function moveBack() {
					const active = document.querySelector( '#afc-ios-basic-create [data-afc-ios-step].is-active' );
					const activeStep = active ? Number( active.getAttribute( 'data-afc-ios-step' ) ) : 3;
					if ( activeStep <= step || ! firstBack || attempts >= 5 ) return;
					attempts += 1;
					firstBack.click();
					window.setTimeout( moveBack, 80 );
				}
				moveBack();
			}, 160 );
		}
	}

	function openAdvancedCreate() {
		const source = document.getElementById( 'afc-add-ppp-account' );
		if ( source ) {
			source.click();
			return;
		}

		const operations = document.querySelector( '[data-afc-app-panel="operations"]' );
		if ( operations ) operations.click();
		window.setTimeout( function () {
			const retry = document.getElementById( 'afc-add-ppp-account' );
			if ( retry ) retry.click();
		}, 180 );
	}

	function finishBasicCreate( response, payload ) {
		const user = response.data && response.data.user ? response.data.user : {};
		installerLogin = 'PPP username: ' + ( user.username || '' ) + '\n' +
			'PPP password: ' + ( response.data && response.data.password ? response.data.password : '' ) + '\n' +
			'WiFi name: ' + payload.wifi + '\n' +
			'WiFi password: ' + payload.wifi_password;
		$( '#afc-ios-created-username' ).text( user.username || '' );
		$( '#afc-ios-details-wizard, #afc-ios-ppp-details-dialog .afc-ios-wizard-bar' ).prop( 'hidden', true );
		$( '#afc-ios-create-success' ).prop( 'hidden', false );
		$( '#afc-refresh-ppp' ).trigger( 'click' );
	}

	function submitBasicCreate( event ) {
		if ( 'basic' !== currentMode() ) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		readDraft();

		const address = fieldValue( '#afc-ios-customer-address' );
		const profile = selectedBasicPlan();
		if ( ! draft.customerName ) {
			returnToMissingBasicField( 0, 'The full name was not retained. Please enter it again.' );
			return;
		}
		if ( ! draft.phone ) {
			returnToMissingBasicField( 1, 'The CP number was not retained. Please enter it again.' );
			return;
		}
		if ( ! draft.wifi ) {
			returnToMissingBasicField( 2, 'The WiFi name was not retained. Please enter it again.' );
			return;
		}
		if ( draft.wifiPassword.length < 8 ) {
			returnToMissingBasicField( 3, 'WiFi password must contain at least 8 characters.' );
			return;
		}
		if ( ! profile ) {
			showDetailsNotice( 'Choose a plan.' );
			return;
		}
		if ( ! address ) {
			showDetailsNotice( 'Enter the complete address.' );
			return;
		}

		const payload = {
			customer_name: draft.customerName,
			phone: draft.phone,
			wifi: draft.wifi,
			wifi_password: draft.wifiPassword,
			profile: profile,
			address: address,
			interface_mode: 'basic',
		};
		const $button = $( event.target.closest( '[data-afc-ios-create]' ) );
		$button.prop( 'disabled', true ).addClass( 'is-loading' ).html( '<span aria-hidden="true">&hellip;</span>' );
		showDetailsNotice( '' );

		$.post( afcPPPManager.ajaxUrl, Object.assign( {
			action: 'afc_ppp_manager_create',
			nonce: afcPPPManager.nonce,
		}, payload ) ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showDetailsNotice( response && response.data && response.data.message ? response.data.message : 'The PPP account could not be created.' );
				return;
			}
			finishBasicCreate( response, payload );
		} ).fail( function () {
			showDetailsNotice( 'The request failed. Check the MikroTik connection.' );
		} ).always( function () {
			$button.prop( 'disabled', false ).removeClass( 'is-loading' ).html( '&#10003;' );
		} );
	}

	/* Keep the AJAX-side mode synchronized with the visible Basic/Advanced UI. */
	$.ajaxPrefilter( function ( options, originalOptions ) {
		const original = originalOptions && originalOptions.data ? originalOptions.data : null;
		let action = '';
		if ( original && 'object' === typeof original ) action = String( original.action || '' );
		else {
			const match = String( options.data || '' ).match( /(?:^|&)action=([^&]+)/ );
			action = match ? decodeURIComponent( match[ 1 ] ) : '';
		}
		if ( ! [ 'afc_ppp_manager_create', 'afc_ppp_manager_save' ].includes( action ) ) return;
		const uiMode = currentMode();
		if ( 'string' === typeof options.data ) {
			if ( ! /(?:^|&)interface_mode=/.test( options.data ) ) options.data += '&interface_mode=' + encodeURIComponent( uiMode );
		} else if ( options.data && 'object' === typeof options.data ) {
			options.data.interface_mode = uiMode;
		}
	} );

	document.addEventListener( 'input', function ( event ) {
		if ( event.target && /^afc-ios-(customer-name|customer-phone|wifi-name|wifi-password)$/.test( event.target.id ) ) {
			setDraftField( event.target.id, event.target.value );
		}
	}, true );

	document.addEventListener( 'click', function ( event ) {
		const dashboardAdd = event.target.closest && event.target.closest( '[data-afc-dashboard-add-ppp]' );
		if ( dashboardAdd && 'advanced' === currentMode() ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			openAdvancedCreate();
			return;
		}

		if ( event.target.closest && event.target.closest( '[data-afc-ios-next]' ) ) readDraft();

		const create = event.target.closest && event.target.closest( '[data-afc-ios-create]' );
		if ( create ) {
			submitBasicCreate( event );
			return;
		}

		const copy = event.target.closest && event.target.closest( '[data-afc-ios-copy-login]' );
		if ( copy && installerLogin && navigator.clipboard ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			navigator.clipboard.writeText( installerLogin ).then( function () {
				copy.textContent = 'Copied';
			} );
		}
	}, true );

	$( document ).on( 'click', '[data-afc-ios-done]', function () {
		draft.customerName = '';
		draft.phone = '';
		draft.wifi = '';
		draft.wifiPassword = '';
		installerLogin = '';
	} );
}( jQuery ) );