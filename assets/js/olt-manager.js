( function ( $ ) {
	'use strict';

	const cfg = window.afcOLTManager || {};
	let root = null;
	let modal = null;
	let dialog = null;
	let form = null;
	let autosaveTimer = null;
	let saving = false;
	let dirty = false;
	let currentId = 0;
	let currentStatus = 'draft';
	let activeTestRequest = null;
	let testElapsedTimer = null;
	let testStartedAt = 0;

	function q( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function qa( selector, scope ) {
		return Array.from( ( scope || document ).querySelectorAll( selector ) );
	}

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, Object.assign( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function setInfo( message, tone ) {
		const bar = q( '[data-afc-olt-save-info]', modal );
		const copy = q( '[data-afc-olt-save-message]', modal );
		if ( ! bar || ! copy ) return;
		bar.classList.remove( 'is-neutral', 'is-saving', 'is-success', 'is-error', 'is-warning' );
		bar.classList.add( 'is-' + ( tone || 'neutral' ) );
		copy.textContent = message || '';
	}

	function setTestAttention( active, focus ) {
		const button = q( '[data-afc-olt-test]', modal );
		if ( ! button ) return;
		button.classList.toggle( 'is-test-attention', Boolean( active ) );
		if ( active ) {
			button.style.setProperty( 'background', '#2f9e56', 'important' );
			button.style.setProperty( 'border-color', '#2f9e56', 'important' );
			button.style.setProperty( 'color', '#fff', 'important' );
			button.style.setProperty( 'box-shadow', '0 0 0 4px rgba(47,158,86,.16), 0 8px 20px rgba(47,158,86,.20)', 'important' );
			button.setAttribute( 'aria-label', 'Test Connection — next step' );
			if ( focus ) {
				window.setTimeout( function () {
					try {
						button.focus( { preventScroll: true } );
					} catch ( error ) {
						button.focus();
					}
				}, 80 );
			}
		} else {
			button.style.removeProperty( 'background' );
			button.style.removeProperty( 'border-color' );
			button.style.removeProperty( 'color' );
			button.style.removeProperty( 'box-shadow' );
			button.removeAttribute( 'aria-label' );
		}
	}

	function ensureTestLog() {
		let details = q( '[data-afc-olt-test-log]', modal );
		if ( details ) return details;
		const info = q( '[data-afc-olt-save-info]', modal );
		if ( ! info ) return null;

		details = document.createElement( 'details' );
		details.setAttribute( 'data-afc-olt-test-log', '' );
		details.style.margin = '10px 20px 0';
		details.style.border = '1px solid #dce6ef';
		details.style.borderRadius = '11px';
		details.style.background = '#f8fbfe';
		details.style.color = '#415269';
		details.hidden = true;

		const summary = document.createElement( 'summary' );
		summary.textContent = 'Connection test details';
		summary.style.padding = '9px 11px';
		summary.style.cursor = 'pointer';
		summary.style.fontSize = '11px';
		summary.style.fontWeight = '600';
		details.appendChild( summary );

		const body = document.createElement( 'div' );
		body.setAttribute( 'data-afc-olt-test-log-body', '' );
		body.style.maxHeight = '190px';
		body.style.overflowY = 'auto';
		body.style.padding = '0 11px 10px';
		body.style.fontFamily = 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace';
		body.style.fontSize = '10.5px';
		body.style.lineHeight = '1.55';
		details.appendChild( body );

		info.insertAdjacentElement( 'afterend', details );
		return details;
	}

	function clearTestLog() {
		const details = ensureTestLog();
		if ( ! details ) return;
		const body = q( '[data-afc-olt-test-log-body]', details );
		if ( body ) body.innerHTML = '';
		details.hidden = false;
		details.open = true;
	}

	function testLog( message, tone ) {
		const details = ensureTestLog();
		if ( ! details ) return;
		details.hidden = false;
		const body = q( '[data-afc-olt-test-log-body]', details );
		if ( ! body ) return;
		const row = document.createElement( 'div' );
		const elapsed = testStartedAt ? ( ( Date.now() - testStartedAt ) / 1000 ).toFixed( 1 ) + 's' : '0.0s';
		row.textContent = '[' + elapsed + '] ' + message;
		if ( tone === 'error' ) row.style.color = '#a84d57';
		else if ( tone === 'success' ) row.style.color = '#317149';
		else if ( tone === 'warning' ) row.style.color = '#88672d';
		body.appendChild( row );
		body.scrollTop = body.scrollHeight;
	}

	function stopTestClock() {
		if ( testElapsedTimer ) window.clearInterval( testElapsedTimer );
		testElapsedTimer = null;
	}

	function setTestRunning( running ) {
		const button = q( '[data-afc-olt-test]', modal );
		if ( ! button ) return;
		button.disabled = Boolean( running );
		button.textContent = running ? 'Testing…' : 'Test Connection';
		button.style.opacity = running ? '.78' : '';
		button.style.cursor = running ? 'wait' : '';
	}

	function countCards() {
		const count = qa( '.afc-olt-card[data-afc-olt-card]', root ).length;
		const node = q( '[data-afc-olt-count]', root );
		if ( node ) node.textContent = count ? count + ( count === 1 ? ' OLT' : ' OLTs' ) : 'No OLTs yet';
	}

	function helpOpenPreference() {
		try {
			return window.localStorage.getItem( 'afcOLTManagerHelpOpen' ) === '1';
		} catch ( error ) {
			return false;
		}
	}

	function setHelpOpen( open, persist ) {
		if ( ! dialog ) return;
		dialog.classList.toggle( 'is-help-open', Boolean( open ) );
		const aside = q( '[data-afc-olt-help]', dialog );
		const toggle = q( '[data-afc-olt-help-toggle]', dialog );
		if ( aside ) aside.setAttribute( 'aria-hidden', open ? 'false' : 'true' );
		if ( toggle ) toggle.setAttribute( 'aria-pressed', open ? 'true' : 'false' );
		if ( persist ) {
			try {
				window.localStorage.setItem( 'afcOLTManagerHelpOpen', open ? '1' : '0' );
			} catch ( error ) {
				// Local storage is optional; the dialog still works without it.
			}
		}
	}

	function updateVersionFields() {
		if ( ! form ) return;
		const version = q( '[name="version"]', form );
		const isV2 = version && version.value === '2c';
		const v2 = q( '[data-afc-olt-v2]', form );
		const v3 = q( '[data-afc-olt-v3]', form );
		if ( v2 ) v2.hidden = ! isV2;
		if ( v3 ) v3.hidden = isV2;
	}

	function updateTechnologyDefaults() {
		if ( ! form ) return;
		const technology = q( '[name="technology"]', form );
		const oid = q( '[name="rx_oid"]', form );
		if ( ! technology || ! oid ) return;

		const known = [ '', cfg.gponRxOid, cfg.eponRxOid ].indexOf( oid.value ) >= 0;
		if ( known ) oid.value = technology.value === 'EPON' ? cfg.eponRxOid : cfg.gponRxOid;
	}

	function resetForm() {
		window.clearTimeout( autosaveTimer );
		autosaveTimer = null;
		stopTestClock();
		if ( activeTestRequest && activeTestRequest.readyState !== 4 ) activeTestRequest.abort();
		activeTestRequest = null;
		setTestRunning( false );
		currentId = 0;
		currentStatus = 'draft';
		dirty = false;
		if ( ! form ) return;
		form.reset();
		q( '[name="id"]', form ).value = '0';
		q( '[name="post_status"]', form ).value = 'draft';
		q( '[name="port"]', form ).value = '161';
		q( '[name="version"]', form ).value = '3';
		q( '[name="technology"]', form ).value = 'GPON';
		q( '[name="security_name"]', form ).value = 'airfiber-monitor';
		q( '[name="rx_oid"]', form ).value = cfg.defaultRxOid || '';
		q( '[name="warning_dbm"]', form ).value = '-24';
		q( '[name="critical_dbm"]', form ).value = '-27';
		q( '[name="cache_ttl"]', form ).value = '300';
		q( '[name="timeout_ms"]', form ).value = '2500';
		q( '[name="retries"]', form ).value = '1';
		updateVersionFields();
		setTestAttention( false, false );
		const details = ensureTestLog();
		if ( details ) details.hidden = true;
		const kicker = q( '[data-afc-olt-dialog-kicker]', modal );
		if ( kicker ) kicker.textContent = 'New OLT';
		setInfo( 'Start entering the OLT details. The first draft will save automatically after 5 seconds.', 'neutral' );
	}

	function formConfig() {
		const names = [
			'host', 'port', 'technology', 'version', 'community', 'security_name', 'auth_passphrase',
			'privacy_passphrase', 'rx_oid', 'warning_dbm', 'critical_dbm', 'cache_ttl',
			'timeout_ms', 'retries'
		];
		const out = {};
		names.forEach( function ( name ) {
			const field = q( '[name="' + name + '"]', form );
			out[ name ] = field ? field.value : '';
		} );
		return out;
	}

	function fillForm( node ) {
		resetForm();
		currentId = Number( node.id || 0 );
		currentStatus = node.post_status || 'draft';
		q( '[name="id"]', form ).value = String( currentId );
		q( '[name="post_status"]', form ).value = currentStatus;
		q( '[name="title"]', form ).value = node.title || '';
		Object.keys( node.config || {} ).forEach( function ( key ) {
			const field = q( '[name="' + key + '"]', form );
			if ( field && ! [ 'has_community', 'has_auth', 'has_privacy' ].includes( key ) ) field.value = node.config[ key ] == null ? '' : node.config[ key ];
		} );
		updateVersionFields();
		const kicker = q( '[data-afc-olt-dialog-kicker]', modal );
		if ( kicker ) kicker.textContent = currentStatus === 'publish' ? 'Published OLT' : 'Draft OLT';
		if ( node.device && node.device.test_status === 'success' ) {
			setTestAttention( false, false );
			setInfo( 'Last test passed' + ( node.device.name ? ' · OLT reports its name as “' + node.device.name + '”.' : '.' ), 'success' );
		} else if ( node.device && node.device.test_status === 'error' ) {
			setTestAttention( currentStatus === 'publish', false );
			setInfo( node.device.message || 'The last connection test failed.', 'error' );
		} else {
			setTestAttention( currentStatus === 'publish', false );
			setInfo( currentStatus === 'publish' ? 'Published. Test the connection when ready.' : 'Draft loaded. Changes autosave after 5 seconds.', 'neutral' );
		}
		dirty = false;
	}

	function openModal() {
		if ( ! modal ) return;
		modal.hidden = false;
		document.body.classList.add( 'afc-olt-modal-open' );
		setHelpOpen( helpOpenPreference(), false );
		window.requestAnimationFrame( function () { modal.classList.add( 'is-open' ); } );
		window.setTimeout( function () {
			const title = q( '[name="title"]', form );
			if ( title ) title.focus();
		}, 180 );
	}

	function closeModal() {
		if ( ! modal ) return;
		window.clearTimeout( autosaveTimer );
		autosaveTimer = null;
		stopTestClock();
		if ( activeTestRequest && activeTestRequest.readyState !== 4 ) activeTestRequest.abort();
		activeTestRequest = null;
		setTestRunning( false );
		modal.classList.remove( 'is-open' );
		document.body.classList.remove( 'afc-olt-modal-open' );
		window.setTimeout( function () { modal.hidden = true; }, 180 );
	}

	function refreshList() {
		return post( 'afc_olt_manager_list' ).done( function ( response ) {
			if ( response && response.success && response.data && response.data.html ) {
				const list = q( '[data-afc-olt-list]', root );
				if ( list ) list.innerHTML = response.data.html;
				countCards();
				document.dispatchEvent( new CustomEvent( 'afc:olt-connections-updated' ) );
			}
		} );
	}

	function save( mode, options ) {
		options = options || {};
		if ( saving || ! form ) return $.Deferred().reject().promise();
		saving = true;
		window.clearTimeout( autosaveTimer );
		autosaveTimer = null;
		setInfo( mode === 'autosave' ? 'Autosaving draft…' : 'Saving…', 'saving' );
		const payload = {
			id: currentId,
			mode: mode,
			title: q( '[name="title"]', form ).value,
			config: JSON.stringify( formConfig() )
		};
		return post( 'afc_olt_manager_save', payload ).done( function ( response ) {
			if ( ! response || ! response.success ) return;
			currentId = Number( response.data.id || currentId );
			currentStatus = response.data.node && response.data.node.post_status ? response.data.node.post_status : currentStatus;
			q( '[name="id"]', form ).value = String( currentId );
			q( '[name="post_status"]', form ).value = currentStatus;
			dirty = false;
			if ( response.data.node ) fillForm( response.data.node );
			if ( mode === 'publish' ) {
				setInfo( 'OLT published. Next step: click Test Connection to verify the OLT and read its RX data.', 'warning' );
				setTestAttention( true, true );
			} else {
				setInfo(
					mode === 'autosave' ? 'Draft autosaved at ' + ( response.data.saved_at || 'just now' ) + '. You can keep editing.' : ( response.data.message || 'Saved.' ),
					'success'
				);
			}
			refreshList();
			if ( options.close ) window.setTimeout( closeModal, 260 );
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Could not save the OLT.';
			setInfo( message, 'error' );
		} ).always( function () {
			saving = false;
		} );
	}

	function scheduleAutosave() {
		if ( ! form ) return;
		dirty = true;
		window.clearTimeout( autosaveTimer );
		setInfo( currentId ? 'Changes pending · autosaving in 5 seconds…' : 'A draft will be created automatically in 5 seconds…', 'warning' );
		autosaveTimer = window.setTimeout( function () {
			if ( dirty ) save( currentStatus === 'publish' ? 'keep' : 'autosave' );
		}, Number( cfg.autosaveMs || 5000 ) );
	}

	function testConnection() {
		if ( activeTestRequest && activeTestRequest.readyState !== 4 ) return;
		window.clearTimeout( autosaveTimer );
		setTestAttention( false, false );
		setTestRunning( true );
		testStartedAt = Date.now();
		clearTestLog();
		testLog( 'Saving the current OLT settings before the test.' );
		const mode = currentStatus === 'publish' ? 'keep' : 'autosave';

		save( mode ).done( function () {
			if ( ! currentId ) {
				setTestRunning( false );
				testLog( 'The OLT record was not saved, so the connection test could not start.', 'error' );
				return;
			}

			const config = formConfig();
			const version = config.version === '2c' ? 'SNMPv2c' : 'SNMPv3';
			testLog( 'Settings saved. Starting ' + version + ' test to ' + ( config.host || '(no host)' ) + ':' + ( config.port || '161' ) + '.' );
			testLog( 'Server will read the OLT identity, description, then RX OID ' + ( config.rx_oid || cfg.defaultRxOid || '' ) + '.' );
			setInfo( 'Testing SNMP connection… 0s', 'saving' );

			let lastMilestone = 0;
			testElapsedTimer = window.setInterval( function () {
				const seconds = Math.max( 0, Math.floor( ( Date.now() - testStartedAt ) / 1000 ) );
				setInfo( 'Testing SNMP connection… ' + seconds + 's', 'saving' );
				if ( seconds >= 5 && lastMilestone < 5 ) {
					lastMilestone = 5;
					testLog( 'Still waiting for the OLT. This can happen when UDP port 161 is filtered or the OLT does not accept this SNMP login.', 'warning' );
				}
				if ( seconds >= 15 && lastMilestone < 15 ) {
					lastMilestone = 15;
					testLog( 'No response yet. Check routing to the OLT, Remote Server/manager restrictions, SNMPv3 username/passwords, and UDP 161.', 'warning' );
				}
			}, 1000 );

			activeTestRequest = $.ajax( {
				url: cfg.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				timeout: Number( cfg.testClientTimeoutMs || 30000 ),
				data: {
					action: 'afc_olt_manager_test',
					nonce: cfg.nonce,
					id: currentId
				}
			} ).done( function ( response ) {
				if ( ! response ) {
					testLog( 'The server returned an empty connection-test response.', 'error' );
					setTestAttention( true, true );
					setInfo( 'Connection test failed: empty server response.', 'error' );
					return;
				}
				if ( ! response.success ) {
					const data = response.data || {};
					const message = data.message || 'Connection test failed.';
					testLog( message, 'error' );
					if ( data.error_code ) testLog( 'Server error code: ' + data.error_code + '.', 'error' );
					if ( data.state ) testLog( 'OLT state after test: ' + data.state + '.', 'warning' );
					setTestAttention( true, true );
					setInfo( message + ' Open Connection test details below.', 'error' );
					refreshList();
					return;
				}
				const device = response.data.device || {};
				let message = response.data.message || 'Connection successful.';
				if ( device.name ) message += ' OLT name: ' + device.name + '.';
				testLog( message, 'success' );
				if ( Number.isFinite( Number( device.onu_count ) ) ) testLog( 'ONU rows returned: ' + Number( device.onu_count ) + '; readable RX values: ' + Number( device.valid_count || 0 ) + '.', 'success' );
				setTestAttention( false, false );
				setInfo( message, 'success' );
				refreshList();
			} ).fail( function ( xhr, textStatus, errorThrown ) {
				let message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : '';
				if ( textStatus === 'timeout' ) {
					message = 'Connection test timed out after ' + Math.round( Number( cfg.testClientTimeoutMs || 30000 ) / 1000 ) + ' seconds.';
					testLog( message, 'error' );
					testLog( 'Most likely: the OLT is unreachable from this server, UDP/161 is blocked, or the OLT Remote Server/manager rule does not allow this server.', 'error' );
				} else if ( textStatus === 'abort' ) {
					message = 'Connection test was cancelled.';
					testLog( message, 'warning' );
				} else {
					message = message || 'Connection test failed.';
					testLog( message, 'error' );
					testLog( 'HTTP ' + ( xhr.status || 0 ) + ( errorThrown ? ' · ' + errorThrown : '' ) + '.', 'error' );
				}
				setTestAttention( true, true );
				setInfo( message + ' Open Connection test details below.', 'error' );
				refreshList();
			} ).always( function () {
				stopTestClock();
				activeTestRequest = null;
				setTestRunning( false );
			} );
		} ).fail( function () {
			setTestRunning( false );
			testLog( 'Could not save the OLT settings before testing.', 'error' );
		} );
	}

	function openEdit( id ) {
		resetForm();
		openModal();
		setInfo( 'Loading OLT configuration…', 'saving' );
		post( 'afc_olt_manager_get', { id: id } ).done( function ( response ) {
			if ( response && response.success && response.data && response.data.node ) fillForm( response.data.node );
		} ).fail( function () {
			setInfo( 'Could not load this OLT.', 'error' );
		} );
	}

	function stateAction( id, state ) {
		if ( state === 'online' ) {
			post( 'afc_olt_manager_state', { id: id, state_action: 'disconnect' } ).always( refreshList );
			return;
		}
		if ( state === 'offline' ) {
			const card = q( '[data-afc-olt-card="' + id + '"]', root );
			if ( card ) card.classList.add( 'is-working' );
			post( 'afc_olt_manager_state', { id: id, state_action: 'reconnect' } ).always( refreshList );
			return;
		}
		openEdit( id );
	}

	function bindEvents() {
		root.addEventListener( 'click', function ( event ) {
			const add = event.target.closest( '[data-afc-olt-add]' );
			if ( add ) {
				resetForm();
				openModal();
				return;
			}

			const action = event.target.closest( '[data-afc-olt-action]' );
			if ( action ) {
				event.stopPropagation();
				stateAction( Number( action.getAttribute( 'data-afc-olt-id' ) || 0 ), action.getAttribute( 'data-afc-olt-action' ) || '' );
				return;
			}

			const card = event.target.closest( '[data-afc-olt-card]' );
			if ( card ) openEdit( Number( card.getAttribute( 'data-afc-olt-card' ) || 0 ) );
		} );

		root.addEventListener( 'keydown', function ( event ) {
			const card = event.target.closest( '[data-afc-olt-card]' );
			if ( card && ( event.key === 'Enter' || event.key === ' ' ) ) {
				event.preventDefault();
				openEdit( Number( card.getAttribute( 'data-afc-olt-card' ) || 0 ) );
			}
		} );

		qa( '[data-afc-olt-close]', modal ).forEach( function ( button ) { button.addEventListener( 'click', closeModal ); } );
		q( '[data-afc-olt-help-toggle]', modal ).addEventListener( 'click', function () { setHelpOpen( ! dialog.classList.contains( 'is-help-open' ), true ); } );
		q( '[data-afc-olt-help-close]', modal ).addEventListener( 'click', function () { setHelpOpen( false, true ); } );
		q( '[data-afc-olt-save-draft]', modal ).addEventListener( 'click', function () { save( 'draft' ); } );
		q( '[data-afc-olt-publish]', modal ).addEventListener( 'click', function () { save( 'publish' ); } );
		q( '[data-afc-olt-test]', modal ).addEventListener( 'click', testConnection );

		form.addEventListener( 'input', scheduleAutosave );
		form.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.name === 'version' ) updateVersionFields();
			if ( event.target && event.target.name === 'technology' ) updateTechnologyDefaults();
			scheduleAutosave();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && modal && ! modal.hidden ) closeModal();
		} );
	}

	function boot() {
		root = document.getElementById( 'afc-olt-manager' );
		if ( ! root || ! cfg.ajaxUrl || ! cfg.nonce ) return;
		modal = q( '[data-afc-olt-modal]', root );
		dialog = q( '.afc-olt-dialog', modal );
		form = q( '[data-afc-olt-form]', modal );
		if ( ! modal || ! dialog || ! form ) return;
		bindEvents();
		countCards();
		updateVersionFields();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
