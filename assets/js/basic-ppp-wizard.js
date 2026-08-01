( function ( $ ) {
	'use strict';

	const state = {
		profiles: [],
		loading: null,
		step: 0,
		detailStep: 0,
		selectedPlan: '',
		switching: false,
		installerLogin: '',
	};

	function mode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) {
			return shell.dataset.afcMode;
		}
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) {
			return 'advanced';
		}
		return 'basic';
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function escapeAttr( value ) {
		return String( null == value ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function api( action, payload ) {
		return $.post( afcPPPManager.ajaxUrl, Object.assign( {
			action: action,
			nonce: afcPPPManager.nonce,
		}, payload || {} ) );
	}

	function errorMessage( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	function loadBootstrap( force ) {
		if ( state.profiles.length && ! force ) {
			return $.Deferred().resolve().promise();
		}
		if ( state.loading && ! force ) {
			return state.loading;
		}
		state.loading = api( 'afc_ppp_manager_bootstrap' ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				return $.Deferred().reject( response ).promise();
			}
			state.profiles = Array.isArray( response.data.profiles ) ? response.data.profiles : [];
			return response;
		} ).always( function () {
			state.loading = null;
		} );
		return state.loading;
	}

	function arrowIcon() {
		return '<span aria-hidden="true">&#8594;</span>';
	}

	function backIcon() {
		return '<span aria-hidden="true">&#8249;</span>';
	}

	function closeIcon() {
		return '<span aria-hidden="true">&#215;</span>';
	}

	function fieldStep( step, id, type, placeholder, autocomplete, inputmode ) {
		return '<section class="afc-ios-wizard-step' + ( 0 === step ? ' is-active' : '' ) + '" data-afc-ios-step="' + step + '">' +
			'<div class="afc-ios-field-row">' +
				'<input id="' + id + '" type="' + type + '" placeholder="' + placeholder + '" aria-label="' + placeholder + '"' +
				( autocomplete ? ' autocomplete="' + autocomplete + '"' : '' ) +
				( inputmode ? ' inputmode="' + inputmode + '"' : '' ) + ' required>' +
				'<button class="afc-ios-next-button" type="button" data-afc-ios-next aria-label="Next">' + arrowIcon() + '</button>' +
			'</div>' +
		'</section>';
	}

	function ensureWizard() {
		const dialog = document.getElementById( 'afc-ppp-create-dialog' );
		if ( ! dialog || document.getElementById( 'afc-ios-basic-create' ) ) {
			return;
		}

		dialog.classList.add( 'afc-ios-basic-create-dialog' );
		const body = dialog.querySelector( '.afc-dialog-body' );
		if ( ! body ) {
			return;
		}

		body.insertAdjacentHTML( 'afterbegin',
			'<div id="afc-ios-basic-create" class="afc-ios-basic-create afc-basic-only">' +
				'<div class="afc-ios-wizard-bar">' +
					'<button class="afc-ios-icon-button" type="button" data-afc-ios-first-back aria-label="Previous" hidden>' + backIcon() + '</button>' +
					'<div class="afc-ios-progress" aria-label="Customer details progress">' +
						'<span class="is-active"></span><span></span><span></span><span></span>' +
					'</div>' +
					'<button class="afc-ios-icon-button" type="button" data-afc-ios-close aria-label="Close">' + closeIcon() + '</button>' +
				'</div>' +
				'<div id="afc-ios-create-notice" class="afc-ios-notice" aria-live="polite"></div>' +
				'<div class="afc-ios-wizard-slides">' +
					fieldStep( 0, 'afc-ios-customer-name', 'text', 'Full name', 'name', '' ) +
					fieldStep( 1, 'afc-ios-customer-phone', 'tel', 'CP number', 'tel', 'tel' ) +
					fieldStep( 2, 'afc-ios-wifi-name', 'text', 'WiFi name', 'off', '' ) +
					fieldStep( 3, 'afc-ios-wifi-password', 'password', 'WiFi password', 'new-password', '' ) +
				'</div>' +
			'</div>'
		);

		if ( ! document.getElementById( 'afc-ios-ppp-details-dialog' ) ) {
			document.body.insertAdjacentHTML( 'beforeend',
				'<dialog id="afc-ios-ppp-details-dialog" class="afc-dialog afc-ios-dialog afc-ios-basic-details-dialog">' +
					'<form id="afc-ios-ppp-details-form">' +
						'<div class="afc-ios-wizard-bar">' +
							'<button class="afc-ios-icon-button" type="button" data-afc-ios-details-back aria-label="Previous">' + backIcon() + '</button>' +
							'<div class="afc-ios-progress afc-ios-progress-two" aria-label="Plan and address progress"><span class="is-active"></span><span></span></div>' +
							'<button class="afc-ios-icon-button" type="button" data-afc-ios-close aria-label="Close">' + closeIcon() + '</button>' +
						'</div>' +
						'<div id="afc-ios-details-notice" class="afc-ios-notice" aria-live="polite"></div>' +
						'<div id="afc-ios-details-wizard" class="afc-ios-details-wizard">' +
							'<section class="afc-ios-wizard-step is-active" data-afc-ios-detail-step="0">' +
								'<div id="afc-ios-plan-cards" class="afc-ios-plan-cards"></div>' +
								'<button class="afc-ios-wide-next" type="button" data-afc-ios-plan-next disabled><span>Continue</span>' + arrowIcon() + '</button>' +
							'</section>' +
							'<section class="afc-ios-wizard-step" data-afc-ios-detail-step="1">' +
								'<div class="afc-ios-field-row">' +
									'<input id="afc-ios-customer-address" type="text" list="afc-ios-address-suggestions" placeholder="Complete address" aria-label="Complete address" autocomplete="street-address" required>' +
									'<datalist id="afc-ios-address-suggestions"></datalist>' +
									'<button class="afc-ios-next-button afc-ios-create-button" type="button" data-afc-ios-create aria-label="Create PPP account">&#10003;</button>' +
								'</div>' +
							'</section>' +
						'</div>' +
						'<div id="afc-ios-create-success" class="afc-ios-success" hidden>' +
							'<div class="afc-ios-success-icon" aria-hidden="true">&#10003;</div>' +
							'<strong>PPP account created</strong>' +
							'<code id="afc-ios-created-username"></code>' +
							'<div class="afc-ios-success-actions">' +
								'<button type="button" data-afc-ios-copy-login>Copy login</button>' +
								'<button type="button" data-afc-ios-done>Done</button>' +
							'</div>' +
						'</div>' +
					'</form>' +
				'</dialog>'
			);
		}
	}

	function serviceAreaSuggestions() {
		const suggestions = [];
		const seen = new Set();
		const areas = Array.isArray( afcPPPManager.serviceAreas ) ? afcPPPManager.serviceAreas : [];
		areas.forEach( function ( area ) {
			const name = String( area.name || '' ).trim();
			if ( ! name ) {
				return;
			}
			if ( ! seen.has( name.toLowerCase() ) ) {
				suggestions.push( name );
				seen.add( name.toLowerCase() );
			}
			( Array.isArray( area.zones ) ? area.zones : [] ).forEach( function ( zone ) {
				const value = 'Zone ' + zone + ', ' + name;
				if ( ! seen.has( value.toLowerCase() ) ) {
					suggestions.push( value );
					seen.add( value.toLowerCase() );
				}
			} );
		} );
		return suggestions;
	}

	function renderAddressSuggestions() {
		$( '#afc-ios-address-suggestions' ).html( serviceAreaSuggestions().map( function ( value ) {
			return '<option value="' + escapeAttr( value ) + '"></option>';
		} ).join( '' ) );
	}

	function renderPlans() {
		const plans = state.profiles.filter( function ( profile ) { return profile.basic; } );
		$( '#afc-ios-plan-cards' ).html( plans.length ? plans.map( function ( profile ) {
			return '<button class="afc-ios-plan-card' + ( state.selectedPlan === profile.name ? ' is-selected' : '' ) + '" type="button" data-afc-ios-plan="' + escapeAttr( profile.name ) + '">' +
				'<strong>' + escapeHtml( profile.name ) + '</strong>' +
				( profile.rate_limit ? '<small>' + escapeHtml( profile.rate_limit ) + '</small>' : '' ) +
			'</button>';
		} ).join( '' ) : '<p class="afc-ios-empty">No active plans found.</p>' );
		$( '[data-afc-ios-plan-next]' ).prop( 'disabled', ! state.selectedPlan );
	}

	function showNotice( selector, message ) {
		$( selector ).text( message || '' ).prop( 'hidden', ! message );
	}

	function focusActiveField() {
		window.setTimeout( function () {
			const details = document.getElementById( 'afc-ios-ppp-details-dialog' );
			const dialog = details && details.open ? details : document.getElementById( 'afc-ppp-create-dialog' );
			const active = dialog && dialog.querySelector( '.afc-ios-wizard-step.is-active input' );
			if ( active ) {
				active.focus();
			}
		}, 180 );
	}

	function showFirstStep( direction ) {
		const $wizard = $( '#afc-ios-basic-create' );
		$wizard.attr( 'data-direction', direction || 'forward' );
		$wizard.find( '[data-afc-ios-step]' ).removeClass( 'is-active' ).filter( '[data-afc-ios-step="' + state.step + '"]' ).addClass( 'is-active' );
		$wizard.find( '.afc-ios-progress span' ).removeClass( 'is-active is-done' ).each( function ( index ) {
			$( this ).toggleClass( 'is-active', index === state.step ).toggleClass( 'is-done', index < state.step );
		} );
		$( '[data-afc-ios-first-back]' ).prop( 'hidden', 0 === state.step );
		showNotice( '#afc-ios-create-notice', '' );
		focusActiveField();
	}

	function showDetailStep( direction ) {
		const $wizard = $( '#afc-ios-details-wizard' );
		$wizard.attr( 'data-direction', direction || 'forward' );
		$wizard.find( '[data-afc-ios-detail-step]' ).removeClass( 'is-active' ).filter( '[data-afc-ios-detail-step="' + state.detailStep + '"]' ).addClass( 'is-active' );
		$( '#afc-ios-ppp-details-dialog .afc-ios-progress span' ).removeClass( 'is-active is-done' ).each( function ( index ) {
			$( this ).toggleClass( 'is-active', index === state.detailStep ).toggleClass( 'is-done', index < state.detailStep );
		} );
		showNotice( '#afc-ios-details-notice', '' );
		focusActiveField();
	}

	function resetWizard() {
		state.step = 0;
		state.detailStep = 0;
		state.selectedPlan = '';
		state.installerLogin = '';
		$( '#afc-ios-customer-name, #afc-ios-customer-phone, #afc-ios-wifi-name, #afc-ios-wifi-password, #afc-ios-customer-address' ).val( '' );
		$( '#afc-ios-create-success' ).prop( 'hidden', true );
		$( '#afc-ios-details-wizard, #afc-ios-ppp-details-dialog .afc-ios-wizard-bar' ).prop( 'hidden', false );
		showNotice( '#afc-ios-create-notice, #afc-ios-details-notice', '' );
		renderPlans();
		renderAddressSuggestions();
		showFirstStep( 'forward' );
		showDetailStep( 'forward' );
	}

	function valueForStep( step ) {
		return String( $( [ '#afc-ios-customer-name', '#afc-ios-customer-phone', '#afc-ios-wifi-name', '#afc-ios-wifi-password' ][ step ] ).val() || '' ).trim();
	}

	function validateFirstStep() {
		const value = valueForStep( state.step );
		if ( ! value ) {
			showNotice( '#afc-ios-create-notice', 'Please complete this field.' );
			return false;
		}
		if ( 3 === state.step && value.length < 8 ) {
			showNotice( '#afc-ios-create-notice', 'WiFi password must contain at least 8 characters.' );
			return false;
		}
		return true;
	}

	function openFirstDialog( preserve ) {
		ensureWizard();
		if ( ! preserve ) {
			resetWizard();
		}
		const dialog = document.getElementById( 'afc-ppp-create-dialog' );
		if ( dialog ) {
			dialog.classList.add( 'is-afc-ios-basic-mode' );
		}
		if ( dialog && ! dialog.open ) {
			dialog.showModal();
		}
		showFirstStep( 'back' );
	}

	function openDetailsDialog() {
		const first = document.getElementById( 'afc-ppp-create-dialog' );
		const details = document.getElementById( 'afc-ios-ppp-details-dialog' );
		if ( ! details ) {
			return;
		}
		state.switching = true;
		if ( first && first.open ) {
			first.close();
		}
		window.setTimeout( function () {
			state.detailStep = 0;
			renderPlans();
			renderAddressSuggestions();
			details.showModal();
			showDetailStep( 'forward' );
			state.switching = false;
		}, 110 );
	}

	function returnToFirstDialog() {
		const details = document.getElementById( 'afc-ios-ppp-details-dialog' );
		state.switching = true;
		if ( details && details.open ) {
			details.close();
		}
		window.setTimeout( function () {
			state.step = 3;
			openFirstDialog( true );
			state.switching = false;
		}, 110 );
	}

	function openBasicWizard() {
		ensureWizard();
		loadBootstrap().done( function () {
			renderPlans();
			openFirstDialog( false );
		} ).fail( function ( response ) {
			window.alert( errorMessage( response, 'Could not load MikroTik plans.' ) );
		} );
	}

	function submitCreate() {
		const address = String( $( '#afc-ios-customer-address' ).val() || '' ).trim();
		if ( ! state.selectedPlan ) {
			state.detailStep = 0;
			showDetailStep( 'back' );
			showNotice( '#afc-ios-details-notice', 'Choose a plan.' );
			return;
		}
		if ( ! address ) {
			showNotice( '#afc-ios-details-notice', 'Enter the complete address.' );
			return;
		}

		const $button = $( '[data-afc-ios-create]' );
		$button.prop( 'disabled', true ).addClass( 'is-loading' ).html( '<span aria-hidden="true">&hellip;</span>' );
		showNotice( '#afc-ios-details-notice', '' );

		const payload = {
			customer_name: String( $( '#afc-ios-customer-name' ).val() || '' ).trim(),
			phone: String( $( '#afc-ios-customer-phone' ).val() || '' ).trim(),
			wifi: String( $( '#afc-ios-wifi-name' ).val() || '' ).trim(),
			wifi_password: String( $( '#afc-ios-wifi-password' ).val() || '' ).trim(),
			profile: state.selectedPlan,
			address: address,
		};

		api( 'afc_ppp_manager_create', payload ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				showNotice( '#afc-ios-details-notice', errorMessage( response, 'The PPP account could not be created.' ) );
				return;
			}
			const user = response.data.user || {};
			state.installerLogin = 'PPP username: ' + ( user.username || '' ) + '\n' +
				'PPP password: ' + ( response.data.password || '' ) + '\n' +
				'WiFi name: ' + payload.wifi + '\n' +
				'WiFi password: ' + payload.wifi_password;
			$( '#afc-ios-created-username' ).text( user.username || '' );
			$( '#afc-ios-details-wizard, #afc-ios-ppp-details-dialog .afc-ios-wizard-bar' ).prop( 'hidden', true );
			$( '#afc-ios-create-success' ).prop( 'hidden', false );
			$( '#afc-refresh-ppp' ).trigger( 'click' );
			loadBootstrap( true );
		} ).fail( function () {
			showNotice( '#afc-ios-details-notice', 'The request failed. Check the MikroTik connection.' );
		} ).always( function () {
			$button.prop( 'disabled', false ).removeClass( 'is-loading' ).html( '&#10003;' );
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		const trigger = event.target.closest && event.target.closest( '#afc-add-ppp-account, #afc-basic-add-ppp' );
		if ( trigger && 'basic' === mode() ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			openBasicWizard();
			return;
		}

		if ( trigger ) {
			const createDialog = document.getElementById( 'afc-ppp-create-dialog' );
			if ( createDialog ) {
				createDialog.classList.remove( 'is-afc-ios-basic-mode' );
			}
		}

		if ( 'undefined' !== typeof HTMLDialogElement && event.target instanceof HTMLDialogElement && event.target.open ) {
			event.preventDefault();
			event.target.close();
		}
	}, true );

	$( document ).on( 'click', '[data-afc-ios-close]', function () {
		const dialog = this.closest( 'dialog' );
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	} );

	$( document ).on( 'click', '[data-afc-ios-next]', function () {
		if ( ! validateFirstStep() ) {
			return;
		}
		if ( state.step < 3 ) {
			state.step += 1;
			showFirstStep( 'forward' );
			return;
		}
		openDetailsDialog();
	} );

	$( document ).on( 'click', '[data-afc-ios-first-back]', function () {
		if ( state.step > 0 ) {
			state.step -= 1;
			showFirstStep( 'back' );
		}
	} );

	$( document ).on( 'keydown', '#afc-ios-basic-create input', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			$( this ).siblings( '[data-afc-ios-next]' ).trigger( 'click' );
		}
	} );

	$( document ).on( 'click', '[data-afc-ios-plan]', function () {
		state.selectedPlan = String( this.dataset.afcIosPlan || '' );
		renderPlans();
	} );

	$( document ).on( 'click', '[data-afc-ios-plan-next]', function () {
		if ( ! state.selectedPlan ) {
			showNotice( '#afc-ios-details-notice', 'Choose a plan.' );
			return;
		}
		state.detailStep = 1;
		showDetailStep( 'forward' );
	} );

	$( document ).on( 'click', '[data-afc-ios-details-back]', function () {
		if ( 1 === state.detailStep ) {
			state.detailStep = 0;
			showDetailStep( 'back' );
			return;
		}
		returnToFirstDialog();
	} );

	$( document ).on( 'keydown', '#afc-ios-customer-address', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			submitCreate();
		}
	} );

	$( document ).on( 'click', '[data-afc-ios-create]', submitCreate );

	$( document ).on( 'click', '[data-afc-ios-copy-login]', function () {
		if ( ! state.installerLogin || ! navigator.clipboard ) {
			return;
		}
		navigator.clipboard.writeText( state.installerLogin ).then( function () {
			$( '[data-afc-ios-copy-login]' ).text( 'Copied' );
		} );
	} );

	$( document ).on( 'click', '[data-afc-ios-done]', function () {
		const dialog = document.getElementById( 'afc-ios-ppp-details-dialog' );
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	} );

	$( document ).on( 'close', '#afc-ppp-create-dialog, #afc-ios-ppp-details-dialog', function () {
		if ( ! state.switching ) {
			resetWizard();
		}
	} );

	$( function () {
		ensureWizard();
	} );
}( jQuery ) );
