( function () {
	'use strict';

	const labels = {
		queued: 'Queued and waiting for the Android gateway',
		claimed: 'Claimed by the Android gateway',
		submitted: 'Submitted to Android telephony',
		sent: 'Accepted by the mobile carrier',
		delivered: 'Delivered to the recipient',
		failed: 'Message failed',
		cancelled: 'Message cancelled',
	};

	const glyphs = {
		queued: '!',
		claimed: '✓',
		submitted: '✓',
		sent: '✓',
		delivered: '✓✓',
		failed: '!',
		cancelled: '×',
	};

	function normalizeStatus( value ) {
		return String( value || '' ).trim().toLowerCase().replace( /\s+/g, '-' );
	}

	function decorateTimeline() {
		const timeline = document.getElementById( 'afc-sms-chat-timeline' );
		if ( ! timeline ) return;

		timeline.querySelectorAll( '.afc-sms-message-row.is-outgoing' ).forEach( function ( row ) {
			const footer = row.querySelector( '.afc-sms-message-footer' );
			const badge = footer && footer.querySelector( '.badge' );
			if ( ! badge ) return;

			const status = normalizeStatus( badge.dataset.afcSmsOriginalStatus || badge.textContent );
			if ( ! status ) return;
			badge.dataset.afcSmsOriginalStatus = status;
			badge.className = 'afc-sms-compact-status afc-sms-status-' + status;
			badge.textContent = glyphs[ status ] || '!';

			const detail = row.querySelector( '.afc-sms-message-detail' );
			const time = footer.querySelector( 'span:not(.afc-sms-compact-status)' );
			const parts = [ labels[ status ] || status.replace( /-/g, ' ' ) ];
			if ( detail && detail.textContent.trim() ) parts.push( detail.textContent.trim() );
			if ( time && time.textContent.trim() ) parts.push( time.textContent.trim() );
			badge.title = parts.join( ' · ' );
			badge.setAttribute( 'aria-label', parts.join( '. ' ) );
		} );
	}

	function updateSendLoading() {
		const send = document.getElementById( 'afc-sms-reply-send' );
		const message = document.getElementById( 'afc-sms-reply-message' );
		if ( ! send || ! message ) return;
		const explicitlyBusy = send.getAttribute( 'aria-busy' ) === 'true';
		const loading = explicitlyBusy || ( send.disabled && message.disabled && !! message.value.trim() );
		send.classList.toggle( 'is-loading', loading );
		send.setAttribute( 'aria-busy', loading ? 'true' : 'false' );
	}

	function boot() {
		decorateTimeline();
		updateSendLoading();

		const timeline = document.getElementById( 'afc-sms-chat-timeline' );
		if ( window.MutationObserver && timeline ) {
			new MutationObserver( decorateTimeline ).observe( timeline, {
				childList: true,
				subtree: true,
				characterData: true,
			} );
		}

		const root = document.getElementById( 'afc-sms-chat-main' );
		if ( window.MutationObserver && root ) {
			new MutationObserver( function () {
				updateSendLoading();
				decorateTimeline();
			} ).observe( root, {
				childList: true,
				subtree: true,
				attributes: true,
				attributeFilter: [ 'disabled' ],
			} );
		}

		document.addEventListener( 'input', function ( event ) {
			if ( event.target && event.target.id === 'afc-sms-reply-message' ) updateSendLoading();
		} );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
} )();
