( function () {
	'use strict';

	let observer = null;
	let premiumRoot = null;
	let premiumLoaded = false;
	let premiumLoading = false;
	let premiumTimer = null;

	function $( selector, scope ) { return ( scope || document ).querySelector( selector ); }
	function text( value ) { return value == null ? '' : String( value ).trim(); }
	function shell() { return document.getElementById( 'afc-frontend-app' ); }
	function currentMode() {
		if ( document.body.classList.contains( 'afc-admin-mode-advanced' ) ) return 'advanced';
		if ( document.body.classList.contains( 'afc-admin-mode-basic' ) ) return 'basic';
		const app = shell();
		return app ? app.getAttribute( 'data-afc-mode' ) || 'basic' : 'basic';
	}

	function revealShell() {
		const app = shell();
		if ( app ) app.classList.add( 'is-ready' );
	}

	function markDashboardAdvancedOnly() {
		const panel = document.querySelector( '[data-afc-panel="dashboard"]' );
		const nav = document.querySelector( '[data-afc-app-panel="dashboard"]' );
		if ( panel ) panel.classList.add( 'afc-advanced-only' );
		if ( nav ) nav.classList.add( 'afc-advanced-only' );
	}

	function paymentApp() { return document.getElementById( 'afc-basic-payment-app' ); }
	function operationsContainer() { return document.querySelector( '[data-afc-panel="operations"] .afc-admin-page .container-fluid' ); }

	function setActivePanel( panelName ) {
		const app = shell();
		if ( ! app ) return;
		app.querySelectorAll( '[data-afc-panel]' ).forEach( function ( panel ) {
			const active = panel.getAttribute( 'data-afc-panel' ) === panelName;
			panel.classList.toggle( 'is-active', active );
			panel.hidden = ! active;
			panel.setAttribute( 'aria-hidden', active ? 'false' : 'true' );
		} );
		app.querySelectorAll( '[data-afc-app-panel]' ).forEach( function ( button ) {
			const active = button.getAttribute( 'data-afc-app-panel' ) === panelName;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
	}

	function restoreBasicPaymentApp() {
		const app = paymentApp();
		const operations = operationsContainer();
		if ( ! app || ! operations ) return false;
		app.classList.add( 'afc-basic-only' );
		app.classList.remove( 'is-dashboard-payment' );
		const switcher = document.getElementById( 'afc-admin-mode-switcher' );
		if ( switcher && switcher.parentNode === operations ) {
			if ( switcher.nextElementSibling !== app ) switcher.insertAdjacentElement( 'afterend', app );
		} else if ( operations.firstElementChild !== app ) {
			operations.insertBefore( app, operations.firstChild );
		}
		return true;
	}

	function movePaymentDialogToBody() {
		const dialog = document.getElementById( 'afc-payment-dialog' );
		if ( dialog && dialog.parentNode !== document.body ) document.body.appendChild( dialog );
	}

	function applyStoredTheme() {
		let theme = 'light';
		try {
			theme = localStorage.getItem( 'afcDashboardTheme' ) || ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light' );
		} catch ( error ) {}
		document.documentElement.setAttribute( 'data-afc-theme', theme === 'dark' ? 'dark' : 'light' );
		document.body.setAttribute( 'data-afc-theme', theme === 'dark' ? 'dark' : 'light' );
	}

	function loadPremiumStyle() {
		if ( document.getElementById( 'afc-dashboard-premium-v2-style' ) ) return;
		const source = document.querySelector( 'script[src*="/assets/js/main-dashboard-advanced-mode.js"]' );
		if ( ! source || ! source.src ) return;
		const marker = '/assets/js/main-dashboard-advanced-mode.js';
		const index = source.src.indexOf( marker );
		if ( index < 0 ) return;
		const link = document.createElement( 'link' );
		link.id = 'afc-dashboard-premium-v2-style';
		link.rel = 'stylesheet';
		link.href = source.src.slice( 0, index ) + '/assets/css/dashboard-premium-v2.css?v=2.8.0';
		document.head.appendChild( link );
	}

	function money( value ) {
		const amount = Number( value || 0 );
		try { return new Intl.NumberFormat( 'en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 0 } ).format( amount ); }
		catch ( error ) { return '₱' + Math.round( amount ).toLocaleString(); }
	}

	function parseCommentValue( comment, key ) {
		const safe = key.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
		const pattern = new RegExp( '(?:^|\\s)' + safe + '\\s*:\\s*(.*?)(?=\\s+[A-Za-z][A-Za-z0-9_-]*\\s*:|$)', 'i' );
		const match = text( comment ).match( pattern );
		return match ? text( match[ 1 ] ).replace( /\s+/g, ' ' ) : '';
	}

	function dateOnly( value ) {
		const match = text( value ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
		return match ? new Date( Number( match[1] ), Number( match[2] ) - 1, Number( match[3] ), 12, 0, 0 ) : null;
	}

	function dayDiff( date ) {
		if ( ! date ) return null;
		const now = new Date();
		const today = new Date( now.getFullYear(), now.getMonth(), now.getDate(), 12, 0, 0 );
		return Math.round( ( date.getTime() - today.getTime() ) / 86400000 );
	}

	function usersFromTable() {
		const users = [];
		document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ).forEach( function ( row ) {
			try { users.push( JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) ) ); } catch ( error ) {}
		} );
		return users;
	}

	function fetchUsers() {
		const cached = usersFromTable();
		if ( cached.length ) return Promise.resolve( cached );
		const ppp = window.afcPPP || {};
		const main = window.afcMainDashboard || {};
		if ( ! ppp.nonce || !( ppp.ajaxUrl || main.ajaxUrl ) ) return Promise.resolve( [] );
		const body = new URLSearchParams();
		body.set( 'action', 'afc_get_ppp_users' );
		body.set( 'nonce', ppp.nonce );
		return window.fetch( ppp.ajaxUrl || main.ajaxUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString() } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) { return response && response.success ? ( response.data.users || [] ) : []; } );
	}

	function fetchTodayCollection() {
		const cfg = window.afcDashboardPaymentTool || {};
		if ( ! cfg.ajaxUrl || ! cfg.nonce ) return Promise.resolve( { total:0, count:0 } );
		const body = new URLSearchParams();
		body.set( 'action', 'afc_dashboard_today_collection' );
		body.set( 'nonce', cfg.nonce );
		return window.fetch( cfg.ajaxUrl, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body:body.toString() } )
			.then( function ( response ) { return response.json(); } )
			.then( function ( response ) { return response && response.success ? response.data : { total:0, count:0 }; } );
	}

	function summarizeUsers( users ) {
		const buckets = [0,0,0,0,0,0,0];
		let online = 0, offline = 0, expired = 0, dueToday = 0, total = 0;
		users.forEach( function ( user ) {
			if ( user.disabled ) return;
			total++;
			const isExpired = text( user.actual_profile ).toLowerCase() === 'expired';
			if ( isExpired ) expired++;
			else if ( user.active ) online++;
			else offline++;
			if ( isExpired ) return;
			const due = dateOnly( parseCommentValue( user.comment, 'nextDue' ) );
			const diff = dayDiff( due );
			if ( diff === 0 ) dueToday++;
			if ( diff != null && diff >= 0 && diff <= 6 ) buckets[ diff ]++;
		} );
		return { online:online, offline:offline, expired:expired, dueToday:dueToday, total:total, buckets:buckets };
	}

	function smsTodayCount() {
		const node = premiumRoot && premiumRoot.querySelector( '[data-afc-dashboard-count="sms"]' );
		const match = node ? text( node.textContent ).match( /\d+/ ) : null;
		return match ? Number( match[0] ) : 0;
	}

	function ensurePremiumUI() {
		premiumRoot = document.getElementById( 'afc-main-dashboard' );
		if ( ! premiumRoot || premiumRoot.querySelector( '.afc-premium-kpis' ) ) return;
		const router = premiumRoot.querySelector( '[data-afc-dashboard-router-alert]' );
		const grid = premiumRoot.querySelector( '[data-afc-dashboard-grid]' );
		if ( ! grid ) return;

		const actions = premiumRoot.querySelector( '.afc-dashboard-header-actions' );
		if ( actions && ! actions.querySelector( '[data-afc-dashboard-theme-toggle]' ) ) {
			const theme = document.createElement( 'button' );
			theme.type = 'button';
			theme.className = 'afc-dashboard-theme-toggle';
			theme.setAttribute( 'data-afc-dashboard-theme-toggle', '' );
			theme.innerHTML = '<span aria-hidden="true">☾</span><b>Theme</b>';
			theme.addEventListener( 'click', function () {
				const next = document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? 'light' : 'dark';
				document.documentElement.setAttribute( 'data-afc-theme', next );
				document.body.setAttribute( 'data-afc-theme', next );
				try { localStorage.setItem( 'afcDashboardTheme', next ); } catch ( error ) {}
				theme.querySelector( 'span' ).textContent = next === 'dark' ? '☀' : '☾';
			} );
			const refresh = actions.querySelector( '[data-afc-dashboard-refresh]' );
			actions.insertBefore( theme, refresh || null );
			theme.querySelector( 'span' ).textContent = document.documentElement.getAttribute( 'data-afc-theme' ) === 'dark' ? '☀' : '☾';
		}

		const kpis = document.createElement( 'section' );
		kpis.className = 'afc-premium-kpis';
		kpis.innerHTML =
			'<article class="afc-premium-kpi is-online"><small>Online</small><strong data-kpi="online">—</strong><span data-note="online">Active PPP sessions</span></article>' +
			'<article class="afc-premium-kpi is-due"><small>Due today</small><strong data-kpi="due">—</strong><span data-note="due">nextDue = today</span></article>' +
			'<article class="afc-premium-kpi is-expired"><small>Expired</small><strong data-kpi="expired">—</strong><span>PPP profile = Expired</span></article>' +
			'<article class="afc-premium-kpi"><small>Collected today</small><strong data-kpi="money">—</strong><span data-note="money">Payment records</span></article>' +
			'<article class="afc-premium-kpi is-sms"><small>SMS today</small><strong data-kpi="sms">—</strong><span>Sent or delivered today</span></article>';

		const insights = document.createElement( 'section' );
		insights.className = 'afc-premium-insights';
		insights.innerHTML =
			'<article class="afc-premium-card"><header><div><small>Billing workload</small><h2>Due in the next 7 days</h2></div><div class="afc-premium-total"><strong data-due-total>—</strong><span>customers</span></div></header><div class="afc-premium-bars" data-due-bars></div></article>' +
			'<article class="afc-premium-card"><header><div><small>Subscribers</small><h2>Service health</h2></div><span class="afc-premium-updated" data-premium-updated>—</span></header><div class="afc-premium-health"><div class="afc-premium-donut" data-health-donut><span><strong>—</strong><small>accounts</small></span></div><div class="afc-premium-legend"><div><i class="is-online"></i><span>Online</span><strong data-health="online">—</strong></div><div><i class="is-offline"></i><span>Offline</span><strong data-health="offline">—</strong></div><div><i class="is-expired"></i><span>Expired</span><strong data-health="expired">—</strong></div></div></div></article>';

		if ( router ) {
			router.insertAdjacentElement( 'afterend', kpis );
			kpis.insertAdjacentElement( 'afterend', insights );
		} else {
			grid.parentNode.insertBefore( kpis, grid );
			grid.parentNode.insertBefore( insights, grid );
		}
		const heading = premiumRoot.querySelector( '.afc-dashboard-header h1' );
		if ( heading ) {
			const hour = new Date().getHours();
			heading.textContent = ( hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening' ) + '. Here is what needs attention.';
		}
	}

	function renderBars( buckets ) {
		const target = premiumRoot.querySelector( '[data-due-bars]' );
		if ( ! target ) return;
		const max = Math.max.apply( Math, buckets.concat( [1] ) );
		const labels = ['Today','+1d','+2d','+3d','+4d','+5d','+6d'];
		target.innerHTML = buckets.map( function ( count, index ) {
			const height = Math.max( 4, Math.round( count / max * 112 ) );
			return '<div class="afc-premium-bar" title="' + labels[index] + ': ' + count + ' customer' + ( count === 1 ? '' : 's' ) + '"><i style="height:' + height + 'px"></i><b>' + count + '</b><small>' + labels[index] + '</small></div>';
		} ).join( '' );
		const total = premiumRoot.querySelector( '[data-due-total]' );
		if ( total ) total.textContent = buckets.reduce( function ( sum, value ) { return sum + value; }, 0 ).toLocaleString();
	}

	function renderHealth( summary ) {
		['online','offline','expired'].forEach( function ( key ) {
			const node = premiumRoot.querySelector( '[data-health="' + key + '"]' );
			if ( node ) node.textContent = Number( summary[key] || 0 ).toLocaleString();
		} );
		const donut = premiumRoot.querySelector( '[data-health-donut]' );
		if ( ! donut ) return;
		const center = donut.querySelector( 'strong' );
		if ( center ) center.textContent = Number( summary.total || 0 ).toLocaleString();
		if ( ! summary.total ) return;
		const onlinePct = summary.online / summary.total * 100;
		const offlinePct = summary.offline / summary.total * 100;
		const second = onlinePct + offlinePct;
		donut.style.background = 'conic-gradient(var(--afc-premium-green) 0 ' + onlinePct.toFixed(2) + '%,var(--afc-premium-orange) ' + onlinePct.toFixed(2) + '% ' + second.toFixed(2) + '%,var(--afc-premium-red) ' + second.toFixed(2) + '% 100%)';
	}

	function renderPremium( users, collection ) {
		const summary = summarizeUsers( users );
		const values = { online:summary.online, due:summary.dueToday, expired:summary.expired, money:money( collection.total || 0 ), sms:smsTodayCount() };
		Object.keys( values ).forEach( function ( key ) {
			const node = premiumRoot.querySelector( '[data-kpi="' + key + '"]' );
			if ( node ) node.textContent = typeof values[key] === 'number' ? values[key].toLocaleString() : values[key];
		} );
		const onlineNote = premiumRoot.querySelector( '[data-note="online"]' );
		if ( onlineNote ) onlineNote.textContent = summary.total.toLocaleString() + ' subscriber accounts';
		const dueNote = premiumRoot.querySelector( '[data-note="due"]' );
		if ( dueNote ) dueNote.textContent = summary.buckets.reduce( function ( a,b ) { return a+b; }, 0 ) + ' due within 7 days';
		const moneyNote = premiumRoot.querySelector( '[data-note="money"]' );
		if ( moneyNote ) moneyNote.textContent = Number( collection.count || 0 ) + ' payment' + ( Number( collection.count || 0 ) === 1 ? '' : 's' );
		renderBars( summary.buckets );
		renderHealth( summary );
		const updated = premiumRoot.querySelector( '[data-premium-updated]' );
		if ( updated ) updated.textContent = 'Updated ' + new Date().toLocaleTimeString( [], { hour:'numeric', minute:'2-digit' } );
	}

	function loadPremium() {
		if ( premiumLoading ) return;
		ensurePremiumUI();
		if ( ! premiumRoot ) return;
		premiumLoading = true;
		Promise.all( [ fetchUsers(), fetchTodayCollection() ] ).then( function ( results ) {
			renderPremium( results[0], results[1] );
			premiumLoaded = true;
		} ).finally( function () { premiumLoading = false; } );
	}

	function dashboardVisible() {
		const panel = document.querySelector( '[data-afc-panel="dashboard"]' );
		return Boolean( panel && ! panel.hidden && panel.getAttribute( 'aria-hidden' ) !== 'true' );
	}

	function syncMode() {
		revealShell();
		markDashboardAdvancedOnly();
		restoreBasicPaymentApp();
		if ( 'basic' === currentMode() ) setActivePanel( 'operations' );
		else if ( dashboardVisible() ) window.setTimeout( loadPremium, 80 );
	}

	function boot() {
		loadPremiumStyle();
		revealShell();
		syncMode();
		document.addEventListener( 'click', function ( event ) {
			if ( 'advanced' === currentMode() && event.target.closest( '[data-afc-dashboard-payment-account]' ) ) movePaymentDialogToBody();
			if ( event.target.closest( '[data-afc-dashboard-refresh]' ) ) window.setTimeout( loadPremium, 120 );
		}, true );
		document.addEventListener( 'afc:admin-mode-change', function () { window.requestAnimationFrame( syncMode ); } );
		observer = new MutationObserver( function () {
			revealShell();
			markDashboardAdvancedOnly();
			restoreBasicPaymentApp();
			if ( 'basic' === currentMode() ) {
				const active = document.querySelector( '[data-afc-panel="dashboard"].is-active' );
				if ( active ) setActivePanel( 'operations' );
			} else if ( dashboardVisible() && ! premiumLoaded ) {
				window.setTimeout( loadPremium, 80 );
			}
			if ( premiumLoaded && premiumRoot ) {
				const sms = premiumRoot.querySelector( '[data-kpi="sms"]' );
				if ( sms ) sms.textContent = smsTodayCount().toLocaleString();
			}
		} );
		observer.observe( document.body, { childList:true, subtree:true, characterData:true } );
		window.setTimeout( function () { revealShell(); syncMode(); }, 12000 );
		window.clearInterval( premiumTimer );
		premiumTimer = window.setInterval( function () { if ( dashboardVisible() ) loadPremium(); }, 60000 );
	}

	applyStoredTheme();
	loadPremiumStyle();
	revealShell();
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
