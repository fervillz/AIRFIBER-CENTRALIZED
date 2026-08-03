( function () {
	'use strict';

	const config = window.afcIntegrations || {};
	let status = null;

	function app() {
		return document.getElementById( 'afc-frontend-app' );
	}

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function markup() {
		return '<div class="afc-integrations-page">' +
			'<header class="afc-integrations-header"><div><span>ADVANCED SETTINGS</span><h1>Integrations</h1><p>Connect Airfiber to reporting and messaging services without exposing private credentials.</p></div></header>' +
			'<div class="afc-integrations-grid">' +
				'<article class="afc-integration-card afc-google-card">' +
					'<header><div class="afc-integration-logo">G</div><div><small>REPORTING MIRROR</small><h2>Google Sheets</h2></div><span class="afc-integration-status" data-afc-google-status>Not connected</span></header>' +
					'<div class="afc-integration-body">' +
						'<div class="afc-integration-message" data-afc-integration-message hidden></div>' +
						'<label for="afc-google-spreadsheet-id">Spreadsheet ID</label>' +
						'<input id="afc-google-spreadsheet-id" type="text" autocomplete="off" value="' + escapeHtml( config.spreadsheetId || '' ) + '">' +
						'<small class="afc-field-help">Use the value between <code>/d/</code> and <code>/edit</code> in the private Sheet URL.</small>' +
						'<label for="afc-google-credential">Service-account credential</label>' +
						'<div class="afc-file-row"><input id="afc-google-credential" type="file" accept="application/json,.json"><span data-afc-google-file-state>No file stored</span></div>' +
						'<small class="afc-field-help">The JSON is encrypted before storage. Its private key is never shown again and is never committed to GitHub.</small>' +
						'<dl class="afc-integration-facts">' +
							'<div><dt>Service account</dt><dd data-afc-google-email>—</dd></div>' +
							'<div><dt>Spreadsheet</dt><dd data-afc-google-title>—</dd></div>' +
							'<div><dt>Last successful test</dt><dd data-afc-google-last>Never</dd></div>' +
						'</dl>' +
						'<div class="afc-integration-actions"><button type="button" class="btn btn-primary" data-afc-google-save>Save securely</button><button type="button" class="btn btn-success" data-afc-google-test>Test connection</button><button type="button" class="btn btn-outline-danger" data-afc-google-remove>Remove credential</button></div>' +
					'</div>' +
				'</article>' +
				'<article class="afc-integration-card afc-messenger-card">' +
					'<header><div class="afc-integration-logo">M</div><div><small>COMING NEXT</small><h2>Facebook Messenger</h2></div><span class="afc-integration-status is-muted">Not configured</span></header>' +
					'<div class="afc-integration-body"><p>Future Messenger tools will live here, including Page connection, webhook health, inbox status and customer message routing.</p><button type="button" class="btn btn-outline-secondary" disabled>Available in a future update</button></div>' +
				'</article>' +
			'</div>' +
		'</div>';
	}

	function inject() {
		const root = app();
		if ( ! root || root.querySelector( '[data-afc-panel="integrations"]' ) ) return false;
		const nav = root.querySelector( '.afc-frontend-nav' );
		const content = root.querySelector( '.afc-frontend-content' );
		if ( ! nav || ! content ) return false;

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-advanced-only';
		button.setAttribute( 'data-afc-app-panel', 'integrations' );
		button.setAttribute( 'aria-pressed', 'false' );
		button.textContent = 'Integrations';
		nav.appendChild( button );

		const panel = document.createElement( 'section' );
		panel.className = 'afc-frontend-panel afc-advanced-only';
		panel.setAttribute( 'data-afc-panel', 'integrations' );
		panel.setAttribute( 'aria-hidden', 'true' );
		panel.hidden = true;
		panel.innerHTML = markup();
		content.appendChild( panel );
		bind( panel );
		return true;
	}

	function request( action, formData ) {
		const body = formData || new FormData();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce || '' );
		return window.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) { return response.json(); } );
	}

	function setMessage( message, type ) {
		const node = document.querySelector( '[data-afc-integration-message]' );
		if ( ! node ) return;
		node.hidden = ! message;
		node.className = 'afc-integration-message' + ( type ? ' is-' + type : '' );
		node.textContent = message || '';
	}

	function setBusy( busy ) {
		document.querySelectorAll( '[data-afc-google-save], [data-afc-google-test], [data-afc-google-remove]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
	}

	function render( data ) {
		status = data || {};
		const state = document.querySelector( '[data-afc-google-status]' );
		const file = document.querySelector( '[data-afc-google-file-state]' );
		const email = document.querySelector( '[data-afc-google-email]' );
		const title = document.querySelector( '[data-afc-google-title]' );
		const last = document.querySelector( '[data-afc-google-last]' );
		const input = document.getElementById( 'afc-google-spreadsheet-id' );
		if ( state ) {
			state.textContent = status.connected ? 'Connected' : ( status.hasCredential ? 'Saved · test needed' : 'Not connected' );
			state.className = 'afc-integration-status' + ( status.connected ? ' is-connected' : '' );
		}
		if ( file ) file.textContent = status.hasCredential ? 'Encrypted credential stored' : 'No file stored';
		if ( email ) email.textContent = status.serviceEmail || '—';
		if ( title ) title.textContent = status.sheetTitle || 'Not tested';
		if ( last ) last.textContent = status.lastSuccess || 'Never';
		if ( input && status.spreadsheetId ) input.value = status.spreadsheetId;
		const remove = document.querySelector( '[data-afc-google-remove]' );
		if ( remove ) remove.hidden = ! status.hasCredential;
	}

	function loadStatus() {
		return request( 'afc_integrations_status' ).then( function ( response ) {
			if ( response && response.success ) render( response.data );
		} );
	}

	function bind( panel ) {
		panel.addEventListener( 'click', function ( event ) {
			const save = event.target.closest( '[data-afc-google-save]' );
			const test = event.target.closest( '[data-afc-google-test]' );
			const remove = event.target.closest( '[data-afc-google-remove]' );
			if ( save ) {
				const body = new FormData();
				const id = document.getElementById( 'afc-google-spreadsheet-id' );
				const file = document.getElementById( 'afc-google-credential' );
				body.set( 'spreadsheet_id', id ? id.value.trim() : '' );
				if ( file && file.files && file.files[ 0 ] ) body.set( 'credential', file.files[ 0 ] );
				setBusy( true );
				setMessage( 'Saving the encrypted credential…', '' );
				request( 'afc_integrations_save_google', body ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Could not save Google settings.' );
					render( response.data );
					if ( file ) file.value = '';
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); } );
			}
			if ( test ) {
				setBusy( true );
				setMessage( 'Contacting Google Sheets…', '' );
				request( 'afc_integrations_test_google' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Connection test failed.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); loadStatus(); } ).finally( function () { setBusy( false ); } );
			}
			if ( remove ) {
				if ( ! window.confirm( 'Remove the encrypted Google service-account credential from Airfiber?' ) ) return;
				setBusy( true );
				request( 'afc_integrations_remove_google' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Could not remove the credential.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); } );
			}
		} );
	}

	function boot() {
		if ( ! inject() ) {
			window.setTimeout( function () { if ( inject() ) loadStatus(); }, 250 );
			return;
		}
		loadStatus();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
