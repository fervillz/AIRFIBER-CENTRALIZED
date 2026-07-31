( function () {
	'use strict';

	if ( ! window.afcPaymentSettings ) {
		return;
	}

	let root = null;
	let saving = false;

	function monthLabel( months ) {
		months = Number( months || 0 );
		if ( 12 === months ) { return '1 Year'; }
		if ( 24 === months ) { return '2 Years'; }
		if ( 60 === months ) { return '5 Years'; }
		return months + ' Month' + ( 1 === months ? '' : 's' );
	}

	function parsePresets( value, maxMonths ) {
		const seen = new Set();
		return String( value || '' ).split( /[\s,;]+/ ).map( Number ).filter( function ( months ) {
			if ( ! Number.isInteger( months ) || months < 1 || months > maxMonths || seen.has( months ) ) {
				return false;
			}
			seen.add( months );
			return true;
		} ).sort( function ( a, b ) { return a - b; } ).slice( 0, 10 );
	}

	function showNotice( message, type ) {
		const notice = root && root.querySelector( '[data-afc-payment-settings-notice]' );
		if ( ! notice ) {
			return;
		}
		notice.className = 'afc-payment-settings-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function renderPreview() {
		const form = root && root.querySelector( '[data-afc-payment-settings-form]' );
		const preview = root && root.querySelector( '[data-afc-payment-preset-preview]' );
		if ( ! form || ! preview ) {
			return;
		}
		const data = new FormData( form );
		const max = Math.min( 240, Math.max( 1, Number( data.get( 'max_months' ) || 120 ) ) );
		const presets = parsePresets( data.get( 'presets' ), max );
		preview.innerHTML = presets.length
			? presets.map( function ( months ) { return '<span data-months="' + months + '">' + monthLabel( months ) + '</span>'; } ).join( '' )
			: '<small>Add at least one valid month preset.</small>';
	}

	function addNavigation() {
		const nav = document.querySelector( '.afc-frontend-nav' );
		if ( ! nav ) {
			return false;
		}
		let button = nav.querySelector( '[data-afc-app-panel="payment-settings"]' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-advanced-only';
			button.setAttribute( 'data-afc-app-panel', 'payment-settings' );
			button.setAttribute( 'aria-pressed', 'false' );
			button.textContent = afcPaymentSettings.labels && afcPaymentSettings.labels.nav ? afcPaymentSettings.labels.nav : 'Payment Settings';
		}
		const comments = nav.querySelector( '[data-afc-app-panel="comment-fields"]' );
		const schedulers = nav.querySelector( '[data-afc-app-panel="schedulers"]' );
		const mikrotik = nav.querySelector( '[data-afc-app-panel="mikrotik"]' );
		const target = comments || schedulers || mikrotik || null;
		if ( target ) {
			if ( button.nextElementSibling !== target ) {
				nav.insertBefore( button, target );
			}
		} else if ( nav.lastElementChild !== button ) {
			nav.appendChild( button );
		}
		return true;
	}

	async function save( form ) {
		if ( saving ) {
			return;
		}
		const data = new FormData( form );
		const max = Math.min( 240, Math.max( 1, Number( data.get( 'max_months' ) || 120 ) ) );
		const presets = parsePresets( data.get( 'presets' ), max );
		if ( ! presets.length ) {
			showNotice( 'Add at least one valid advance-payment preset.', 'warning' );
			return;
		}
		const button = form.querySelector( 'button[type="submit"]' );
		saving = true;
		showNotice( 'Saving payment settings…', 'info' );
		if ( button ) {
			button.disabled = true;
		}

		const body = new URLSearchParams();
		body.set( 'action', 'afc_save_advance_payment_settings' );
		body.set( 'nonce', afcPaymentSettings.nonce );
		body.set( 'presets', presets.join( ',' ) );
		body.set( 'max_months', String( max ) );
		body.set( 'warning_months', String( Math.min( max, Math.max( 1, Number( data.get( 'warning_months' ) || 12 ) ) ) ) );
		body.set( 'auto_amount', data.get( 'auto_amount' ) ? '1' : '0' );

		try {
			const response = await fetch( afcPaymentSettings.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} );
			const result = await response.json();
			if ( ! result.success ) {
				throw new Error( result.data && result.data.message ? result.data.message : 'Payment settings could not be saved.' );
			}
			afcPaymentSettings.settings = result.data.settings;
			form.querySelector( '[name="presets"]' ).value = result.data.settings.presets.join( ', ' );
			form.querySelector( '[name="max_months"]' ).value = result.data.settings.max_months;
			form.querySelector( '[name="warning_months"]' ).value = result.data.settings.warning_months;
			form.querySelector( '[name="auto_amount"]' ).checked = Boolean( Number( result.data.settings.auto_amount ) );
			renderPreview();
			showNotice( result.data.message || 'Payment settings saved.', 'success' );
			document.dispatchEvent( new CustomEvent( 'afc:payment-settings-updated', { detail: result.data.settings } ) );
		} catch ( error ) {
			showNotice( error.message || 'Payment settings could not be saved.', 'error' );
		} finally {
			saving = false;
			if ( button ) {
				button.disabled = false;
			}
		}
	}

	function initialize() {
		root = document.getElementById( 'afc-payment-settings' );
		if ( ! root ) {
			return;
		}
		addNavigation();
		const form = root.querySelector( '[data-afc-payment-settings-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				save( form );
			} );
			form.addEventListener( 'input', renderPreview );
		}
		renderPreview();

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			const panel = root.closest( '[data-afc-panel="payment-settings"]' );
			if ( event.detail && 'basic' === event.detail.mode && panel && ! panel.hidden ) {
				const operations = document.querySelector( '[data-afc-app-panel="operations"]' );
				if ( operations ) {
					operations.click();
				}
			}
		} );

		const nav = document.querySelector( '.afc-frontend-nav' );
		if ( nav ) {
			const observer = new MutationObserver( addNavigation );
			observer.observe( nav, { childList: true } );
			window.setTimeout( function () { observer.disconnect(); }, 10000 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );
