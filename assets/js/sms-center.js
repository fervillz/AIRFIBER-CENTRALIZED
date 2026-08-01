( function () {
	'use strict';

	const config = window.afcSmsCenter || {};
	let candidates = [];
	let selected = null;
	let stateTimer = null;
	let stateLoading = false;

	function byId( id ) {
		return document.getElementById( id );
	}

	function panel() {
		return document.querySelector( '[data-afc-panel="sms"]' );
	}

	function injectNavigation() {
		const root = document.getElementById( 'afc-frontend-app' );
		if ( ! root || root.querySelector( '[data-afc-app-panel="sms"]' ) ) {
			return;
		}
		const nav = root.querySelector( '.afc-frontend-nav' );
		if ( ! nav ) {
			return;
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-advanced-only';
		button.setAttribute( 'data-afc-app-panel', 'sms' );
		button.setAttribute( 'aria-pressed', 'false' );
		button.textContent = 'SMS Center';
		const mikrotik = nav.querySelector( '[data-afc-app-panel="mikrotik"]' );
		nav.insertBefore( button, mikrotik || null );
	}

	function ajax( action, data ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.set( key, data[ key ] == null ? '' : String( data[ key ] ) );
		} );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () {
				throw new Error( 'Airfiber returned an invalid response.' );
			} );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				const message = response && response.data && response.data.message ? response.data.message : 'The Airfiber request failed.';
				throw new Error( message );
			}
			return response.data || {};
		} );
	}

	function notice( message, type ) {
		const target = byId( 'afc-sms-notice' );
		if ( ! target ) {
			return;
		}
		target.replaceChildren();
		if ( ! message ) {
			return;
		}
		const item = document.createElement( 'div' );
		item.className = 'alert alert-' + ( type || 'info' );
		item.textContent = message;
		target.appendChild( item );
	}

	function setBusy( element, busy, busyText ) {
		if ( ! element ) {
			return;
		}
		if ( busy ) {
			element.dataset.afcOriginalText = element.textContent;
			element.textContent = busyText || 'Working…';
			element.disabled = true;
		} else {
			element.textContent = element.dataset.afcOriginalText || element.textContent;
			element.disabled = false;
		}
	}

	function text( value, fallback ) {
		const normalized = value == null ? '' : String( value );
		return normalized || ( fallback || '' );
	}

	function stateBadge( status ) {
		const badge = document.createElement( 'span' );
		const name = text( status, 'unknown' ).toLowerCase();
		const map = {
			queued: 'bg-yellow-lt text-yellow',
			claimed: 'bg-azure-lt text-azure',
			submitted: 'bg-blue-lt text-blue',
			sent: 'bg-green-lt text-green',
			delivered: 'bg-teal-lt text-teal',
			failed: 'bg-red-lt text-red',
			cancelled: 'bg-secondary-lt text-secondary',
			online: 'bg-green-lt text-green',
			warning: 'bg-yellow-lt text-yellow',
			'token-created': 'bg-blue-lt text-blue',
			'not-configured': 'bg-secondary-lt text-secondary',
		};
		badge.className = 'badge ' + ( map[ name ] || 'bg-secondary-lt text-secondary' );
		badge.textContent = name.replace( /-/g, ' ' );
		return badge;
	}

	function renderDevice( device ) {
		device = device || {};
		const state = byId( 'afc-sms-device-state' );
		if ( state ) {
			state.replaceChildren( stateBadge( device.state || 'not-configured' ) );
		}
		if ( byId( 'afc-sms-last-seen' ) ) {
			byId( 'afc-sms-last-seen' ).textContent = text( device.last_seen, 'Never' );
		}
		if ( byId( 'afc-sms-device-id' ) ) {
			byId( 'afc-sms-device-id' ).textContent = text( device.device_id, 'Not connected' );
		}
		if ( byId( 'afc-sms-device-detail' ) ) {
			byId( 'afc-sms-device-detail' ).textContent = text( device.detail );
		}
		if ( byId( 'afc-sms-token-hint' ) ) {
			byId( 'afc-sms-token-hint' ).textContent = text( device.token_hint, 'None' );
		}
		const generate = byId( 'afc-sms-generate-token' );
		if ( generate && ! generate.disabled ) {
			generate.textContent = device.exists ? 'Rotate Device Token' : 'Generate Device Token';
		}
	}

	function renderCounts( counts ) {
		counts = counts || {};
		document.querySelectorAll( '[data-afc-sms-count]' ).forEach( function ( target ) {
			const key = target.getAttribute( 'data-afc-sms-count' );
			target.textContent = String( counts[ key ] || 0 );
		} );
	}

	function cell( value, className ) {
		const td = document.createElement( 'td' );
		if ( className ) {
			td.className = className;
		}
		td.textContent = text( value );
		return td;
	}

	function renderJobs( jobs ) {
		const table = byId( 'afc-sms-jobs-table' );
		if ( ! table ) {
			return;
		}
		const body = table.querySelector( 'tbody' );
		body.replaceChildren();
		if ( ! jobs || ! jobs.length ) {
			const row = document.createElement( 'tr' );
			const empty = cell( 'No SMS jobs yet.', 'text-center text-secondary py-4' );
			empty.colSpan = 7;
			row.appendChild( empty );
			body.appendChild( row );
			return;
		}
		jobs.forEach( function ( job ) {
			const row = document.createElement( 'tr' );
			row.appendChild( cell( '#' + job.id, 'text-secondary' ) );
			const subscriber = document.createElement( 'td' );
			const name = document.createElement( 'div' );
			name.className = 'fw-bold';
			name.textContent = text( job.customer_name, job.ppp_username );
			const ppp = document.createElement( 'div' );
			ppp.className = 'text-secondary small font-monospace';
			ppp.textContent = text( job.ppp_username );
			subscriber.append( name, ppp );
			row.appendChild( subscriber );
			row.appendChild( cell( job.phone, 'font-monospace' ) );
			const status = document.createElement( 'td' );
			status.appendChild( stateBadge( job.status ) );
			row.appendChild( status );
			row.appendChild( cell( job.last_detail || job.message, 'afc-sms-detail-cell' ) );
			row.appendChild( cell( job.created_at, 'text-secondary' ) );
			const actions = document.createElement( 'td' );
			actions.className = 'text-end';
			if ( job.can_cancel ) {
				const cancel = document.createElement( 'button' );
				cancel.type = 'button';
				cancel.className = 'btn btn-sm btn-outline-danger';
				cancel.setAttribute( 'data-afc-cancel-job', job.id );
				cancel.textContent = 'Cancel';
				actions.appendChild( cancel );
			}
			row.appendChild( actions );
			body.appendChild( row );
		} );
	}

	function renderActivityList( id, rows, emptyLabel, replyMode ) {
		const target = byId( id );
		if ( ! target ) {
			return;
		}
		target.replaceChildren();
		if ( ! rows || ! rows.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'list-group-item text-secondary';
			empty.textContent = emptyLabel;
			target.appendChild( empty );
			return;
		}
		rows.forEach( function ( row ) {
			const item = document.createElement( 'div' );
			item.className = 'list-group-item';
			const top = document.createElement( 'div' );
			top.className = 'd-flex justify-content-between gap-3';
			const title = document.createElement( 'strong' );
			if ( replyMode ) {
				title.textContent = text( row.phone, 'Unknown number' );
			} else {
				title.appendChild( stateBadge( row.status ) );
				if ( row.job_id ) {
					title.appendChild( document.createTextNode( ' Job #' + row.job_id ) );
				}
			}
			const time = document.createElement( 'span' );
			time.className = 'text-secondary small';
			time.textContent = text( replyMode ? ( row.received_at || row.created_at ) : row.created_at );
			top.append( title, time );
			const detail = document.createElement( 'div' );
			detail.className = 'mt-1 text-secondary afc-sms-event-detail';
			detail.textContent = text( replyMode ? row.message : row.detail );
			item.append( top, detail );
			target.appendChild( item );
		} );
	}

	function renderState( state ) {
		renderDevice( state.device );
		renderCounts( state.counts );
		renderJobs( state.jobs );
		renderActivityList( 'afc-sms-events', state.events, 'No activity yet.', false );
		renderActivityList( 'afc-sms-replies', state.replies, 'No replies received.', true );
	}

	function loadState( quiet ) {
		if ( stateLoading ) {
			return Promise.resolve();
		}
		stateLoading = true;
		return ajax( 'afc_sms_get_state' ).then( function ( state ) {
			renderState( state );
			if ( ! quiet ) {
				notice( 'SMS Center status refreshed.', 'success' );
			}
		} ).catch( function ( error ) {
			if ( ! quiet ) {
				notice( error.message, 'danger' );
			}
		} ).finally( function () {
			stateLoading = false;
		} );
	}

	function candidateAvailable( candidate ) {
		return candidate && candidate.phone_normalized && ! candidate.do_not_text;
	}

	function updateSelected() {
		const label = byId( 'afc-sms-selected-label' );
		const queue = byId( 'afc-sms-queue-test' );
		if ( ! selected ) {
			if ( label ) label.textContent = 'No PPP customer selected.';
			if ( queue ) queue.disabled = true;
			return;
		}
		if ( label ) {
			label.textContent = text( selected.customer_name, selected.ppp_username ) + ' · ' + text( selected.phone_normalized || selected.phone, 'No valid phone' );
		}
		if ( queue ) {
			queue.disabled = ! candidateAvailable( selected );
		}
	}

	function renderCandidates() {
		const target = byId( 'afc-sms-ppp-list' );
		if ( ! target ) {
			return;
		}
		const query = text( byId( 'afc-sms-ppp-search' ) && byId( 'afc-sms-ppp-search' ).value ).toLowerCase();
		const filtered = candidates.filter( function ( candidate ) {
			const haystack = [ candidate.ppp_username, candidate.customer_name, candidate.phone, candidate.profile ].join( ' ' ).toLowerCase();
			return ! query || haystack.includes( query );
		} );
		target.replaceChildren();
		if ( ! filtered.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'text-secondary p-3';
			empty.textContent = candidates.length ? 'No PPP customer matches your search.' : 'No PPP customers were found.';
			target.appendChild( empty );
			return;
		}
		filtered.forEach( function ( candidate ) {
			const row = document.createElement( 'label' );
			row.className = 'afc-sms-ppp-row';
			if ( ! candidateAvailable( candidate ) ) {
				row.classList.add( 'is-unavailable' );
			}
			const radio = document.createElement( 'input' );
			radio.type = 'radio';
			radio.name = 'afc-sms-ppp';
			radio.value = candidate.ppp_username;
			radio.className = 'form-check-input';
			radio.disabled = ! candidateAvailable( candidate );
			radio.checked = !! selected && selected.ppp_username === candidate.ppp_username;
			const main = document.createElement( 'span' );
			main.className = 'afc-sms-ppp-main';
			const heading = document.createElement( 'strong' );
			heading.textContent = text( candidate.customer_name, candidate.ppp_username );
			const meta = document.createElement( 'span' );
			meta.className = 'text-secondary';
			meta.textContent = candidate.ppp_username + ' · ' + text( candidate.phone_normalized || candidate.phone, 'No valid mobile number' ) + ( candidate.profile ? ' · ' + candidate.profile : '' );
			main.append( heading, meta );
			const issue = document.createElement( 'span' );
			issue.className = 'afc-sms-ppp-issue';
			issue.textContent = candidate.do_not_text ? 'Do Not Text' : ( candidate.phone_normalized ? '' : 'Invalid phone' );
			row.append( radio, main, issue );
			target.appendChild( row );
		} );
	}

	function loadCandidates() {
		const button = byId( 'afc-sms-load-ppp' );
		setBusy( button, true, 'Loading…' );
		const target = byId( 'afc-sms-ppp-list' );
		if ( target ) {
			target.innerHTML = '<div class="text-secondary p-3">Loading PPP customers…</div>';
		}
		return ajax( 'afc_sms_list_ppp' ).then( function ( response ) {
			candidates = Array.isArray( response.users ) ? response.users : [];
			if ( selected ) {
				selected = candidates.find( function ( item ) { return item.ppp_username === selected.ppp_username; } ) || null;
			}
			renderCandidates();
			updateSelected();
			if ( response.warning ) {
				notice( 'PPP list loaded from WordPress fallback: ' + response.warning, 'warning' );
			}
		} ).catch( function ( error ) {
			candidates = [];
			renderCandidates();
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function generateToken() {
		const button = byId( 'afc-sms-generate-token' );
		if ( button && /Rotate/.test( button.textContent ) && ! window.confirm( config.labels && config.labels.confirmRotate ? config.labels.confirmRotate : 'Rotate the device token?' ) ) {
			return;
		}
		setBusy( button, true, 'Generating…' );
		ajax( 'afc_sms_generate_token' ).then( function ( response ) {
			const input = byId( 'afc-sms-device-token' );
			const box = byId( 'afc-sms-token-box' );
			if ( input ) input.value = response.token || '';
			if ( box ) box.hidden = false;
			renderDevice( response.device );
			notice( response.message || 'Device token generated.', 'success' );
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
			loadState( true );
		} );
	}

	function copyToken() {
		const input = byId( 'afc-sms-device-token' );
		if ( ! input || ! input.value ) {
			return;
		}
		const done = function () {
			notice( config.labels && config.labels.copied ? config.labels.copied : 'Copied.', 'success' );
		};
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( input.value ).then( done ).catch( function () {
				input.select();
				document.execCommand( 'copy' );
				done();
			} );
		} else {
			input.select();
			document.execCommand( 'copy' );
			done();
		}
	}

	function queueTest() {
		if ( ! candidateAvailable( selected ) ) {
			notice( 'Select a PPP customer with a valid mobile number.', 'warning' );
			return;
		}
		const confirmation = config.labels && config.labels.confirmQueue ? config.labels.confirmQueue : 'Queue this test SMS?';
		if ( ! window.confirm( confirmation + '\n\nRecipient: ' + text( selected.customer_name, selected.ppp_username ) + ' (' + selected.phone_normalized + ')' ) ) {
			return;
		}
		const button = byId( 'afc-sms-queue-test' );
		setBusy( button, true, 'Queueing…' );
		ajax( 'afc_sms_queue_test', {
			ppp_username: selected.ppp_username,
			message: byId( 'afc-sms-message' ) ? byId( 'afc-sms-message' ).value : '',
		} ).then( function ( response ) {
			notice( response.message || 'Test SMS queued.', 'success' );
			loadState( true );
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
			updateSelected();
		} );
	}

	function cancelJob( jobId, button ) {
		if ( ! window.confirm( 'Cancel queued SMS job #' + jobId + '?' ) ) {
			return;
		}
		setBusy( button, true, 'Cancelling…' );
		ajax( 'afc_sms_cancel_job', { job_id: jobId } ).then( function ( response ) {
			notice( response.message || 'Queued SMS cancelled.', 'success' );
			loadState( true );
		} ).catch( function ( error ) {
			notice( error.message, 'danger' );
		} ).finally( function () {
			setBusy( button, false );
		} );
	}

	function bind() {
		const root = panel();
		if ( ! root ) {
			return;
		}
		root.addEventListener( 'click', function ( event ) {
			const radioRow = event.target.closest( '.afc-sms-ppp-row' );
			if ( radioRow ) {
				const radio = radioRow.querySelector( 'input[type="radio"]' );
				if ( radio && ! radio.disabled ) {
					radio.checked = true;
					selected = candidates.find( function ( item ) { return item.ppp_username === radio.value; } ) || null;
					updateSelected();
				}
			}
			if ( event.target.closest( '#afc-sms-refresh' ) ) loadState( false );
			if ( event.target.closest( '#afc-sms-generate-token' ) ) generateToken();
			if ( event.target.closest( '#afc-sms-copy-token' ) ) copyToken();
			if ( event.target.closest( '#afc-sms-load-ppp' ) ) loadCandidates();
			if ( event.target.closest( '#afc-sms-queue-test' ) ) queueTest();
			const cancel = event.target.closest( '[data-afc-cancel-job]' );
			if ( cancel ) cancelJob( cancel.getAttribute( 'data-afc-cancel-job' ), cancel );
		} );
		const search = byId( 'afc-sms-ppp-search' );
		if ( search ) {
			search.addEventListener( 'input', renderCandidates );
		}
	}

	function startPolling() {
		if ( stateTimer ) {
			window.clearInterval( stateTimer );
		}
		stateTimer = window.setInterval( function () {
			const current = panel();
			if ( current && ! current.hidden && current.getAttribute( 'aria-hidden' ) !== 'true' ) {
				loadState( true );
			}
		}, 5000 );
	}

	function boot() {
		injectNavigation();
		if ( ! panel() ) {
			return;
		}
		bind();
		loadState( true );
		loadCandidates();
		startPolling();
	}

	injectNavigation();

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
