( function ( $ ) {
	'use strict';

	const cfg = window.afcCustomerActions || {};
	const users = new Map();
	const signals = new Map();
	const loading = new Set();
	let activeAccount = '';
	let modal = null;
	let decorateTimer = null;
	let observer = null;

	function text( value ) { return value == null ? '' : String( value ); }
	function esc( value ) { const node = document.createElement( 'div' ); node.textContent = text( value ); return node.innerHTML; }
	function icon( name ) { return window.AFCIcons ? AFCIcons.icon( name ) : '<span aria-hidden="true">•</span>'; }
	function normalize( value ) { return text( value ).trim().toLowerCase(); }
	function currentMode() { return document.body.classList.contains( 'afc-admin-mode-basic' ) ? 'basic' : 'advanced'; }
	function setHtml( node, html ) {
		if ( ! node ) return;
		const signature = text( html );
		if ( node.dataset.afcSignalSignature === signature ) return;
		node.dataset.afcSignalSignature = signature;
		node.innerHTML = signature;
	}

	function ajax( action, data ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', cfg.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			const value = data[ key ];
			if ( Array.isArray( value ) ) value.forEach( function ( item ) { body.append( key + '[]', item ); } );
			else body.set( key, value == null ? '' : String( value ) );
		} );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Airfiber returned an invalid response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) throw new Error( response && response.data && response.data.message ? response.data.message : 'The request failed.' );
			return response.data || {};
		} );
	}

	function userPayload( account ) {
		const user = users.get( normalize( account ) ) || {};
		return {
			account: account,
			customer_name: user.customer_name || user.name || '',
			phone: user.phone || '',
			comment: user.comment || '',
			installed: user.installed || '',
			payment_date: user.payment_date || '',
			payment_amount: user.payment_amount || '',
			profile: user.profile || '',
			actual_profile: user.actual_profile || '',
		};
	}

	function postUser( account, extra ) {
		return Object.assign( userPayload( account ), extra || {} );
	}

	function parseRowUser( row ) {
		try { return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) ); }
		catch ( error ) { return null; }
	}

	function rememberUser( user ) {
		if ( user && user.name ) users.set( normalize( user.name ), user );
	}

	function collectUsersFromTable() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) { rememberUser( parseRowUser( row ) ); } );
	}

	function signalFor( account ) {
		const key = normalize( account );
		if ( signals.has( key ) ) return signals.get( key );
		const user = users.get( key ) || {};
		const comment = text( user.comment );
		const value = function ( field ) {
			const match = comment.match( new RegExp( '(?:^|\\s)' + field + '\\s*:\\s*(.*?)(?=\\s+[A-Za-z][A-Za-z0-9_-]*\\s*:|$)', 'i' ) );
			return match ? text( match[ 1 ] ).trim() : '';
		};
		return {
			account: account,
			customerId: 0,
			reminderEnabled: false,
			reminderDays: Number( cfg.defaultDays || 3 ),
			smsState: 'none', smsLabel: '',
			dueState: String( user.actual_profile || '' ).toLowerCase() === 'expired' ? 'expired' : 'unknown',
			cutoffDate: value( 'cutoffDate' ), nextDue: value( 'nextDue' ),
			paidRecent: false, paymentDate: user.payment_date || '',
			newInstall: false, installedDate: user.installed || '',
			serviceState: String( user.actual_profile || '' ).toLowerCase() === 'expired' ? 'expired' : 'active',
			phoneValid: Boolean( user.phone ), incomingUnread: false,
		};
	}

	function setSignal( account, value ) {
		if ( account && value ) signals.set( normalize( account ), value );
	}

	function chunk( items, size ) {
		const out = [];
		for ( let index = 0; index < items.length; index += size ) out.push( items.slice( index, index + size ) );
		return out;
	}

	function loadSignals( accounts ) {
		const unique = [];
		( accounts || [] ).forEach( function ( account ) {
			const clean = text( account ).trim();
			const key = normalize( clean );
			if ( clean && ! signals.has( key ) && ! loading.has( key ) && ! unique.some( function ( item ) { return normalize( item ) === key; } ) ) unique.push( clean );
		} );
		if ( ! unique.length ) return Promise.resolve();
		unique.forEach( function ( account ) { loading.add( normalize( account ) ); } );
		return Promise.all( chunk( unique, 120 ).map( function ( batch ) {
			return ajax( 'afc_customer_signals', { accounts: JSON.stringify( batch ) } ).then( function ( data ) {
				Object.keys( data.signals || {} ).forEach( function ( account ) { setSignal( account, data.signals[ account ] ); } );
			} ).catch( function () {
				// The payment tools continue to work even when a status request fails.
			} ).finally( function () { batch.forEach( function ( account ) { loading.delete( normalize( account ) ); } ); } );
		} ) ).then( scheduleDecorate );
	}

	function dateLabel( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
		return match ? Number( match[ 2 ] ) + '-' + Number( match[ 3 ] ) : text( value );
	}

	function dueChip( state ) {
		if ( ! state || state.dueState === 'unknown' ) return '';
		const labels = {
			expired: 'EXPIRED', soon: state.daysToCutoff === 0 ? 'TODAY' : ( state.daysToCutoff + 'D' ),
			upcoming: state.daysToCutoff + 'D', safe: dateLabel( state.cutoffDate ),
		};
		return '<span class="afc-signal-chip is-due-' + esc( state.dueState ) + '" title="Cutoff ' + esc( state.cutoffDate || 'not set' ) + '">' + icon( 'clock' ) + '<b>' + esc( labels[ state.dueState ] || '' ) + '</b></span>';
	}

	function smsChip( state, account, interactive ) {
		if ( ! state || state.smsState === 'none' ) return '';
		const classes = 'afc-signal-chip is-sms-' + esc( state.smsState ) + ( state.incomingUnread ? ' is-pulsing' : '' );
		const label = state.smsLabel || ( state.smsState === 'sent' ? 'SENT' : 'SMS' );
		const title = state.incomingUnread ? 'New customer SMS received' : ( state.lastSmsStatus ? 'SMS ' + state.lastSmsStatus : 'SMS reminder' );
		if ( interactive ) {
			return '<button type="button" class="' + classes + '" data-afc-account-options="' + esc( account ) + '" data-afc-no-auto-icon title="' + esc( title ) + '">' + icon( 'mail' ) + '<b>' + esc( label ) + '</b></button>';
		}
		return '<span class="' + classes + '" title="' + esc( title ) + '">' + icon( 'mail' ) + '<b>' + esc( label ) + '</b></span>';
	}

	function activityChips( state ) {
		let html = '';
		if ( state && state.newInstall ) html += '<span class="afc-signal-chip is-install" title="New installation ' + esc( state.installedDate ) + '">' + icon( 'check' ) + '<b>NEW</b></span>';
		if ( state && state.paidRecent ) html += '<span class="afc-signal-chip is-paid" title="Paid ' + esc( state.paymentDate ) + '">' + icon( 'money' ) + '<b>' + esc( dateLabel( state.paymentDate ) ) + '</b></span>';
		return html;
	}

	function chipsHtml( account, interactiveSms ) {
		const state = signalFor( account );
		return smsChip( state, account, interactiveSms ) + dueChip( state ) + activityChips( state );
	}

	function decorateBasicResults() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const account = result.getAttribute( 'data-account' ) || '';
			let target = result.querySelector( '.afc-customer-signal-row' );
			if ( ! target ) {
				target = document.createElement( 'span' );
				target.className = 'afc-customer-signal-row';
				result.appendChild( target );
			}
			setHtml( target, chipsHtml( account, false ) );
			target.hidden = ! target.dataset.afcSignalSignature;
		} );
	}

	function decorateBasicSelected() {
		const card = document.querySelector( '.afc-basic-selected-card' );
		if ( ! card ) return;
		const accountNode = card.querySelector( '.afc-basic-selected-account strong' );
		const account = accountNode ? text( accountNode.textContent ).trim() : '';
		if ( ! account ) return;
		let row = card.querySelector( '.afc-customer-selected-signals' );
		if ( ! row ) {
			row = document.createElement( 'div' );
			row.className = 'afc-customer-selected-signals';
			card.querySelector( '.afc-basic-selected-account' ).insertAdjacentElement( 'afterend', row );
		}
		setHtml( row, chipsHtml( account, false ) );
		const actions = card.querySelector( '.afc-basic-selected-actions' );
		if ( actions && ! actions.querySelector( '[data-afc-account-options]' ) ) {
			const gear = document.createElement( 'button' );
			gear.type = 'button';
			gear.className = 'afc-basic-options-button';
			gear.setAttribute( 'data-afc-account-options', account );
			gear.setAttribute( 'data-afc-no-auto-icon', '' );
			gear.setAttribute( 'aria-label', 'Open account options' );
			gear.innerHTML = icon( 'settings' ) + '<span>Options</span>';
			actions.appendChild( gear );
		} else if ( actions ) {
			actions.querySelector( '[data-afc-account-options]' ).setAttribute( 'data-afc-account-options', account );
		}
	}

	function decorateOperationsRows() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = parseRowUser( row );
			if ( ! user || ! user.name ) return;
			rememberUser( user );
			const first = row.querySelector( 'td:nth-child(2)' );
			let signalsNode = first && first.querySelector( '.afc-operations-signal-row' );
			if ( first && ! signalsNode ) {
				signalsNode = document.createElement( 'div' );
				signalsNode.className = 'afc-operations-signal-row';
				first.appendChild( signalsNode );
			}
			if ( signalsNode ) setHtml( signalsNode, dueChip( signalFor( user.name ) ) + activityChips( signalFor( user.name ) ) );
			const actions = row.querySelector( '.afc-row-actions' );
			if ( actions ) {
				let sms = actions.querySelector( '.afc-row-sms-action' );
				if ( ! sms ) {
					sms = document.createElement( 'button' );
					sms.type = 'button';
					sms.className = 'afc-row-sms-action';
					sms.setAttribute( 'data-afc-no-auto-icon', '' );
					actions.insertBefore( sms, actions.firstChild );
				}
				sms.setAttribute( 'data-afc-account-options', user.name );
				setHtml( sms, smsChip( signalFor( user.name ), user.name, false ) || '<span class="afc-signal-chip is-sms-none">' + icon( 'mail' ) + '</span>' );
				sms.title = 'SMS and reminder options';
			}
		} );
	}

	function decorateDashboardResults() {
		document.querySelectorAll( '[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
			let target = result.querySelector( '.afc-dashboard-signal-row' );
			if ( ! target ) {
				target = document.createElement( 'span' );
				target.className = 'afc-dashboard-signal-row';
				result.appendChild( target );
			}
			setHtml( target, chipsHtml( account, false ) );
			target.hidden = ! target.dataset.afcSignalSignature;
		} );
	}

	function serviceAction( account ) {
		const user = users.get( normalize( account ) ) || {};
		const expired = String( user.actual_profile || signalFor( account ).serviceState || '' ).toLowerCase() === 'expired';
		return expired ? 'reconnect' : 'expire';
	}

	function optionsMarkup( account, compact ) {
		const state = signalFor( account );
		const action = serviceAction( account );
		const disabled = ! state.phoneValid ? ' disabled' : '';
		return '<div class="afc-inline-action-message" data-afc-inline-message hidden></div><div class="afc-inline-account-status">' + chipsHtml( account, false ) + '</div>' +
			'<div class="afc-account-option-grid' + ( compact ? ' is-compact' : '' ) + '">' +
				'<label class="afc-account-reminder-toggle"><input type="checkbox" data-afc-reminder-enabled' + ( state.reminderEnabled ? ' checked' : '' ) + disabled + '><span></span><b>SMS before cutoff</b></label>' +
				'<label class="afc-account-reminder-days"><span>Days before</span><select data-afc-reminder-days' + disabled + '>' + [ 1, 2, 3, 4, 5, 7, 10, 14 ].map( function ( day ) { return '<option value="' + day + '"' + ( Number( state.reminderDays || 3 ) === day ? ' selected' : '' ) + '>' + day + '</option>'; } ).join( '' ) + '</select></label>' +
			'</div>' +
			( state.cutoffDate ? '<small class="afc-account-cutoff-copy">Cutoff ' + esc( state.cutoffDate ) + ( state.reminderDate ? ' · reminder ' + esc( state.reminderDate ) : '' ) + '</small>' : '<small class="afc-account-cutoff-copy is-warning">No cutoff date is saved yet.</small>' ) +
			'<div class="afc-account-option-actions">' +
				'<button type="button" data-afc-save-reminder data-afc-icon="save">Save reminder</button>' +
				'<button type="button" data-afc-send-now data-afc-icon="mail"' + disabled + '>Send SMS now</button>' +
				'<button type="button" data-afc-service-action="' + action + '" data-afc-icon="' + ( action === 'reconnect' ? 'plug' : 'clock' ) + '">' + ( action === 'reconnect' ? 'Reconnect' : 'Expire' ) + '</button>' +
			'</div>' +
			( ! state.phoneValid ? '<div class="afc-account-option-warning">Add a valid customer mobile number before using SMS.</div>' : '' );
	}

	function injectPaymentOptions( dialog ) {
		if ( ! dialog || currentMode() !== 'advanced' ) return;
		const body = dialog.querySelector( '.afc-dashboard-direct-payment-body, .afc-dialog-body' );
		if ( ! body ) return;
		let section = body.querySelector( '.afc-payment-account-options' );
		if ( ! section ) {
			section = document.createElement( 'details' );
			section.className = 'afc-payment-account-options';
			section.open = true;
			section.innerHTML = '<summary>' + icon( 'settings' ) + '<span>Account options</span><em></em></summary><div data-afc-inline-options></div>';
			body.appendChild( section );
		}
		const account = dialog.id === 'afc-dashboard-direct-payment-dialog'
			? text( dialog.querySelector( '[data-afc-dashboard-direct-payment-account]' ) && dialog.querySelector( '[data-afc-dashboard-direct-payment-account]' ).textContent ).trim()
			: activeAccount;
		if ( ! account ) return;
		activeAccount = account;
		section.setAttribute( 'data-account', account );
		const inline = section.querySelector( '[data-afc-inline-options]' );
		const inlineSignature = account + '|' + JSON.stringify( signalFor( account ) );
		if ( inline && inline.dataset.afcInlineSignature !== inlineSignature ) {
			inline.dataset.afcInlineSignature = inlineSignature;
			inline.innerHTML = optionsMarkup( account, true );
		}
		const summary = section.querySelector( 'summary em' );
		const state = signalFor( account );
		if ( summary ) { const next = state.smsState === 'sent' ? 'SMS ' + ( state.smsLabel || 'sent' ) : ( state.reminderEnabled ? state.reminderDays + ' days before' : 'Optional' ); if ( summary.textContent !== next ) summary.textContent = next; }
		if ( window.AFCIcons ) AFCIcons.decorate( section );
	}

	function createModal() {
		if ( modal ) return modal;
		modal = document.createElement( 'dialog' );
		modal.id = 'afc-customer-actions-dialog';
		modal.className = 'afc-customer-actions-dialog';
		modal.innerHTML = '<form method="dialog"><header><div><small>Customer tools</small><h2 data-afc-actions-name>Account options</h2><p data-afc-actions-account></p></div><span data-afc-actions-sms-pill></span><button type="button" data-afc-actions-close aria-label="Close">×</button></header>' +
			'<div class="afc-customer-actions-body"><div class="afc-customer-actions-message" data-afc-actions-message hidden></div><div data-afc-actions-content></div></div>' +
			'<footer><button type="button" data-afc-actions-close>Close</button></footer></form>';
		document.body.appendChild( modal );
		modal.addEventListener( 'click', function ( event ) {
			if ( event.target === modal || event.target.closest( '[data-afc-actions-close]' ) ) { event.preventDefault(); if ( modal.open ) modal.close(); }
		} );
		return modal;
	}

	function message( value, type ) {
		const node = createModal().querySelector( '[data-afc-actions-message]' );
		node.hidden = ! value;
		node.className = 'afc-customer-actions-message' + ( type ? ' is-' + type : '' );
		node.textContent = value || '';
	}

	function notifyContext( scope, value, type ) {
		if ( scope && scope.classList && scope.classList.contains( 'afc-payment-account-options' ) ) {
			const node = scope.querySelector( '[data-afc-inline-message]' );
			if ( node ) {
				node.hidden = ! value;
				node.className = 'afc-inline-action-message' + ( type ? ' is-' + type : '' );
				node.textContent = value || '';
				return;
			}
		}
		message( value, type );
	}

	function renderModal() {
		const dialog = createModal();
		const user = users.get( normalize( activeAccount ) ) || {};
		const state = signalFor( activeAccount );
		dialog.querySelector( '[data-afc-actions-name]' ).textContent = user.customer_name || user.name || activeAccount;
		dialog.querySelector( '[data-afc-actions-account]' ).textContent = activeAccount;
		const pill = dialog.querySelector( '[data-afc-actions-sms-pill]' );
		pill.innerHTML = smsChip( state, activeAccount, false ) || '<span class="afc-signal-chip is-sms-none">' + icon( 'mail' ) + '<b>NO SMS</b></span>';
		dialog.querySelector( '[data-afc-actions-content]' ).innerHTML = optionsMarkup( activeAccount, false ) +
			'<dl class="afc-account-facts">' +
			'<div><dt>Next due</dt><dd>' + esc( state.nextDue || 'Not set' ) + '</dd></div>' +
			'<div><dt>Cutoff</dt><dd>' + esc( state.cutoffDate || 'Not set' ) + '</dd></div>' +
			'<div><dt>Last SMS</dt><dd>' + esc( state.lastSmsAt || 'None' ) + '</dd></div>' +
			'<div><dt>SMS status</dt><dd>' + esc( state.lastSmsStatus || state.smsState || 'None' ) + '</dd></div>' +
			'</dl>';
		if ( window.AFCIcons ) AFCIcons.decorate( dialog );
	}

	function openOptions( account ) {
		activeAccount = text( account ).trim();
		if ( ! activeAccount ) return;
		const dialog = createModal();
		message( '', '' );
		renderModal();
		const state = signalFor( activeAccount );
		if ( state.incomingUnread ) {
			ajax( 'afc_precutoff_mark_seen', { account: activeAccount } ).then( function () {
				signals.delete( normalize( activeAccount ) );
				return loadSignals( [ activeAccount ] );
			} ).then( function () {
				if ( modal && modal.open ) renderModal();
				scheduleDecorate();
			} ).catch( function () {} );
		}
		try { dialog.showModal(); } catch ( error ) { dialog.setAttribute( 'open', 'open' ); }
	}

	function actionContext( button ) {
		const scope = button.closest( '#afc-customer-actions-dialog, .afc-payment-account-options' );
		return { scope: scope, account: ( scope && scope.getAttribute( 'data-account' ) ) || activeAccount || '' };
	}

	function busy( button, state, label ) {
		if ( ! button ) return;
		if ( state ) { button.dataset.label = button.textContent; button.textContent = label || 'Working…'; button.disabled = true; }
		else { button.textContent = button.dataset.label || button.textContent; button.disabled = false; }
	}

	function saveReminder( button ) {
		const context = actionContext( button );
		const scope = context.scope;
		const enabled = scope && scope.querySelector( '[data-afc-reminder-enabled]' );
		const days = scope && scope.querySelector( '[data-afc-reminder-days]' );
		busy( button, true, 'Saving…' );
		ajax( 'afc_precutoff_save', postUser( context.account, { enabled: enabled && enabled.checked ? '1' : '0', days: days ? days.value : '3' } ) ).then( function ( data ) {
			if ( data.signal ) setSignal( context.account, data.signal );
			if ( modal && modal.open ) renderModal();
			refreshInlineDialogs(); scheduleDecorate();
			notifyContext( scope, data.message || 'Reminder saved.', 'success' );
		} ).catch( function ( error ) { notifyContext( scope, error.message, 'error' ); } ).finally( function () { busy( button, false ); } );
	}

	function sendNow( button ) {
		const context = actionContext( button );
		if ( ! window.confirm( 'Queue a pre-cutoff SMS for ' + context.account + ' now?' ) ) return;
		busy( button, true, 'Queueing…' );
		ajax( 'afc_precutoff_send_now', postUser( context.account ) ).then( function ( data ) {
			if ( data.signal ) setSignal( context.account, data.signal );
			if ( modal && modal.open ) renderModal();
			refreshInlineDialogs(); scheduleDecorate();
			notifyContext( context.scope, data.message || 'SMS queued.', 'success' );
		} ).catch( function ( error ) { notifyContext( context.scope, error.message, 'error' ); } ).finally( function () { busy( button, false ); } );
	}

	function changeService( button, change ) {
		const context = actionContext( button );
		const user = users.get( normalize( context.account ) );
		if ( ! user || ! window.afcPPP ) { notifyContext( context.scope, 'Refresh MikroTik first, then try again.', 'error' ); return; }
		const wording = change === 'expire'
			? 'Set ' + context.account + ' to the Expired profile?'
			: 'Reconnect ' + context.account + ' and restore the saved plan?';
		if ( ! window.confirm( wording ) ) return;
		busy( button, true, change === 'expire' ? 'Expiring…' : 'Reconnecting…' );
		$.post( afcPPP.ajaxUrl, { action: 'afc_ppp_change_service', nonce: afcPPP.nonce, change: change, user: user } ).done( function ( response ) {
			if ( ! response || ! response.success ) { notifyContext( context.scope, response && response.data && response.data.message ? response.data.message : 'Service update failed.', 'error' ); return; }
			notifyContext( context.scope, response.data.message || 'Service updated.', 'success' );
			const refresh = document.getElementById( 'afc-refresh-ppp' );
			if ( refresh ) refresh.click();
		} ).fail( function () { notifyContext( context.scope, 'Service update failed.', 'error' ); } ).always( function () { busy( button, false ); } );
	}

	function refreshInlineDialogs() {
		injectPaymentOptions( document.getElementById( 'afc-dashboard-direct-payment-dialog' ) );
		injectPaymentOptions( document.getElementById( 'afc-payment-dialog' ) );
	}

	function scheduleDecorate() {
		window.clearTimeout( decorateTimer );
		decorateTimer = window.setTimeout( decorate, 40 );
	}

	function decorate() {
		collectUsersFromTable();
		decorateBasicResults();
		decorateBasicSelected();
		decorateOperationsRows();
		decorateDashboardResults();
		refreshInlineDialogs();
		const safety = document.querySelector( '.afc-sms-payor-safety' );
		const safetyCopy = 'Overdue reminders follow payor ratings. Pre-cutoff reminders are set per customer from the payment search gear or SMS icon. Replies and payments stop queued reminders.';
		if ( safety && safety.textContent !== safetyCopy ) safety.textContent = safetyCopy;
		const accounts = [];
		document.querySelectorAll( '[data-account], [data-afc-dashboard-payment-account], #afc-ppp-table tbody tr[data-user]' ).forEach( function ( node ) {
			if ( node.matches( 'tr[data-user]' ) ) { const user = parseRowUser( node ); if ( user && user.name ) accounts.push( user.name ); }
			else accounts.push( node.getAttribute( 'data-account' ) || node.getAttribute( 'data-afc-dashboard-payment-account' ) || '' );
		} );
		if ( activeAccount ) accounts.push( activeAccount );
		loadSignals( accounts );
	}

	document.addEventListener( 'click', function ( event ) {
		const dashboardResult = event.target.closest( '[data-afc-dashboard-payment-account]' );
		if ( dashboardResult ) activeAccount = dashboardResult.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
		const pay = event.target.closest( '.afc-pay-today' );
		if ( pay ) { const user = parseRowUser( pay.closest( 'tr[data-user]' ) ); if ( user ) { rememberUser( user ); activeAccount = user.name; } }
		const options = event.target.closest( '[data-afc-account-options]' );
		if ( options ) { event.preventDefault(); event.stopPropagation(); openOptions( options.getAttribute( 'data-afc-account-options' ) ); return; }
		const save = event.target.closest( '[data-afc-save-reminder]' );
		if ( save ) { event.preventDefault(); saveReminder( save ); return; }
		const send = event.target.closest( '[data-afc-send-now]' );
		if ( send ) { event.preventDefault(); sendNow( send ); return; }
		const service = event.target.closest( '[data-afc-service-action]' );
		if ( service ) { event.preventDefault(); changeService( service, service.getAttribute( 'data-afc-service-action' ) ); }
	}, true );

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		const raw = settings && settings.data;
		const isUsers = raw && ( ( typeof raw === 'object' && raw.action === 'afc_get_ppp_users' ) || String( raw ).includes( 'action=afc_get_ppp_users' ) );
		if ( isUsers && xhr.responseJSON && xhr.responseJSON.success ) {
			( xhr.responseJSON.data.users || [] ).forEach( rememberUser );
			signals.clear();
			loadSignals( Array.from( users.values() ).map( function ( user ) { return user.name; } ) ).then( scheduleDecorate );
		}
		const isPayment = raw && ( ( typeof raw === 'object' && raw.action === 'afc_ppp_record_payment' ) || String( raw ).includes( 'action=afc_ppp_record_payment' ) );
		if ( isPayment && xhr.responseJSON && xhr.responseJSON.success && activeAccount ) {
			signals.delete( normalize( activeAccount ) );
			loadSignals( [ activeAccount ] );
		}
	} );

	function boot() {
		createModal();
		collectUsersFromTable();
		observer = new MutationObserver( scheduleDecorate );
		observer.observe( document.body, { childList: true, subtree: true, characterData: false } );
		scheduleDecorate();
		window.setInterval( function () {
			const visible = Array.from( document.querySelectorAll( '#afc-ppp-table tbody tr[data-user], [data-account], [data-afc-dashboard-payment-account]' ) ).map( function ( node ) {
				if ( node.matches( 'tr[data-user]' ) ) { const user = parseRowUser( node ); return user ? user.name : ''; }
				return node.getAttribute( 'data-account' ) || node.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
			} ).filter( Boolean );
			visible.forEach( function ( account ) { signals.delete( normalize( account ) ); } );
			loadSignals( visible );
		}, 60000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
