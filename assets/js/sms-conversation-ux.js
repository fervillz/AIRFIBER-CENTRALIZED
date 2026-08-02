( function () {
	'use strict';

	const config = window.afcSmsCenter || {};
	const historyKey = 'afcSmsConversationReplyHistoryV1';
	const serverStates = new Map();
	const pendingReplies = new Map();
	let replyHistory = readHistory();
	let pendingAttempt = null;
	let listObserver = null;
	let noticeObserver = null;
	let applyQueued = false;
	let lastStateAt = 0;
	let stateLoading = false;

	function byId( id ) {
		return document.getElementById( id );
	}

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function readHistory() {
		try {
			const stored = JSON.parse( window.localStorage.getItem( historyKey ) || '{}' );
			return stored && typeof stored === 'object' && ! Array.isArray( stored ) ? stored : {};
		} catch ( error ) {
			return {};
		}
	}

	function saveHistory() {
		const cutoff = Date.now() - ( 180 * 24 * 60 * 60 * 1000 );
		const compact = Object.entries( replyHistory )
			.filter( function ( entry ) { return Number( entry[ 1 ] ) >= cutoff; } )
			.sort( function ( a, b ) { return Number( b[ 1 ] ) - Number( a[ 1 ] ); } )
			.slice( 0, 100 );
		replyHistory = Object.fromEntries( compact );
		try {
			window.localStorage.setItem( historyKey, JSON.stringify( replyHistory ) );
		} catch ( error ) {
			// The server state still keeps the UX accurate when storage is unavailable.
		}
	}

	function phoneKey( value ) {
		const raw = text( value ).trim();
		const digits = raw.replace( /\D+/g, '' );
		if ( digits.length >= 10 ) return 'phone:' + digits.slice( -10 );
		if ( raw ) return 'sender:' + raw.toLowerCase().replace( /\s+/g, '' );
		return '';
	}

	function timestampValue( value ) {
		const raw = text( value ).trim();
		if ( ! raw ) return 0;
		if ( /^\d{10,13}$/.test( raw ) ) {
			const numeric = Number( raw );
			return raw.length === 10 ? numeric * 1000 : numeric;
		}
		const parsed = Date.parse( raw.replace( ' ', 'T' ) );
		return Number.isNaN( parsed ) ? 0 : parsed;
	}

	function updateLatest( key, type, at ) {
		if ( ! key || ! at ) return;
		const current = serverStates.get( key );
		if ( ! current || at >= current.lastAt ) {
			serverStates.set( key, { lastAt: at, lastType: type } );
		}
	}

	function consumeState( state ) {
		serverStates.clear();
		( state.jobs || [] ).forEach( function ( job ) {
			const key = phoneKey( job.phone ) || ( job.ppp_username ? 'ppp:' + text( job.ppp_username ).toLowerCase() : '' );
			updateLatest( key, 'outgoing', timestampValue( job.created_at ) );
		} );
		( state.replies || [] ).forEach( function ( reply ) {
			const key = phoneKey( reply.phone ) || ( reply.ppp_username ? 'ppp:' + text( reply.ppp_username ).toLowerCase() : '' );
			updateLatest( key, 'incoming', timestampValue( reply.received_at || reply.created_at ) );
		} );
		lastStateAt = Date.now();

		if ( pendingAttempt ) {
			const latest = serverStates.get( pendingAttempt.key );
			if ( latest && latest.lastType === 'outgoing' && latest.lastAt >= pendingAttempt.at - 5000 ) {
				commitPendingReply();
			}
		}
		queueApply();
	}

	function isStateRequest( input, init ) {
		const url = typeof input === 'string' ? input : ( input && input.url ? input.url : '' );
		const body = init && init.body != null ? text( init.body ) : '';
		return body.includes( 'action=afc_sms_get_state' ) || url.includes( 'action=afc_sms_get_state' );
	}

	function interceptStateRequests() {
		if ( ! window.fetch || window.fetch.afcConversationUxWrapped ) return;
		const nativeFetch = window.fetch.bind( window );
		const wrapped = function ( input, init ) {
			const stateRequest = isStateRequest( input, init );
			return nativeFetch( input, init ).then( function ( response ) {
				if ( stateRequest && response && response.clone ) {
					response.clone().json().then( function ( payload ) {
						if ( payload && payload.success && payload.data ) consumeState( payload.data );
					} ).catch( function () {} );
				}
				return response;
			} );
		};
		wrapped.afcConversationUxWrapped = true;
		window.fetch = wrapped;
	}

	function requestState() {
		if ( stateLoading || ! config.ajaxUrl || ! config.nonce ) return;
		stateLoading = true;
		const body = new URLSearchParams();
		body.set( 'action', 'afc_sms_get_state' );
		body.set( 'nonce', config.nonce );
		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).finally( function () {
			stateLoading = false;
		} );
	}

	function toneForKey( key ) {
		let hash = 0;
		for ( let index = 0; index < key.length; index += 1 ) hash = ( ( hash << 5 ) - hash + key.charCodeAt( index ) ) | 0;
		return Math.abs( hash ) % 8;
	}

	function rowKey( row ) {
		const select = row.querySelector( '[data-afc-sms-conversation]' );
		return select ? text( select.getAttribute( 'data-afc-sms-conversation' ) ) : '';
	}

	function effectiveState( key ) {
		const server = serverStates.get( key ) || { lastAt: 0, lastType: '' };
		const storedAt = Number( replyHistory[ key ] || 0 );
		const pendingAt = Number( pendingReplies.get( key ) || 0 );
		const localAt = Math.max( storedAt, pendingAt );
		const recipientReply = server.lastType === 'incoming' && server.lastAt > localAt;
		const replied = ! recipientReply && ( server.lastType === 'outgoing' || localAt > 0 );
		return {
			effectiveAt: Math.max( server.lastAt, localAt ),
			recipientReply: recipientReply,
			replied: replied,
		};
	}

	function ensureNameUi( row ) {
		const identity = row.querySelector( '.afc-sms-list-identity' );
		if ( ! identity ) return;
		let nameRow = identity.querySelector( '.afc-sms-list-name-row' );
		const name = identity.querySelector( ':scope > strong' );
		if ( ! nameRow && name ) {
			nameRow = document.createElement( 'span' );
			nameRow.className = 'afc-sms-list-name-row';
			identity.insertBefore( nameRow, name );
			nameRow.appendChild( name );
		}
		if ( nameRow && ! nameRow.querySelector( '.afc-sms-list-replied-icon' ) ) {
			const icon = document.createElement( 'span' );
			icon.className = 'afc-sms-list-replied-icon';
			icon.textContent = '✓';
			icon.title = 'You replied';
			icon.setAttribute( 'aria-label', 'You replied' );
			nameRow.appendChild( icon );
		}
	}

	function applyRows() {
		applyQueued = false;
		const list = byId( 'afc-sms-conversations' );
		if ( ! list ) return;
		const rows = Array.from( list.querySelectorAll( ':scope > .afc-sms-conversation-item' ) );
		if ( ! rows.length ) return;

		rows.forEach( function ( row, index ) {
			const key = rowKey( row );
			const state = effectiveState( key );
			const avatar = row.querySelector( '.afc-sms-list-avatar' );
			row.dataset.afcUxOriginalIndex = row.dataset.afcUxOriginalIndex || String( index );
			row.dataset.afcUxOrder = String( state.effectiveAt || 0 );
			row.classList.toggle( 'is-replied', state.replied );
			row.classList.toggle( 'has-recipient-reply', state.recipientReply );
			if ( avatar ) {
				for ( let tone = 0; tone < 8; tone += 1 ) avatar.classList.remove( 'is-tone-' + tone );
				avatar.classList.add( 'is-tone-' + toneForKey( key || avatar.textContent ) );
			}
			ensureNameUi( row );
		} );

		const sorted = rows.slice().sort( function ( a, b ) {
			const orderDifference = Number( b.dataset.afcUxOrder || 0 ) - Number( a.dataset.afcUxOrder || 0 );
			if ( orderDifference ) return orderDifference;
			return Number( a.dataset.afcUxOriginalIndex || 0 ) - Number( b.dataset.afcUxOriginalIndex || 0 );
		} );
		const changed = sorted.some( function ( row, index ) { return row !== rows[ index ]; } );
		if ( changed ) {
			const fragment = document.createDocumentFragment();
			sorted.forEach( function ( row ) { fragment.appendChild( row ); } );
			list.appendChild( fragment );
		}
	}

	function queueApply() {
		if ( applyQueued ) return;
		applyQueued = true;
		window.requestAnimationFrame( applyRows );
	}

	function activeConversationKey() {
		const active = document.querySelector( '#afc-sms-conversations .afc-sms-conversation-item.is-active [data-afc-sms-conversation]' );
		if ( active ) return text( active.getAttribute( 'data-afc-sms-conversation' ) );
		const meta = byId( 'afc-sms-chat-meta' );
		const first = meta ? text( meta.textContent ).split( '·' )[ 0 ].trim() : '';
		return phoneKey( first );
	}

	function beginReplyAttempt() {
		const message = byId( 'afc-sms-reply-message' );
		const key = activeConversationKey();
		if ( ! key || ! message || ! message.value.trim() ) return;
		const at = Date.now();
		pendingReplies.set( key, at );
		pendingAttempt = { key: key, at: at };
		queueApply();
	}

	function commitPendingReply() {
		if ( ! pendingAttempt ) return;
		replyHistory[ pendingAttempt.key ] = pendingAttempt.at;
		pendingReplies.delete( pendingAttempt.key );
		pendingAttempt = null;
		saveHistory();
		queueApply();
	}

	function rollbackPendingReply() {
		if ( ! pendingAttempt ) return;
		pendingReplies.delete( pendingAttempt.key );
		pendingAttempt = null;
		queueApply();
	}

	function bindSending() {
		document.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '#afc-sms-reply-send' );
			if ( button && ! button.disabled ) beginReplyAttempt();
		}, true );
		document.addEventListener( 'keydown', function ( event ) {
			if ( ! event.target || event.target.id !== 'afc-sms-reply-message' ) return;
			if ( event.isComposing || event.key !== 'Enter' || event.shiftKey ) return;
			beginReplyAttempt();
		}, true );
	}

	function observeNotice() {
		const notice = byId( 'afc-sms-notice' );
		if ( ! notice || noticeObserver ) return;
		noticeObserver = new MutationObserver( function () {
			if ( ! pendingAttempt ) return;
			if ( notice.querySelector( '.alert-danger' ) ) rollbackPendingReply();
			else if ( notice.querySelector( '.alert-success' ) ) commitPendingReply();
		} );
		noticeObserver.observe( notice, { childList: true, subtree: true } );
	}

	function observeList() {
		const list = byId( 'afc-sms-conversations' );
		if ( ! list || listObserver ) return;
		listObserver = new MutationObserver( queueApply );
		listObserver.observe( list, { childList: true, subtree: true } );
		queueApply();
	}

	function boot() {
		interceptStateRequests();
		bindSending();
		observeList();
		observeNotice();
		window.addEventListener( 'storage', function ( event ) {
			if ( event.key !== historyKey ) return;
			replyHistory = readHistory();
			queueApply();
		} );
		window.setTimeout( function () {
			if ( ! lastStateAt ) requestState();
		}, 1400 );
		window.setInterval( function () {
			if ( Date.now() - lastStateAt > 12000 ) requestState();
		}, 8000 );
	}

	interceptStateRequests();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
