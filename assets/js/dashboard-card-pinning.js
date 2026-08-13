( function () {
	'use strict';

	const STORAGE_KEY = 'afcDashboardPinnedCardsV1';
	const PAYMENT_ID = 'payment';
	let root = null;
	let grid = null;
	let observer = null;
	let scheduled = false;
	let applying = false;
	let saveTimer = null;

	function readPins() {
		let ids = [];
		try {
			const parsed = JSON.parse( localStorage.getItem( STORAGE_KEY ) || '[]' );
			ids = Array.isArray( parsed ) ? parsed.map( String ) : [];
		} catch ( error ) {}
		if ( ids.indexOf( PAYMENT_ID ) < 0 ) ids.unshift( PAYMENT_ID );
		return new Set( ids );
	}

	function writePins( pins ) {
		const ids = Array.from( pins );
		if ( ids.indexOf( PAYMENT_ID ) < 0 ) ids.unshift( PAYMENT_ID );
		try { localStorage.setItem( STORAGE_KEY, JSON.stringify( ids ) ); } catch ( error ) {}
	}

	function pinIcon() {
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6l-1 6 3 3v2h-4v7l-1 1-1-1v-7H7v-2l3-3-1-6Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
	}

	function cardId( card ) {
		return card && card.getAttribute( 'data-afc-dashboard-widget' ) || '';
	}

	function ensureTools( card, pins ) {
		const id = cardId( card );
		const header = card.querySelector( '.afc-dashboard-card-head' );
		if ( ! id || ! header ) return;

		let tools = header.querySelector( '.afc-dashboard-card-tools' );
		if ( ! tools ) {
			tools = document.createElement( 'div' );
			tools.className = 'afc-dashboard-card-tools';
			const drag = header.querySelector( '.afc-dashboard-drag' );
			if ( drag ) {
				header.insertBefore( tools, drag );
				tools.appendChild( drag );
			} else {
				header.appendChild( tools );
			}
		}

		let button = tools.querySelector( '[data-afc-dashboard-pin]' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-dashboard-pin';
			button.setAttribute( 'data-afc-dashboard-pin', id );
			button.innerHTML = pinIcon();
			tools.insertBefore( button, tools.firstChild );
		}

		const pinned = id === PAYMENT_ID || pins.has( id );
		card.classList.toggle( 'is-pinned', pinned );
		button.classList.toggle( 'is-pinned', pinned );
		button.setAttribute( 'aria-pressed', pinned ? 'true' : 'false' );
		button.setAttribute( 'aria-label', id === PAYMENT_ID ? 'Payment search is pinned to the top' : ( pinned ? 'Unpin this dashboard card' : 'Pin this dashboard card to the top' ) );
		button.setAttribute( 'title', id === PAYMENT_ID ? 'Payment search stays pinned at the top' : ( pinned ? 'Unpin card' : 'Pin card' ) );
		button.toggleAttribute( 'data-afc-pin-locked', id === PAYMENT_ID );
	}

	function desiredCards( pins ) {
		const cards = Array.from( grid.querySelectorAll( ':scope > [data-afc-dashboard-widget]' ) );
		const payment = cards.filter( function ( card ) { return cardId( card ) === PAYMENT_ID; } );
		const pinned = cards.filter( function ( card ) { const id = cardId( card ); return id !== PAYMENT_ID && pins.has( id ); } );
		const normal = cards.filter( function ( card ) { const id = cardId( card ); return id !== PAYMENT_ID && ! pins.has( id ); } );
		return payment.concat( pinned, normal );
	}

	function sameOrder( current, desired ) {
		if ( current.length !== desired.length ) return false;
		for ( let i = 0; i < current.length; i++ ) if ( current[ i ] !== desired[ i ] ) return false;
		return true;
	}

	function saveCurrentOrder() {
		const config = window.afcMainDashboard || {};
		if ( ! config.ajaxUrl || ! config.nonce || ! grid ) return;
		window.clearTimeout( saveTimer );
		saveTimer = window.setTimeout( function () {
			const body = new URLSearchParams();
			body.set( 'action', 'afc_dashboard_save_layout' );
			body.set( 'nonce', config.nonce );
			Array.from( grid.querySelectorAll( ':scope > [data-afc-dashboard-widget]' ) ).forEach( function ( card ) {
				body.append( 'order[]', cardId( card ) );
			} );
			window.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
			} ).catch( function () {} );
		}, 120 );
	}

	function applyPins( persistOrder ) {
		if ( ! grid || applying ) return;
		const pins = readPins();
		const cards = Array.from( grid.querySelectorAll( ':scope > [data-afc-dashboard-widget]' ) );
		cards.forEach( function ( card ) { ensureTools( card, pins ); } );
		const desired = desiredCards( pins );
		if ( ! sameOrder( cards, desired ) ) {
			applying = true;
			desired.forEach( function ( card ) { grid.appendChild( card ); } );
			applying = false;
			if ( persistOrder ) saveCurrentOrder();
		}
	}

	function scheduleApply() {
		if ( scheduled ) return;
		scheduled = true;
		window.requestAnimationFrame( function () {
			scheduled = false;
			applyPins( false );
		} );
	}

	function bind() {
		root.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-afc-dashboard-pin]' );
			if ( ! button ) return;
			event.preventDefault();
			event.stopPropagation();
			const id = button.getAttribute( 'data-afc-dashboard-pin' ) || '';
			if ( id === PAYMENT_ID ) return;
			const pins = readPins();
			if ( pins.has( id ) ) pins.delete( id );
			else pins.add( id );
			pins.add( PAYMENT_ID );
			writePins( pins );
			applyPins( true );
		} );
	}

	function boot() {
		root = document.getElementById( 'afc-main-dashboard' );
		if ( ! root ) return;
		grid = root.querySelector( '[data-afc-dashboard-grid]' );
		if ( ! grid ) return;
		applyPins( true );
		bind();
		observer = new MutationObserver( function ( mutations ) {
			if ( applying ) return;
			if ( mutations.some( function ( mutation ) { return mutation.type === 'childList'; } ) ) scheduleApply();
		} );
		observer.observe( grid, { childList: true } );
		document.addEventListener( 'afc:ajaxify-fragment-loaded', scheduleApply );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
