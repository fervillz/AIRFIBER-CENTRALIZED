( function ( $ ) {
	'use strict';

	let autoApplying = false;
	let buttonTimer = null;

	function selectedCount() {
		return Number( $( '#afc-area-manager-summary > div:first-child strong' ).text() || 0 );
	}

	function ensureInterface() {
		const select = $( '#afc-area-bulk-zone' );
		if ( ! select.length ) {
			return;
		}

		const wrapper = select.closest( '.mt-3' );
		const hint = wrapper.find( '.form-hint' ).first();
		hint.text( 'Choose a zone and it will apply immediately to every selected account. Review the preview, then update MikroTik.' );

		if ( ! document.getElementById( 'afc-area-bulk-zone-status' ) ) {
			$( '<div>', {
				id: 'afc-area-bulk-zone-status',
				class: 'small text-primary mt-2',
				'aria-live': 'polite'
			} ).insertAfter( hint );
		}

		$( '#afc-area-apply-zone' )
			.text( 'Reapply' )
			.attr( 'title', 'Reapply the selected zone to all checked accounts' );
	}

	function setStatus( message, type ) {
		ensureInterface();
		$( '#afc-area-bulk-zone-status' )
			.removeClass( 'text-primary text-success text-danger text-secondary' )
			.addClass( 'text-' + ( type || 'primary' ) )
			.text( message || '' );
	}

	function showAppliedButton() {
		const button = $( '#afc-area-apply-zone' );
		window.clearTimeout( buttonTimer );
		button.text( 'Applied ✓' );
		buttonTimer = window.setTimeout( function () {
			button.text( 'Reapply' );
		}, 1200 );
	}

	function applySelectedZone() {
		ensureInterface();

		const select = $( '#afc-area-bulk-zone' );
		const zone = String( select.val() || '' );
		const count = selectedCount();

		if ( ! zone ) {
			setStatus( 'Choose a zone for the selected accounts.', 'secondary' );
			return;
		}

		if ( ! count ) {
			setStatus( 'Select one or more accounts first. The chosen zone will apply as soon as accounts are checked.', 'danger' );
			return;
		}

		autoApplying = true;
		$( '#afc-area-apply-zone' ).trigger( 'click' );
		autoApplying = false;

		const ready = Number( $( '#afc-area-manager-summary > div:nth-child(2) strong' ).text() || count );
		setStatus(
			'Zone ' + zone + ' applied to ' + count + ' selected account(s). ' + ready + ' ready to update.',
			'success'
		);
		showAppliedButton();
	}

	$( document ).on( 'change', '#afc-area-bulk-zone', function () {
		applySelectedZone();
	} );

	$( document ).on( 'change', '#afc-area-select-all-visible, #afc-area-manager-rows .afc-area-user-check', function () {
		if ( this.checked && String( $( '#afc-area-bulk-zone' ).val() || '' ) ) {
			window.setTimeout( applySelectedZone, 0 );
		}
	} );

	$( document ).on( 'click', '#afc-area-apply-zone', function () {
		if ( autoApplying ) {
			return;
		}
		window.setTimeout( function () {
			const zone = String( $( '#afc-area-bulk-zone' ).val() || '' );
			const count = selectedCount();
			if ( zone && count ) {
				setStatus( 'Zone ' + zone + ' reapplied to ' + count + ' selected account(s).', 'success' );
				showAppliedButton();
			}
		}, 0 );
	} );

	/*
	 * The manager already has an in-dialog preview, progress bar and result
	 * message. Skip its legacy browser confirm so repeated AJAX batches require
	 * only one click on Update MikroTik.
	 */
	document.addEventListener( 'click', function ( event ) {
		const button = event.target.closest( '#afc-confirm-area-update' );
		if ( ! button || button.disabled ) {
			return;
		}

		const originalConfirm = window.confirm;
		window.confirm = function ( message ) {
			if ( String( message || '' ).includes( 'Update the Address value for' ) ) {
				return true;
			}
			return originalConfirm.call( window, message );
		};

		window.setTimeout( function () {
			window.confirm = originalConfirm;
		}, 0 );
	}, true );

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! String( settings.data || '' ).includes( 'action=afc_ppp_bulk_assign_area' ) ) {
			return;
		}

		if ( xhr.responseJSON && xhr.responseJSON.success ) {
			$( '#afc-area-bulk-zone' ).val( '' );
			setStatus( 'MikroTik update completed. Select the next accounts, then choose their zone.', 'secondary' );
			$( '#afc-area-apply-zone' ).text( 'Reapply' );
		}
	} );

	$( function () {
		ensureInterface();
		document.addEventListener( 'afc:open-area-manager', function () {
			window.setTimeout( ensureInterface, 0 );
		} );
	} );
}( jQuery ) );