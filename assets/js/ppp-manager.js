( function ( $ ) {
	'use strict';

	let profiles = [];
	let users = [];
	let loaded = false;
	let loadingPromise = null;
	let createStep = 1;
	let selectedPlan = '';
	let selectedUser = null;
	let installerLogin = '';

	function mode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) {
			return shell.dataset.afcMode;
		}
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) {
			return 'advanced';
		}
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) {
			return 'basic';
		}
		return afcPPPManager.mode || 'basic';
	}

	function errorMessage( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	function showNotice( target, message, type ) {
		$( target ).html( '<div class="alert alert-' + ( type || 'info' ) + '">' + $( '<div>' ).text( message ).html() + '</div>' );
	}

	function clearNotice( target ) {
		$( target ).empty();
	}

	function api( action, payload ) {
		return $.post( afcPPPManager.ajaxUrl, Object.assign( {
			action: action,
			nonce: afcPPPManager.nonce
		}, payload || {} ) );
	}

	function loadData( force ) {
		if ( loaded && ! force ) {
			return $.Deferred().resolve().promise();
		}
		if ( loadingPromise && ! force ) {
			return loadingPromise;
		}
		loadingPromise = api( 'afc_ppp_manager_bootstrap' ).then( function ( response ) {
			if ( ! response.success ) {
				return $.Deferred().reject( response ).promise();
			}
			profiles = response.data.profiles || [];
			users = response.data.users || [];
			loaded = true;
			populateProfileSelects();
			renderPlanCards();
			renderManagerList();
			return response;
		} ).always( function () {
			loadingPromise = null;
		} );
		return loadingPromise;
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function escapeAttr( value ) {
		return String( value || '' )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function profileOptions( includeTechnical ) {
		return profiles.filter( function ( profile ) {
			return includeTechnical || profile.basic;
		} ).map( function ( profile ) {
			const rate = profile.rate_limit ? ' · ' + profile.rate_limit : '';
			return '<option value="' + escapeAttr( profile.name ) + '">' + escapeHtml( profile.name + rate ) + '</option>';
		} ).join( '' );
	}

	function populateProfileSelects() {
		$( '#afc-new-ppp-profile-advanced' ).html( '<option value="">Choose profile</option>' + profileOptions( true ) );
		$( '#afc-edit-ppp-profile' ).html( '<option value="">Choose plan</option>' + profileOptions( 'advanced' === mode() ) );
	}

	function renderPlanCards() {
		const basicProfiles = profiles.filter( function ( profile ) { return profile.basic; } );
		if ( ! basicProfiles.length ) {
			$( '#afc-new-ppp-plan-cards' ).html( '<div class="alert alert-warning">No active MikroTik plans were found.</div>' );
			return;
		}
		$( '#afc-new-ppp-plan-cards' ).html( basicProfiles.map( function ( profile ) {
			return '<button class="afc-ppp-plan-card' + ( selectedPlan === profile.name ? ' is-selected' : '' ) + '" type="button" data-profile="' + escapeAttr( profile.name ) + '">' +
				'<strong>' + escapeHtml( profile.name ) + '</strong>' +
				( profile.rate_limit ? '<small>' + escapeHtml( profile.rate_limit ) + '</small>' : '' ) +
				'</button>';
		} ).join( '' ) );
	}

	function pascalName( value ) {
		return String( value || '' ).normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			.split( /[^A-Za-z0-9]+/ ).filter( Boolean )
			.map( function ( part ) { return part.charAt( 0 ).toUpperCase() + part.slice( 1 ).toLowerCase(); } )
			.join( '' ) || 'Customer';
	}

	function planToken( value ) {
		const match = String( value || '' ).match( /\d{3,6}/ );
		return match ? match[ 0 ] : String( value || '' ).replace( /[^A-Za-z0-9]+/g, '' ) || 'Plan';
	}

	function generatedUsername( name, installed, plan ) {
		const dayMatch = String( installed || '' ).match( /^\d{4}-\d{2}-(\d{2})$/ );
		return pascalName( name ) + '_' + ( dayMatch ? dayMatch[ 1 ] : '00' ) + '_' + planToken( plan );
	}

	function resetCreate() {
		createStep = 1;
		selectedPlan = '';
		installerLogin = '';
		$( '#afc-ppp-create-form' )[ 0 ].reset();
		$( '#afc-new-ppp-installed-advanced' ).val( afcPPPManager.currentDate );
		$( '#afc-ppp-create-success' ).prop( 'hidden', true );
		$( '#afc-created-ppp-username' ).text( '' );
		clearNotice( '#afc-ppp-create-notice' );
		renderPlanCards();
		showCreateStep();
	}

	function showCreateStep() {
		$( '[data-afc-create-step]' ).removeClass( 'is-active' ).filter( '[data-afc-create-step="' + createStep + '"]' ).addClass( 'is-active' );
		$( '[data-afc-create-step-dot]' ).removeClass( 'is-active is-done' ).each( function () {
			const number = Number( this.dataset.afcCreateStepDot );
			$( this ).toggleClass( 'is-active', number === createStep ).toggleClass( 'is-done', number < createStep );
		} );
		$( '#afc-ppp-create-back' ).prop( 'hidden', 1 === createStep );
		$( '#afc-ppp-create-next' ).prop( 'hidden', 3 === createStep );
		$( '#afc-ppp-create-submit' ).prop( 'hidden', 3 !== createStep );
		if ( 3 === createStep ) {
			fillCreateReview();
		}
	}

	function basicCreateFieldsValid() {
		const fields = [ '#afc-new-ppp-name', '#afc-new-ppp-phone', '#afc-new-ppp-address' ];
		let valid = true;
		fields.forEach( function ( selector ) {
			const element = $( selector )[ 0 ];
			if ( ! element.reportValidity() ) {
				valid = false;
			}
		} );
		return valid;
	}

	function fillCreateReview() {
		const name = $( '#afc-new-ppp-name' ).val();
		const phone = $( '#afc-new-ppp-phone' ).val();
		const address = $( '#afc-new-ppp-address' ).val();
		const username = generatedUsername( name, afcPPPManager.currentDate, selectedPlan );
		$( '#afc-new-ppp-review-name' ).text( name );
		$( '#afc-new-ppp-review-phone' ).text( phone );
		$( '#afc-new-ppp-review-address' ).text( address );
		$( '#afc-new-ppp-review-plan' ).text( 'Plan: ' + selectedPlan );
		$( '#afc-new-ppp-review-username' ).text( username );
	}

	function createPayload() {
		if ( 'advanced' === mode() ) {
			return {
				customer_name: $( '#afc-new-ppp-name-advanced' ).val(),
				phone: $( '#afc-new-ppp-phone-advanced' ).val(),
				address: $( '#afc-new-ppp-address-advanced' ).val(),
				profile: $( '#afc-new-ppp-profile-advanced' ).val(),
				installed: $( '#afc-new-ppp-installed-advanced' ).val(),
				username: $( '#afc-new-ppp-username-advanced' ).val()
			};
		}
		return {
			customer_name: $( '#afc-new-ppp-name' ).val(),
			phone: $( '#afc-new-ppp-phone' ).val(),
			address: $( '#afc-new-ppp-address' ).val(),
			profile: selectedPlan
		};
	}

	function submitCreate( button ) {
		const payload = createPayload();
		if ( ! payload.customer_name || ! payload.phone || ! payload.address || ! payload.profile ) {
			showNotice( '#afc-ppp-create-notice', 'Complete the name, CP number, address, and plan.', 'warning' );
			return;
		}
		const $button = $( button );
		$button.prop( 'disabled', true ).text( 'Creating…' );
		clearNotice( '#afc-ppp-create-notice' );
		api( 'afc_ppp_manager_create', payload ).done( function ( response ) {
			if ( ! response.success ) {
				showNotice( '#afc-ppp-create-notice', errorMessage( response, 'The PPP account could not be created.' ), 'danger' );
				return;
			}
			const user = response.data.user;
			installerLogin = 'PPP username: ' + user.username + '\nPPP password: ' + response.data.password;
			$( '#afc-created-ppp-username' ).text( user.username );
			$( '#afc-ppp-create-success' ).prop( 'hidden', false );
			$( '.afc-ppp-wizard, .afc-ppp-advanced-grid, .afc-ppp-wizard-actions, #afc-ppp-create-submit-advanced' ).addClass( 'afc-created-hidden' );
			showNotice( '#afc-ppp-create-notice', response.data.message, 'success' );
			loadData( true );
			$( '#afc-refresh-ppp' ).trigger( 'click' );
		} ).fail( function () {
			showNotice( '#afc-ppp-create-notice', 'The request failed. Check the MikroTik connection.', 'danger' );
		} ).always( function () {
			$button.prop( 'disabled', false ).text( 'Create PPP Account' );
		} );
	}

	function renderManagerList() {
		const term = String( $( '#afc-ppp-manager-search' ).val() || '' ).toLowerCase();
		const filtered = users.filter( function ( user ) {
			return [ user.customer_name, user.username, user.phone, user.address, user.profile ].join( ' ' ).toLowerCase().includes( term );
		} );
		$( '#afc-ppp-manager-list' ).html( filtered.length ? filtered.map( function ( user ) {
			const active = selectedUser && selectedUser.id === user.id ? ' is-active' : '';
			return '<button class="afc-ppp-manager-person' + active + '" type="button" data-id="' + escapeAttr( user.id ) + '">' +
				'<strong>' + escapeHtml( user.customer_name || user.username ) + '</strong>' +
				'<small>' + escapeHtml( user.phone || 'No CP number' ) + '</small>' +
				'<span>' + escapeHtml( user.username ) + ' · ' + escapeHtml( user.profile ) + '</span>' +
				'</button>';
		} ).join( '' ) : '<div class="afc-ppp-manager-empty-list">No matching PPP account.</div>' );
	}

	function selectUser( id ) {
		selectedUser = users.find( function ( user ) { return String( user.id ) === String( id ); } ) || null;
		if ( ! selectedUser ) {
			return;
		}
		renderManagerList();
		$( '#afc-ppp-manager-empty' ).prop( 'hidden', true );
		$( '#afc-ppp-manager-editor' ).prop( 'hidden', false );
		$( '#afc-save-ppp-details' ).prop( 'disabled', false );
		clearNotice( '#afc-ppp-manager-notice' );
		$( '#afc-edit-ppp-id' ).val( selectedUser.id );
		$( '#afc-edit-heading-name' ).text( selectedUser.customer_name || selectedUser.username );
		$( '#afc-edit-heading-username' ).text( selectedUser.username );
		$( '#afc-edit-heading-profile' ).text( selectedUser.profile );
		$( '#afc-edit-ppp-name' ).val( selectedUser.customer_name );
		$( '#afc-edit-ppp-phone' ).val( selectedUser.phone );
		$( '#afc-edit-ppp-address' ).val( selectedUser.address );
		populateProfileSelects();
		$( '#afc-edit-ppp-profile' ).val( selectedUser.profile );
		$( '#afc-edit-ppp-installed-basic, #afc-edit-ppp-installed' ).val( selectedUser.installed );
		$( '.afc-install-date-change' ).prop( 'open', false );
		$( '#afc-edit-ppp-username' ).val( selectedUser.username );
		$( '#afc-edit-ppp-grace' ).val( selectedUser.grace || 3 );
		$( '#afc-edit-ppp-billing-day' ).val( selectedUser.billing_day );
		$( '#afc-edit-ppp-payment-date' ).val( selectedUser.payment_date );
		$( '#afc-edit-ppp-paid-through' ).val( selectedUser.paid_through );
		$( '#afc-edit-ppp-next-due' ).val( selectedUser.next_due );
		$( '#afc-edit-ppp-cutoff' ).val( selectedUser.cutoff_date );
		$( '#afc-edit-ppp-reminder' ).val( selectedUser.due_reminder_date );
		$( '#afc-edit-ppp-amount' ).val( selectedUser.payment_amount );
		$( '#afc-edit-ppp-method' ).val( selectedUser.payment_method );
		$( '#afc-edit-ppp-wifi' ).val( selectedUser.wifi );
		$( '#afc-edit-ppp-new-password' ).val( '' );
		$( '#afc-edit-ppp-comment' ).val( selectedUser.comment );
	}

	function expectedEditedUsername() {
		if ( ! selectedUser ) {
			return '';
		}
		if ( 'advanced' === mode() ) {
			return $( '#afc-edit-ppp-username' ).val();
		}
		const date = $( '.afc-install-date-change' ).prop( 'open' ) ? $( '#afc-edit-ppp-installed-basic' ).val() : selectedUser.installed;
		return generatedUsername( $( '#afc-edit-ppp-name' ).val(), date, $( '#afc-edit-ppp-profile' ).val() );
	}

	function editPayload() {
		const payload = {
			id: $( '#afc-edit-ppp-id' ).val(),
			customer_name: $( '#afc-edit-ppp-name' ).val(),
			phone: $( '#afc-edit-ppp-phone' ).val(),
			address: $( '#afc-edit-ppp-address' ).val(),
			profile: $( '#afc-edit-ppp-profile' ).val()
		};
		if ( 'advanced' === mode() ) {
			return Object.assign( payload, {
				username: $( '#afc-edit-ppp-username' ).val(),
				installed: $( '#afc-edit-ppp-installed' ).val(),
				grace: $( '#afc-edit-ppp-grace' ).val(),
				billing_day: $( '#afc-edit-ppp-billing-day' ).val(),
				payment_date: $( '#afc-edit-ppp-payment-date' ).val(),
				paid_through: $( '#afc-edit-ppp-paid-through' ).val(),
				next_due: $( '#afc-edit-ppp-next-due' ).val(),
				cutoff_date: $( '#afc-edit-ppp-cutoff' ).val(),
				due_reminder_date: $( '#afc-edit-ppp-reminder' ).val(),
				payment_amount: $( '#afc-edit-ppp-amount' ).val(),
				payment_method: $( '#afc-edit-ppp-method' ).val(),
				wifi: $( '#afc-edit-ppp-wifi' ).val(),
				new_password: $( '#afc-edit-ppp-new-password' ).val(),
				comment: $( '#afc-edit-ppp-comment' ).val()
			} );
		}
		if ( $( '.afc-install-date-change' ).prop( 'open' ) && $( '#afc-edit-ppp-installed-basic' ).val() !== selectedUser.installed ) {
			payload.change_installed = 1;
			payload.installed = $( '#afc-edit-ppp-installed-basic' ).val();
		}
		return payload;
	}

	function saveUser() {
		if ( ! selectedUser ) {
			return;
		}
		const payload = editPayload();
		if ( ! payload.customer_name || ! payload.phone || ! payload.address || ! payload.profile ) {
			showNotice( '#afc-ppp-manager-notice', 'Name, CP number, address, and plan are required.', 'warning' );
			return;
		}
		const expected = expectedEditedUsername();
		if ( expected && expected !== selectedUser.username && ! window.confirm( 'The PPP username will change from ' + selectedUser.username + ' to ' + expected + '. The customer router login must also be updated. Continue?' ) ) {
			return;
		}
		const $button = $( '#afc-save-ppp-details' );
		$button.prop( 'disabled', true ).text( 'Saving…' );
		clearNotice( '#afc-ppp-manager-notice' );
		api( 'afc_ppp_manager_save', payload ).done( function ( response ) {
			if ( ! response.success ) {
				showNotice( '#afc-ppp-manager-notice', errorMessage( response, 'The PPP account could not be updated.' ), 'danger' );
				return;
			}
			showNotice( '#afc-ppp-manager-notice', response.data.message, 'success' );
			loadData( true ).done( function () {
				selectUser( response.data.user.id );
			} );
			$( '#afc-refresh-ppp' ).trigger( 'click' );
		} ).fail( function () {
			showNotice( '#afc-ppp-manager-notice', 'The request failed. Check the MikroTik connection.', 'danger' );
		} ).always( function () {
			$button.prop( 'disabled', false ).text( 'Save PPP Details' );
		} );
	}

	function openDialog( selector ) {
		const dialog = document.querySelector( selector );
		if ( ! dialog ) {
			return;
		}
		loadData().done( function () {
			populateProfileSelects();
			if ( '#afc-ppp-create-dialog' === selector ) {
				resetCreate();
				$( '.afc-created-hidden' ).removeClass( 'afc-created-hidden' );
			} else {
				renderManagerList();
			}
			dialog.showModal();
		} ).fail( function ( response ) {
			window.alert( errorMessage( response, 'Could not load MikroTik PPP accounts.' ) );
		} );
	}

	$( document ).on( 'click', '#afc-add-ppp-account', function () {
		openDialog( '#afc-ppp-create-dialog' );
	} );

	$( document ).on( 'click', '#afc-find-edit-ppp', function () {
		openDialog( '#afc-ppp-manage-dialog' );
	} );

	$( document ).on( 'click', '.afc-ppp-plan-card', function () {
		selectedPlan = this.dataset.profile || '';
		renderPlanCards();
	} );

	$( document ).on( 'click', '#afc-ppp-create-next', function () {
		if ( 1 === createStep && ! basicCreateFieldsValid() ) {
			return;
		}
		if ( 2 === createStep && ! selectedPlan ) {
			showNotice( '#afc-ppp-create-notice', 'Choose one plan.', 'warning' );
			return;
		}
		clearNotice( '#afc-ppp-create-notice' );
		createStep = Math.min( 3, createStep + 1 );
		showCreateStep();
	} );

	$( document ).on( 'click', '#afc-ppp-create-back', function () {
		createStep = Math.max( 1, createStep - 1 );
		showCreateStep();
	} );

	$( document ).on( 'click', '#afc-ppp-create-submit, #afc-ppp-create-submit-advanced', function () {
		submitCreate( this );
	} );

	$( document ).on( 'click', '#afc-copy-installer-login', function () {
		if ( ! installerLogin ) {
			return;
		}
		navigator.clipboard.writeText( installerLogin ).then( function () {
			$( '#afc-copy-installer-login' ).text( 'Copied' );
		} );
	} );

	$( document ).on( 'input', '#afc-ppp-manager-search', renderManagerList );
	$( document ).on( 'click', '.afc-ppp-manager-person', function () { selectUser( this.dataset.id ); } );
	$( document ).on( 'click', '#afc-save-ppp-details', saveUser );

	const shell = document.getElementById( 'afc-frontend-app' );
	if ( shell && window.MutationObserver ) {
		new MutationObserver( function () { populateProfileSelects(); } ).observe( shell, { attributes: true, attributeFilter: [ 'data-afc-mode' ] } );
	}
} )( jQuery );
