( function ( $ ) {
	'use strict';

	let profiles = [];
	let users = [];
	let serviceAreas = Array.isArray( afcPPPManager.serviceAreas ) ? afcPPPManager.serviceAreas : [];
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

	function addressSuggestions() {
		const suggestions = [];
		const seen = new Set();
		serviceAreas.forEach( function ( area ) {
			const name = String( area.name || '' ).trim();
			if ( ! name ) {
				return;
			}
			if ( ! seen.has( name.toLowerCase() ) ) {
				suggestions.push( name );
				seen.add( name.toLowerCase() );
			}
			( Array.isArray( area.zones ) ? area.zones : [] ).forEach( function ( zone ) {
				const text = 'Zone ' + zone + ', ' + name;
				if ( ! seen.has( text.toLowerCase() ) ) {
					suggestions.push( text );
					seen.add( text.toLowerCase() );
				}
			} );
		} );
		return suggestions;
	}

	function initializeAddressSelect( element, selectedValue ) {
		const $select = $( element );
		if ( ! $select.length ) {
			return;
		}
		const value = undefined === selectedValue ? String( $select.val() || '' ) : String( selectedValue || '' );
		if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
			$select.select2( 'destroy' );
		}
		$select.empty().append( $( '<option>', { value: '', text: '' } ) );
		addressSuggestions().forEach( function ( suggestion ) {
			$select.append( $( '<option>', { value: suggestion, text: suggestion } ) );
		} );
		if ( value && ! $select.find( 'option' ).filter( function () { return this.value === value; } ).length ) {
			$select.append( $( '<option>', { value: value, text: value } ) );
		}
		$select.val( value );

		if ( $.fn.select2 ) {
			const $dialog = $select.closest( 'dialog' );
			$select.select2( {
				width: '100%',
				tags: true,
				placeholder: $select.data( 'placeholder' ) || 'Type zone, barangay, or complete address',
				allowClear: true,
				dropdownParent: $dialog.length ? $dialog : $( document.body ),
				createTag: function ( params ) {
					const term = String( params.term || '' ).trim();
					return term ? { id: term, text: term, newTag: true } : null;
				}
			} );
		}
	}

	function initializeAddressSelects() {
		$( '.afc-address-select' ).each( function () {
			initializeAddressSelect( this );
		} );
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
		const form = $( '#afc-ppp-create-form' )[ 0 ];
		if ( form ) {
			form.reset();
		}
		$( '#afc-new-ppp-installed-advanced' ).val( afcPPPManager.currentDate );
		$( '#afc-ppp-create-success' ).prop( 'hidden', true );
		$( '#afc-created-ppp-username' ).text( '' );
		$( '#afc-copy-installer-login' ).text( 'Copy installer login' );
		clearNotice( '#afc-ppp-create-notice' );
		$( '#afc-ppp-create-dialog .afc-created-hidden' ).removeClass( 'afc-created-hidden' );
		initializeAddressSelect( '#afc-new-ppp-address', '' );
		initializeAddressSelect( '#afc-new-ppp-address-advanced', '' );
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
		const fields = [ '#afc-new-ppp-name', '#afc-new-ppp-phone' ];
		let valid = true;
		fields.forEach( function ( selector ) {
			const element = $( selector )[ 0 ];
			if ( element && ! element.reportValidity() ) {
				valid = false;
			}
		} );
		if ( ! $( '#afc-new-ppp-address' ).val() ) {
			showNotice( '#afc-ppp-create-notice', 'Choose or type the customer address.', 'warning' );
			valid = false;
		}
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
			$( '#afc-ppp-create-dialog' ).find( '.afc-ppp-wizard, .afc-ppp-advanced-grid, .afc-ppp-wizard-actions, #afc-ppp-create-submit-advanced' ).addClass( 'afc-created-hidden' );
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
		initializeAddressSelect( '#afc-edit-ppp-address', selectedUser.address );
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

	function serviceAreaRow( area ) {
		area = area || {};
		return '<div class="afc-service-area-row">' +
			'<input class="form-control" data-area-field="name" type="text" value="' + escapeAttr( area.name || '' ) + '" placeholder="Lingion">' +
			'<input class="form-control" data-area-field="zones" type="text" value="' + escapeAttr( ( area.zones || [] ).join( ', ' ) ) + '" placeholder="1, 2, 3">' +
			'<input class="form-control" data-area-field="latitude" type="number" step="0.0000001" value="' + escapeAttr( area.latitude || '' ) + '" placeholder="Optional">' +
			'<input class="form-control" data-area-field="longitude" type="number" step="0.0000001" value="' + escapeAttr( area.longitude || '' ) + '" placeholder="Optional">' +
			'<button class="btn btn-outline-danger btn-sm afc-remove-service-area" type="button" aria-label="Remove service area">×</button>' +
			'</div>';
	}

	function renderServiceAreas() {
		const rows = serviceAreas.length ? serviceAreas : [ { name: '', zones: [], latitude: '', longitude: '' } ];
		$( '#afc-service-area-rows' ).html( rows.map( serviceAreaRow ).join( '' ) );
	}

	function collectServiceAreas() {
		const areas = [];
		$( '#afc-service-area-rows .afc-service-area-row' ).each( function () {
			const $row = $( this );
			const name = String( $row.find( '[data-area-field="name"]' ).val() || '' ).trim();
			if ( ! name ) {
				return;
			}
			areas.push( {
				name: name,
				zones: String( $row.find( '[data-area-field="zones"]' ).val() || '' ).split( ',' ).map( function ( zone ) { return zone.trim(); } ).filter( Boolean ),
				latitude: $row.find( '[data-area-field="latitude"]' ).val(),
				longitude: $row.find( '[data-area-field="longitude"]' ).val()
			} );
		} );
		return areas;
	}

	function saveServiceAreas() {
		const $button = $( '#afc-save-service-areas' );
		$button.prop( 'disabled', true ).text( 'Saving…' );
		clearNotice( '#afc-service-areas-notice' );
		api( 'afc_ppp_manager_save_service_areas', { areas: JSON.stringify( collectServiceAreas() ) } ).done( function ( response ) {
			if ( ! response.success ) {
				showNotice( '#afc-service-areas-notice', errorMessage( response, 'Service areas could not be saved.' ), 'danger' );
				return;
			}
			serviceAreas = response.data.areas || [];
			renderServiceAreas();
			initializeAddressSelects();
			showNotice( '#afc-service-areas-notice', response.data.message, 'success' );
		} ).fail( function () {
			showNotice( '#afc-service-areas-notice', 'The request failed while saving service areas.', 'danger' );
		} ).always( function () {
			$button.prop( 'disabled', false ).text( 'Save Service Areas' );
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
			} else {
				renderManagerList();
			}
			dialog.showModal();
			initializeAddressSelects();
		} ).fail( function ( response ) {
			window.alert( errorMessage( response, 'Could not load MikroTik PPP accounts.' ) );
		} );
	}

	$( document ).on( 'submit', '#afc-ppp-create-form, #afc-ppp-manage-form, #afc-service-areas-form', function ( event ) {
		event.preventDefault();
	} );

	$( document ).on( 'click', '[data-afc-dialog-close]', function ( event ) {
		event.preventDefault();
		const dialog = this.closest( 'dialog' );
		if ( dialog && dialog.open ) {
			dialog.close();
		}
	} );

	$( document ).on( 'click', '#afc-add-ppp-account', function () {
		openDialog( '#afc-ppp-create-dialog' );
	} );

	$( document ).on( 'click', '#afc-find-edit-ppp', function () {
		openDialog( '#afc-ppp-manage-dialog' );
	} );

	$( document ).on( 'click', '#afc-manage-service-areas', function () {
		renderServiceAreas();
		clearNotice( '#afc-service-areas-notice' );
		const dialog = document.getElementById( 'afc-service-areas-dialog' );
		if ( dialog ) {
			dialog.showModal();
		}
	} );

	$( document ).on( 'click', '#afc-add-service-area-row', function () {
		$( '#afc-service-area-rows' ).append( serviceAreaRow( {} ) );
	} );

	$( document ).on( 'click', '.afc-remove-service-area', function () {
		$( this ).closest( '.afc-service-area-row' ).remove();
		if ( ! $( '#afc-service-area-rows .afc-service-area-row' ).length ) {
			$( '#afc-service-area-rows' ).append( serviceAreaRow( {} ) );
		}
	} );

	$( document ).on( 'click', '#afc-save-service-areas', saveServiceAreas );

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

	$( '#afc-ppp-create-dialog' ).on( 'close', function () {
		resetCreate();
	} );

	const shell = document.getElementById( 'afc-frontend-app' );
	if ( shell && window.MutationObserver ) {
		new MutationObserver( function () {
			populateProfileSelects();
			initializeAddressSelects();
		} ).observe( shell, { attributes: true, attributeFilter: [ 'data-afc-mode' ] } );
	}
} )( jQuery );