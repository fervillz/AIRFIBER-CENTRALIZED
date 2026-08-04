( function () {
	'use strict';

	const cfg = window.afcSmsAudienceFilters || {};
	let users = [];
	let counts = {};
	let gateway = {};
	let activeFilter = 'all';
	let selectedAccount = '';
	let loading = false;
	let timer = null;
	let observer = null;

	function byId( id ) { return document.getElementById( id ); }
	function text( value ) { return value == null ? '' : String( value ).trim(); }
	function esc( value ) { const node = document.createElement( 'div' ); node.textContent = value == null ? '' : String( value ); return node.innerHTML; }
	function lower( value ) { return text( value ).toLowerCase(); }
	function panel() { return document.querySelector( '[data-afc-panel="sms"]' ); }

	function initials( value ) {
		const words = text( value || 'A' ).split( /\s+/ ).filter( Boolean );
		return ( words[ 0 ] ? words[ 0 ].charAt( 0 ) : 'A' ) + ( words[ 1 ] ? words[ 1 ].charAt( 0 ) : '' );
	}

	function formatDate( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
		if ( ! match ) return text( value );
		const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
		return date.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
	}

	function ajax( refresh ) {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_sms_audience_state' );
		body.set( 'nonce', cfg.nonce || '' );
		body.set( 'refresh', refresh ? '1' : '0' );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
			if ( ! response || ! response.success ) throw new Error( response && response.data && response.data.message ? response.data.message : 'Could not load SMS customers.' );
			return response.data || {};
		} );
	}

	function filterMatch( user ) {
		if ( activeFilter === 'queued' ) return Number( user.queuedCount || 0 ) > 0;
		if ( activeFilter === 'delivered' ) return Number( user.deliveredCount || 0 ) > 0;
		if ( activeFilter === 'sent' ) return Number( user.sentCount || 0 ) > 0;
		if ( activeFilter === 'due-soon' ) return Boolean( user.dueSoon );
		if ( activeFilter === 'prepared' ) return Boolean( user.prepared );
		return true;
	}

	function filterLabel( filter ) {
		const labels = {
			all: 'All customers',
			gateway: 'Gateway',
			queued: 'Queued',
			sent: 'Sent',
			delivered: 'Delivered',
			'due-soon': 'Due soon',
			prepared: 'Prepared',
		};
		return labels[ filter ] || 'Customers';
	}

	function statusChips( user ) {
		let html = '';
		if ( Number( user.queuedCount || 0 ) > 0 ) html += '<span class="is-queued">Queued ' + Number( user.queuedCount ) + '</span>';
		else if ( Number( user.deliveredCount || 0 ) > 0 ) html += '<span class="is-delivered">Delivered</span>';
		else if ( Number( user.sentCount || 0 ) > 0 ) html += '<span class="is-sent">Sent</span>';
		if ( user.dueSoon ) html += '<span class="is-due">' + ( Number( user.daysToDue ) === 0 ? 'Due today' : Number( user.daysToDue ) + 'd to due' ) + '</span>';
		if ( user.prepared ) html += '<span class="is-prepared">' + esc( user.preparedLabel || 'Prepared' ) + '</span>';
		if ( user.doNotText ) html += '<span class="is-blocked">Do Not Text</span>';
		if ( user.contactPaused ) html += '<span class="is-paused">Paused</span>';
		return html;
	}

	function audienceSummary( user ) {
		if ( activeFilter === 'queued' ) return Number( user.queuedCount || 0 ) + ' waiting for phone';
		if ( activeFilter === 'delivered' ) return Number( user.deliveredCount || 0 ) + ' delivered SMS';
		if ( activeFilter === 'sent' ) return Number( user.sentCount || 0 ) + ' sent SMS';
		if ( activeFilter === 'due-soon' ) return Number( user.daysToDue ) === 0 ? 'Due today' : 'Due ' + formatDate( user.nextDue );
		if ( activeFilter === 'prepared' ) return user.preparedLabel || 'Message prepared';
		if ( user.latestStatus ) return user.latestStatus.replace( /-/g, ' ' );
		if ( user.nextDue ) return 'Due ' + formatDate( user.nextDue );
		return user.profile || 'No SMS history';
	}

	function visibleUsers() {
		const search = lower( byId( 'afc-sms-conversation-search' ) && byId( 'afc-sms-conversation-search' ).value );
		return users.filter( function ( user ) {
			const matchesSearch = ! search || [ user.name, user.account, user.phone, user.profile, user.nextDue, user.preparedLabel ].join( ' ' ).toLowerCase().includes( search );
			return matchesSearch && filterMatch( user );
		} );
	}

	function renderGateway( target ) {
		const state = lower( gateway.state || 'not-configured' );
		target.innerHTML =
			'<div class="afc-sms-audience-info">' +
				'<span class="afc-sms-audience-info-icon is-' + esc( state ) + '">⇄</span>' +
				'<div><small>ANDROID GATEWAY</small><h4>' + esc( state.replace( /-/g, ' ' ) ) + '</h4><p>' + esc( gateway.detail || 'No gateway detail is available.' ) + '</p></div>' +
				'<dl><div><dt>Device</dt><dd>' + esc( gateway.device_id || 'Not connected' ) + '</dd></div><div><dt>Last seen</dt><dd>' + esc( gateway.last_seen || 'Never' ) + '</dd></div></dl>' +
			'</div>';
	}

	function renderList() {
		const target = byId( 'afc-sms-conversations' );
		if ( ! target ) return;
		decorateFilterButtons();

		if ( activeFilter === 'gateway' ) {
			const signature = 'gateway|' + JSON.stringify( gateway );
			if ( target.dataset.afcAudienceSignature !== signature ) {
				target.dataset.afcAudienceSignature = signature;
				renderGateway( target );
			}
			setCount( 1, 'gateway status' );
			return;
		}

		const list = visibleUsers();
		const signature = activeFilter + '|' + lower( byId( 'afc-sms-conversation-search' ) && byId( 'afc-sms-conversation-search' ).value ) + '|' + list.map( function ( user ) {
			return [ user.account, user.queuedCount, user.deliveredCount, user.sentCount, user.dueSoon, user.prepared, user.latestStatus, selectedAccount === user.account ].join( ':' );
		} ).join( ',' );
		if ( target.dataset.afcAudienceSignature === signature && target.querySelector( '[data-afc-sms-audience-account]' ) ) return;
		target.dataset.afcAudienceSignature = signature;
		target.replaceChildren();
		setCount( list.length, filterLabel( activeFilter ).toLowerCase() );

		if ( ! list.length ) {
			target.innerHTML = '<div class="afc-sms-chat-empty">No customers match this filter.</div>';
			return;
		}

		list.forEach( function ( user ) {
			const item = document.createElement( 'button' );
			item.type = 'button';
			item.className = 'afc-sms-audience-row';
			if ( selectedAccount === user.account ) item.classList.add( 'is-active' );
			item.setAttribute( 'data-afc-sms-audience-account', user.account );
			if ( user.hasConversation ) item.setAttribute( 'data-afc-sms-conversation', user.conversationKey );
			item.innerHTML =
				'<span class="afc-sms-list-avatar">' + esc( initials( user.name ) ) + '</span>' +
				'<span class="afc-sms-audience-identity"><strong>' + esc( user.name || user.account ) + '</strong><small>' + esc( [ user.account, user.phone ].filter( Boolean ).join( ' · ' ) ) + '</small><em>' + esc( audienceSummary( user ) ) + '</em></span>' +
				'<span class="afc-sms-audience-chips">' + statusChips( user ) + '</span>' +
				'<span class="afc-sms-audience-arrow">›</span>';
			target.appendChild( item );
		} );
	}

	function setCount( amount, label ) {
		const node = byId( 'afc-sms-conversation-count' );
		if ( node ) node.textContent = amount + ' ' + label;
	}

	function decorateFilterButtons() {
		document.querySelectorAll( '[data-afc-sms-filter]' ).forEach( function ( button ) {
			const filter = button.getAttribute( 'data-afc-sms-filter' );
			button.classList.toggle( 'is-active', filter === activeFilter );
			button.setAttribute( 'aria-pressed', filter === activeFilter ? 'true' : 'false' );
			const counter = button.querySelector( '[data-afc-sms-filter-count]' );
			if ( counter && Object.prototype.hasOwnProperty.call( counts, filter ) ) counter.textContent = String( counts[ filter ] || 0 );
		} );
	}

	function decorateWorkspaceStats() {
		const smsPanel = panel();
		if ( ! smsPanel ) return;
		smsPanel.querySelectorAll( '.afc-workspace-stats > span' ).forEach( function ( stat ) {
			const label = lower( stat.querySelector( 'small' ) && stat.querySelector( 'small' ).textContent );
			const map = { gateway: 'gateway', queued: 'queued', sent: 'sent', delivered: 'delivered' };
			if ( ! map[ label ] ) return;
			stat.setAttribute( 'data-afc-sms-filter', map[ label ] );
			stat.setAttribute( 'role', 'button' );
			stat.setAttribute( 'tabindex', '0' );
			stat.setAttribute( 'aria-pressed', map[ label ] === activeFilter ? 'true' : 'false' );
		} );
	}

	function renderUserInfo( user ) {
		const timeline = byId( 'afc-sms-chat-timeline' );
		if ( ! timeline || ! user ) return;
		const name = byId( 'afc-sms-chat-name' );
		const meta = byId( 'afc-sms-chat-meta' );
		const avatar = byId( 'afc-sms-chat-avatar' );
		const compose = byId( 'afc-sms-compose-selected' );
		if ( name ) name.textContent = user.name || user.account;
		if ( meta ) meta.textContent = [ user.account, user.phone, user.profile ].filter( Boolean ).join( ' · ' );
		if ( avatar ) avatar.textContent = initials( user.name );
		if ( compose ) compose.hidden = true;
		timeline.dataset.afcAudienceUser = user.account;
		timeline.innerHTML =
			'<article class="afc-sms-audience-profile">' +
				'<header><div><small>SMS CUSTOMER</small><h3>' + esc( user.name || user.account ) + '</h3><p>' + esc( user.account ) + '</p></div><span class="afc-sms-audience-rating">' + Number( user.payorRating || 0 ) + '★</span></header>' +
				'<div class="afc-sms-audience-profile-chips">' + statusChips( user ) + '</div>' +
				'<dl><div><dt>Phone</dt><dd>' + esc( user.phone || 'No valid phone' ) + '</dd></div><div><dt>Next due</dt><dd>' + esc( user.nextDue || 'Not set' ) + '</dd></div><div><dt>Cutoff</dt><dd>' + esc( user.cutoffDate || 'Not set' ) + '</dd></div><div><dt>Reminder</dt><dd>' + esc( user.preparedLabel || ( user.reminderEnabled ? user.reminderDays + ' days before' : 'Not prepared' ) ) + '</dd></div></dl>' +
				'<footer><button type="button" class="btn btn-primary" data-afc-sms-audience-compose="' + esc( user.account ) + '"' + ( user.phone ? '' : ' disabled' ) + '>Queue SMS</button></footer>' +
			'</article>';
	}

	function selectAccount( account ) {
		selectedAccount = account;
		const user = users.find( function ( item ) { return item.account === account; } );
		if ( ! user ) return;
		if ( ! user.hasConversation ) renderUserInfo( user );
		renderList();
	}

	function openComposerFor( account ) {
		const open = byId( 'afc-sms-new-message' );
		if ( open ) open.click();
		window.setTimeout( function () {
			const search = byId( 'afc-sms-ppp-search' );
			if ( search ) {
				search.value = account;
				search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
			window.setTimeout( function () {
				const row = Array.from( document.querySelectorAll( '.afc-sms-ppp-row' ) ).find( function ( item ) {
					const radio = item.querySelector( 'input[type="radio"]' );
					return radio && radio.value === account;
				} );
				if ( row ) row.click();
			}, 120 );
		}, 80 );
	}

	function setFilter( filter ) {
		activeFilter = filter || 'all';
		selectedAccount = '';
		const target = byId( 'afc-sms-conversations' );
		if ( target ) delete target.dataset.afcAudienceSignature;
		renderList();
		decorateWorkspaceStats();
	}

	function load( refresh ) {
		if ( loading ) return;
		loading = true;
		ajax( refresh ).then( function ( state ) {
			users = Array.isArray( state.users ) ? state.users : [];
			counts = state.counts || {};
			gateway = state.gateway || {};
			renderList();
			decorateWorkspaceStats();
		} ).catch( function ( error ) {
			const target = byId( 'afc-sms-conversations' );
			if ( target ) target.innerHTML = '<div class="afc-sms-chat-empty">' + esc( error.message ) + '</div>';
		} ).finally( function () { loading = false; } );
	}

	function scheduleRender() {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			decorateWorkspaceStats();
			renderList();
			if ( selectedAccount ) {
				const user = users.find( function ( item ) { return item.account === selectedAccount; } );
				const timeline = byId( 'afc-sms-chat-timeline' );
				if ( user && ! user.hasConversation && timeline && ( timeline.dataset.afcAudienceUser !== user.account || ! timeline.querySelector( '.afc-sms-audience-profile' ) ) ) renderUserInfo( user );
			}
		}, 45 );
	}

	function bind() {
		const root = panel();
		if ( ! root ) return;
		root.addEventListener( 'click', function ( event ) {
			const filter = event.target.closest( '[data-afc-sms-filter]' );
			if ( filter ) {
				event.preventDefault();
				setFilter( filter.getAttribute( 'data-afc-sms-filter' ) );
				return;
			}
			const audience = event.target.closest( '[data-afc-sms-audience-account]' );
			if ( audience ) selectAccount( audience.getAttribute( 'data-afc-sms-audience-account' ) );
			const compose = event.target.closest( '[data-afc-sms-audience-compose]' );
			if ( compose ) {
				event.preventDefault();
				openComposerFor( compose.getAttribute( 'data-afc-sms-audience-compose' ) );
			}
			if ( event.target.closest( '#afc-sms-refresh' ) ) window.setTimeout( function () { load( true ); }, 150 );
		} );
		root.addEventListener( 'keydown', function ( event ) {
			const filter = event.target.closest( '[data-afc-sms-filter]' );
			if ( filter && ( event.key === 'Enter' || event.key === ' ' ) ) {
				event.preventDefault();
				setFilter( filter.getAttribute( 'data-afc-sms-filter' ) );
			}
		} );
		const search = byId( 'afc-sms-conversation-search' );
		if ( search ) search.addEventListener( 'input', scheduleRender );
	}

	function boot() {
		if ( ! panel() ) return;
		bind();
		observer = new MutationObserver( scheduleRender );
		observer.observe( panel(), { childList: true, subtree: true, characterData: true } );
		load( true );
		window.setInterval( function () {
			const current = panel();
			if ( current && ! current.hidden && current.getAttribute( 'aria-hidden' ) !== 'true' ) load( false );
		}, 15000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
