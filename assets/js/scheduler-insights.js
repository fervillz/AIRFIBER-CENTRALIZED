( function () {
	'use strict';

	const config = window.afcSchedulerInsights || {};
	const labels = config.labels || {};
	let root = null;
	let panel = null;
	let activeGroup = 'due';
	let data = { groups: {}, counts: {} };
	let loading = false;
	let loadedAt = 0;

	function escapeHtml( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function parseDate( value ) {
		const match = String( value || '' ).match( /^(\d{4})-(\d{2})-(\d{2})$/ );
		if ( ! match ) return null;
		return new Date( Number( match[ 1 ] ), Number( match[ 2 ] ) - 1, Number( match[ 3 ] ), 12, 0, 0 );
	}

	function formatDate( value ) {
		const date = parseDate( value );
		return date ? date.toLocaleDateString( [], { month: 'short', day: 'numeric' } ) : ( value || '—' );
	}

	function relativeDays( days, future ) {
		const number = Number( days || 0 );
		if ( number === 0 ) return 'Today';
		if ( future ) return 'in ' + number + ' day' + ( number === 1 ? '' : 's' );
		return number + ' day' + ( number === 1 ? '' : 's' ) + ' ago';
	}

	function formatAmount( value ) {
		const raw = String( value || '' ).trim();
		if ( ! raw ) return 'Amount not recorded';
		const numeric = Number( raw.replace( /[^0-9.-]/g, '' ) );
		if ( Number.isFinite( numeric ) && /\d/.test( raw ) ) {
			try {
				return new Intl.NumberFormat( 'en-PH', { style: 'currency', currency: 'PHP', maximumFractionDigits: 2 } ).format( numeric );
			} catch ( error ) {
				return '₱' + numeric.toLocaleString();
			}
		}
		return raw;
	}

	function createPanel() {
		if ( panel ) return panel;
		const summary = root && root.querySelector( '[data-afc-scheduler-summary]' );
		if ( ! summary ) return null;

		panel = document.createElement( 'section' );
		panel.className = 'afc-scheduler-insights';
		panel.setAttribute( 'data-afc-scheduler-insights', '' );
		panel.innerHTML =
			'<header class="afc-scheduler-insights-head">' +
				'<div><span class="afc-scheduler-kicker">Operational inbox</span><h3>' + escapeHtml( labels.title || 'Attention & recent activity' ) + '</h3><p>' + escapeHtml( labels.description || '' ) + '</p></div>' +
				'<button type="button" class="afc-scheduler-insights-refresh" data-afc-insights-refresh aria-label="' + escapeHtml( labels.refresh || 'Refresh activity' ) + '" title="' + escapeHtml( labels.refresh || 'Refresh activity' ) + '">↻</button>' +
			'</header>' +
			'<div class="afc-scheduler-insights-tabs" role="tablist">' +
				'<button type="button" class="is-active is-due" data-afc-insights-tab="due" role="tab"><span>!</span><strong>' + escapeHtml( labels.due || 'Due soon' ) + '</strong><em>0</em></button>' +
				'<button type="button" class="is-scheduled" data-afc-insights-tab="scheduled" role="tab"><span>◷</span><strong>' + escapeHtml( labels.scheduled || 'Scheduled' ) + '</strong><em>0</em></button>' +
				'<button type="button" class="is-expired" data-afc-insights-tab="expired" role="tab"><span>×</span><strong>' + escapeHtml( labels.expired || 'Expired' ) + '</strong><em>0</em></button>' +
				'<button type="button" class="is-payments" data-afc-insights-tab="payments" role="tab"><span>₱</span><strong>' + escapeHtml( labels.payments || 'Payments' ) + '</strong><em>0</em></button>' +
			'</div>' +
			'<div class="afc-scheduler-insights-list" data-afc-insights-list><div class="afc-scheduler-insights-empty is-loading"><span></span>' + escapeHtml( labels.loading || 'Loading…' ) + '</div></div>' +
			'<footer class="afc-scheduler-insights-note" data-afc-insights-note></footer>';
		summary.insertAdjacentElement( 'afterend', panel );
		return panel;
	}

	function groupItems( key ) {
		return data.groups && Array.isArray( data.groups[ key ] ) ? data.groups[ key ] : [];
	}

	function detailFor( item, key ) {
		if ( key === 'due' ) {
			return 'Due ' + formatDate( item.nextDue ) + ( item.cutoffDate ? ' · cutoff ' + formatDate( item.cutoffDate ) : '' );
		}
		if ( key === 'scheduled' ) {
			return 'Cutoff ' + formatDate( item.schedulerDate ) + ( item.schedulerTime ? ' · ' + item.schedulerTime : '' );
		}
		if ( key === 'expired' ) {
			return 'Expired profile' + ( item.cutoffDate ? ' · cutoff ' + formatDate( item.cutoffDate ) : '' ) + ( Number( item.runCount || 0 ) > 0 ? ' · scheduler ran' : '' );
		}
		return formatAmount( item.paymentAmount ) + ( item.paymentMethod ? ' · ' + item.paymentMethod : '' ) + ( item.nextDue ? ' · next due ' + formatDate( item.nextDue ) : '' );
	}

	function badgeFor( item, key ) {
		if ( key === 'due' ) return relativeDays( item.daysUntil, true );
		if ( key === 'scheduled' ) return relativeDays( item.daysUntil, true );
		if ( key === 'expired' ) return item.daysSinceCutoff == null ? 'Expired' : relativeDays( item.daysSinceCutoff, false );
		return item.paymentDate ? formatDate( item.paymentDate ) : 'Paid';
	}

	function itemHtml( item, key ) {
		const name = item.customer || item.name || 'Unknown customer';
		const initial = String( name ).trim().charAt( 0 ).toUpperCase() || '?';
		return '<button type="button" class="afc-scheduler-insight-row is-' + escapeHtml( key ) + '" data-afc-insight-account="' + escapeHtml( item.name || '' ) + '">' +
			'<span class="afc-scheduler-insight-avatar">' + escapeHtml( initial ) + '</span>' +
			'<span class="afc-scheduler-insight-person"><strong>' + escapeHtml( name ) + '</strong><small>' + escapeHtml( item.name || '' ) + '</small></span>' +
			'<span class="afc-scheduler-insight-detail">' + escapeHtml( detailFor( item, key ) ) + '</span>' +
			'<span class="afc-scheduler-insight-badge">' + escapeHtml( badgeFor( item, key ) ) + '</span>' +
		'</button>';
	}

	function emptyLabel( key ) {
		return {
			due: labels.emptyDue,
			scheduled: labels.emptySchedule,
			expired: labels.emptyExpired,
			payments: labels.emptyPayments,
		}[ key ] || 'Nothing to show.';
	}

	function render() {
		if ( ! createPanel() ) return;
		panel.querySelectorAll( '[data-afc-insights-tab]' ).forEach( function ( button ) {
			const key = button.getAttribute( 'data-afc-insights-tab' );
			button.classList.toggle( 'is-active', key === activeGroup );
			button.setAttribute( 'aria-selected', key === activeGroup ? 'true' : 'false' );
			const count = button.querySelector( 'em' );
			if ( count ) count.textContent = String( Number( data.counts && data.counts[ key ] || 0 ) );
		} );

		const list = panel.querySelector( '[data-afc-insights-list]' );
		const items = groupItems( activeGroup );
		if ( list ) {
			list.innerHTML = items.length
				? items.map( function ( item ) { return itemHtml( item, activeGroup ); } ).join( '' )
				: '<div class="afc-scheduler-insights-empty">' + escapeHtml( emptyLabel( activeGroup ) || 'Nothing to show.' ) + '</div>';
		}

		const note = panel.querySelector( '[data-afc-insights-note]' );
		if ( note ) {
			note.textContent = activeGroup === 'expired'
				? 'Expired activity is based on the current PPP profile and cutoff date.'
				: 'Select a customer to open that account in PPP Schedulers.';
		}
	}

	function chooseUsefulGroup() {
		if ( groupItems( activeGroup ).length ) return;
		[ 'due', 'scheduled', 'expired', 'payments' ].some( function ( key ) {
			if ( groupItems( key ).length ) {
				activeGroup = key;
				return true;
			}
			return false;
		} );
	}

	function showLoading() {
		if ( ! createPanel() ) return;
		panel.classList.add( 'is-loading' );
		const list = panel.querySelector( '[data-afc-insights-list]' );
		if ( list ) list.innerHTML = '<div class="afc-scheduler-insights-empty is-loading"><span></span>' + escapeHtml( labels.loading || 'Loading…' ) + '</div>';
	}

	function load( force ) {
		if ( loading || ! root ) return;
		if ( ! force && loadedAt && Date.now() - loadedAt < 60000 ) {
			render();
			return;
		}
		loading = true;
		showLoading();

		const body = new URLSearchParams();
		body.set( 'action', 'afc_scheduler_insights' );
		body.set( 'nonce', config.nonce || '' );
		window.fetch( config.ajaxUrl || '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( response && response.data && response.data.message ? response.data.message : ( labels.failed || 'Failed.' ) );
			}
			data = response.data || { groups: {}, counts: {} };
			loadedAt = Date.now();
			chooseUsefulGroup();
			render();
		} ).catch( function ( error ) {
			if ( ! createPanel() ) return;
			const list = panel.querySelector( '[data-afc-insights-list]' );
			if ( list ) list.innerHTML = '<div class="afc-scheduler-insights-empty is-error">' + escapeHtml( error.message || labels.failed || 'Failed.' ) + '</div>';
		} ).finally( function () {
			loading = false;
			if ( panel ) panel.classList.remove( 'is-loading' );
		} );
	}

	function applyAccountSearch( ppp ) {
		const search = root.querySelector( '[data-afc-scheduler-search]' );
		const filter = root.querySelector( '[data-afc-scheduler-filter]' );
		if ( filter ) {
			filter.value = 'all';
			filter.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( search ) {
			search.value = ppp;
			search.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			search.focus();
		}
	}

	function openAccount( ppp ) {
		const view = root.querySelector( '[data-afc-scheduler-view="accounts"]' );
		if ( view ) view.click();

		const hasRows = Boolean( root.querySelector( '[data-afc-scheduler-row]' ) );
		if ( hasRows ) {
			window.setTimeout( function () { applyAccountSearch( ppp ); }, 120 );
			return;
		}

		const refresh = root.querySelector( '[data-afc-scheduler-refresh]' );
		if ( refresh && ! refresh.disabled ) refresh.click();
		let attempts = 0;
		const wait = window.setInterval( function () {
			attempts += 1;
			if ( root.querySelector( '[data-afc-scheduler-row]' ) || attempts >= 40 ) {
				window.clearInterval( wait );
				applyAccountSearch( ppp );
			}
		}, 250 );
	}

	function isSchedulerVisible() {
		const host = root && root.closest( '[data-afc-panel="schedulers"]' );
		return Boolean( host && ! host.hidden && host.getAttribute( 'aria-hidden' ) !== 'true' );
	}

	function bind() {
		root.addEventListener( 'click', function ( event ) {
			const tab = event.target.closest( '[data-afc-insights-tab]' );
			if ( tab ) {
				activeGroup = tab.getAttribute( 'data-afc-insights-tab' ) || 'due';
				render();
				return;
			}
			if ( event.target.closest( '[data-afc-insights-refresh]' ) ) {
				load( true );
				return;
			}
			const account = event.target.closest( '[data-afc-insight-account]' );
			if ( account ) openAccount( account.getAttribute( 'data-afc-insight-account' ) || '' );
		} );
	}

	function watchPage() {
		const host = root.closest( '[data-afc-panel="schedulers"]' );
		if ( ! host || ! window.MutationObserver ) return;
		new MutationObserver( function () {
			if ( isSchedulerVisible() ) load( false );
		} ).observe( host, { attributes: true, attributeFilter: [ 'hidden', 'aria-hidden' ] } );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-app-panel="schedulers"]' ) ) {
				window.setTimeout( function () { load( false ); }, 80 );
			}
		} );
	}

	function boot() {
		root = document.getElementById( 'afc-scheduler-center' );
		if ( ! root ) return;
		createPanel();
		bind();
		watchPage();
		if ( isSchedulerVisible() ) load( false );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}() );
