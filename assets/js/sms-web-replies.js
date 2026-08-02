( function () {
	'use strict';

	const config = window.afcSmsWebReplies || {};
	let replyOpen = false;
	let replyContext = null;
	let observedKey = '';

	function byId( id ) {
		return document.getElementById( id );
	}

	function normalizePhone( value ) {
		const digits = String( value || '' ).replace( /\D+/g, '' );
		if ( digits.length === 11 && digits.indexOf( '09' ) === 0 ) return '+63' + digits.slice( 1 );
		if ( digits.length === 12 && digits.indexOf( '639' ) === 0 ) return '+' + digits;
		if ( digits.length === 10 && digits.indexOf( '9' ) === 0 ) return '+63' + digits;
		return '';
	}

	function conversationContext() {
		const nameNode = byId( 'afc-sms-chat-name' );
		const metaNode = byId( 'afc-sms-chat-meta' );
		if ( ! nameNode || ! metaNode ) return null;
		const name = nameNode.textContent.trim();
		if ( ! name || name === 'Select a conversation' ) return null;
		const parts = metaNode.textContent.split( '·' ).map( function ( part ) { return part.trim(); } ).filter( Boolean );
		const phone = normalizePhone( parts[ 0 ] || '' );
		if ( ! phone ) return null;
		return {
			name: name,
			phone: phone,
			ppp: parts.length > 1 ? parts.slice( 1 ).join( ' · ' ) : '',
			key: phone + '|' + ( parts.length > 1 ? parts.slice( 1 ).join( '|' ) : '' ),
		};
	}

	function showNotice( message, type ) {
		const target = byId( 'afc-sms-notice' );
		if ( ! target ) return;
		target.replaceChildren();
		if ( ! message ) return;
		const item = document.createElement( 'div' );
		item.className = 'alert alert-' + ( type || 'info' ) + ' py-2 mb-2';
		item.textContent = message;
		target.appendChild( item );
	}

	function ajax( data ) {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_sms_queue_reply' );
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
				throw new Error( 'Airfiber returned an invalid reply response.' );
			} );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'The SMS reply could not be queued.' );
			}
			return response.data || {};
		} );
	}

	function injectInterface() {
		const header = document.querySelector( '.afc-sms-chat-header' );
		const main = byId( 'afc-sms-chat-main' );
		const timeline = byId( 'afc-sms-chat-timeline' );
		if ( ! header || ! main || ! timeline ) return false;

		if ( ! byId( 'afc-sms-reply-button' ) ) {
			const button = document.createElement( 'button' );
			button.id = 'afc-sms-reply-button';
			button.type = 'button';
			button.className = 'btn btn-sm btn-success afc-sms-reply-button';
			button.textContent = 'Reply';
			button.hidden = true;
			const queueButton = byId( 'afc-sms-compose-selected' );
			header.insertBefore( button, queueButton || null );
		}

		if ( ! byId( 'afc-sms-inline-reply' ) ) {
			const composer = document.createElement( 'section' );
			composer.id = 'afc-sms-inline-reply';
			composer.className = 'afc-sms-inline-reply';
			composer.hidden = true;
			composer.setAttribute( 'aria-hidden', 'true' );
			composer.innerHTML =
				'<div class="afc-sms-inline-reply-top">' +
					'<div><small>Replying to</small><strong id="afc-sms-reply-recipient"></strong></div>' +
					'<button type="button" class="btn-close" id="afc-sms-reply-close" aria-label="Close reply"></button>' +
				'</div>' +
				'<textarea class="form-control" id="afc-sms-reply-message" rows="2" maxlength="1200" placeholder="Type a reply..."></textarea>' +
				'<div class="afc-sms-inline-reply-bottom">' +
					'<small><span id="afc-sms-reply-count">0</span>/1200 · Ctrl + Enter to send</small>' +
					'<div><button type="button" class="btn btn-sm btn-outline-secondary" id="afc-sms-reply-cancel">Cancel</button>' +
					'<button type="button" class="btn btn-sm btn-success" id="afc-sms-reply-send">Send Reply</button></div>' +
				'</div>';
			main.insertBefore( composer, timeline.nextSibling );
		}
		return true;
	}

	function updateCount() {
		const message = byId( 'afc-sms-reply-message' );
		const count = byId( 'afc-sms-reply-count' );
		if ( count ) count.textContent = String( message ? message.value.length : 0 );
	}

	function closeReply( clear ) {
		replyOpen = false;
		replyContext = null;
		const composer = byId( 'afc-sms-inline-reply' );
		if ( composer ) {
			composer.hidden = true;
			composer.setAttribute( 'aria-hidden', 'true' );
		}
		const main = byId( 'afc-sms-chat-main' );
		if ( main ) main.classList.remove( 'is-inline-reply-open' );
		if ( clear && byId( 'afc-sms-reply-message' ) ) byId( 'afc-sms-reply-message' ).value = '';
		updateCount();
	}

	function openReply() {
		const context = conversationContext();
		if ( ! context ) {
			showNotice( 'This conversation does not have a valid Philippine mobile number.', 'warning' );
			return;
		}
		replyOpen = true;
		replyContext = context;
		const composer = byId( 'afc-sms-inline-reply' );
		const main = byId( 'afc-sms-chat-main' );
		const recipient = byId( 'afc-sms-reply-recipient' );
		if ( recipient ) recipient.textContent = context.name + ' · ' + context.phone;
		if ( composer ) {
			composer.hidden = false;
			composer.setAttribute( 'aria-hidden', 'false' );
		}
		if ( main ) main.classList.add( 'is-inline-reply-open' );
		window.setTimeout( function () {
			const message = byId( 'afc-sms-reply-message' );
			if ( message ) message.focus();
		}, 40 );
	}

	function optimisticBubble( job, fallbackMessage ) {
		const timeline = byId( 'afc-sms-chat-timeline' );
		if ( ! timeline ) return;
		const row = document.createElement( 'div' );
		row.className = 'afc-sms-message-row is-outgoing afc-sms-web-reply-pending';
		const bubble = document.createElement( 'div' );
		bubble.className = 'afc-sms-message-bubble';
		const body = document.createElement( 'div' );
		body.className = 'afc-sms-message-body';
		body.textContent = job && job.message ? job.message : fallbackMessage;
		const footer = document.createElement( 'div' );
		footer.className = 'afc-sms-message-footer';
		const badge = document.createElement( 'span' );
		badge.className = 'badge bg-yellow-lt text-yellow';
		badge.textContent = 'queued';
		const time = document.createElement( 'span' );
		time.textContent = new Date().toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
		footer.append( badge, time );
		bubble.append( body, footer );
		row.appendChild( bubble );
		timeline.appendChild( row );
		timeline.scrollTop = timeline.scrollHeight;
	}

	function sendReply() {
		const messageNode = byId( 'afc-sms-reply-message' );
		const send = byId( 'afc-sms-reply-send' );
		const current = conversationContext();
		const message = messageNode ? messageNode.value.trim() : '';
		if ( ! replyContext || ! current || current.key !== replyContext.key ) {
			closeReply( true );
			showNotice( 'The selected conversation changed. Open Reply again.', 'warning' );
			return;
		}
		if ( ! message ) {
			showNotice( 'Type a reply first.', 'warning' );
			if ( messageNode ) messageNode.focus();
			return;
		}

		if ( send ) {
			send.disabled = true;
			send.dataset.originalText = send.textContent;
			send.textContent = 'Queueing…';
		}
		ajax( {
			phone: replyContext.phone,
			ppp_username: replyContext.ppp,
			customer_name: replyContext.name,
			message: message,
		} ).then( function ( response ) {
			optimisticBubble( response.job || {}, message );
			showNotice( response.message || 'Reply queued for the Android gateway.', 'success' );
			closeReply( true );
		} ).catch( function ( error ) {
			showNotice( error.message, 'danger' );
		} ).finally( function () {
			if ( send ) {
				send.disabled = false;
				send.textContent = send.dataset.originalText || 'Send Reply';
			}
		} );
	}

	function updateAvailability() {
		if ( ! injectInterface() ) return;
		const context = conversationContext();
		const button = byId( 'afc-sms-reply-button' );
		const key = context ? context.key : '';
		if ( button ) button.hidden = ! context;
		if ( replyOpen && observedKey && key !== observedKey ) closeReply( true );
		observedKey = key;
	}

	function bind() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-sms-reply-button' ) ) openReply();
			if ( event.target.closest( '#afc-sms-reply-close, #afc-sms-reply-cancel' ) ) closeReply( false );
			if ( event.target.closest( '#afc-sms-reply-send' ) ) sendReply();
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.id === 'afc-sms-reply-message' ) updateCount();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && replyOpen ) {
				closeReply( false );
				return;
			}
			if ( event.target && event.target.id === 'afc-sms-reply-message' && event.key === 'Enter' && ( event.ctrlKey || event.metaKey ) ) {
				event.preventDefault();
				sendReply();
			}
		} );
	}

	function boot() {
		if ( ! injectInterface() ) return;
		bind();
		updateAvailability();
		const name = byId( 'afc-sms-chat-name' );
		const meta = byId( 'afc-sms-chat-meta' );
		if ( window.MutationObserver && name && meta ) {
			const observer = new MutationObserver( updateAvailability );
			observer.observe( name, { childList: true, characterData: true, subtree: true } );
			observer.observe( meta, { childList: true, characterData: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
} )();
