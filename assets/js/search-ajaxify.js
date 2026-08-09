( function ( $ ) {
	'use strict';

	const cfg = window.afcSearchAjaxify || {};
	const watchers = new Map();
	const records = new Map();
	let requestCounter = 0;
	let popover = null;
	let hoverTimer = null;
	let hideTimer = null;
	let observerTimer = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function key( value ) {
		return text( value ).trim().toLowerCase();
	}

	function esc( value ) {
		const node = document.createElement( 'div' );
		node.textContent = text( value );
		return node.innerHTML;
	}

	function dateLabel( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{1,2})-(\d{1,2})$/ );
		if ( ! match ) return text( value );
		const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
		try {
			return date.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
		} catch ( error ) {
			return Number( match[ 2 ] ) + '/' + Number( match[ 3 ] );
		}
	}

	function fullDateLabel( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{1,2})-(\d{1,2})$/ );
		if ( ! match ) return text( value ) || 'Not set';
		const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ) );
		try {
			return date.toLocaleDateString( [], { year: 'numeric', month: 'short', day: 'numeric' } );
		} catch ( error ) {
			return text( value );
		}
	}

	function accountFrom( element, watcher ) {
		if ( ! element ) return '';
		if ( typeof watcher.account === 'function' ) return text( watcher.account( element ) ).trim();
		return text( element.getAttribute( watcher.accountAttr || 'data-account' ) ).trim();
	}

	function matchingResultElements( watcher ) {
		return Array.from( document.querySelectorAll( watcher.resultSelector ) );
	}

	function visibleAccounts( watcher ) {
		const seen = new Set();
		const accounts = [];
		matchingResultElements( watcher ).forEach( function ( element ) {
			const account = accountFrom( element, watcher );
			const normalized = key( account );
			if ( normalized && ! seen.has( normalized ) ) {
				seen.add( normalized );
				accounts.push( account );
			}
		} );
		return accounts.slice( 0, Number( cfg.maxItems || 20 ) );
	}

	function queryForWatcher( watcher ) {
		const input = document.querySelector( watcher.inputSelector );
		return input ? input.value.trim() : '';
	}

	function request( options ) {
		options = options || {};
		const accounts = Array.isArray( options.accounts ) ? options.accounts.filter( Boolean ) : [];
		if ( ! accounts.length || ! cfg.ajaxUrl || ! cfg.nonce ) {
			return Promise.resolve( { records: {} } );
		}

		const requestId = options.requestId || ( 'search-' + Date.now() + '-' + ( ++requestCounter ) );
		const body = new URLSearchParams();
		body.set( 'action', 'afc_search_ajaxify' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'accounts', JSON.stringify( accounts ) );
		body.set( 'providers', JSON.stringify( options.providers || [ 'ppp-live' ] ) );
		body.set( 'query', options.query || '' );
		body.set( 'request_id', requestId );

		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Live search update failed.' );
			}
			return response.data || { records: {} };
		} );
	}

	function dueTone( state ) {
		if ( state === 'expired' ) return 'danger';
		if ( state === 'due' || state === 'soon' || state === 'upcoming' ) return 'warning';
		if ( state === 'safe' ) return 'safe';
		return 'neutral';
	}

	function dueLabel( record ) {
		if ( ! record || ! record.next_due ) return '';
		if ( record.due_state === 'due' ) return 'DUE ' + dateLabel( record.next_due );
		if ( record.due_state === 'soon' || record.due_state === 'upcoming' ) return 'DUE ' + dateLabel( record.next_due );
		return 'DUE ' + dateLabel( record.next_due );
	}

	function liveRowMarkup( record ) {
		if ( ! record ) return '';
		let html = '<span class="afc-search-live-chip ' + ( record.online ? 'is-online' : 'is-offline' ) + '"><i></i><b>' + ( record.online ? 'ONLINE' : 'OFFLINE' ) + '</b></span>';
		if ( record.expired ) {
			html += '<span class="afc-search-live-chip is-danger"><b>EXPIRED</b></span>';
		}
		const label = dueLabel( record );
		if ( label ) {
			html += '<span class="afc-search-live-chip is-' + dueTone( record.due_state ) + '"><b>' + esc( label ) + '</b></span>';
		}
		html += '<span class="afc-search-ajaxify-info" aria-label="PPP details" title="Hover for live PPP details">i</span>';
		return html;
	}

	function liveMount( element, watcher ) {
		if ( ! element ) return null;
		if ( typeof watcher.mount === 'function' ) return watcher.mount( element );
		return element.querySelector( watcher.mountSelector || '' );
	}

	function renderRecord( element, watcher, record ) {
		if ( ! element || ! record ) return;
		element.setAttribute( 'data-afc-live-account', record.account || accountFrom( element, watcher ) );
		element.setAttribute( 'data-afc-live-online', record.online ? '1' : '0' );
		element.setAttribute( 'data-afc-live-due-state', record.due_state || 'unknown' );
		element.setAttribute( 'data-afc-live-expired', record.expired ? '1' : '0' );

		const mount = liveMount( element, watcher );
		if ( ! mount ) return;
		let row = mount.querySelector( ':scope > .afc-search-ajaxify-live' );
		if ( ! row ) {
			row = document.createElement( 'span' );
			row.className = 'afc-search-ajaxify-live';
			mount.appendChild( row );
		}
		row.innerHTML = liveRowMarkup( record );
	}

	function decorateWatcherFromCache( watcher ) {
		matchingResultElements( watcher ).forEach( function ( element ) {
			const account = accountFrom( element, watcher );
			const record = records.get( key( account ) );
			if ( record ) renderRecord( element, watcher, record );
		} );
	}

	function decorateAllFromCache() {
		watchers.forEach( decorateWatcherFromCache );
	}

	function runWatcher( watcher, sequence ) {
		const query = queryForWatcher( watcher );
		const minChars = Number( watcher.minChars || cfg.minChars || 3 );
		if ( query.length < minChars || sequence !== watcher.sequence ) return;

		const accounts = visibleAccounts( watcher );
		if ( ! accounts.length ) return;
		const requestId = watcher.key + '-' + sequence + '-' + Date.now();
		watcher.inflight = requestId;

		matchingResultElements( watcher ).forEach( function ( element ) {
			element.classList.add( 'afc-search-ajaxify-checking' );
		} );

		request( {
			accounts: accounts,
			providers: watcher.providers,
			query: query,
			requestId: requestId,
		} ).then( function ( data ) {
			if ( watcher.inflight !== requestId || sequence !== watcher.sequence || queryForWatcher( watcher ) !== query ) return;
			Object.keys( data.records || {} ).forEach( function ( accountKey ) {
				const record = data.records[ accountKey ];
				if ( record ) records.set( key( record.account || accountKey ), record );
			} );
			decorateWatcherFromCache( watcher );
			document.dispatchEvent( new CustomEvent( 'afc:search-ajaxify:results', { detail: data } ) );
		} ).catch( function () {
			// Keep the last known full-PPP state when a live refresh fails.
		} ).finally( function () {
			if ( watcher.inflight === requestId ) watcher.inflight = '';
			matchingResultElements( watcher ).forEach( function ( element ) {
				element.classList.remove( 'afc-search-ajaxify-checking' );
			} );
		} );
	}

	function scheduleWatcher( watcher ) {
		window.clearTimeout( watcher.timer );
		watcher.sequence += 1;
		const sequence = watcher.sequence;
		const query = queryForWatcher( watcher );
		if ( query.length < Number( watcher.minChars || cfg.minChars || 3 ) ) return;
		watcher.timer = window.setTimeout( function () {
			runWatcher( watcher, sequence );
		}, Number( watcher.delayMs || cfg.delayMs || 1000 ) );
	}

	function register( options ) {
		options = options || {};
		if ( ! options.key || ! options.inputSelector || ! options.resultSelector ) return null;
		const watcher = Object.assign( {
			minChars: Number( cfg.minChars || 3 ),
			delayMs: Number( cfg.delayMs || 1000 ),
			providers: [ 'ppp-live' ],
			accountAttr: 'data-account',
			mountSelector: '',
			sequence: 0,
			timer: null,
			inflight: '',
		}, options );
		watchers.set( watcher.key, watcher );
		decorateWatcherFromCache( watcher );
		return watcher;
	}

	function seedFromFullPppUsers( list ) {
		( list || [] ).forEach( function ( user ) {
			if ( ! user || ! user.name ) return;
			const accountKey = key( user.name );
			const previous = records.get( accountKey ) || {};
			records.set( accountKey, Object.assign( {}, previous, {
				account: user.name,
				online: Boolean( user.active ),
				profile: user.actual_profile || user.profile || previous.profile || '',
				expired: String( user.actual_profile || '' ).toLowerCase() === 'expired' || Boolean( previous.expired ),
			} ) );
		} );
		decorateAllFromCache();
	}

	function ensurePopover() {
		if ( popover ) return popover;
		popover = document.createElement( 'aside' );
		popover.className = 'afc-search-ajaxify-popover';
		popover.setAttribute( 'role', 'dialog' );
		popover.setAttribute( 'aria-label', 'Live PPP details' );
		popover.hidden = true;
		popover.addEventListener( 'mouseenter', function () { window.clearTimeout( hideTimer ); } );
		popover.addEventListener( 'mouseleave', scheduleHidePopover );
		document.body.appendChild( popover );
		return popover;
	}

	function detailRow( label, value ) {
		if ( ! text( value ).trim() ) return '';
		return '<div><dt>' + esc( label ) + '</dt><dd>' + esc( value ) + '</dd></div>';
	}

	function popoverMarkup( record ) {
		const session = record.session || {};
		const fields = Array.isArray( record.comment_fields ) ? record.comment_fields : [];
		let comment = '';
		fields.forEach( function ( field ) {
			comment += '<div class="afc-search-comment-field' + ( field.sensitive ? ' is-sensitive' : '' ) + '"><span>' + esc( field.label || field.key ) + '</span><strong>' + esc( field.value ) + '</strong></div>';
		} );
		if ( ! comment ) comment = '<div class="afc-search-comment-empty">No structured PPP comment fields were found.</div>';

		return '<header>' +
			'<div><small>LIVE MIKROTIK PPP</small><h4>' + esc( record.customer_name || record.account ) + '</h4><p>' + esc( record.account ) + '</p></div>' +
			'<span class="afc-search-popover-presence ' + ( record.online ? 'is-online' : 'is-offline' ) + '"><i></i>' + ( record.online ? 'ONLINE' : 'OFFLINE' ) + '</span>' +
		'</header>' +
		'<dl class="afc-search-live-facts">' +
			detailRow( 'Profile', record.profile || 'Not set' ) +
			detailRow( 'Service', record.expired ? 'Expired' : 'Active' ) +
			detailRow( 'Next due', fullDateLabel( record.next_due ) ) +
			detailRow( 'Cutoff', fullDateLabel( record.cutoff_date ) ) +
			detailRow( 'IP address', session.address ) +
			detailRow( 'Uptime', session.uptime ) +
			detailRow( 'Caller ID', session.caller_id ) +
		'</dl>' +
		'<section class="afc-search-comment-section"><div class="afc-search-comment-title"><span>PPP COMMENT</span><small>Live from RouterOS</small></div><div class="afc-search-comment-grid">' + comment + '</div></section>';
	}

	function positionPopover( anchor ) {
		const box = ensurePopover();
		const rect = anchor.getBoundingClientRect();
		const width = Math.min( 420, Math.max( 300, window.innerWidth - 24 ) );
		box.style.width = width + 'px';
		box.style.left = '12px';
		box.style.top = '12px';
		box.hidden = false;

		const measured = box.getBoundingClientRect();
		let left = rect.right + 10;
		if ( left + measured.width > window.innerWidth - 10 ) left = rect.left - measured.width - 10;
		if ( left < 10 ) left = Math.min( Math.max( 10, rect.left ), window.innerWidth - measured.width - 10 );

		let top = rect.top;
		if ( top + measured.height > window.innerHeight - 10 ) top = window.innerHeight - measured.height - 10;
		if ( top < 10 ) top = 10;
		box.style.left = Math.round( left ) + 'px';
		box.style.top = Math.round( top ) + 'px';
	}

	function recordForElement( element ) {
		if ( ! element ) return null;
		const account = element.getAttribute( 'data-afc-live-account' ) || element.getAttribute( 'data-account' ) || element.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
		return records.get( key( account ) ) || null;
	}

	function showPopover( element ) {
		const record = recordForElement( element );
		if ( ! record ) return;
		window.clearTimeout( hideTimer );
		const box = ensurePopover();
		box.innerHTML = popoverMarkup( record );
		box.dataset.account = record.account || '';
		positionPopover( element );
	}

	function hidePopover() {
		window.clearTimeout( hoverTimer );
		if ( popover ) {
			popover.hidden = true;
			popover.dataset.account = '';
		}
	}

	function scheduleHidePopover() {
		window.clearTimeout( hideTimer );
		hideTimer = window.setTimeout( hidePopover, 120 );
	}

	function resultFromTarget( target ) {
		return target && target.closest ? target.closest( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ) : null;
	}

	function bindDocumentEvents() {
		document.addEventListener( 'input', function ( event ) {
			watchers.forEach( function ( watcher ) {
				if ( event.target && event.target.matches && event.target.matches( watcher.inputSelector ) ) scheduleWatcher( watcher );
			} );
		}, true );

		document.addEventListener( 'mouseover', function ( event ) {
			const result = resultFromTarget( event.target );
			if ( ! result || ( event.relatedTarget && result.contains( event.relatedTarget ) ) ) return;
			window.clearTimeout( hoverTimer );
			window.clearTimeout( hideTimer );
			hoverTimer = window.setTimeout( function () { showPopover( result ); }, 320 );
		}, true );

		document.addEventListener( 'mouseout', function ( event ) {
			const result = resultFromTarget( event.target );
			if ( ! result || ( event.relatedTarget && result.contains( event.relatedTarget ) ) ) return;
			scheduleHidePopover();
		}, true );

		document.addEventListener( 'click', function ( event ) {
			const info = event.target && event.target.closest ? event.target.closest( '.afc-search-ajaxify-info' ) : null;
			if ( info ) {
				event.preventDefault();
				event.stopPropagation();
				showPopover( resultFromTarget( info ) );
				return;
			}
			if ( popover && ! popover.hidden && ! popover.contains( event.target ) ) hidePopover();
		}, true );

		window.addEventListener( 'scroll', hidePopover, true );
		window.addEventListener( 'resize', hidePopover );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return text( settings.data ).includes( 'action=' + action );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! requestHasAction( settings, 'afc_get_ppp_users' ) || ! xhr.responseJSON || ! xhr.responseJSON.success ) return;
		seedFromFullPppUsers( xhr.responseJSON.data && Array.isArray( xhr.responseJSON.data.users ) ? xhr.responseJSON.data.users : [] );
	} );

	function boot() {
		register( {
			key: 'basic-payment',
			inputSelector: '#afc-basic-payment-search',
			resultSelector: '.afc-basic-customer-result[data-account]',
			accountAttr: 'data-account',
			mountSelector: '.afc-basic-customer-main',
		} );
		register( {
			key: 'advanced-payment',
			inputSelector: '#afc-dashboard-payment-search',
			resultSelector: '.afc-dashboard-payment-result[data-afc-dashboard-payment-account]',
			accountAttr: 'data-afc-dashboard-payment-account',
			mountSelector: '.afc-dashboard-payment-result-copy',
		} );

		bindDocumentEvents();
		new MutationObserver( function () {
			window.clearTimeout( observerTimer );
			observerTimer = window.setTimeout( decorateAllFromCache, 35 );
		} ).observe( document.body, { childList: true, subtree: true } );
		decorateAllFromCache();
	}

	window.AFCSearchAjaxify = {
		register: register,
		request: request,
		refresh: function ( watcherKey ) {
			const watcher = watchers.get( watcherKey );
			if ( watcher ) scheduleWatcher( watcher );
		},
		get: function ( account ) { return records.get( key( account ) ) || null; },
	};

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
