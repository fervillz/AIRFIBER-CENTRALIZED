( function ( $ ) {
	'use strict';

	const cfg = window.afcCustomerSearchPolish || {};
	const users = new Map();
	const signals = new Map();
	let loadingUsers = null;
	let timer = null;

	const paths = {
		money: '<path d="M4 7h16v10H4z"/><circle cx="12" cy="12" r="2.5"/><path d="M7 7a3 3 0 0 1-3 3M17 7a3 3 0 0 0 3 3M7 17a3 3 0 0 0-3-3M17 17a3 3 0 0 1 3-3"/>',
		mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
		clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		check: '<path d="m5 12 4 4L19 6"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
	};

	function text( value ) { return value == null ? '' : String( value ); }
	function key( value ) { return text( value ).trim().toLowerCase(); }
	function esc( value ) { const node = document.createElement( 'div' ); node.textContent = text( value ); return node.innerHTML; }
	function svg( name ) { return '<svg class="afc-v262-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ( paths[ name ] || paths.info ) + '</svg>'; }
	function parseUser( row ) { try { return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) ); } catch ( error ) { return null; } }
	function remember( user ) { if ( user && user.name ) users.set( key( user.name ), user ); }

	function accounts() {
		const out = new Set();
		document.querySelectorAll( '[data-afc-dashboard-payment-account]' ).forEach( function ( node ) {
			const account = node.getAttribute( 'data-afc-dashboard-payment-account' );
			if ( account ) out.add( account );
		} );
		return Array.from( out );
	}

	function collectTable() {
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) { remember( parseUser( row ) ); } );
	}

	function ensureUsers() {
		collectTable();
		if ( accounts().every( function ( account ) { return users.has( key( account ) ); } ) ) return Promise.resolve();
		if ( loadingUsers ) return loadingUsers;
		if ( ! window.afcPPP || ! afcPPP.ajaxUrl || ! afcPPP.nonce ) return Promise.resolve();
		loadingUsers = new Promise( function ( resolve ) {
			$.post( afcPPP.ajaxUrl, { action: 'afc_get_ppp_users', nonce: afcPPP.nonce } )
				.done( function ( response ) { if ( response && response.success ) ( response.data.users || [] ).forEach( remember ); } )
				.always( resolve );
		} );
		return loadingUsers;
	}

	function payload( user ) {
		return {
			account: user.name || '', customer_name: user.customer_name || '', phone: user.phone || '',
			comment: user.comment || '', installed: user.installed || '', payment_date: user.payment_date || '',
			actual_profile: user.actual_profile || '', profile: user.profile || '',
		};
	}

	function loadSignals() {
		return ensureUsers().then( function () {
			const list = accounts().map( function ( account ) { return users.get( key( account ) ); } ).filter( Boolean );
			if ( ! list.length || ! cfg.ajaxUrl || ! cfg.nonce ) return;
			const body = new URLSearchParams();
			body.set( 'action', 'afc_customer_search_signals_v2' );
			body.set( 'nonce', cfg.nonce );
			body.set( 'users', JSON.stringify( list.map( payload ) ) );
			return fetch( cfg.ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString(),
			} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( ! response || ! response.success ) return;
				Object.keys( response.data.signals || {} ).forEach( function ( account ) { signals.set( key( account ), response.data.signals[ account ] ); } );
				render();
			} );
		} );
	}

	function dateLabel( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
		return match ? Number( match[ 2 ] ) + '-' + Number( match[ 3 ] ) : text( value );
	}

	function chip( tone, icon, label, title, pulse ) {
		return '<span class="afc-v262-chip is-' + tone + ( pulse ? ' is-pulse' : '' ) + '" title="' + esc( title ) + '">' + svg( icon ) + '<b>' + esc( label ) + '</b></span>';
	}

	function chips( state ) {
		if ( ! state ) return chip( 'loading', 'clock', 'STATUS', 'Loading account status', false );
		let html = '';
		if ( state.smsState === 'received' ) html += chip( 'message', 'mail', 'NEW', 'New customer SMS received', true );
		else if ( state.smsState === 'queued' || state.smsState === 'due' ) html += chip( 'warning', 'mail', 'TODAY', 'SMS due or queued today', false );
		else if ( state.smsState === 'scheduled' ) html += chip( 'warning', 'mail', state.smsLabel || 'SET', 'SMS scheduled', false );
		else if ( state.smsState === 'sent' ) html += chip( 'success', 'mail', state.smsLabel || 'SENT', 'SMS sent', false );

		if ( state.dueState === 'expired' ) html += chip( 'danger', 'clock', 'EXPIRED', 'Cutoff passed: ' + ( state.cutoffDate || '' ), false );
		else if ( state.dueState === 'due' ) html += chip( 'warning', 'clock', Number( state.daysToDue || 0 ) < 0 ? Math.abs( Number( state.daysToDue ) ) + 'D LATE' : 'DUE', 'Due: ' + ( state.nextDue || '' ), false );
		else if ( state.dueState === 'soon' || state.dueState === 'upcoming' ) {
			const days = state.daysToDue == null ? state.daysToCutoff : state.daysToDue;
			html += chip( 'warning', 'clock', Number( days ) <= 1 ? 'DUE' : Number( days ) + 'D', 'Coming due: ' + ( state.nextDue || state.cutoffDate || '' ), false );
		} else if ( state.dueState === 'safe' ) html += chip( 'success', 'clock', dateLabel( state.nextDue || state.cutoffDate ) || 'OK', 'Not near due', false );

		if ( state.paidRecent ) html += chip( 'success', 'money', dateLabel( state.paymentDate ) || 'PAID', 'Recently paid', false );
		if ( state.newInstall ) html += chip( 'info', 'check', 'NEW', 'New installation', false );
		return html || chip( 'neutral', 'info', 'ACTIVE', 'No alert', false );
	}

	function render() {
		document.querySelectorAll( '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
			result.setAttribute( 'data-afc-no-auto-icon', '' );
			result.querySelectorAll( ':scope > .afc-ui-icon, :scope > .afc-dashboard-signal-row' ).forEach( function ( node ) { node.remove(); } );
			const copy = result.querySelector( '.afc-dashboard-payment-result-copy' );
			if ( copy ) {
				let row = copy.querySelector( '.afc-v262-status' );
				if ( ! row ) { row = document.createElement( 'span' ); row.className = 'afc-v262-status'; copy.appendChild( row ); }
				row.innerHTML = chips( signals.get( key( account ) ) );
			}
			const action = result.querySelector( '.afc-dashboard-payment-result-action' );
			if ( action ) action.innerHTML = svg( 'money' ) + '<b>Record</b>';
		} );
	}

	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( function () { render(); loadSignals(); }, 70 );
	}

	function addStyles() {
		if ( document.getElementById( 'afc-v262-icon-fix' ) ) return;
		const style = document.createElement( 'style' );
		style.id = 'afc-v262-icon-fix';
		style.textContent = '.afc-v262-status{display:flex;align-items:center;flex-wrap:wrap;gap:5px;min-height:23px;margin-top:7px}.afc-v262-chip{display:inline-flex!important;align-items:center;justify-content:center;gap:4px;height:23px;padding:0 7px;border:1px solid #dce3eb;border-radius:999px;background:#f3f5f7;color:#5f6d7d;font-size:9px;font-weight:900;line-height:1;letter-spacing:.035em;white-space:nowrap;visibility:visible!important;opacity:1!important}.afc-v262-chip b{font:inherit}.afc-v262-icon{display:block!important;width:13px!important;height:13px!important;flex:0 0 13px;fill:none!important;stroke:currentColor!important;visibility:visible!important;opacity:1!important}.afc-v262-chip.is-danger{border-color:#efbcbc;background:#fde7e7;color:#b4232f}.afc-v262-chip.is-warning{border-color:#f0d096;background:#fff0c8;color:#8f5b00}.afc-v262-chip.is-success{border-color:#c4e5ce;background:#e6f7eb;color:#22773b}.afc-v262-chip.is-message{border-color:#d5c3f4;background:#f1e9ff;color:#6840ad}.afc-v262-chip.is-info{border-color:#c6dcf2;background:#e9f4ff;color:#246da8}.afc-v262-chip.is-loading{color:#6d7b8c}.afc-v262-chip.is-pulse{animation:afcV262Pulse 1.5s ease-in-out infinite}.afc-dashboard-payment-result-action .afc-v262-icon{width:14px!important;height:14px!important}.afc-dashboard-payment-result-action{display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:6px!important}.afc-dashboard-payment-result-action b{font:inherit}@keyframes afcV262Pulse{50%{box-shadow:0 0 0 6px rgba(104,64,173,.15)}}';
		document.head.appendChild( style );
	}

	function boot() {
		addStyles();
		collectTable();
		new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
		schedule();
		setInterval( function () { signals.clear(); loadingUsers = null; loadSignals(); }, 60000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
