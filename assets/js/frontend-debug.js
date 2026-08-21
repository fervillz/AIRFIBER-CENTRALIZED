( function () {
	'use strict';

	const cfg = window.afcPluginDebug || {};
	if ( ! cfg.enabled || ! cfg.ajaxUrl || ! cfg.nonce ) return;

	const session = ( window.crypto && typeof window.crypto.randomUUID === 'function' ) ? window.crypto.randomUUID() : String( Date.now() ) + '-' + Math.random().toString( 16 ).slice( 2 );
	const limit = Number( cfg.maxEvents ) || 250;
	let buffer = [];
	let timer = 0;
	let sending = false;
	let recorded = 0;

	function describe( node ) {
		if ( ! node || node.nodeType !== 1 ) return '';
		let value = node.tagName.toLowerCase();
		if ( node.id ) value += '#' + node.id;
		if ( node.classList && node.classList.length ) value += '.' + Array.from( node.classList ).slice( 0, 5 ).join( '.' );
		return value.slice( 0, 240 );
	}

	function cardData( event ) {
		const target = event.target && event.target.closest ? event.target : null;
		const card = target ? target.closest( '.afc-connection-card[data-afc-connection-key], [data-afc-connection-add]' ) : null;
		if ( ! card ) return null;
		const rect = card.getBoundingClientRect();
		const x = typeof event.clientX === 'number' ? event.clientX : rect.left + rect.width / 2;
		const y = typeof event.clientY === 'number' ? event.clientY : rect.top + rect.height / 2;
		const top = document.elementFromPoint( x, y );
		const style = window.getComputedStyle( card );
		return {
			key: card.getAttribute( 'data-afc-connection-key' ) || ( card.hasAttribute( 'data-afc-connection-add' ) ? 'add' : '' ),
			type: card.getAttribute( 'data-afc-connection-type' ) || '',
			id: card.getAttribute( 'data-afc-connection-id' ) || '',
			target: describe( target ),
			top: describe( top ),
			connected: card.isConnected,
			defaultPrevented: event.defaultPrevented,
			pointerEvents: style.pointerEvents,
			display: style.display,
			visibility: style.visibility,
			rect: [ Math.round( rect.left ), Math.round( rect.top ), Math.round( rect.width ), Math.round( rect.height ) ],
		};
	}

	function client() {
		return {
			session: session,
			at: new Date().toISOString(),
			path: window.location.pathname,
			hash: window.location.hash,
			version: cfg.version || '',
		};
	}

	function flush( beacon ) {
		window.clearTimeout( timer );
		timer = 0;
		if ( sending || ! buffer.length ) return;
		const events = buffer.splice( 0, 40 );
		const body = new URLSearchParams();
		body.set( 'action', cfg.action || 'afc_plugin_debug_log' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'events', JSON.stringify( events ) );

		if ( beacon && navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.ajaxUrl, body );
			return;
		}

		sending = true;
		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).catch( function () {
			buffer = events.concat( buffer ).slice( 0, 80 );
		} ).finally( function () {
			sending = false;
			if ( buffer.length ) timer = window.setTimeout( flush, 350 );
		} );
	}

	function record( event, data ) {
		if ( recorded++ >= limit ) return;
		buffer.push( { event: String( event || 'unknown' ).slice( 0, 80 ), client: client(), data: data || {} } );
		if ( buffer.length >= 8 ) flush();
		else if ( ! timer ) timer = window.setTimeout( flush, 350 );
	}

	window.AFCDebug = Object.assign( {}, window.AFCDebug || {}, { record: record, flush: flush } );

	window.addEventListener( 'error', function ( event ) {
		record( 'window-error', { message: event.message || '', file: event.filename || '', line: event.lineno || 0, column: event.colno || 0 } );
	}, true );
	window.addEventListener( 'unhandledrejection', function ( event ) {
		const reason = event.reason && event.reason.message ? event.reason.message : String( event.reason || 'Unhandled rejection' );
		record( 'unhandled-rejection', { message: reason } );
	} );

	[ 'pointerdown', 'pointerup', 'pointercancel', 'click' ].forEach( function ( name ) {
		document.addEventListener( name, function ( event ) {
			const data = cardData( event );
			if ( data ) record( 'connections-' + name + '-capture', data );
		}, true );
	} );
	document.addEventListener( 'click', function ( event ) {
		const data = cardData( event );
		if ( data ) record( 'connections-click-bubble', data );
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key !== 'Enter' && event.key !== ' ' ) return;
		const data = cardData( event );
		if ( data ) record( 'connections-keydown-capture', Object.assign( { keyPressed: event.key }, data ) );
	}, true );

	function snapshot() {
		const cards = Array.from( document.querySelectorAll( '.afc-connection-card[data-afc-connection-key]' ) );
		record( 'connections-snapshot', {
			readyState: document.readyState,
			bodyClass: document.body ? document.body.className : '',
			cards: cards.length,
			cardNodes: cards.map( describe ),
			hasApi: Boolean( window.AFCUI && typeof window.AFCUI.openPanel === 'function' ),
		} );
		window.setTimeout( function () {
			record( 'connections-stability', { cards: cards.length, stillConnected: cards.filter( function ( card ) { return card.isConnected; } ).length } );
		}, 1000 );
	}

	function initialSnapshots() {
		snapshot();
		window.setTimeout( snapshot, 800 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', initialSnapshots );
	else initialSnapshots();
	window.addEventListener( 'pagehide', function () { flush( true ); } );
}() );
