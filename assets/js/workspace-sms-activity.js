( function () {
	'use strict';

	const cfg = window.afcSmsDueActivity || {};
	let panel = null;
	let timer = null;
	let loading = false;

	function esc( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function statusTone( status ) {
		if ( status === 'delivered' || status === 'sent' ) return 'success';
		if ( status === 'claimed' || status === 'submitted' ) return 'phone';
		if ( status === 'failed' || status === 'cancelled' ) return 'danger';
		return 'queued';
	}

	function timing( item ) {
		const parts = [];
		if ( item.queuedAt ) parts.push( 'Web ' + item.queuedAt );
		if ( item.phoneReceivedAt ) parts.push( 'Phone ' + item.phoneReceivedAt );
		if ( item.deliveredAt ) parts.push( 'Delivered ' + item.deliveredAt );
		else if ( item.sentAt ) parts.push( 'Sent ' + item.sentAt );
		else if ( item.failedAt ) parts.push( 'Failed ' + item.failedAt );
		return parts.join( ' · ' );
	}

	function ensurePanel() {
		const sidebar = document.querySelector( '.afc-workspace-sidebar' );
		if ( ! sidebar ) return null;
		if ( panel && panel.isConnected ) return panel;
		panel = document.createElement( 'section' );
		panel.className = 'afc-workspace-sms-activity';
		panel.innerHTML =
			'<header><div><small>LIVE LOG</small><strong>SMS delivery</strong></div><button type="button" data-afc-sms-log-refresh aria-label="Refresh SMS log">↻</button></header>' +
			'<div class="afc-workspace-sms-activity-list"><p>Loading SMS activity…</p></div>' +
			'<footer><button type="button" data-afc-sms-log-open>Open SMS →</button></footer>';
		const footer = sidebar.querySelector( ':scope > footer' );
		if ( footer ) sidebar.insertBefore( panel, footer );
		else sidebar.appendChild( panel );
		panel.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-sms-log-refresh]' ) ) load();
			if ( event.target.closest( '[data-afc-sms-log-open]' ) ) {
				const open = document.querySelector( '[data-afc-ws-panel="sms"]' );
				if ( open ) open.click();
			}
		} );
		return panel;
	}

	function render( data ) {
		const root = ensurePanel();
		if ( ! root ) return;
		const list = root.querySelector( '.afc-workspace-sms-activity-list' );
		const items = data && Array.isArray( data.items ) ? data.items : [];
		if ( ! items.length ) {
			const scan = data && data.lastScan && data.lastScan.message ? data.lastScan.message : 'No due or pre-cutoff SMS activity yet.';
			list.innerHTML = '<p>' + esc( scan ) + '</p>';
			return;
		}
		list.innerHTML = items.map( function ( item ) {
			return '<article class="is-' + esc( statusTone( item.status ) ) + '">' +
				'<span aria-hidden="true"></span>' +
				'<div><strong>' + esc( item.ppp || item.name || 'Unknown PPP' ) + '</strong>' +
				'<small>' + esc( item.statusLabel || item.status || 'Queued' ) + '</small>' +
				'<em>' + esc( timing( item ) ) + '</em></div>' +
				'</article>';
		} ).join( '' );
	}

	function load() {
		if ( loading || ! cfg.ajaxUrl || ! cfg.nonce || document.hidden ) return;
		const root = ensurePanel();
		if ( ! root ) return;
		loading = true;
		root.classList.add( 'is-loading' );
		const body = new URLSearchParams();
		body.set( 'action', 'afc_sms_due_activity' );
		body.set( 'nonce', cfg.nonce );
		window.fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
			if ( response && response.success ) render( response.data || {} );
		} ).catch( function () {
			const list = root.querySelector( '.afc-workspace-sms-activity-list' );
			if ( list ) list.innerHTML = '<p>SMS activity could not be refreshed.</p>';
		} ).finally( function () {
			loading = false;
			root.classList.remove( 'is-loading' );
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			if ( ensurePanel() ) load();
		}, 80 );
	}

	function boot() {
		new MutationObserver( schedule ).observe( document.body, { childList: true, subtree: true } );
		schedule();
		document.addEventListener( 'afc:sms-activity-refresh', load );
		document.addEventListener( 'visibilitychange', function () { if ( ! document.hidden ) load(); } );
		window.setInterval( load, 15000 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
