( function () {
	'use strict';

	const config = window.afcSmsCenter || {};
	let candidates = [];
	let selected = null;
	let stateTimer = null;
	let stateLoading = false;
	let latestState = { jobs: [], events: [], replies: [], counts: {}, device: {} };
	let conversations = [];
	let activeConversationKey = '';
	let drawerView = 'menu';

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
		if ( ! target ) return;
		target.replaceChildren();
		if ( ! message ) return;
		const item = document.createElement( 'div' );
		item.className = 'alert alert-' + ( type || 'info' );
		item.textContent = message;
		target.appendChild( item );
	}

	function setBusy( element, busy, busyText ) {
		if ( ! element ) return;
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
		if ( state ) state.replaceChildren( stateBadge( device.state || 'not-configured' ) );
		if ( byId( 'afc-sms-last-seen' ) ) byId( 'afc-sms-last-seen' ).textContent = text( device.last_seen, 'Never' );
		if ( byId( 'afc-sms-device-id' ) ) byId( 'afc-sms-device-id' ).textContent = text( device.device_id, 'Not connected' );
		if ( byId( 'afc-sms-device-detail' ) ) byId( 'afc-sms-device-detail' ).textContent = text( device.detail );
		if ( byId( 'afc-sms-token-hint' ) ) byId( 'afc-sms-token-hint' ).textContent = text( device.token_hint, 'None' );
		const generate = byId( 'afc-sms-generate-token' );
		if ( generate && ! generate.disabled ) generate.textContent = device.exists ? 'Rotate Device Token' : 'Generate Device Token';
	}

	function renderCounts( counts ) {
		counts = counts || {};
		document.querySelectorAll( '[data-afc-sms-count]' ).forEach( function ( target ) {
			const key = target.getAttribute( 'data-afc-sms-count' );
			target.textContent = String( counts[ key ] || 0 );
		} );
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

	function formatTime( value, includeDate ) {
		const timestamp = timestampValue( value );
		if ( ! timestamp ) return text( value );
		const date = new Date( timestamp );
		const now = new Date();
		const sameDay = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();
		if ( includeDate || ! sameDay ) return date.toLocaleString( [], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
		return date.toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
	}

	function initials( value ) {
		const words = text( value, 'A' ).trim().split( /\s+/ ).filter( Boolean );
		if ( ! words.length ) return 'A';
		return words.slice( 0, 2 ).map( function ( word ) { return word.charAt( 0 ).toUpperCase(); } ).join( '' );
	}

	function eventKey( event ) {
		return [ event.job_id, event.status, event.detail ].join( '|' ).toLowerCase();
	}

	function getOrCreateConversation( map, key, source ) {
		if ( ! key ) key = source && source.ppp_username ? 'ppp:' + source.ppp_username.toLowerCase() : 'unknown';
		if ( ! map.has( key ) ) {
			map.set( key, { key: key, name: '', ppp: '', phone: '', jobs: [], replies: [], events: [], messages: [], lastAt: 0, lastExcerpt: '' } );
		}
		const conversation = map.get( key );
		if ( source ) {
			conversation.name = conversation.name || text( source.customer_name );
			conversation.ppp = conversation.ppp || text( source.ppp_username );
			conversation.phone = conversation.phone || text( source.phone );
		}
		return conversation;
	}

	function buildConversations( state ) {
		const map = new Map();
		const jobsById = new Map();
		const seenEvents = new Set();
		( state.jobs || [] ).forEach( function ( job ) {
			const key = phoneKey( job.phone ) || ( job.ppp_username ? 'ppp:' + job.ppp_username.toLowerCase() : '' );
			const conversation = getOrCreateConversation( map, key, job );
			conversation.jobs.push( job );
			conversation.messages.push( { type: 'outgoing', job: job, body: text( job.message ), at: text( job.created_at ), timestamp: timestampValue( job.created_at ) } );
			jobsById.set( String( job.id ), conversation );
		} );
		( state.events || [] ).forEach( function ( event ) {
			const conversation = jobsById.get( String( event.job_id ) );
			if ( ! conversation ) return;
			const unique = eventKey( event );
			if ( seenEvents.has( unique ) ) return;
			seenEvents.add( unique );
			conversation.events.push( event );
		} );
		( state.replies || [] ).forEach( function ( reply ) {
			const key = phoneKey( reply.phone );
			const conversation = getOrCreateConversation( map, key, reply );
			conversation.phone = conversation.phone || text( reply.phone );
			conversation.replies.push( reply );
			conversation.messages.push( { type: 'incoming', reply: reply, body: text( reply.message ), at: text( reply.received_at || reply.created_at ), timestamp: timestampValue( reply.received_at || reply.created_at ) } );
		} );
		const result = Array.from( map.values() );
		result.forEach( function ( conversation ) {
			conversation.name = conversation.name || conversation.phone || conversation.ppp || 'Unknown sender';
			conversation.messages.sort( function ( a, b ) { return a.timestamp - b.timestamp; } );
			conversation.jobs.sort( function ( a, b ) { return timestampValue( b.created_at ) - timestampValue( a.created_at ); } );
			conversation.events.sort( function ( a, b ) { return timestampValue( a.created_at ) - timestampValue( b.created_at ); } );
			const lastMessage = conversation.messages[ conversation.messages.length - 1 ];
			conversation.lastAt = lastMessage ? lastMessage.timestamp : 0;
			conversation.lastExcerpt = lastMessage ? text( lastMessage.body ) : 'No message content';
		} );
		result.sort( function ( a, b ) { return b.lastAt - a.lastAt; } );
		return result;
	}

	function activeConversation() {
		return conversations.find( function ( item ) { return item.key === activeConversationKey; } ) || null;
	}

	function selectConversation( key, closeOptions ) {
		activeConversationKey = key;
		renderConversationList();
		renderConversation();
		if ( closeOptions ) closeDrawer();
		else if ( byId( 'afc-sms-chat-layout' ) && byId( 'afc-sms-chat-layout' ).classList.contains( 'is-drawer-open' ) ) renderDrawer();
	}

	function renderConversationList() {
		const target = byId( 'afc-sms-conversations' );
		if ( ! target ) return;
		const search = text( byId( 'afc-sms-conversation-search' ) && byId( 'afc-sms-conversation-search' ).value ).toLowerCase();
		const filtered = conversations.filter( function ( conversation ) {
			const haystack = [ conversation.name, conversation.ppp, conversation.phone, conversation.lastExcerpt ].join( ' ' ).toLowerCase();
			return ! search || haystack.includes( search );
		} );
		const count = byId( 'afc-sms-conversation-count' );
		if ( count ) count.textContent = conversations.length + ( conversations.length === 1 ? ' conversation' : ' conversations' );
		target.replaceChildren();
		if ( ! filtered.length ) {
			const empty = document.createElement( 'div' );
			empty.className = 'afc-sms-chat-empty';
			empty.textContent = conversations.length ? 'No conversation matches your search.' : 'No messages yet.';
			target.appendChild( empty );
			return;
		}
		filtered.forEach( function ( conversation ) {
			const item = document.createElement( 'div' );
			item.className = 'afc-sms-conversation-item';
			if ( conversation.key === activeConversationKey ) item.classList.add( 'is-active' );
			const select = document.createElement( 'button' );
			select.type = 'button';
			select.className = 'afc-sms-conversation-select';
			select.setAttribute( 'data-afc-sms-conversation', conversation.key );
			const avatar = document.createElement( 'span' );
			avatar.className = 'afc-sms-list-avatar';
			avatar.textContent = initials( conversation.name );
			const main = document.createElement( 'span' );
			main.className = 'afc-sms-list-main';
			const name = document.createElement( 'strong' );
			name.textContent = conversation.name;
			const excerpt = document.createElement( 'span' );
			excerpt.textContent = conversation.lastExcerpt;
			main.append( name, excerpt );
			const meta = document.createElement( 'span' );
			meta.className = 'afc-sms-list-meta';
			meta.textContent = formatTime( conversation.lastAt );
			select.append( avatar, main, meta );
			const menu = document.createElement( 'button' );
			menu.type = 'button';
			menu.className = 'afc-sms-conversation-menu';
			menu.setAttribute( 'data-afc-sms-conversation-menu', conversation.key );
			menu.setAttribute( 'aria-label', 'Open options for ' + conversation.name );
			menu.textContent = '⋮';
			item.append( select, menu );
			target.appendChild( item );
		} );
	}

	function appendEmpty( target, message ) {
		const empty = document.createElement( 'div' );
		empty.className = 'afc-sms-chat-empty';
		empty.textContent = message;
		target.appendChild( empty );
	}

	function renderOutgoingMessage( target, message, conversation ) {
		const job = message.job || {};
		const row = document.createElement( 'div' );
		row.className = 'afc-sms-message-row is-outgoing';
		const bubble = document.createElement( 'div' );
		bubble.className = 'afc-sms-message-bubble';
		const body = document.createElement( 'div' );
		body.className = 'afc-sms-message-body';
		body.textContent = text( message.body );
		const footer = document.createElement( 'div' );
		footer.className = 'afc-sms-message-footer';
		footer.append( stateBadge( job.status ) );
		const time = document.createElement( 'span' );
		time.textContent = formatTime( message.at, true );
		footer.appendChild( time );
		bubble.append( body, footer );
		if ( job.last_detail ) {
			const detail = document.createElement( 'div' );
			detail.className = 'afc-sms-message-detail';
			detail.textContent = job.last_detail;
			bubble.appendChild( detail );
		}
		row.appendChild( bubble );
		target.appendChild( row );
		conversation.events.filter( function ( event ) { return String( event.job_id ) === String( job.id ); } ).forEach( function ( event ) {
			const status = document.createElement( 'div' );
			status.className = 'afc-sms-status-line';
			status.appendChild( stateBadge( event.status ) );
			const detail = document.createElement( 'span' );
			detail.textContent = text( event.detail );
			const eventTime = document.createElement( 'small' );
			eventTime.textContent = formatTime( event.created_at, true );
			status.append( detail, eventTime );
			target.appendChild( status );
		} );
	}

	function renderIncomingMessage( target, message ) {
		const row = document.createElement( 'div' );
		row.className = 'afc-sms-message-row is-incoming';
		const bubble = document.createElement( 'div' );
		bubble.className = 'afc-sms-message-bubble';
		const body = document.createElement( 'div' );
		body.className = 'afc-sms-message-body';
		body.textContent = text( message.body );
		const footer = document.createElement( 'div' );
		footer.className = 'afc-sms-message-footer';
		const label = document.createElement( 'span' );
		label.textContent = 'Reply';
		const time = document.createElement( 'span' );
		time.textContent = formatTime( message.at, true );
		footer.append( label, time );
		bubble.append( body, footer );
		row.appendChild( bubble );
		target.appendChild( row );
	}

	function renderConversation() {
		const conversation = activeConversation();
		const timeline = byId( 'afc-sms-chat-timeline' );
		if ( ! timeline ) return;
		timeline.replaceChildren();
		if ( ! conversation ) {
			if ( byId( 'afc-sms-chat-name' ) ) byId( 'afc-sms-chat-name' ).textContent = 'Select a conversation';
			if ( byId( 'afc-sms-chat-meta' ) ) byId( 'afc-sms-chat-meta' ).textContent = 'Delivery updates and replies will appear here.';
			if ( byId( 'afc-sms-chat-avatar' ) ) byId( 'afc-sms-chat-avatar' ).textContent = 'A';
			appendEmpty( timeline, 'Choose a customer from the list to open the conversation.' );
			return;
		}
		if ( byId( 'afc-sms-chat-name' ) ) byId( 'afc-sms-chat-name' ).textContent = conversation.name;
		if ( byId( 'afc-sms-chat-meta' ) ) byId( 'afc-sms-chat-meta' ).textContent = [ conversation.ppp, conversation.phone ].filter( Boolean ).join( ' · ' ) || 'SMS conversation';
		if ( byId( 'afc-sms-chat-avatar' ) ) byId( 'afc-sms-chat-avatar' ).textContent = initials( conversation.name );
		if ( ! conversation.messages.length ) {
			appendEmpty( timeline, 'No messages in this conversation yet.' );
			return;
		}
		conversation.messages.forEach( function ( message ) {
			if ( message.type === 'incoming' ) renderIncomingMessage( timeline, message );
			else renderOutgoingMessage( timeline, message, conversation );
		} );
		timeline.scrollTop = timeline.scrollHeight;
	}

	function openDrawer( conversation, view ) {
		if ( ! conversation ) return;
		activeConversationKey = conversation.key;
		drawerView = view || 'menu';
		const layout = byId( 'afc-sms-chat-layout' );
		const drawer = byId( 'afc-sms-chat-drawer' );
		if ( layout ) layout.classList.add( 'is-drawer-open' );
		if ( drawer ) drawer.setAttribute( 'aria-hidden', 'false' );
		if ( byId( 'afc-sms-close-drawer' ) ) byId( 'afc-sms-close-drawer' ).hidden = false;
		renderConversationList();
		renderConversation();
		renderDrawer();
	}

	function closeDrawer() {
		const layout = byId( 'afc-sms-chat-layout' );
		const drawer = byId( 'afc-sms-chat-drawer' );
		if ( layout ) layout.classList.remove( 'is-drawer-open' );
		if ( drawer ) drawer.setAttribute( 'aria-hidden', 'true' );
		if ( byId( 'afc-sms-close-drawer' ) ) byId( 'afc-sms-close-drawer' ).hidden = true;
		drawerView = 'menu';
	}

	function drawerBackButton() {
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-sms-drawer-back';
		button.setAttribute( 'data-afc-sms-drawer-view', 'menu' );
		button.textContent = '← Back to options';
		return button;
	}

	function renderDrawerMenu( target ) {
		const intro = document.createElement( 'p' );
		intro.className = 'text-secondary';
		intro.textContent = 'Choose what to show for this customer.';
		target.appendChild( intro );
		[
			[ 'conversation', 'Conversation', 'Return to the message and reply timeline.' ],
			[ 'delivery', 'Queue & Delivery', 'Show every queued SMS, status and delivery update for this customer.' ],
			[ 'details', 'Customer Details', 'Show the PPP username, phone and conversation totals.' ],
		].forEach( function ( option ) {
			const button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-sms-drawer-option';
			button.setAttribute( 'data-afc-sms-drawer-view', option[ 0 ] );
			const title = document.createElement( 'strong' );
			title.textContent = option[ 1 ];
			const description = document.createElement( 'span' );
			description.textContent = option[ 2 ];
			button.append( title, description );
			target.appendChild( button );
		} );
	}

	function renderDeliveryDrawer( target, conversation ) {
		target.appendChild( drawerBackButton() );
		const heading = document.createElement( 'h4' );
		heading.textContent = 'Queue & Delivery';
		target.appendChild( heading );
		if ( ! conversation.jobs.length ) {
			appendEmpty( target, 'No outgoing SMS jobs for this conversation.' );
			return;
		}
		conversation.jobs.forEach( function ( job ) {
			const card = document.createElement( 'article' );
			card.className = 'afc-sms-delivery-card';
			const top = document.createElement( 'div' );
			top.className = 'afc-sms-delivery-top';
			const id = document.createElement( 'strong' );
			id.textContent = 'Job #' + job.id;
			top.append( id, stateBadge( job.status ) );
			const message = document.createElement( 'p' );
			message.textContent = text( job.message );
			const meta = document.createElement( 'div' );
			meta.className = 'afc-sms-delivery-meta';
			meta.textContent = formatTime( job.created_at, true ) + ( job.device_id ? ' · ' + job.device_id : '' );
			card.append( top, message, meta );
			if ( job.last_detail ) {
				const detail = document.createElement( 'div' );
				detail.className = 'afc-sms-delivery-detail';
				detail.textContent = job.last_detail;
				card.appendChild( detail );
			}
			const matching = conversation.events.filter( function ( event ) { return String( event.job_id ) === String( job.id ); } );
			if ( matching.length ) {
				const history = document.createElement( 'div' );
				history.className = 'afc-sms-delivery-history';
				matching.forEach( function ( event ) {
					const line = document.createElement( 'div' );
					line.appendChild( stateBadge( event.status ) );
					const detail = document.createElement( 'span' );
					detail.textContent = text( event.detail );
					const time = document.createElement( 'small' );
					time.textContent = formatTime( event.created_at, true );
					line.append( detail, time );
					history.appendChild( line );
				} );
				card.appendChild( history );
			}
			if ( job.can_cancel ) {
				const cancel = document.createElement( 'button' );
				cancel.type = 'button';
				cancel.className = 'btn btn-sm btn-outline-danger mt-2';
				cancel.setAttribute( 'data-afc-cancel-job', job.id );
				cancel.textContent = 'Cancel queued SMS';
				card.appendChild( cancel );
			}
			target.appendChild( card );
		} );
	}

	function detailRow( label, value ) {
		const row = document.createElement( 'div' );
		row.className = 'afc-sms-detail-row';
		const name = document.createElement( 'span' );
		name.textContent = label;
		const content = document.createElement( 'strong' );
		content.textContent = text( value, '—' );
		row.append( name, content );
		return row;
	}

	function renderDetailsDrawer( target, conversation ) {
		target.appendChild( drawerBackButton() );
		const heading = document.createElement( 'h4' );
		heading.textContent = 'Customer Details';
		target.appendChild( heading );
		const details = document.createElement( 'div' );
		details.className = 'afc-sms-customer-details';
		details.append(
			detailRow( 'Name', conversation.name ),
			detailRow( 'PPP username', conversation.ppp ),
			detailRow( 'Phone / sender', conversation.phone ),
			detailRow( 'Outgoing SMS', conversation.jobs.length ),
			detailRow( 'Incoming replies', conversation.replies.length ),
			detailRow( 'Last activity', formatTime( conversation.lastAt, true ) )
		);
		target.appendChild( details );
	}

	function renderDrawer() {
		const target = byId( 'afc-sms-drawer-content' );
		const conversation = activeConversation();
		if ( ! target || ! conversation ) return;
		if ( byId( 'afc-sms-drawer-name' ) ) byId( 'afc-sms-drawer-name' ).textContent = conversation.name;
		target.replaceChildren();
		if ( drawerView === 'delivery' ) renderDeliveryDrawer( target, conversation );
		else if ( drawerView === 'details' ) renderDetailsDrawer( target, conversation );
		else renderDrawerMenu( target );
	}

	function renderState( state ) {
		latestState = state || latestState;
		renderDevice( latestState.device );
		renderCounts( latestState.counts );
		conversations = buildConversations( latestState );
		if ( activeConversationKey && ! conversations.some( function ( item ) { return item.key === activeConversationKey; } ) ) activeConversationKey = '';
		if ( ! activeConversationKey && conversations.length ) activeConversationKey = conversations[ 0 ].key;
		renderConversationList();
		renderConversation();
		if ( byId( 'afc-sms-chat-layout' ) && byId( 'afc-sms-chat-layout' ).classList.contains( 'is-drawer-open' ) ) renderDrawer();
	}

	function loadState( quiet ) {
		if ( stateLoading ) return Promise.resolve();
		stateLoading = true;
		return ajax( 'afc_sms_get_state' ).then( function ( state ) {
			renderState( state );
			if ( ! quiet ) notice( 'SMS Center status refreshed.', 'success' );
		} ).catch( function ( error ) {
			if ( ! quiet ) notice( error.message, 'danger' );
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
		if ( label ) label.textContent = text( selected.customer_name, selected.ppp_username ) + ' · ' + text( selected.phone_normalized || selected.phone, 'No valid phone' );
		if ( queue ) queue.disabled = ! candidateAvailable( selected );
	}

	function renderCandidates() {
		const target = byId( 'afc-sms-ppp-list' );
		if ( ! target ) return;
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
			if ( ! candidateAvailable( candidate ) ) row.classList.add( 'is-unavailable' );
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
		if ( target ) target.innerHTML = '<div class="text-secondary p-3">Loading PPP customers…</div>';
		return ajax( 'afc_sms_list_ppp' ).then( function ( response ) {
			candidates = Array.isArray( response.users ) ? response.users : [];
			if ( selected ) selected = candidates.find( function ( item ) { return item.ppp_username === selected.ppp_username; } ) || null;
			renderCandidates();
			updateSelected();
			if ( response.warning ) notice( 'PPP list loaded from WordPress fallback: ' + response.warning, 'warning' );
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
		if ( button && /Rotate/.test( button.textContent ) && ! window.confirm( config.labels && config.labels.confirmRotate ? config.labels.confirmRotate : 'Rotate the device token?' ) ) return;
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
		if ( ! input || ! input.value ) return;
		const done = function () { notice( config.labels && config.labels.copied ? config.labels.copied : 'Copied.', 'success' ); };
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
		if ( ! window.confirm( confirmation + '\n\nRecipient: ' + text( selected.customer_name, selected.ppp_username ) + ' (' + selected.phone_normalized + ')' ) ) return;
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
		if ( ! window.confirm( 'Cancel queued SMS job #' + jobId + '?' ) ) return;
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
		if ( ! root ) return;
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
			if ( event.target.closest( '#afc-sms-drawer-close, #afc-sms-close-drawer' ) ) closeDrawer();
			const conversationButton = event.target.closest( '[data-afc-sms-conversation]' );
			if ( conversationButton ) selectConversation( conversationButton.getAttribute( 'data-afc-sms-conversation' ), true );
			const conversationMenu = event.target.closest( '[data-afc-sms-conversation-menu]' );
			if ( conversationMenu ) {
				const key = conversationMenu.getAttribute( 'data-afc-sms-conversation-menu' );
				const conversation = conversations.find( function ( item ) { return item.key === key; } );
				openDrawer( conversation, 'menu' );
			}
			const drawerButton = event.target.closest( '[data-afc-sms-drawer-view]' );
			if ( drawerButton ) {
				const view = drawerButton.getAttribute( 'data-afc-sms-drawer-view' );
				if ( view === 'conversation' ) closeDrawer();
				else {
					drawerView = view;
					renderDrawer();
				}
			}
			const cancel = event.target.closest( '[data-afc-cancel-job]' );
			if ( cancel ) cancelJob( cancel.getAttribute( 'data-afc-cancel-job' ), cancel );
		} );
		const pppSearch = byId( 'afc-sms-ppp-search' );
		if ( pppSearch ) pppSearch.addEventListener( 'input', renderCandidates );
		const conversationSearch = byId( 'afc-sms-conversation-search' );
		if ( conversationSearch ) conversationSearch.addEventListener( 'input', renderConversationList );
		document.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Escape' ) closeDrawer(); } );
	}

	function startPolling() {
		if ( stateTimer ) window.clearInterval( stateTimer );
		stateTimer = window.setInterval( function () {
			const current = panel();
			if ( current && ! current.hidden && current.getAttribute( 'aria-hidden' ) !== 'true' ) loadState( true );
		}, 5000 );
	}

	function boot() {
		injectNavigation();
		if ( ! panel() ) return;
		bind();
		loadState( true );
		loadCandidates();
		startPolling();
	}

	injectNavigation();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
