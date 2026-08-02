( function () {
	'use strict';

	const config = window.afcSmsWebReplies || {};
	const drafts = new Map();
	let activeContext = null;
	let activeKey = '';
	let sending = false;

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

		const parts = metaNode.textContent.split( '·' ).map( function ( part ) {
			return part.trim();
		} ).filter( Boolean );
		const rawPhone = parts[ 0 ] || '';
		const phone = normalizePhone( rawPhone );
		const ppp = parts.length > 1 ? parts.slice( 1 ).join( ' · ' ) : '';

		return {
			name: name,
			phone: phone,
			rawPhone: rawPhone,
			ppp: ppp,
			valid: !! phone,
			key: ( phone || 'sender:' + rawPhone.toLowerCase() ) + '|' + ppp.toLowerCase(),
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
		const main = byId( 'afc-sms-chat-main' );
		const timeline = byId( 'afc-sms-chat-timeline' );
		if ( ! main || ! timeline ) return false;

		const oldButton = byId( 'afc-sms-reply-button' );
		if ( oldButton ) oldButton.remove();

		if ( ! byId( 'afc-sms-inline-reply' ) ) {
			const composer = document.createElement( 'section' );
			composer.id = 'afc-sms-inline-reply';
			composer.className = 'afc-sms-inline-reply';
			composer.hidden = true;
			composer.setAttribute( 'aria-hidden', 'true' );
			composer.innerHTML =
				'<div class="afc-sms-inline-reply-bar">' +
					'<textarea id="afc-sms-reply-message" rows="1" maxlength="1200" aria-label="Type an SMS message" placeholder="Type a message..."></textarea>' +
					'<button type="button" id="afc-sms-reply-send" class="afc-sms-reply-send" aria-label="Send message" title="Send message">' +
						'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.4 20.2 21 12 3.4 3.8l-.1 6.4 11.3 1.8-11.3 1.8.1 6.4Z"></path></svg>' +
					'</button>' +
				'</div>' +
				'<div class="afc-sms-inline-reply-status">' +
					'<span id="afc-sms-reply-hint">Enter to send · Shift + Enter for a new line</span>' +
					'<span id="afc-sms-reply-count">0/1200</span>' +
				'</div>';
			main.insertBefore( composer, timeline.nextSibling );
		}
		return true;
	}

	function messageNode() {
		return byId( 'afc-sms-reply-message' );
	}

	function saveCurrentDraft() {
		const message = messageNode();
		if ( ! activeKey || ! message ) return;
		if ( message.value ) drafts.set( activeKey, message.value );
		else drafts.delete( activeKey );
	}

	function autoResize() {
		const message = messageNode();
		if ( ! message ) return;
		message.style.height = 'auto';
		message.style.height = Math.min( Math.max( message.scrollHeight, 42 ), 120 ) + 'px';
	}

	function updateComposerState() {
		const composer = byId( 'afc-sms-inline-reply' );
		const message = messageNode();
		const send = byId( 'afc-sms-reply-send' );
		const count = byId( 'afc-sms-reply-count' );
		const hint = byId( 'afc-sms-reply-hint' );
		if ( ! composer || ! message || ! send ) return;

		if ( ! activeContext ) {
			composer.hidden = true;
			composer.setAttribute( 'aria-hidden', 'true' );
			return;
		}

		composer.hidden = false;
		composer.setAttribute( 'aria-hidden', 'false' );
		composer.classList.toggle( 'is-disabled', ! activeContext.valid );
		message.disabled = ! activeContext.valid || sending;
		message.placeholder = activeContext.valid ? 'Type a message...' : 'Replies are unavailable for this sender.';
		if ( hint ) {
			hint.textContent = activeContext.valid
				? 'Enter to send · Shift + Enter for a new line'
				: 'This conversation does not have a reply-capable mobile number.';
		}
		if ( count ) count.textContent = message.value.length + '/1200';
		send.disabled = sending || ! activeContext.valid || ! message.value.trim();
		autoResize();
	}

	function switchConversation() {
		if ( ! injectInterface() ) return;
		const next = conversationContext();
		const nextKey = next ? next.key : '';
		if ( nextKey === activeKey ) {
			activeContext = next;
			updateComposerState();
			return;
		}

		saveCurrentDraft();
		activeContext = next;
		activeKey = nextKey;
		const message = messageNode();
		if ( message ) message.value = nextKey && drafts.has( nextKey ) ? drafts.get( nextKey ) : '';
		updateComposerState();

		if ( next && next.valid && window.matchMedia && window.matchMedia( '(min-width: 768px)' ).matches ) {
			window.setTimeout( function () {
				if ( message && ! message.disabled ) message.focus();
			}, 70 );
		}
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
		const message = messageNode();
		const current = conversationContext();
		const body = message ? message.value.trim() : '';

		if ( sending ) return;
		if ( ! activeContext || ! current || current.key !== activeKey || current.key !== activeContext.key ) {
			switchConversation();
			showNotice( 'The selected conversation changed. Please type the message again.', 'warning' );
			return;
		}
		if ( ! activeContext.valid ) {
			showNotice( 'This conversation does not have a valid Philippine mobile number.', 'warning' );
			return;
		}
		if ( ! body ) {
			if ( message ) message.focus();
			return;
		}

		sending = true;
		updateComposerState();
		ajax( {
			phone: activeContext.phone,
			ppp_username: activeContext.ppp,
			customer_name: activeContext.name,
			message: body,
		} ).then( function ( response ) {
			optimisticBubble( response.job || {}, body );
			drafts.delete( activeKey );
			if ( message ) message.value = '';
			showNotice( response.message || 'Message queued for the Android gateway.', 'success' );
		} ).catch( function ( error ) {
			showNotice( error.message, 'danger' );
		} ).finally( function () {
			sending = false;
			updateComposerState();
			if ( message && ! message.disabled ) message.focus();
		} );
	}

	function bind() {
		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '#afc-sms-reply-send' ) ) sendReply();
		} );

		document.addEventListener( 'input', function ( event ) {
			if ( ! event.target || event.target.id !== 'afc-sms-reply-message' ) return;
			if ( activeKey ) {
				if ( event.target.value ) drafts.set( activeKey, event.target.value );
				else drafts.delete( activeKey );
			}
			updateComposerState();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( ! event.target || event.target.id !== 'afc-sms-reply-message' ) return;
			if ( event.isComposing || event.key !== 'Enter' || event.shiftKey ) return;
			event.preventDefault();
			sendReply();
		} );
	}

	function boot() {
		if ( ! injectInterface() ) return;
		bind();
		switchConversation();
		const name = byId( 'afc-sms-chat-name' );
		const meta = byId( 'afc-sms-chat-meta' );
		if ( window.MutationObserver && name && meta ) {
			const observer = new MutationObserver( switchConversation );
			observer.observe( name, { childList: true, characterData: true, subtree: true } );
			observer.observe( meta, { childList: true, characterData: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
} )();
