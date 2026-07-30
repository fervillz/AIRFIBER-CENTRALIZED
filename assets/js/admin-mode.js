( function ( $ ) {
	'use strict';

	let currentMode = 'advanced';
	let saveRequest = null;

	function modeClass( mode ) {
		return 'afc-admin-mode-' + mode;
	}

	function setButtonState( switcher, mode ) {
		switcher.querySelectorAll( '[data-afc-admin-mode]' ).forEach( function ( button ) {
			const active = button.getAttribute( 'data-afc-admin-mode' ) === mode;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );

		const description = switcher.querySelector( '.afc-admin-mode-description' );
		if ( description && window.afcAdminMode && afcAdminMode.labels ) {
			description.textContent = 'basic' === mode
				? afcAdminMode.labels.basicDescription
				: afcAdminMode.labels.advancedDescription;
		}
	}

	function updateFriendlyLabels( mode ) {
		const subtitle = document.querySelector( '.afc-admin-page .page-header .text-secondary.mb-0' );
		if ( subtitle ) {
			if ( ! subtitle.hasAttribute( 'data-afc-advanced-copy' ) ) {
				subtitle.setAttribute( 'data-afc-advanced-copy', subtitle.textContent.trim() );
			}
			subtitle.textContent = 'basic' === mode
				? 'Collect payments, print collection lists and manage customer service.'
				: subtitle.getAttribute( 'data-afc-advanced-copy' );
		}

		const subscriberHeading = document.querySelector( '#afc-ppp-table thead th:nth-child(2) .afc-sort' );
		if ( subscriberHeading ) {
			const indicator = subscriberHeading.querySelector( '.afc-sort-indicator' );
			const indicatorText = indicator ? indicator.textContent : '';
			subscriberHeading.childNodes.forEach( function ( node ) {
				if ( Node.TEXT_NODE === node.nodeType ) {
					node.textContent = ( 'basic' === mode ? 'Customer ' : 'Subscriber ' );
				}
			} );
			if ( indicator ) {
				indicator.textContent = indicatorText;
			}
		}
	}

	function applyMode( mode, switcher ) {
		if ( 'basic' !== mode && 'advanced' !== mode ) {
			return;
		}

		currentMode = mode;
		document.body.classList.remove( modeClass( 'basic' ), modeClass( 'advanced' ), 'afc-admin-mode-changing' );
		document.body.classList.add( modeClass( mode ), 'afc-admin-mode-changing' );
		window.setTimeout( function () {
			document.body.classList.remove( 'afc-admin-mode-changing' );
		}, 260 );

		if ( switcher ) {
			setButtonState( switcher, mode );
		}
		updateFriendlyLabels( mode );
		document.dispatchEvent( new CustomEvent( 'afc:admin-mode-change', { detail: { mode: mode } } ) );
	}

	function saveMode( mode, switcher ) {
		if ( ! window.afcAdminMode ) {
			return;
		}

		const status = switcher.querySelector( '.afc-admin-mode-save-status' );
		if ( status ) {
			status.textContent = 'Saving…';
		}

		if ( saveRequest && saveRequest.abort ) {
			saveRequest.abort();
		}

		saveRequest = $.post( afcAdminMode.ajaxUrl, {
			action: 'afc_set_admin_mode',
			nonce: afcAdminMode.nonce,
			mode: mode
		} ).done( function ( response ) {
			if ( status ) {
				status.textContent = response && response.success ? 'Saved for your account' : 'Could not save';
			}
		} ).fail( function () {
			if ( status ) {
				status.textContent = 'Could not save';
			}
		} ).always( function () {
			window.setTimeout( function () {
				if ( status ) {
					status.textContent = '';
				}
			}, 1600 );
		} );
	}

	function createSwitcher() {
		if ( document.getElementById( 'afc-admin-mode-switcher' ) ) {
			return document.getElementById( 'afc-admin-mode-switcher' );
		}

		const page = document.querySelector( '.afc-admin-page' );
		if ( ! page ) {
			return null;
		}

		const switcher = document.createElement( 'div' );
		switcher.id = 'afc-admin-mode-switcher';
		switcher.className = 'afc-admin-mode-switcher';
		switcher.setAttribute( 'aria-label', 'Airfiber interface mode' );
		switcher.innerHTML =
			'<div class="afc-admin-mode-copy">' +
			'<span class="afc-admin-mode-eyebrow">Interface</span>' +
			'<span class="afc-admin-mode-description"></span>' +
			'</div>' +
			'<div class="afc-admin-mode-segment" role="group" aria-label="Choose Basic or Advanced view">' +
			'<button type="button" data-afc-admin-mode="basic" aria-pressed="false">Basic</button>' +
			'<button type="button" data-afc-admin-mode="advanced" aria-pressed="false">Advanced</button>' +
			'</div>' +
			'<span class="afc-admin-mode-save-status" aria-live="polite"></span>';

		const container = page.querySelector( '.container-fluid' ) || page;
		container.insertBefore( switcher, container.firstChild );

		switcher.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-afc-admin-mode]' );
			if ( ! button ) {
				return;
			}
			const mode = button.getAttribute( 'data-afc-admin-mode' );
			if ( mode === currentMode ) {
				return;
			}
			applyMode( mode, switcher );
			saveMode( mode, switcher );
		} );

		return switcher;
	}

	$( function () {
		const switcher = createSwitcher();
		currentMode = window.afcAdminMode && afcAdminMode.mode ? afcAdminMode.mode :
			( document.body.classList.contains( modeClass( 'basic' ) ) ? 'basic' : 'advanced' );
		applyMode( currentMode, switcher );
	} );
}( jQuery ) );
