( function () {
	'use strict';

	const config = window.afcIntegrations || {};
	const syncConfig = window.afcGoogleSync || {};
	let status = null;
	let syncStatus = null;

	function app() {
		return document.getElementById( 'afc-frontend-app' );
	}

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function helpMarkup() {
		return '<dialog class="afc-google-help-dialog" data-afc-google-help-dialog>' +
			'<div class="afc-google-help-shell">' +
				'<header><div><small>GOOGLE SHEETS SETUP</small><h2>Connect Airfiber safely</h2><p>Google Sheets is only a reporting mirror. WordPress keeps payment history and MikroTik keeps live PPP status.</p></div><button type="button" data-afc-google-help-close aria-label="Close setup guide">×</button></header>' +
				'<div class="afc-google-help-steps">' +
					'<article><b>1</b><div><h3>Use a native private Google Sheet</h3><p>Open the file in Google Sheets, choose <strong>File → Save as Google Sheets</strong> when it came from Excel, and keep General access set to <strong>Restricted</strong>.</p></div></article>' +
					'<article><b>2</b><div><h3>Create the free Google project</h3><p>Open Google Cloud Console, create or rename a project to <strong>Airfiber Integrations</strong>, then enable only the <strong>Google Sheets API</strong>. Billing and a credit card are not required.</p></div></article>' +
					'<article><b>3</b><div><h3>Create the service account</h3><p>Go to IAM &amp; Admin → Service Accounts, create <strong>Airfiber Sheets Sync</strong>, leave project roles empty, then create a JSON key.</p></div></article>' +
					'<article><b>4</b><div><h3>Share the Sheet with Airfiber</h3><p>Copy the service-account email from the JSON or Google Cloud and share the native Google Sheet with that exact email as <strong>Editor</strong>.</p></div></article>' +
					'<article><b>5</b><div><h3>Save and test here</h3><p>Paste the Spreadsheet ID found between <code>/d/</code> and <code>/edit</code>, upload the JSON once, click <strong>Save securely</strong>, then <strong>Test connection</strong>.</p></div></article>' +
					'<article><b>6</b><div><h3>Prepare the reporting tabs</h3><p>Click <strong>Prepare Sheet</strong>. Airfiber creates or repairs the current-year and Transactions tabs, freezes headers, adds status dropdowns, and applies PAID, DUE and EXPIRED colors.</p></div></article>' +
					'<article><b>7</b><div><h3>Load PPP customers</h3><p>Click <strong>Sync All PPP</strong>. MikroTik usernames become the unique keys, while existing monthly PAID values are preserved for matching accounts.</p></div></article>' +
					'<article><b>8</b><div><h3>Payments sync automatically</h3><p>New payments are saved in WordPress first, copied to a protected JSONL backup, queued, and then written to the yearly month and Transactions tab. Google downtime never blocks a payment.</p></div></article>' +
				'</div>' +
				'<footer><button type="button" class="btn btn-primary" data-afc-google-help-close>Got it</button></footer>' +
			'</div>' +
		'</dialog>';
	}

	function markup() {
		return '<div class="afc-integrations-page">' +
			'<header class="afc-integrations-header"><div><span>ADVANCED SETTINGS</span><h1>Integrations</h1><p>Connect Airfiber to reporting and messaging services without exposing private credentials.</p></div></header>' +
			'<div class="afc-integrations-grid">' +
				'<article class="afc-integration-card afc-google-card">' +
					'<header><div class="afc-integration-logo">G</div><div><small>REPORTING MIRROR</small><h2>Google Sheets</h2></div><div class="afc-integration-head-actions"><button type="button" class="afc-integration-help" data-afc-google-help aria-label="Open Google Sheets setup guide">?</button><span class="afc-integration-status" data-afc-google-status>Not connected</span></div></header>' +
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
						'<section class="afc-google-automation">' +
							'<header><div><small>REPORTING AUTOMATION</small><h3>Sheet synchronization</h3><p>Prepare the tabs once, load MikroTik customers, then let payments sync through the safe retry queue.</p></div><label class="afc-google-auto-toggle"><input type="checkbox" data-afc-google-auto><span></span><b>Automatic sync</b></label></header>' +
							'<div class="afc-google-sync-message" data-afc-google-sync-message hidden></div>' +
							'<dl class="afc-google-sync-facts">' +
								'<div><dt>Prepared year</dt><dd data-afc-google-prepared>Not prepared</dd></div>' +
								'<div><dt>Last PPP sync</dt><dd data-afc-google-customer-sync>Never</dd></div>' +
								'<div><dt>Payment queue</dt><dd data-afc-google-queue>0 pending</dd></div>' +
							'</dl>' +
							'<div class="afc-google-sync-actions"><button type="button" class="btn btn-primary" data-afc-google-prepare>Prepare Sheet</button><button type="button" class="btn btn-success" data-afc-google-sync>Sync All PPP</button><button type="button" class="btn btn-outline-primary" data-afc-google-reconcile>Reconcile</button><button type="button" class="btn btn-outline-secondary" data-afc-google-retry>Retry Pending</button><button type="button" class="btn btn-outline-secondary" data-afc-google-backup>Download Backup</button></div>' +
							'<div class="afc-google-sync-note">MikroTik supplies PPP and service status. WordPress supplies the payment ledger. Google Sheets can be rebuilt from both.</div>' +
						'</section>' +
					'</div>' +
				'</article>' +
				'<article class="afc-integration-card afc-messenger-card">' +
					'<header><div class="afc-integration-logo">M</div><div><small>COMING NEXT</small><h2>Facebook Messenger</h2></div><span class="afc-integration-status is-muted">Not configured</span></header>' +
					'<div class="afc-integration-body"><p>Future Messenger tools will live here, including Page connection, webhook health, inbox status and customer message routing.</p><button type="button" class="btn btn-outline-secondary" disabled>Available in a future update</button></div>' +
				'</article>' +
			'</div>' +
			helpMarkup() +
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

	function setSyncMessage( message, type ) {
		const node = document.querySelector( '[data-afc-google-sync-message]' );
		if ( ! node ) return;
		node.hidden = ! message;
		node.className = 'afc-google-sync-message' + ( type ? ' is-' + type : '' );
		node.textContent = message || '';
	}

	function setBusy( busy, label ) {
		document.querySelectorAll( '[data-afc-google-save], [data-afc-google-test], [data-afc-google-remove], [data-afc-google-prepare], [data-afc-google-sync], [data-afc-google-reconcile], [data-afc-google-retry]' ).forEach( function ( button ) {
			button.disabled = busy;
		} );
		const automation = document.querySelector( '.afc-google-automation' );
		if ( automation ) automation.classList.toggle( 'is-busy', busy );
		if ( busy && label ) setSyncMessage( label, '' );
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
		renderSync( syncStatus || {} );
	}

	function renderSync( data ) {
		syncStatus = data || {};
		const prepared = document.querySelector( '[data-afc-google-prepared]' );
		const customerSync = document.querySelector( '[data-afc-google-customer-sync]' );
		const queue = document.querySelector( '[data-afc-google-queue]' );
		const auto = document.querySelector( '[data-afc-google-auto]' );
		const connected = Boolean( status && status.connected );
		if ( prepared ) prepared.textContent = syncStatus.preparedYear ? String( syncStatus.preparedYear ) : 'Not prepared';
		if ( customerSync ) customerSync.textContent = syncStatus.lastCustomerSync ? syncStatus.lastCustomerSync + ' · ' + Number( syncStatus.lastSyncCount || 0 ) + ' accounts' : 'Never';
		if ( queue ) queue.textContent = Number( syncStatus.pending || 0 ) + ' pending' + ( Number( syncStatus.failed || 0 ) ? ' · ' + Number( syncStatus.failed ) + ' failed' : '' );
		if ( auto ) auto.checked = syncStatus.autoSync !== false;
		document.querySelectorAll( '[data-afc-google-prepare], [data-afc-google-sync], [data-afc-google-reconcile], [data-afc-google-retry]' ).forEach( function ( button ) {
			button.disabled = ! connected;
		} );
		const backup = document.querySelector( '[data-afc-google-backup]' );
		if ( backup ) backup.disabled = ! syncStatus.backupAvailable;
	}

	function loadStatus() {
		return request( 'afc_integrations_status' ).then( function ( response ) {
			if ( response && response.success ) render( response.data );
		} );
	}

	function loadSyncStatus() {
		return request( 'afc_google_sync_status' ).then( function ( response ) {
			if ( response && response.success ) renderSync( response.data );
		} );
	}

	function runSyncAction( action, loadingMessage ) {
		setBusy( true, loadingMessage );
		return request( action ).then( function ( response ) {
			if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'The Google Sheet operation failed.' );
			renderSync( response.data );
			setSyncMessage( response.data.message || 'Completed.', 'success' );
			return response;
		} ).catch( function ( error ) {
			setSyncMessage( error.message, 'error' );
		} ).finally( function () {
			setBusy( false );
			loadSyncStatus();
		} );
	}

	function bind( panel ) {
		panel.addEventListener( 'click', function ( event ) {
			const help = event.target.closest( '[data-afc-google-help]' );
			const helpClose = event.target.closest( '[data-afc-google-help-close]' );
			const helpDialog = panel.querySelector( '[data-afc-google-help-dialog]' );
			if ( help && helpDialog ) {
				helpDialog.showModal();
				return;
			}
			if ( helpClose && helpDialog ) {
				helpDialog.close();
				return;
			}

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
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); loadSyncStatus(); } );
				return;
			}
			if ( test ) {
				setBusy( true );
				setMessage( 'Contacting Google Sheets…', '' );
				request( 'afc_integrations_test_google' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Connection test failed.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
					return loadSyncStatus();
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); loadStatus(); } ).finally( function () { setBusy( false ); } );
				return;
			}
			if ( remove ) {
				if ( ! window.confirm( 'Remove the encrypted Google service-account credential from Airfiber?' ) ) return;
				setBusy( true );
				request( 'afc_integrations_remove_google' ).then( function ( response ) {
					if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Could not remove the credential.' );
					render( response.data );
					setMessage( response.data.message, 'success' );
				} ).catch( function ( error ) { setMessage( error.message, 'error' ); } ).finally( function () { setBusy( false ); loadSyncStatus(); } );
				return;
			}
			if ( event.target.closest( '[data-afc-google-prepare]' ) ) return void runSyncAction( 'afc_google_prepare_sheet', 'Preparing and formatting the Google Sheet…' );
			if ( event.target.closest( '[data-afc-google-sync]' ) ) return void runSyncAction( 'afc_google_sync_customers', 'Reading MikroTik and synchronizing all PPP accounts…' );
			if ( event.target.closest( '[data-afc-google-reconcile]' ) ) return void runSyncAction( 'afc_google_reconcile', 'Comparing MikroTik, WordPress payments and Google Sheets…' );
			if ( event.target.closest( '[data-afc-google-retry]' ) ) return void runSyncAction( 'afc_google_retry_queue', 'Retrying pending payment updates…' );
			if ( event.target.closest( '[data-afc-google-backup]' ) && syncConfig.downloadUrl ) {
				window.location.href = syncConfig.downloadUrl;
			}
		} );

		panel.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( '[data-afc-google-auto]' ) ) return;
			const body = new FormData();
			body.set( 'enabled', event.target.checked ? '1' : '' );
			request( 'afc_google_set_auto_sync', body ).then( function ( response ) {
				if ( ! response.success ) throw new Error( response.data && response.data.message ? response.data.message : 'Could not update automatic sync.' );
				renderSync( response.data );
				setSyncMessage( response.data.message, 'success' );
			} ).catch( function ( error ) {
				event.target.checked = ! event.target.checked;
				setSyncMessage( error.message, 'error' );
			} );
		} );

		const helpDialog = panel.querySelector( '[data-afc-google-help-dialog]' );
		if ( helpDialog ) {
			helpDialog.addEventListener( 'click', function ( event ) {
				if ( event.target === helpDialog ) helpDialog.close();
			} );
		}
	}

	function boot() {
		if ( ! inject() ) {
			window.setTimeout( function () { if ( inject() ) Promise.all( [ loadStatus(), loadSyncStatus() ] ); }, 250 );
			return;
		}
		Promise.all( [ loadStatus(), loadSyncStatus() ] );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
