( function ( $ ) {
	'use strict';

	const cfg = window.afcCustomerSearchPolish || {};
	const users = new Map();
	const signals = new Map();
	const pending = new Set();
	let timer = null;
	let observer = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function normalize( value ) {
		return text( value ).trim().toLowerCase();
	}

	function esc( value ) {
		const node = document.createElement( 'div' );
		node.textContent = text( value );
		return node.innerHTML;
	}

	function icon( name ) {
		return window.AFCIcons ? window.AFCIcons.icon( name ) : '<span aria-hidden="true">•</span>';
	}

	function parseRowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function rememberUser( user ) {
		if ( user && user.name ) users.set( normalize( user.name ), user );
	}

	function collectTableUsers() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			rememberUser( parseRowUser( row ) );
		} );
	}

	function payload( user ) {
		return {
			account: user.name || '',
			customer_name: user.customer_name || '',
			phone: user.phone || '',
			comment: user.comment || '',
			installed: user.installed || '',
			payment_date: user.payment_date || '',
			actual_profile: user.actual_profile || '',
			profile: user.profile || '',
		};
	}

	function request( list ) {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_customer_search_signals_v2' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'users', JSON.stringify( list.map( payload ) ) );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Invalid signal response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) throw new Error( 'Signal request failed.' );
			return response.data && response.data.signals ? response.data.signals : {};
		} );
	}

	function loadAccounts( accounts, force ) {
		const list = [];
		const seen = new Set();
		( accounts || [] ).forEach( function ( account ) {
			const key = normalize( account );
			const user = users.get( key );
			if ( ! user || seen.has( key ) || pending.has( key ) || ( ! force && signals.has( key ) ) ) return;
			seen.add( key );
			pending.add( key );
			list.push( user );
		} );
		if ( ! list.length ) return Promise.resolve();

		const batches = [];
		for ( let index = 0; index < list.length; index += 100 ) batches.push( list.slice( index, index + 100 ) );
		return Promise.all( batches.map( function ( batch ) {
			return request( batch ).then( function ( data ) {
				Object.keys( data ).forEach( function ( account ) {
					signals.set( normalize( account ), data[ account ] );
				} );
			} ).catch( function () {
				// Payment search stays usable when status information cannot load.
			} ).finally( function () {
				batch.forEach( function ( user ) { pending.delete( normalize( user.name ) ); } );
			} );
		} ) ).then( schedule );
	}

	function dateLabel( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
		return match ? Number( match[ 2 ] ) + '-' + Number( match[ 3 ] ) : text( value );
	}

	function signal( account ) {
		return signals.get( normalize( account ) ) || null;
	}

	function chip( type, iconName, label, title, pulse ) {
		return '<span class="afc-polished-signal is-' + esc( type ) + ( pulse ? ' is-pulsing' : '' ) + '" title="' + esc( title || label ) + '">' +
			icon( iconName ) + '<b>' + esc( label ) + '</b></span>';
	}

	function dueSignal( state ) {
		if ( ! state || ! state.dueState || state.dueState === 'unknown' ) return '';
		if ( state.dueState === 'expired' ) {
			return chip( 'expired', 'clock', 'EXPIRED', 'Cutoff passed' + ( state.cutoffDate ? ': ' + state.cutoffDate : '' ) );
		}
		if ( state.dueState === 'due' ) {
			const overdue = Number( state.daysToDue || 0 ) < 0 ? Math.abs( Number( state.daysToDue ) ) + 'D LATE' : 'DUE';
			return chip( 'due', 'clock', overdue, 'Billing due ' + ( state.nextDue || 'today' ) );
		}
		if ( state.dueState === 'soon' ) {
			const days = state.daysToDue == null ? state.daysToCutoff : state.daysToDue;
			const label = Number( days ) <= 1 ? 'DUE' : Number( days ) + 'D';
			return chip( 'due-soon', 'clock', label, 'Due soon' + ( state.nextDue ? ': ' + state.nextDue : '' ) );
		}
		if ( state.dueState === 'upcoming' ) {
			const days = state.daysToDue == null ? state.daysToCutoff : state.daysToDue;
			return chip( 'upcoming', 'clock', Number( days ) + 'D', 'Coming due' + ( state.nextDue ? ': ' + state.nextDue : '' ) );
		}
		return chip( 'safe', 'clock', dateLabel( state.nextDue || state.cutoffDate ) || 'OK', 'Account is not near due' );
	}

	function smsSignal( state ) {
		if ( ! state || ! state.smsState || state.smsState === 'none' ) return '';
		if ( state.smsState === 'received' ) return chip( 'sms-received', 'mail', 'NEW', 'New customer SMS received', true );
		if ( state.smsState === 'queued' ) return chip( 'sms-queued', 'mail', 'TODAY', 'SMS waiting in the Android gateway queue' );
		if ( state.smsState === 'due' ) return chip( 'sms-due', 'mail', 'TODAY', 'Automatic SMS is due today' );
		if ( state.smsState === 'scheduled' ) return chip( 'sms-scheduled', 'mail', state.smsLabel || 'SET', 'Automatic SMS scheduled' );
		if ( state.smsState === 'sent' ) return chip( 'sms-sent', 'mail', state.smsLabel || 'SENT', 'SMS ' + ( state.lastSmsStatus || 'sent' ) );
		return '';
	}

	function activitySignals( state ) {
		let html = '';
		if ( state && state.paidRecent ) html += chip( 'paid', 'money', dateLabel( state.paymentDate ) || 'PAID', 'Recently paid: ' + state.paymentDate );
		if ( state && state.newInstall ) html += chip( 'install', 'check', 'NEW', 'New installation: ' + state.installedDate );
		return html;
	}

	function signalsHtml( account ) {
		const state = signal( account );
		if ( ! state ) return '<span class="afc-polished-signal is-loading"><i></i><b>STATUS</b></span>';
		return smsSignal( state ) + dueSignal( state ) + activitySignals( state );
	}

	function setContents( node, html ) {
		if ( ! node ) return;
		if ( node.dataset.afcPolishSignature === html && node.innerHTML === html ) return;
		node.dataset.afcPolishSignature = html;
		node.innerHTML = html;
	}

	function primaryTone( state ) {
		if ( ! state ) return 'loading';
		if ( state.dueState === 'expired' ) return 'expired';
		if ( state.incomingUnread ) return 'message';
		if ( state.dueState === 'due' || state.dueState === 'soon' ) return 'due';
		if ( state.smsState === 'queued' || state.smsState === 'due' ) return 'sms';
		if ( state.paidRecent ) return 'paid';
		return 'normal';
	}

	function removeAutoButtonIcon( result ) {
		result.setAttribute( 'data-afc-no-auto-icon', '' );
		result.classList.remove( 'afc-has-ui-icon' );
		delete result.dataset.afcIconDone;
		Array.from( result.children ).forEach( function ( child ) {
			if ( child.classList && child.classList.contains( 'afc-ui-icon' ) ) child.remove();
		} );
	}

	function decorateDashboard() {
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
			removeAutoButtonIcon( result );
			result.classList.add( 'is-polished' );
			result.dataset.afcTone = primaryTone( signal( account ) );
			const old = result.querySelector( ':scope > .afc-dashboard-signal-row' );
			if ( old ) old.hidden = true;
			const copy = result.querySelector( '.afc-dashboard-payment-result-copy' );
			if ( ! copy ) return;
			let row = copy.querySelector( '.afc-polished-signal-row' );
			if ( ! row ) {
				row = document.createElement( 'span' );
				row.className = 'afc-polished-signal-row';
				copy.appendChild( row );
			}
			setContents( row, signalsHtml( account ) );
			const action = result.querySelector( '.afc-dashboard-payment-result-action' );
			if ( action ) setContents( action, icon( 'money' ) + '<b>Record</b>' );
		} );
	}

	function decorateBasic() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const account = result.getAttribute( 'data-account' ) || '';
			result.setAttribute( 'data-afc-no-auto-icon', '' );
			let row = result.querySelector( '.afc-polished-basic-signal-row' );
			if ( ! row ) {
				row = document.createElement( 'span' );
				row.className = 'afc-polished-basic-signal-row';
				result.appendChild( row );
			}
			setContents( row, signalsHtml( account ) );
		} );
	}

	function decorateOperations() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = parseRowUser( row );
			if ( ! user || ! user.name ) return;
			rememberUser( user );
			const cell = row.querySelector( 'td:nth-child(2)' );
			let target = cell && cell.querySelector( '.afc-polished-operations-signal-row' );
			if ( cell && ! target ) {
				target = document.createElement( 'div' );
				target.className = 'afc-polished-operations-signal-row';
				cell.appendChild( target );
			}
			if ( target ) setContents( target, signalsHtml( user.name ) );
		} );
	}

	function visibleAccounts() {
		const out = new Set();
		document.querySelectorAll( '[data-afc-dashboard-payment-account], .afc-basic-customer-result[data-account]' ).forEach( function ( node ) {
			const account = node.getAttribute( 'data-afc-dashboard-payment-account' ) || node.getAttribute( 'data-account' ) || '';
			if ( account ) out.add( account );
		} );
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			const user = parseRowUser( row );
			if ( user && user.name ) out.add( user.name );
		} );
		return Array.from( out );
	}

	function decorate() {
		collectTableUsers();
		decorateDashboard();
		decorateBasic();
		decorateOperations();
		loadAccounts( visibleAccounts(), false );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( decorate, 85 );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return text( settings.data ).includes( 'action=' + action );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			( xhr.responseJSON.data.users || [] ).forEach( rememberUser );
			signals.clear();
			loadAccounts( Array.from( users.values() ).map( function ( user ) { return user.name; } ), true );
			schedule();
		}
		if ( requestHasAction( settings, 'afc_ppp_record_payment' ) && xhr.responseJSON && xhr.responseJSON.success ) {
			signals.clear();
			window.setTimeout( function () { loadAccounts( visibleAccounts(), true ); }, 250 );
		}
	} );

	function boot() {
		collectTableUsers();
		observer = new MutationObserver( schedule );
		observer.observe( document.body, { childList: true, subtree: true } );
		schedule();
		window.setInterval( function () {
			const accounts = visibleAccounts();
			accounts.forEach( function ( account ) { signals.delete( normalize( account ) ); } );
			loadAccounts( accounts, true );
		}, 60000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
