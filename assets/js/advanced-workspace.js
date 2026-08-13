( function () {
	'use strict';

	const cfg = window.afcAdvancedWorkspace || {};
	const panels = Object.assign( {}, cfg.panels || {} );
	const groups = Array.isArray( cfg.groups ) ? cfg.groups : [];
	const moved = new Map();
	const views = {};
	let app;
	let side;
	let active = '';
	let timer;
	let observer;
	let popover;
	let modal;
	let menuReturnFocus = null;

	const $ = function ( selector, scope ) { return ( scope || document ).querySelector( selector ); };
	const $$ = function ( selector, scope ) { return Array.from( ( scope || document ).querySelectorAll( selector ) ); };
	const text = function ( value ) { return value == null ? '' : String( value ).trim(); };
	const esc = function ( value ) { const node = document.createElement( 'div' ); node.textContent = value == null ? '' : String( value ); return node.innerHTML; };
	const advanced = function () { return document.body.classList.contains( 'afc-admin-mode-advanced' ); };
	const panel = function ( key ) { return $( '[data-afc-panel="' + key + '"]', app ); };
	const desktopSidebar = function () { return window.matchMedia( '(min-width: 981px)' ).matches; };

	function ensureSidebarStyles() {
		if ( document.getElementById( 'afc-workspace-sidebar-smooth-runtime' ) ) return;
		const script = document.querySelector( 'script[src*="/assets/js/advanced-workspace.js"]' );
		if ( ! script || ! script.src ) return;
		const marker = '/assets/js/advanced-workspace.js';
		const index = script.src.indexOf( marker );
		if ( index < 0 ) return;
		const root = script.src.slice( 0, index );
		[
			[ 'afc-workspace-sidebar-smooth-runtime', '/assets/css/advanced-workspace-sidebar-smooth.css' ],
			[ 'afc-workspace-sidebar-polish-runtime', '/assets/css/advanced-workspace-sidebar-polish.css' ],
		].forEach( function ( asset ) {
			if ( document.getElementById( asset[ 0 ] ) ) return;
			const link = document.createElement( 'link' );
			link.id = asset[ 0 ];
			link.rel = 'stylesheet';
			link.href = root + asset[ 1 ] + '?v=' + encodeURIComponent( cfg.version || 'sidebar' );
			document.head.appendChild( link );
		} );
	}

	function meta( key ) {
		return panels[ key ] || { group: 'system', title: key, short: 'Advanced tool', description: '', icon: '•', order: 999 };
	}

	function current() {
		const node = $( '[data-afc-panel].is-active:not([hidden])', app );
		return node ? node.getAttribute( 'data-afc-panel' ) || '' : '';
	}

	function ordered() {
		return Object.keys( panels ).filter( function ( key ) { return Boolean( panel( key ) ); } ).sort( function ( a, b ) {
			return Number( meta( a ).order || 999 ) - Number( meta( b ).order || 999 );
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( refresh, 80 );
	}

	function summary( key ) {
		const node = panel( key );
		if ( ! node ) return '';
		const selectors = {
			dashboard: '[data-afc-dashboard-count="due"]',
			operations: '[data-summary="total"]',
			schedulers: '.afc-scheduler-summary strong',
			sms: '#afc-sms-device-state',
			mikrotik: '.badge',
			integrations: '[data-afc-google-status]',
		};
		const value = selectors[ key ] ? $( selectors[ key ], node ) : null;
		const output = value ? text( value.textContent ) : '';
		return output === '—' ? '' : output;
	}

	function collapsed() {
		return document.body.classList.contains( 'afc-workspace-sidebar-collapsed' );
	}

	function syncSidebarState() {
		if ( ! side ) return;
		const isCollapsed = collapsed();
		const collapseButton = $( '[data-afc-ws-collapse]', side );
		if ( collapseButton ) {
			collapseButton.setAttribute( 'aria-expanded', isCollapsed ? 'false' : 'true' );
			collapseButton.setAttribute( 'aria-label', isCollapsed ? 'Expand menu' : 'Collapse menu' );
			collapseButton.setAttribute( 'title', isCollapsed ? 'Expand menu' : 'Collapse menu' );
		}
		const mobileToggle = $( '.afc-workspace-mobile-toggle', app );
		if ( mobileToggle ) {
			const open = document.body.classList.contains( 'afc-workspace-menu-open' );
			mobileToggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			mobileToggle.setAttribute( 'aria-label', open ? 'Close menu' : 'Open menu' );
		}
		side.setAttribute( 'data-afc-sidebar-state', isCollapsed && desktopSidebar() ? 'collapsed' : 'expanded' );
	}

	function setCollapsed( state ) {
		document.body.classList.toggle( 'afc-workspace-sidebar-collapsed', Boolean( state ) );
		try { localStorage.setItem( 'afcWorkspaceCollapsed', state ? '1' : '0' ); } catch ( error ) {}
		syncSidebarState();
	}

	function closeMobileMenu( restoreFocus ) {
		const wasOpen = document.body.classList.contains( 'afc-workspace-menu-open' );
		document.body.classList.remove( 'afc-workspace-menu-open' );
		syncSidebarState();
		if ( wasOpen && restoreFocus && menuReturnFocus && typeof menuReturnFocus.focus === 'function' ) {
			menuReturnFocus.focus();
		}
		menuReturnFocus = null;
	}

	function makeSide() {
		if ( side ) return;
		side = document.createElement( 'aside' );
		side.id = 'afc-workspace-sidebar';
		side.className = 'afc-workspace-sidebar';
		side.innerHTML =
			'<header><div><small>WORKSPACE</small><strong>' + esc( cfg.labels && cfg.labels.advanced || 'Advanced workspace' ) + '</strong></div><button type="button" data-afc-ws-collapse aria-controls="afc-workspace-sidebar" aria-expanded="true" aria-label="Collapse menu" title="Collapse menu"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg></button></header>' +
			'<label class="afc-workspace-search" title="Find a tool"><span>⌕</span><input type="search" placeholder="' + esc( cfg.labels && cfg.labels.findTool || 'Find a tool…' ) + '"></label>' +
			'<nav></nav><footer><span>Airfiber</span><small>v' + esc( cfg.version || '' ) + '</small></footer>';
		const header = $( '.afc-frontend-header', app );
		header.parentNode.insertBefore( side, header.nextSibling );
		$( 'input', side ).addEventListener( 'input', renderSide );

		const search = $( '.afc-workspace-search', side );
		search.addEventListener( 'click', function () {
			if ( desktopSidebar() && collapsed() ) {
				setCollapsed( false );
				window.setTimeout( function () { const input = $( 'input', search ); if ( input ) input.focus(); }, 180 );
			}
		} );

		side.addEventListener( 'click', function ( event ) {
			const open = event.target.closest( '[data-afc-ws-panel]' );
			if ( open ) return openPanel( open.getAttribute( 'data-afc-ws-panel' ) );
			const help = event.target.closest( '[data-afc-ws-help]' );
			if ( help ) return showTip( help, help.getAttribute( 'data-afc-ws-help' ) );
			if ( event.target.closest( '[data-afc-ws-collapse]' ) ) {
				setCollapsed( ! collapsed() );
			}
		} );

		const actions = $( '.afc-frontend-header-actions', app );
		if ( actions ) {
			const toggle = document.createElement( 'button' );
			toggle.type = 'button';
			toggle.className = 'afc-workspace-mobile-toggle';
			toggle.setAttribute( 'aria-controls', 'afc-workspace-sidebar' );
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.setAttribute( 'aria-label', 'Open menu' );
			toggle.innerHTML = '<i></i><i></i><i></i>';
			toggle.addEventListener( 'click', function () {
				const opening = ! document.body.classList.contains( 'afc-workspace-menu-open' );
				if ( opening ) menuReturnFocus = toggle;
				document.body.classList.toggle( 'afc-workspace-menu-open', opening );
				syncSidebarState();
				if ( opening ) window.setTimeout( function () { const input = $( '.afc-workspace-search input', side ); if ( input ) input.focus(); }, 160 );
			} );
			actions.insertBefore( toggle, actions.firstChild );
		}
		syncSidebarState();
	}

	function renderSide() {
		if ( ! side ) return;
		const query = text( $( 'input', side ).value ).toLowerCase();
		const nav = $( 'nav', side );
		let html = '';
		groups.forEach( function ( group ) {
			const keys = ordered().filter( function ( key ) {
				const item = meta( key );
				return item.group === group.id && ( ! query || [ item.title, item.short, item.description, key ].join( ' ' ).toLowerCase().includes( query ) );
			} );
			if ( ! keys.length ) return;
			html += '<section><header><strong>' + esc( group.label ) + '</strong><button type="button" data-afc-ws-help="' + esc( group.description || '' ) + '">?</button></header>';
			keys.forEach( function ( key ) {
				const item = meta( key );
				const label = item.title || key;
				html += '<button type="button" class="afc-workspace-menu-item' + ( key === active ? ' is-active' : '' ) + '" data-afc-ws-panel="' + esc( key ) + '" data-afc-ws-label="' + esc( label ) + '" aria-label="' + esc( label ) + '"><span>' + esc( item.icon || '•' ) + '</span><b><strong>' + esc( item.title ) + '</strong><small>' + esc( item.short || '' ) + '</small></b><em>' + esc( summary( key ) ) + '</em></button>';
			} );
			html += '</section>';
		} );
		nav.innerHTML = html || '<p class="afc-workspace-empty">No matching tools.</p>';
		syncSidebarState();
	}

	function openPanel( key, view ) {
		const original = $( '[data-afc-app-panel="' + key + '"]', app );
		if ( original ) original.click();
		window.setTimeout( function () {
			active = key;
			refresh();
			if ( view ) setView( key, view );
			closeMobileMenu( false );
		}, 0 );
	}

	function sourceHeader( key, node ) {
		const map = { dashboard: '.afc-dashboard-header', operations: '.page-header', schedulers: '.afc-scheduler-header', sms: '.afc-sms-page-header', mikrotik: '.page-header', integrations: '.afc-integrations-header' };
		return $( map[ key ] || '.page-header', node );
	}

	function moveActions( key, node, target ) {
		if ( moved.has( key ) ) return;
		const map = { dashboard: '.afc-dashboard-header-actions', operations: '.afc-ppp-header-actions', schedulers: '.afc-scheduler-header > button', sms: '.afc-sms-page-header .col-auto' };
		const action = map[ key ] ? $( map[ key ], node ) : null;
		if ( ! action ) return;
		const marker = document.createComment( 'afc-ws-' + key );
		action.parentNode.insertBefore( marker, action );
		target.appendChild( action );
		action.classList.add( 'afc-workspace-moved-actions' );
		moved.set( key, { action: action, marker: marker } );
	}

	function pageHead( key, node ) {
		let head = $( ':scope > .afc-workspace-pagehead', node );
		if ( ! head ) {
			head = document.createElement( 'header' );
			head.className = 'afc-workspace-pagehead';
			head.innerHTML = '<div class="afc-workspace-title"><span></span><div><small></small><h1></h1></div><button type="button" data-afc-ws-about>?</button></div><div class="afc-workspace-actions"></div><div class="afc-workspace-head-bottom"><div class="afc-workspace-stats"></div><nav class="afc-workspace-subnav"></nav></div>';
			node.insertBefore( head, node.firstChild );
			head.addEventListener( 'click', function ( event ) {
				const about = event.target.closest( '[data-afc-ws-about]' );
				if ( about ) return openModal( meta( key ).title, about.getAttribute( 'data-description' ) || meta( key ).description );
				const sub = event.target.closest( '[data-afc-ws-view]' );
				if ( sub ) setView( key, sub.getAttribute( 'data-afc-ws-view' ) );
			} );
		}
		const item = meta( key );
		const group = groups.find( function ( groupItem ) { return groupItem.id === item.group; } );
		$( '.afc-workspace-title > span', head ).textContent = item.icon || '•';
		$( '.afc-workspace-title small', head ).textContent = group ? group.label : 'Advanced';
		$( '.afc-workspace-title h1', head ).textContent = item.title || key;
		const original = sourceHeader( key, node );
		let description = item.description || '';
		if ( original ) {
			const p = $( 'p', original );
			if ( p && text( p.textContent ) ) description = text( p.textContent );
			original.classList.add( 'afc-workspace-source-header' );
		}
		$( '[data-afc-ws-about]', head ).setAttribute( 'data-description', description );
		moveActions( key, node, $( '.afc-workspace-actions', head ) );
		renderStats( key, head );
		renderViews( key, node, head );
	}

	function stats( key ) {
		const node = panel( key );
		if ( ! node ) return [];
		if ( key === 'operations' ) return [ [ 'Accounts', '#afc-ppp-summary [data-summary="total"]' ], [ 'Online', '#afc-ppp-summary [data-summary="online"]' ], [ 'Expired', '#afc-ppp-summary [data-summary="expired"]' ] ];
		if ( key === 'sms' ) return [ [ 'Gateway', '#afc-sms-device-state' ], [ 'Queued', '[data-afc-sms-count="queued"]' ], [ 'Delivered', '[data-afc-sms-count="delivered"]' ] ];
		if ( key === 'integrations' ) return [ [ 'Sheets', '[data-afc-google-status]' ], [ 'Messenger', '[data-afc-messenger-status]' ] ];
		if ( key === 'dashboard' ) return [ [ 'Due', '[data-afc-dashboard-count="due"]' ], [ 'Expired', '[data-afc-dashboard-count="expired"]' ], [ 'Paid', '[data-afc-dashboard-count="payments"]' ] ];
		return [];
	}

	function renderStats( key, head ) {
		const node = panel( key );
		const list = stats( key ).map( function ( item ) {
			const value = $( item[ 1 ], node );
			return [ item[ 0 ], value ? text( value.textContent ) : '' ];
		} ).filter( function ( item ) { return item[ 1 ] && item[ 1 ] !== '—'; } );
		const target = $( '.afc-workspace-stats', head );
		target.innerHTML = list.map( function ( item ) { return '<span><small>' + esc( item[ 0 ] ) + '</small><strong>' + esc( item[ 1 ] ) + '</strong></span>'; } ).join( '' );
		target.hidden = ! list.length;
	}

	function viewList( key, node ) {
		if ( key === 'operations' ) return [ [ 'accounts', 'PPP Accounts' ], [ 'collection', 'Collection' ] ];
		if ( key === 'sms' ) return [ [ 'messages', 'Messages' ], [ 'gateway', 'Gateway' ] ];
		if ( key === 'mikrotik' ) return [ [ 'connection', 'Connection' ], [ 'help', 'Help & Test' ] ];
		if ( key === 'schedulers' ) return $$( '.afc-scheduler-mega [data-afc-scheduler-view]', node ).map( function ( button ) { return [ button.getAttribute( 'data-afc-scheduler-view' ), text( $( 'strong', button ) ? $( 'strong', button ).textContent : button.textContent ) ]; } );
		if ( key === 'integrations' ) {
			const out = [];
			if ( $( '.afc-google-card', node ) ) out.push( [ 'google', 'Google Sheets' ] );
			if ( $( '.afc-messenger-card', node ) ) {
				out.push( [ 'messenger', 'Messenger' ] );
				$$( '.afc-messenger-section', node ).forEach( function ( section, index ) { const label = $( 'header small', section ); out.push( [ 'messenger-' + index, titleCase( label ? label.textContent : 'Messenger ' + ( index + 1 ) ) ] ); } );
			}
			return out;
		}
		return [];
	}

	function titleCase( value ) { return text( value ).toLowerCase().replace( /\b\w/g, function ( letter ) { return letter.toUpperCase(); } ); }

	function renderViews( key, node, head ) {
		const list = viewList( key, node );
		const nav = $( '.afc-workspace-subnav', head );
		if ( ! list.length ) { nav.hidden = true; nav.innerHTML = ''; return; }
		let selected = views[ key ];
		if ( ! selected ) { try { selected = sessionStorage.getItem( 'afcWsView:' + key ); } catch ( error ) {} }
		if ( ! list.some( function ( item ) { return item[ 0 ] === selected; } ) ) selected = list[ 0 ][ 0 ];
		views[ key ] = selected;
		nav.hidden = false;
		nav.innerHTML = list.map( function ( item ) { return '<button type="button" class="' + ( item[ 0 ] === selected ? 'is-active' : '' ) + '" data-afc-ws-view="' + esc( item[ 0 ] ) + '">' + esc( item[ 1 ] ) + '</button>'; } ).join( '' );
		setView( key, selected, true );
	}

	function setView( key, selected, quiet ) {
		const node = panel( key );
		if ( ! node ) return;
		views[ key ] = selected;
		try { sessionStorage.setItem( 'afcWsView:' + key, selected ); } catch ( error ) {}
		$$( ':scope > .afc-workspace-pagehead [data-afc-ws-view]', node ).forEach( function ( button ) { button.classList.toggle( 'is-active', button.getAttribute( 'data-afc-ws-view' ) === selected ); } );
		if ( key === 'operations' ) {
			const collection = $( '.afc-collection-card', node );
			const table = $( '#afc-ppp-table', node );
			if ( collection ) collection.hidden = selected !== 'collection';
			if ( table ) table.closest( '.card' ).hidden = selected !== 'accounts';
		}
		if ( key === 'sms' ) {
			const overview = $( '.afc-sms-overview-card', node );
			const messages = $( '.afc-sms-chat-card', node );
			if ( overview ) overview.hidden = selected !== 'gateway';
			if ( messages ) messages.hidden = selected !== 'messages';
		}
		if ( key === 'mikrotik' ) {
			const row = $( '.container-xl > .row', node );
			if ( row ) $$( ':scope > div', row ).forEach( function ( column, index ) { column.hidden = selected === 'connection' ? index !== 0 : index !== 1; column.classList.toggle( 'col-lg-12', ! column.hidden ); } );
		}
		if ( key === 'schedulers' ) {
			const button = $( '.afc-scheduler-mega [data-afc-scheduler-view="' + selected + '"]', node );
			if ( button && button.getAttribute( 'aria-pressed' ) !== 'true' ) button.click();
		}
		if ( key === 'integrations' ) {
			const messenger = selected.indexOf( 'messenger' ) === 0;
			$$( '.afc-integrations-grid > article', node ).forEach( function ( card ) { card.hidden = messenger ? ! card.classList.contains( 'afc-messenger-card' ) : ! card.classList.contains( 'afc-google-card' ); } );
			const index = selected.indexOf( 'messenger-' ) === 0 ? Number( selected.split( '-' )[ 1 ] ) : -1;
			$$( '.afc-messenger-section', node ).forEach( function ( section, sectionIndex ) { section.hidden = ! messenger || ( index >= 0 && index !== sectionIndex ); } );
			const save = $( '.afc-messenger-savebar', node ); if ( save ) save.hidden = ! messenger;
		}
		if ( ! quiet ) window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function helpButtons( node ) {
		$$( '.afc-field-help:not([data-afc-ws-ready])', node ).forEach( function ( help ) {
			const message = text( help.textContent );
			if ( ! message ) return;
			help.setAttribute( 'data-afc-ws-ready', '1' );
			help.classList.add( 'afc-workspace-help-source' );
			const button = document.createElement( 'button' );
			button.type = 'button'; button.className = 'afc-workspace-help'; button.textContent = '?';
			button.addEventListener( 'click', function () { showTip( button, message ); } );
			help.parentNode.insertBefore( button, help );
		} );
	}

	function refresh() {
		if ( ! app || ! advanced() ) return;
		active = current() || active || 'dashboard';
		ordered().forEach( function ( key ) { const node = panel( key ); if ( node ) { node.classList.add( 'afc-workspace-panel' ); pageHead( key, node ); helpButtons( node ); } } );
		renderSide();
	}

	function showTip( trigger, message ) {
		if ( ! popover ) { popover = document.createElement( 'div' ); popover.className = 'afc-ui-popover'; popover.hidden = true; document.body.appendChild( popover ); }
		popover.textContent = message || '';
		popover.hidden = false;
		const box = trigger.getBoundingClientRect();
		const width = Math.min( 340, window.innerWidth - 24 );
		popover.style.width = width + 'px';
		popover.style.left = Math.max( 12, Math.min( window.innerWidth - width - 12, box.left + box.width / 2 - width / 2 ) ) + 'px';
		popover.style.top = Math.min( window.innerHeight - popover.offsetHeight - 12, box.bottom + 9 ) + 'px';
	}

	function openModal( title, content ) {
		if ( ! modal ) {
			modal = document.createElement( 'dialog' ); modal.className = 'afc-ui-modal';
			modal.innerHTML = '<div><header><h2></h2><button type="button">×</button></header><main></main><footer><button type="button" class="btn btn-primary">Close</button></footer></div>';
			document.body.appendChild( modal );
			modal.addEventListener( 'click', function ( event ) { if ( event.target === modal || event.target.closest( 'header button, footer button' ) ) modal.close(); } );
		}
		$( 'h2', modal ).textContent = title || '';
		$( 'main', modal ).textContent = content || '';
		if ( modal.open ) modal.close();
		modal.showModal();
	}

	function restore() {
		moved.forEach( function ( item ) { if ( item.marker.parentNode ) item.marker.parentNode.insertBefore( item.action, item.marker ); item.marker.remove(); item.action.classList.remove( 'afc-workspace-moved-actions' ); } );
		moved.clear();
		const operations = panel( 'operations' ); if ( operations ) $$( '.afc-collection-card, #afc-ppp-table', operations ).forEach( function ( node ) { ( node.closest( '.card' ) || node ).hidden = false; } );
		const sms = panel( 'sms' ); if ( sms ) $$( '.afc-sms-overview-card, .afc-sms-chat-card', sms ).forEach( function ( node ) { node.hidden = false; } );
		const integrations = panel( 'integrations' ); if ( integrations ) $$( '.afc-integrations-grid > article, .afc-messenger-section, .afc-messenger-savebar', integrations ).forEach( function ( node ) { node.hidden = false; } );
		$$( '.afc-workspace-pagehead', app ).forEach( function ( node ) { node.remove(); } );
		$$( '.afc-workspace-source-header', app ).forEach( function ( node ) { node.classList.remove( 'afc-workspace-source-header' ); } );
	}

	function sync() {
		if ( advanced() ) {
			document.body.classList.add( 'afc-workspace-active' );
			if ( side ) side.hidden = false;
			try { if ( localStorage.getItem( 'afcWorkspaceCollapsed' ) === '1' ) document.body.classList.add( 'afc-workspace-sidebar-collapsed' ); } catch ( error ) {}
			makeSide(); refresh(); syncSidebarState();
		} else {
			document.body.classList.remove( 'afc-workspace-active', 'afc-workspace-menu-open' );
			if ( side ) side.hidden = true;
			restore();
		}
	}

	function boot() {
		ensureSidebarStyles();
		app = document.getElementById( 'afc-frontend-app' );
		if ( ! app ) return;
		window.AFCUI = Object.assign( {}, window.AFCUI || {}, {
			openModal: function ( options ) { openModal( options && options.title, options && options.content ); },
			showTooltip: showTip,
			openPanel: openPanel,
			registerPanel: function ( key, details ) { panels[ key ] = Object.assign( {}, panels[ key ] || {}, details || {} ); schedule(); },
			getRegistry: function () { return Object.assign( {}, panels ); },
			apiEndpoint: cfg.apiEndpoint || '',
		} );
		sync();
		document.addEventListener( 'afc:admin-mode-change', sync );
		document.addEventListener( 'pointerdown', function ( event ) {
			if ( popover && ! popover.hidden && ! popover.contains( event.target ) && ! event.target.closest( '.afc-workspace-help, [data-afc-ws-help]' ) ) popover.hidden = true;
			if ( ! desktopSidebar() && document.body.classList.contains( 'afc-workspace-menu-open' ) && side && ! side.contains( event.target ) && ! event.target.closest( '.afc-workspace-mobile-toggle' ) ) closeMobileMenu( true );
		} );
		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' && document.body.classList.contains( 'afc-workspace-menu-open' ) ) {
				event.preventDefault();
				closeMobileMenu( true );
			}
		} );
		observer = new MutationObserver( function ( changes ) {
			if ( changes.some( function ( change ) { const node = change.target.nodeType === 3 ? change.target.parentElement : change.target; return node && ! node.closest( '.afc-workspace-sidebar, .afc-workspace-pagehead' ); } ) ) schedule();
		} );
		observer.observe( app, { childList: true, subtree: true, characterData: true } );
		window.addEventListener( 'resize', function () {
			if ( window.innerWidth > 980 ) closeMobileMenu( false );
			syncSidebarState();
		} );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot ); else boot();
}() );
