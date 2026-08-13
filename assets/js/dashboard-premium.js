( function () {
	'use strict';

	const cfg = window.afcDashboardPremium || {};
	let root = null;
	let loading = false;
	let loaded = false;
	let refreshTimer = null;

	function $( selector, scope ) { return ( scope || document ).querySelector( selector ); }
	function $$( selector, scope ) { return Array.from( ( scope || document ).querySelectorAll( selector ) ); }
	function text( value ) { return value == null ? '' : String( value ); }
	function esc( value ) { const node = document.createElement( 'div' ); node.textContent = text( value ); return node.innerHTML; }

	function money( value ) {
		const amount = Number( value || 0 );
		try {
			return new Intl.NumberFormat( 'en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 } ).format( amount );
		} catch ( error ) {
			return '₱' + Math.round( amount ).toLocaleString();
		}
	}

	function compactMoney( value ) {
		const amount = Number( value || 0 );
		try {
			return new Intl.NumberFormat( 'en-PH', { notation: 'compact', style: 'currency', currency: 'PHP', maximumFractionDigits: 1 } ).format( amount );
		} catch ( error ) {
			return money( amount );
		}
	}

	function formatUpdated( value ) {
		const raw = text( value ).trim();
		if ( ! raw ) return 'Updated now';
		const normalized = raw.replace( ' ', 'T' );
		const date = new Date( normalized );
		if ( Number.isNaN( date.getTime() ) ) return 'Updated now';
		return 'Updated ' + date.toLocaleTimeString( [], { hour: 'numeric', minute: '2-digit' } );
	}

	function request() {
		const body = new URLSearchParams();
		body.set( 'action', 'afc_dashboard_premium_snapshot' );
		body.set( 'nonce', cfg.nonce || '' );
		return window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : 'Dashboard summary failed.' );
			}
			return response.data || {};
		} );
	}

	function createThemeToggle() {
		if ( ! root || $( '[data-afc-dashboard-theme-toggle]', root ) ) return;
		const actions = $( '.afc-dashboard-header-actions', root );
		if ( ! actions ) return;
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'afc-dashboard-theme-toggle';
		button.setAttribute( 'data-afc-dashboard-theme-toggle', '' );
		button.innerHTML = '<span aria-hidden="true">☾</span><b>Theme</b>';
		button.addEventListener( 'click', toggleTheme );
		const refresh = $( '[data-afc-dashboard-refresh]', actions );
		actions.insertBefore( button, refresh || null );
		syncThemeToggle();
	}

	function currentTheme() {
		return document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'dark' : 'light';
	}

	function applyTheme( theme, persist ) {
		const value = theme === 'dark' ? 'dark' : 'light';
		document.documentElement.setAttribute( 'data-afc-theme', value );
		document.body.setAttribute( 'data-afc-theme', value );
		if ( persist ) {
			try { localStorage.setItem( 'afcDashboardTheme', value ); } catch ( error ) {}
		}
		syncThemeToggle();
	}

	function toggleTheme() {
		applyTheme( currentTheme() === 'dark' ? 'light' : 'dark', true );
	}

	function syncThemeToggle() {
		const button = root && $( '[data-afc-dashboard-theme-toggle]', root );
		if ( ! button ) return;
		const dark = currentTheme() === 'dark';
		const icon = $( 'span', button );
		if ( icon ) icon.textContent = dark ? '☀' : '☾';
		button.setAttribute( 'aria-label', dark ? 'Use light mode' : 'Use dark mode' );
		button.setAttribute( 'title', dark ? 'Use light mode' : 'Use dark mode' );
	}

	function createPremiumLayout() {
		if ( ! root || $( '.afc-premium-kpis', root ) ) return;
		const router = $( '[data-afc-dashboard-router-alert]', root );
		const grid = $( '[data-afc-dashboard-grid]', root );
		if ( ! grid ) return;

		const kpis = document.createElement( 'section' );
		kpis.className = 'afc-premium-kpis';
		kpis.setAttribute( 'aria-label', 'Daily dashboard summary' );
		kpis.innerHTML =
			'<article class="afc-premium-kpi is-online"><small>Online</small><strong data-afc-premium-kpi="online">—</strong><span data-afc-premium-kpi-note="online">Active PPP sessions</span></article>' +
			'<article class="afc-premium-kpi is-due"><small>Due today</small><strong data-afc-premium-kpi="due_today">—</strong><span data-afc-premium-kpi-note="due_today">Checking nextDue</span></article>' +
			'<article class="afc-premium-kpi is-expired"><small>Expired</small><strong data-afc-premium-kpi="expired">—</strong><span>PPP profile = Expired</span></article>' +
			'<article class="afc-premium-kpi is-money"><small>Collected today</small><strong data-afc-premium-kpi="today_amount">—</strong><span data-afc-premium-kpi-note="today_amount">Payment records</span></article>' +
			'<article class="afc-premium-kpi is-sms"><small>SMS queue</small><strong data-afc-premium-kpi="sms_queue">—</strong><span>Waiting for phone</span></article>';

		const insights = document.createElement( 'section' );
		insights.className = 'afc-premium-insights';
		insights.setAttribute( 'aria-label', 'Dashboard trends' );
		insights.innerHTML =
			'<article class="afc-premium-insight-card afc-premium-collection-card">' +
				'<header><div><small>Collections</small><h2>Last 7 days</h2></div><div class="afc-premium-chart-total"><strong data-afc-premium-collection-total>—</strong><span data-afc-premium-collection-change></span></div></header>' +
				'<div class="afc-premium-line-chart" data-afc-premium-collection-chart><div class="afc-premium-skeleton"></div></div>' +
			'</article>' +
			'<article class="afc-premium-insight-card afc-premium-health-card">' +
				'<header><div><small>Subscribers</small><h2>Service health</h2></div><span class="afc-premium-updated" data-afc-premium-updated>—</span></header>' +
				'<div class="afc-premium-health-body">' +
					'<div class="afc-premium-donut" data-afc-premium-health-donut><span><strong>—</strong><small>accounts</small></span></div>' +
					'<div class="afc-premium-health-legend">' +
						'<div><i class="is-online"></i><span>Online</span><strong data-afc-premium-health="online">—</strong></div>' +
						'<div><i class="is-offline"></i><span>Offline</span><strong data-afc-premium-health="offline">—</strong></div>' +
						'<div><i class="is-expired"></i><span>Expired</span><strong data-afc-premium-health="expired">—</strong></div>' +
					'</div>' +
				'</div>' +
			'</article>';

		if ( router && router.parentNode ) {
			router.insertAdjacentElement( 'afterend', kpis );
			kpis.insertAdjacentElement( 'afterend', insights );
		} else {
			grid.parentNode.insertBefore( kpis, grid );
			grid.parentNode.insertBefore( insights, grid );
		}
	}

	function greeting() {
		const title = root && $( '.afc-dashboard-header h1', root );
		if ( ! title ) return;
		const hour = new Date().getHours();
		const part = hour < 12 ? 'Good morning' : ( hour < 18 ? 'Good afternoon' : 'Good evening' );
		title.textContent = part + '. Here is what needs attention.';
	}

	function renderKpis( data ) {
		const kpis = data.kpis || {};
		const map = {
			online: Number( kpis.online || 0 ).toLocaleString(),
			due_today: Number( kpis.due_today || 0 ).toLocaleString(),
			expired: Number( kpis.expired || 0 ).toLocaleString(),
			today_amount: money( kpis.today_amount || 0 ),
			sms_queue: Number( kpis.sms_queue || 0 ).toLocaleString(),
		};
		Object.keys( map ).forEach( function ( key ) {
			const node = $( '[data-afc-premium-kpi="' + key + '"]', root );
			if ( node ) node.textContent = map[ key ];
		} );
		const onlineNote = $( '[data-afc-premium-kpi-note="online"]', root );
		if ( onlineNote ) onlineNote.textContent = Number( data.health && data.health.total || 0 ).toLocaleString() + ' subscriber accounts';
		const dueNote = $( '[data-afc-premium-kpi-note="due_today"]', root );
		if ( dueNote ) dueNote.textContent = Number( kpis.due_7 || 0 ).toLocaleString() + ' due within 7 days';
		const moneyNote = $( '[data-afc-premium-kpi-note="today_amount"]', root );
		if ( moneyNote ) moneyNote.textContent = Number( kpis.today_count || 0 ).toLocaleString() + ' payment' + ( Number( kpis.today_count || 0 ) === 1 ? '' : 's' );
	}

	function renderCollectionChart( collections ) {
		const target = $( '[data-afc-premium-collection-chart]', root );
		const total = $( '[data-afc-premium-collection-total]', root );
		const change = $( '[data-afc-premium-collection-change]', root );
		if ( ! target ) return;
		const series = Array.isArray( collections && collections.series ) ? collections.series.slice( -7 ) : [];
		if ( total ) total.textContent = money( collections && collections.total || 0 );
		if ( change ) {
			const delta = collections ? collections.change_percent : null;
			change.className = delta == null ? '' : ( Number( delta ) >= 0 ? 'is-up' : 'is-down' );
			change.textContent = delta == null ? 'No previous-period comparison' : ( Number( delta ) >= 0 ? '↑ ' : '↓ ' ) + Math.abs( Number( delta ) ).toFixed( 1 ) + '% vs previous 7 days';
		}
		if ( ! series.length ) {
			target.innerHTML = '<div class="afc-dashboard-empty">No payment history for this period.</div>';
			return;
		}

		const width = 700;
		const height = 145;
		const padX = 15;
		const padY = 12;
		const values = series.map( function ( item ) { return Number( item.amount || 0 ); } );
		const max = Math.max.apply( Math, values.concat( [ 1 ] ) );
		const step = ( width - padX * 2 ) / Math.max( 1, series.length - 1 );
		const points = series.map( function ( item, index ) {
			const x = padX + step * index;
			const y = height - padY - ( Number( item.amount || 0 ) / max ) * ( height - padY * 2 );
			return { x: x, y: y, item: item };
		} );
		const line = points.map( function ( point, index ) { return ( index ? 'L' : 'M' ) + point.x.toFixed( 2 ) + ' ' + point.y.toFixed( 2 ); } ).join( ' ' );
		const area = line + ' L ' + points[ points.length - 1 ].x.toFixed( 2 ) + ' ' + ( height - padY ) + ' L ' + points[ 0 ].x.toFixed( 2 ) + ' ' + ( height - padY ) + ' Z';
		const gridLines = [ .25, .5, .75 ].map( function ( ratio ) {
			const y = padY + ( height - padY * 2 ) * ratio;
			return '<line class="afc-premium-line-grid" x1="' + padX + '" x2="' + ( width - padX ) + '" y1="' + y + '" y2="' + y + '"></line>';
		} ).join( '' );
		const circles = points.map( function ( point ) {
			return '<circle class="afc-premium-line-point" cx="' + point.x.toFixed( 2 ) + '" cy="' + point.y.toFixed( 2 ) + '" r="4"><title>' + esc( point.item.label + ': ' + money( point.item.amount ) + ' · ' + Number( point.item.count || 0 ) + ' payments' ) + '</title></circle>';
		} ).join( '' );
		const labels = series.map( function ( item ) {
			return '<span>' + esc( item.label || '' ) + '<strong>' + esc( compactMoney( item.amount || 0 ) ) + '</strong></span>';
		} ).join( '' );

		target.innerHTML =
			'<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="Seven day collections trend">' +
				'<defs><linearGradient id="afcPremiumCollectionGradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="currentColor" stop-opacity=".22"></stop><stop offset="100%" stop-color="currentColor" stop-opacity=".015"></stop></linearGradient></defs>' +
				gridLines + '<path class="afc-premium-line-area" d="' + area + '"></path><path class="afc-premium-line-path" d="' + line + '"></path>' + circles +
			'</svg><div class="afc-premium-chart-labels">' + labels + '</div>';
	}

	function renderHealth( health ) {
		const values = health || {};
		[ 'online', 'offline', 'expired' ].forEach( function ( key ) {
			const node = $( '[data-afc-premium-health="' + key + '"]', root );
			if ( node ) node.textContent = Number( values[ key ] || 0 ).toLocaleString();
		} );
		const total = Math.max( 0, Number( values.total || 0 ) );
		const donut = $( '[data-afc-premium-health-donut]', root );
		if ( ! donut ) return;
		const center = $( 'strong', donut );
		if ( center ) center.textContent = total.toLocaleString();
		if ( total <= 0 ) {
			donut.style.background = 'conic-gradient(var(--afc-premium-surface-3) 0 100%)';
			return;
		}
		const onlinePct = Math.max( 0, Math.min( 100, Number( values.online || 0 ) / total * 100 ) );
		const offlinePct = Math.max( 0, Math.min( 100 - onlinePct, Number( values.offline || 0 ) / total * 100 ) );
		const second = onlinePct + offlinePct;
		donut.style.background = 'conic-gradient(var(--afc-premium-green) 0 ' + onlinePct.toFixed( 2 ) + '%, var(--afc-premium-orange) ' + onlinePct.toFixed( 2 ) + '% ' + second.toFixed( 2 ) + '%, var(--afc-premium-red) ' + second.toFixed( 2 ) + '% 100%)';
	}

	function render( data ) {
		renderKpis( data );
		renderCollectionChart( data.collections || {} );
		renderHealth( data.health || {} );
		const updated = $( '[data-afc-premium-updated]', root );
		if ( updated ) updated.textContent = formatUpdated( data.generated );
	}

	function setPremiumLoading( busy ) {
		if ( ! root ) return;
		root.classList.toggle( 'is-premium-loading', busy );
	}

	function load() {
		if ( loading || ! root ) return Promise.resolve();
		loading = true;
		setPremiumLoading( true );
		return request().then( function ( data ) {
			render( data );
			loaded = true;
		} ).catch( function ( error ) {
			const updated = $( '[data-afc-premium-updated]', root );
			if ( updated ) updated.textContent = error.message || ( cfg.labels && cfg.labels.failed ) || 'Summary unavailable';
		} ).finally( function () {
			loading = false;
			setPremiumLoading( false );
		} );
	}

	function visible() {
		const panel = root && root.closest( '[data-afc-panel="dashboard"]' );
		return Boolean( panel && ! panel.hidden && panel.getAttribute( 'aria-hidden' ) !== 'true' );
	}

	function bind() {
		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-dashboard-refresh]' ) ) {
				window.setTimeout( load, 90 );
			}
		} );
		const panel = root.closest( '[data-afc-panel="dashboard"]' );
		if ( panel ) {
			new MutationObserver( function () {
				if ( visible() && ! loaded ) load();
			} ).observe( panel, { attributes: true, attributeFilter: [ 'hidden', 'aria-hidden', 'class' ] } );
		}
	}

	function boot() {
		root = document.getElementById( 'afc-main-dashboard' );
		if ( ! root ) return;
		applyTheme( currentTheme(), false );
		createThemeToggle();
		createPremiumLayout();
		greeting();
		bind();
		if ( visible() ) load();
		window.clearInterval( refreshTimer );
		refreshTimer = window.setInterval( function () { if ( visible() ) load(); }, 60000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
