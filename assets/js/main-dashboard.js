( function () {
	'use strict';

	const config = window.afcMainDashboard || {};
	const labels = config.labels || {};
	let root = null;
	let grid = null;
	let sortable = null;
	let loading = false;
	let loaded = false;
	let timer = null;
	let saveTimer = null;

	function text( value ) {
		return value == null ? '' : String( value );
	}

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = text( value );
		return node.innerHTML;
	}

	function request( action, data ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', config.nonce || '' );
		Object.keys( data || {} ).forEach( function ( key ) {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) { body.append( key + '[]', item ); } );
			} else {
				body.set( key, value == null ? '' : String( value ) );
			}
		} );
		return window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json().catch( function () { throw new Error( 'Invalid dashboard response.' ); } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Dashboard request failed.' );
			}
			return response.data || {};
		} );
	}

	function injectNavigation() {
		const nav = document.querySelector( '#afc-frontend-app .afc-frontend-nav' );
		if ( ! nav ) return;
		let button = nav.querySelector( '[data-afc-app-panel="dashboard"]' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.setAttribute( 'data-afc-app-panel', 'dashboard' );
			button.setAttribute( 'aria-pressed', 'false' );
			button.textContent = 'Dashboard';
		}
		if ( nav.firstElementChild !== button ) nav.insertBefore( button, nav.firstElementChild );
	}

	function applySavedOrder() {
		if ( ! grid || ! Array.isArray( config.layout ) ) return;
		const cards = new Map();
		grid.querySelectorAll( '[data-afc-dashboard-widget]' ).forEach( function ( card ) {
			cards.set( card.dataset.afcDashboardWidget, card );
		} );
		config.layout.forEach( function ( id ) {
			if ( cards.has( id ) ) grid.appendChild( cards.get( id ) );
		} );
	}

	function setSaveState( message, state ) {
		const target = root && root.querySelector( '[data-afc-dashboard-save-state]' );
		if ( ! target ) return;
		target.textContent = message || 'Drag cards to arrange';
		target.className = 'afc-dashboard-save-state' + ( state ? ' is-' + state : '' );
	}

	function saveLayout() {
		if ( ! grid ) return;
		window.clearTimeout( saveTimer );
		saveTimer = window.setTimeout( function () {
			const order = Array.from( grid.querySelectorAll( '[data-afc-dashboard-widget]' ) ).map( function ( card ) {
				return card.dataset.afcDashboardWidget;
			} );
			setSaveState( labels.saving || 'Saving layout…', 'saving' );
			request( 'afc_dashboard_save_layout', { order: order } ).then( function () {
				setSaveState( labels.saved || 'Layout saved', 'saved' );
				window.setTimeout( function () { setSaveState( 'Drag cards to arrange', '' ); }, 1600 );
			} ).catch( function () {
				setSaveState( 'Layout could not be saved', 'error' );
			} );
		}, 180 );
	}

	function initializeSortable() {
		if ( ! grid || sortable || ! window.Sortable ) return;
		sortable = window.Sortable.create( grid, {
			animation: 180,
			easing: 'cubic-bezier(.2,.8,.2,1)',
			handle: '.afc-dashboard-drag',
			ghostClass: 'afc-dashboard-ghost',
			chosenClass: 'afc-dashboard-chosen',
			dragClass: 'afc-dashboard-dragging',
			fallbackTolerance: 5,
			swapThreshold: 0.62,
			delay: 90,
			delayOnTouchOnly: true,
			touchStartThreshold: 4,
			onStart: function () { document.body.classList.add( 'afc-dashboard-is-sorting' ); },
			onEnd: function () {
				document.body.classList.remove( 'afc-dashboard-is-sorting' );
				saveLayout();
			},
		} );
	}

	/* The Advanced dashboard now owns its own PPP payment search. */
	function watchPaymentApp() {}

	function formatDate( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})/ );
		if ( ! match ) return text( value ) || '—';
		const date = new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ), 12, 0, 0 );
		return date.toLocaleDateString( [], { month: 'short', day: 'numeric' } );
	}

	function formatDateTime( value ) {
		const raw = text( value ).trim();
		if ( ! raw ) return '—';
		let normalized = raw.replace( ' ', 'T' );
		if ( /^\d{4}-\d{2}-\d{2}T/.test( normalized ) && ! /(?:Z|[+-]\d{2}:?\d{2})$/i.test( normalized ) ) normalized += 'Z';
		const date = new Date( normalized );
		return Number.isNaN( date.getTime() ) ? raw : date.toLocaleString( [], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } );
	}

	function relativeDay( value, future ) {
		const days = Number( value || 0 );
		if ( days === 0 ) return 'Today';
		if ( future ) return 'in ' + days + ' day' + ( days === 1 ? '' : 's' );
		return days + ' day' + ( days === 1 ? '' : 's' ) + ' ago';
	}

	function formatAmount( value ) {
		const raw = text( value ).trim();
		if ( ! raw ) return 'Amount not recorded';
		const number = Number( raw.replace( /[^0-9.-]/g, '' ) );
		if ( ! Number.isFinite( number ) ) return raw;
		try {
			return new Intl.NumberFormat( 'en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 } ).format( number );
		} catch ( error ) {
			return '₱' + number.toLocaleString();
		}
	}

	function itemDetails( item, group ) {
		if ( group === 'due' ) return formatDate( item.nextDue ) + ' · ' + relativeDay( item.daysUntil, true );
		if ( group === 'expired' ) return formatDate( item.cutoffDate ) + ' · ' + ( item.schedulerRan ? 'scheduler ran' : relativeDay( item.daysAgo, false ) );
		if ( group === 'payments' ) return formatAmount( item.paymentAmount ) + ( item.paymentMethod ? ' · ' + item.paymentMethod : '' );
		if ( group === 'installs' ) return formatDate( item.installed ) + ' · ' + relativeDay( item.daysAgo, false );
		if ( group === 'sms' ) return formatDateTime( item.delivered_at || item.sent_at || item.created_at );
		return '';
	}

	function itemTitle( item, group ) {
		if ( group === 'sms' ) return item.customer_name || item.ppp_username || item.phone || 'Unknown recipient';
		return item.customer || item.name || 'Unknown account';
	}

	function itemMeta( item, group ) {
		if ( group === 'sms' ) return item.ppp_username || item.phone || '';
		return item.name || '';
	}

	function itemBadge( item, group ) {
		if ( group === 'due' ) return '<span class="afc-dashboard-item-badge is-due">Due</span>';
		if ( group === 'expired' ) return '<span class="afc-dashboard-item-badge is-expired">Expired</span>';
		if ( group === 'payments' ) return '<span class="afc-dashboard-item-badge is-paid">Paid</span>';
		if ( group === 'installs' ) return '<span class="afc-dashboard-item-badge is-install">New</span>';
		if ( group === 'sms' ) return '<span class="afc-dashboard-item-badge is-sms is-' + escapeHtml( item.status || 'unknown' ) + '">' + escapeHtml( item.status || 'SMS' ) + '</span>';
		return '';
	}

	function emptyMessage( group ) {
		return {
			due: 'No customers are due within the next 7 days.',
			expired: 'No expired customers were returned.',
			payments: 'No payment dates were found.',
			installs: 'No installation dates were found.',
			sms: 'No recent SMS jobs were found.',
		}[ group ] || 'Nothing to show.';
	}

	function renderList( group, items ) {
		const target = root.querySelector( '[data-afc-dashboard-list="' + group + '"]' );
		if ( ! target ) return;
		if ( ! Array.isArray( items ) || ! items.length ) {
			target.innerHTML = '<div class="afc-dashboard-empty">' + escapeHtml( emptyMessage( group ) ) + '</div>';
			return;
		}
		target.innerHTML = items.map( function ( item ) {
			const excerpt = group === 'sms' ? text( item.message ).slice( 0, 74 ) : itemDetails( item, group );
			return '<button type="button" class="afc-dashboard-list-item" data-afc-dashboard-account="' + escapeHtml( item.name || item.ppp_username || '' ) + '" data-afc-dashboard-group="' + escapeHtml( group ) + '">' +
				'<span class="afc-dashboard-list-avatar">' + escapeHtml( itemTitle( item, group ).charAt( 0 ).toUpperCase() || '?' ) + '</span>' +
				'<span class="afc-dashboard-list-copy"><strong>' + escapeHtml( itemTitle( item, group ) ) + '</strong><small>' + escapeHtml( itemMeta( item, group ) ) + '</small><em>' + escapeHtml( excerpt ) + '</em></span>' +
				itemBadge( item, group ) +
			'</button>';
		} ).join( '' );
	}

	function renderCounts( counts ) {
		Object.keys( counts || {} ).forEach( function ( key ) {
			const target = root.querySelector( '[data-afc-dashboard-count="' + key + '"]' );
			if ( target ) target.textContent = Number( counts[ key ] || 0 ) + ( key === 'sms' ? ' sent today' : ' total' );
		} );
	}

	function renderRouterAlert( data ) {
		const alert = root.querySelector( '[data-afc-dashboard-router-alert]' );
		if ( ! alert ) return;
		const router = data.router || {};
		alert.className = 'afc-dashboard-router-alert ' + ( data.connected ? 'is-online' : 'is-offline' );
		alert.innerHTML = '<span class="afc-dashboard-router-dot"></span><div><strong>' +
			escapeHtml( data.connected ? ( router.name || 'MikroTik' ) + ' connected' : ( router.name || 'MikroTik' ) + ' unavailable' ) +
			'</strong><small>' + escapeHtml( data.connected ? [ router.board, router.version ].filter( Boolean ).join( ' · ' ) || router.message : router.message ) + '</small></div>' +
			'<button type="button" data-afc-dashboard-open-panel="mikrotik">' + ( data.connected ? 'View router' : 'Check settings' ) + ' →</button>';
	}

	function metricHtml( label, value, suffix, tone ) {
		return '<div class="afc-dashboard-metric ' + ( tone ? 'is-' + tone : '' ) + '"><span>' + escapeHtml( label ) + '</span><strong>' + escapeHtml( value ) + ( suffix ? '<small>' + escapeHtml( suffix ) + '</small>' : '' ) + '</strong></div>';
	}

	function renderNetwork( network ) {
		const metrics = root.querySelector( '[data-afc-dashboard-router-metrics]' );
		const ports = root.querySelector( '[data-afc-dashboard-ports]' );
		if ( ! metrics || ! ports ) return;
		if ( network && network.error ) {
			metrics.innerHTML = '<div class="afc-dashboard-network-error">' + escapeHtml( network.error ) + '</div>';
			ports.innerHTML = '';
			return;
		}
		metrics.innerHTML = metricHtml( 'CPU usage', Number( network.cpu || 0 ), '%', Number( network.cpu || 0 ) >= 80 ? 'danger' : 'good' ) +
			metricHtml( 'Memory', Number( network.memory || 0 ), '%', Number( network.memory || 0 ) >= 85 ? 'danger' : '' ) +
			metricHtml( 'Uptime', network.uptime || '—', '', '' ) +
			metricHtml( 'RouterOS', network.version || '—', '', '' );
		const items = Array.isArray( network.ports ) ? network.ports : [];
		ports.innerHTML = items.map( function ( port ) {
			const online = port.found && port.running && ! port.disabled;
			const state = ! port.found ? 'Not found' : ( port.disabled ? 'Disabled' : ( online ? 'Online' : 'No link' ) );
			return '<article class="afc-dashboard-port ' + ( online ? 'is-online' : 'is-offline' ) + '">' +
				'<header><span></span><strong>' + escapeHtml( port.name ) + '</strong><em>' + escapeHtml( state ) + '</em></header>' +
				'<div><span>↓ <strong>' + Number( port.rxMbps || 0 ).toFixed( 2 ) + '</strong> Mbps</span><span>↑ <strong>' + Number( port.txMbps || 0 ).toFixed( 2 ) + '</strong> Mbps</span></div>' +
				( port.comment ? '<small>' + escapeHtml( port.comment ) + '</small>' : '' ) +
			'</article>';
		} ).join( '' );
	}

	function renderData( data ) {
		renderRouterAlert( data );
		const groups = data.groups || {};
		[ 'due', 'expired', 'payments', 'installs', 'sms' ].forEach( function ( group ) {
			renderList( group, groups[ group ] || [] );
		} );
		renderCounts( data.counts || {} );
		renderNetwork( data.network || {} );
	}

	function setLoading( busy ) {
		loading = busy;
		root.classList.toggle( 'is-loading', busy );
		const button = root.querySelector( '[data-afc-dashboard-refresh]' );
		if ( button ) button.disabled = busy;
	}

	function loadData() {
		if ( loading ) return Promise.resolve();
		setLoading( true );
		return request( 'afc_dashboard_data' ).then( function ( data ) {
			renderData( data );
			loaded = true;
		} ).catch( function ( error ) {
			renderRouterAlert( { connected: false, router: { name: 'Dashboard', message: error.message || labels.failed || 'Dashboard data could not be loaded.' } } );
		} ).finally( function () {
			setLoading( false );
		} );
	}

	function openPanel( panel, account ) {
		const button = document.querySelector( '[data-afc-app-panel="' + panel + '"]' );
		if ( button ) button.click();
		if ( account && panel === 'operations' ) {
			window.setTimeout( function () {
				const search = document.getElementById( 'afc-ppp-search' );
				if ( search ) {
					search.value = account;
					search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
					search.focus();
				}
			}, 220 );
		}
	}

	function bind() {
		root.addEventListener( 'click', function ( event ) {
			const refresh = event.target.closest( '[data-afc-dashboard-refresh]' );
			if ( refresh ) {
				loadData();
				return;
			}
			const add = event.target.closest( '[data-afc-dashboard-add-ppp]' );
			if ( add ) {
				const original = document.getElementById( 'afc-add-ppp-account' );
				if ( original ) original.click();
				return;
			}
			const panelButton = event.target.closest( '[data-afc-dashboard-open-panel]' );
			if ( panelButton ) {
				openPanel( panelButton.dataset.afcDashboardOpenPanel );
				return;
			}
			const item = event.target.closest( '[data-afc-dashboard-account]' );
			if ( item ) {
				const group = item.dataset.afcDashboardGroup;
				const panel = group === 'sms' ? 'sms' : ( group === 'due' || group === 'expired' ? 'schedulers' : 'operations' );
				openPanel( panel, item.dataset.afcDashboardAccount );
			}
		} );
	}

	function isVisible() {
		const panel = root && root.closest( '[data-afc-panel="dashboard"]' );
		return Boolean( panel && ! panel.hidden && panel.getAttribute( 'aria-hidden' ) !== 'true' );
	}

	function startRefreshTimer() {
		window.clearInterval( timer );
		timer = window.setInterval( function () {
			if ( isVisible() ) loadData();
		}, Math.max( 15, Number( config.refreshSeconds || 30 ) ) * 1000 );
	}

	function watchPanel() {
		const panel = root.closest( '[data-afc-panel="dashboard"]' );
		if ( ! panel ) return;
		const observer = new MutationObserver( function () {
			if ( isVisible() && ! loaded ) loadData();
		} );
		observer.observe( panel, { attributes: true, attributeFilter: [ 'hidden', 'aria-hidden', 'class' ] } );
	}

	function initialize() {
		injectNavigation();
		root = document.getElementById( 'afc-main-dashboard' );
		if ( ! root ) return;
		grid = root.querySelector( '[data-afc-dashboard-grid]' );
		applySavedOrder();
		initializeSortable();
		watchPaymentApp();
		bind();
		watchPanel();
		startRefreshTimer();
		if ( isVisible() ) loadData();
	}

	injectNavigation();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', initialize );
	else initialize();
}() );