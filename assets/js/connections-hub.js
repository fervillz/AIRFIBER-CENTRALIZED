( function () {
	'use strict';

	const cfg = window.afcConnectionsHub || {};
	let cards = Array.isArray( cfg.cards ) ? cfg.cards.slice() : [];
	let hub = null;
	let dialog = null;
	let frame = 0;
	let refreshTimer = 0;
	let dragKey = '';

	const $ = function ( selector, scope ) { return ( scope || document ).querySelector( selector ); };
	const $$ = function ( selector, scope ) { return Array.from( ( scope || document ).querySelectorAll( selector ) ); };
	const esc = function ( value ) { const node = document.createElement( 'div' ); node.textContent = value == null ? '' : String( value ); return node.innerHTML; };

	function advanced() {
		return document.body.classList.contains( 'afc-admin-mode-advanced' );
	}

	function panel() {
		return $( '[data-afc-panel="integrations"]' );
	}

	function typeLabel( type, technology ) {
		if ( type === 'olt' ) return ( technology === 'EPON' ? 'EPON' : 'GPON' ) + ' OLT';
		if ( type === 'mikrotik' ) return 'MikroTik';
		return 'Google Sheet';
	}

	function statusClass( state ) {
		return [ 'online', 'error', 'offline', 'draft' ].indexOf( state ) >= 0 ? state : 'draft';
	}

	function cardHtml( card ) {
		const label = typeLabel( card.type, card.technology );
		const title = esc( card.title || label );
		const subtitle = esc( card.subtitle || '' );
		const meta = esc( card.meta || '' );
		const status = esc( card.status || '' );
		return '<article class="afc-connection-card is-' + statusClass( card.state ) + '" data-afc-connection-key="' + esc( card.key ) + '" data-afc-connection-type="' + esc( card.type ) + '" data-afc-connection-id="' + esc( card.id ) + '" tabindex="0" role="button" aria-label="Open ' + title + '">' +
			'<button type="button" class="afc-connection-drag" data-afc-connection-drag data-afc-no-auto-icon aria-label="Drag to reorder" title="Drag to reorder">⠿</button>' +
			'<span class="afc-connection-dot" aria-hidden="true"></span>' +
			'<div class="afc-connection-center"><span class="afc-connection-type">' + esc( label ) + '</span><h3 title="' + title + '">' + title + '</h3><p title="' + subtitle + '">' + subtitle + '</p></div>' +
			'<div class="afc-connection-details"><div><span>' + meta + '</span><strong>' + status + '</strong></div><small>' + esc( card.type === 'olt' ? 'Optical monitoring' : ( card.type === 'mikrotik' ? 'RouterOS API connection' : 'Reporting & sync' ) ) + '</small><button type="button" class="afc-connection-open" data-afc-connection-open data-afc-no-auto-icon>Open settings</button></div>' +
		'</article>';
	}

	function buildHub() {
		const root = panel();
		if ( ! root || ! advanced() ) return;
		let target = $( '.afc-integrations-page', root ) || root;
		if ( ! hub || ! hub.isConnected ) {
			hub = document.createElement( 'section' );
			hub.className = 'afc-connections-hub';
			target.insertBefore( hub, target.firstChild );
		}
		const existingOrder = $$( '[data-afc-connection-key]', hub ).map( function ( node ) { return node.getAttribute( 'data-afc-connection-key' ); } );
		const incoming = new Map( cards.map( function ( card ) { return [ card.key, card ]; } ) );
		const ordered = [];
		existingOrder.forEach( function ( key ) { if ( incoming.has( key ) ) { ordered.push( incoming.get( key ) ); incoming.delete( key ); } } );
		cards.forEach( function ( card ) { if ( incoming.has( card.key ) ) { ordered.push( card ); incoming.delete( card.key ); } } );
		cards = ordered;
		hub.innerHTML = '<div class="afc-connections-head"><div><small>NETWORK & SERVICES</small><h2>Connections</h2><p>All devices and external services in one place.</p></div></div>' +
			'<div class="afc-connections-grid" data-afc-connections-grid>' +
			'<button type="button" class="afc-connection-add" data-afc-connection-add data-afc-no-auto-icon><span class="afc-connection-add-plus">+</span><strong>Add connection</strong><small>OLT, MikroTik or Google Sheet</small></button>' +
			cards.map( cardHtml ).join( '' ) +
			'</div>' +
			( cards.length ? '' : '<p class="afc-connection-empty">No connections have been added yet.</p>' );
		bindHub();
		polishWorkspace();
	}

	function ensureDialog() {
		if ( dialog && dialog.isConnected ) return;
		dialog = document.createElement( 'dialog' );
		dialog.className = 'afc-connection-add-dialog';
		dialog.innerHTML = '<div class="afc-connection-add-shell"><header><div><small>ADD CONNECTION</small><h2>What do you want to connect?</h2></div><button type="button" class="afc-connection-add-close" data-afc-connection-add-close data-afc-no-auto-icon aria-label="Close">×</button></header><div class="afc-connection-add-options">' +
			'<button type="button" class="afc-connection-add-option" data-afc-add-type="olt" data-afc-no-auto-icon><span>RX</span><div><strong>OLT</strong><small>Add another optical line terminal.</small></div></button>' +
			'<button type="button" class="afc-connection-add-option" data-afc-add-type="mikrotik" data-afc-no-auto-icon><span>⌁</span><div><strong>MikroTik</strong><small>Connect a RouterOS device.</small></div></button>' +
			'<button type="button" class="afc-connection-add-option" data-afc-add-type="sheet" data-afc-no-auto-icon><span>▦</span><div><strong>Google Sheet</strong><small>Connect reporting and sync.</small></div></button>' +
		'</div></div>';
		document.body.appendChild( dialog );
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog || event.target.closest( '[data-afc-connection-add-close]' ) ) dialog.close();
			const option = event.target.closest( '[data-afc-add-type]' );
			if ( option ) {
				dialog.close();
				openAdd( option.getAttribute( 'data-afc-add-type' ) );
			}
		} );
	}

	function waitFor( selector, callback, attempts ) {
		const node = $( selector );
		if ( node ) return callback( node );
		if ( attempts <= 0 ) return;
		window.setTimeout( function () { waitFor( selector, callback, attempts - 1 ); }, 100 );
	}

	function openPanel( key, after ) {
		if ( window.AFCUI && typeof window.AFCUI.openPanel === 'function' ) {
			window.AFCUI.openPanel( key );
		} else {
			const trigger = $( '[data-afc-app-panel="' + key + '"]' );
			if ( trigger ) trigger.click();
		}
		if ( after ) window.setTimeout( after, 80 );
	}

	function openAdd( type ) {
		if ( type === 'olt' ) {
			openPanel( 'olt', function () { waitFor( '[data-afc-olt-add]', function ( node ) { node.click(); }, 30 ); } );
			return;
		}
		if ( type === 'mikrotik' ) {
			openPanel( 'mikrotik', function () { waitFor( '[data-afc-mikrotik-add]', function ( node ) { node.click(); }, 30 ); } );
			return;
		}
		openPanel( 'integrations', function () { waitFor( '[data-afc-sheet-add]', function ( node ) { node.click(); }, 30 ); } );
	}

	function openCard( node ) {
		if ( ! node ) return;
		const type = node.getAttribute( 'data-afc-connection-type' );
		const id = node.getAttribute( 'data-afc-connection-id' );
		if ( type === 'olt' ) {
			openPanel( 'olt', function () { waitFor( '[data-afc-olt-card="' + CSS.escape( id ) + '"]', function ( target ) { target.click(); }, 30 ); } );
			return;
		}
		if ( type === 'mikrotik' ) {
			openPanel( 'mikrotik', function () { waitFor( '[data-afc-mikrotik-card]', function ( target ) { target.click(); }, 30 ); } );
			return;
		}
		openPanel( 'integrations', function () { waitFor( '[data-afc-sheet-settings]', function ( target ) { target.click(); }, 30 ); } );
	}

	function bindHub() {
		if ( ! hub ) return;
		const grid = $( '[data-afc-connections-grid]', hub );
		if ( ! grid ) return;
		grid.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-connection-drag]' ) ) return;
			if ( event.target.closest( '[data-afc-connection-add]' ) ) {
				ensureDialog();
				if ( dialog.open ) dialog.close();
				dialog.showModal();
				return;
			}
			const card = event.target.closest( '[data-afc-connection-key]' );
			if ( card ) openCard( card );
		} );
		grid.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Enter' && event.key !== ' ' ) return;
			const card = event.target.closest( '[data-afc-connection-key]' );
			if ( card && ! event.target.closest( '[data-afc-connection-drag]' ) ) { event.preventDefault(); openCard( card ); }
		} );
		$$( '[data-afc-connection-key]', grid ).forEach( bindDrag );
	}

	function bindDrag( card ) {
		const handle = $( '[data-afc-connection-drag]', card );
		if ( ! handle ) return;
		handle.addEventListener( 'pointerdown', function () { dragKey = card.getAttribute( 'data-afc-connection-key' ); card.setAttribute( 'draggable', 'true' ); } );
		card.addEventListener( 'dragstart', function ( event ) {
			if ( dragKey !== card.getAttribute( 'data-afc-connection-key' ) ) { event.preventDefault(); return; }
			card.classList.add( 'is-dragging' );
			if ( event.dataTransfer ) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData( 'text/plain', dragKey ); }
		} );
		card.addEventListener( 'dragover', function ( event ) { event.preventDefault(); if ( dragKey && dragKey !== card.getAttribute( 'data-afc-connection-key' ) ) card.classList.add( 'is-drop-target' ); } );
		card.addEventListener( 'dragleave', function () { card.classList.remove( 'is-drop-target' ); } );
		card.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			card.classList.remove( 'is-drop-target' );
			const dragged = hub && hub.querySelector( '[data-afc-connection-key="' + CSS.escape( dragKey ) + '"]' );
			if ( ! dragged || dragged === card ) return;
			const rect = card.getBoundingClientRect();
			const after = event.clientY > rect.top + rect.height / 2;
			card.parentNode.insertBefore( dragged, after ? card.nextSibling : card );
			saveOrder();
		} );
		card.addEventListener( 'dragend', function () {
			card.classList.remove( 'is-dragging' );
			$$( '.is-drop-target', hub ).forEach( function ( node ) { node.classList.remove( 'is-drop-target' ); } );
			card.removeAttribute( 'draggable' );
			dragKey = '';
		} );
	}

	function saveOrder() {
		if ( ! hub || ! cfg.ajaxUrl || ! cfg.nonce ) return;
		const order = $$( '[data-afc-connection-key]', hub ).map( function ( node ) { return node.getAttribute( 'data-afc-connection-key' ); } );
		const body = new URLSearchParams();
		body.set( 'action', 'afc_connections_save_order' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'order', JSON.stringify( order ) );
		fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() } ).catch( function () {} );
		cards.sort( function ( a, b ) { return order.indexOf( a.key ) - order.indexOf( b.key ); } );
	}

	function refreshCards() {
		if ( ! advanced() || ! cfg.ajaxUrl || ! cfg.nonce ) return;
		window.clearTimeout( refreshTimer );
		refreshTimer = window.setTimeout( function () {
			const body = new URLSearchParams();
			body.set( 'action', 'afc_connections_status' );
			body.set( 'nonce', cfg.nonce );
			fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( response ) { if ( response && response.success && response.data && Array.isArray( response.data.cards ) ) { cards = response.data.cards; buildHub(); } } )
				.catch( function () {} );
		}, 220 );
	}

	function polishWorkspace() {
		if ( ! advanced() ) return;
		const connections = $( '.afc-workspace-menu-item[data-afc-ws-panel="integrations"]' );
		const mikrotik = $( '.afc-workspace-menu-item[data-afc-ws-panel="mikrotik"]' );
		const optical = $( '.afc-workspace-menu-item[data-afc-ws-panel="optical"]' );
		if ( mikrotik ) mikrotik.hidden = true;
		if ( optical ) optical.hidden = true;
		if ( connections ) {
			connections.hidden = false;
			connections.classList.add( 'afc-connections-menu-item' );
			const title = $( 'b strong', connections ); if ( title ) title.textContent = cfg.labels && cfg.labels.title ? cfg.labels.title : 'Connections';
			const small = $( 'b small', connections ); if ( small ) small.textContent = cfg.labels && cfg.labels.subtitle ? cfg.labels.subtitle : 'Devices & integrations';
			const icon = $( ':scope > span', connections ); if ( icon ) icon.textContent = '⌁';
			const activePanel = $( '[data-afc-panel].is-active:not([hidden])' );
			const activeKey = activePanel ? activePanel.getAttribute( 'data-afc-panel' ) : '';
			connections.classList.toggle( 'is-active', [ 'integrations', 'mikrotik', 'optical', 'olt' ].indexOf( activeKey ) >= 0 );
		}
		const root = panel();
		if ( root ) {
			const head = $( ':scope > .afc-workspace-pagehead', root );
			if ( head ) {
				const title = $( '.afc-workspace-title h1', head ); if ( title ) title.textContent = 'Connections';
				const icon = $( '.afc-workspace-title > span', head ); if ( icon ) icon.textContent = '⌁';
				const subnav = $( '.afc-workspace-subnav', head ); if ( subnav ) subnav.hidden = true;
				const stats = $( '.afc-workspace-stats', head ); if ( stats ) stats.hidden = true;
			}
		}
	}

	function queue() {
		if ( frame ) return;
		frame = window.requestAnimationFrame( function () { frame = 0; if ( advanced() ) { buildHub(); polishWorkspace(); } } );
	}

	function boot() {
		queue();
		refreshCards();
		document.addEventListener( 'afc:admin-mode-change', function () { queue(); refreshCards(); } );
		document.addEventListener( 'afc:ajaxify-panel-loaded', function () { queue(); refreshCards(); } );
		document.addEventListener( 'afc:olt-connections-updated', refreshCards );
		document.addEventListener( 'click', function ( event ) {
			const menu = event.target.closest( '.afc-workspace-menu-item[data-afc-ws-panel="integrations"]' );
			if ( menu ) window.setTimeout( refreshCards, 120 );
		} );
		const observer = new MutationObserver( function ( mutations ) {
			/* Rebuilding the hub mutates its own descendants. Ignore those changes
			 * so the observer cannot replace every card again on the next frame. */
			const externalChange = mutations.some( function ( mutation ) {
				return ! hub || ! hub.contains( mutation.target );
			} );
			if ( externalChange ) queue();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
