( function ( $ ) {
	'use strict';

	const cfg = window.afcOLTRefresh || {};
	const opticalByAccount = new Map();
	let navButton = null;
	let navAge = null;
	let lastRefreshTs = Number( cfg.lastRefresh && cfg.lastRefresh.refreshed_ts ? cfg.lastRefresh.refreshed_ts : 0 );
	let ageTimer = null;

	function esc( value ) {
		const node = document.createElement( 'div' );
		node.textContent = value == null ? '' : String( value );
		return node.innerHTML;
	}

	function accountKey( value ) {
		return String( value || '' ).trim().toLowerCase();
	}

	function compactAge( timestamp ) {
		const ts = Number( timestamp || 0 );
		if ( ! ts ) return '—';
		const seconds = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
		if ( seconds < 60 ) return Math.max( 1, seconds ) + 's';
		if ( seconds < 3600 ) return Math.floor( seconds / 60 ) + 'm';
		if ( seconds < 86400 ) return Math.floor( seconds / 3600 ) + 'h';
		if ( seconds < 604800 ) return Math.floor( seconds / 86400 ) + 'd';
		return Math.floor( seconds / 604800 ) + 'w';
	}

	function icon( type ) {
		if ( type === 'loading' ) {
			return '<svg class="afc-olt-refresh-spinner" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8"></circle></svg>';
		}
		if ( type === 'check' ) {
			return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.1 4L19 7"></path></svg>';
		}
		return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 7v5h-5"></path><path d="M18.2 12A6.5 6.5 0 1 1 16 7.2L19 10"></path></svg>';
	}

	function setNavState( state ) {
		if ( ! navButton ) return;
		navButton.dataset.state = state;
		navButton.setAttribute( 'aria-disabled', state === 'loading' ? 'true' : 'false' );
		navButton.classList.toggle( 'is-loading', state === 'loading' );
		const iconNode = navButton.querySelector( '.afc-olt-nav-refresh-icon' );
		if ( iconNode ) iconNode.innerHTML = icon( state === 'loading' ? 'loading' : ( lastRefreshTs ? 'check' : 'refresh' ) );
		if ( navAge ) navAge.textContent = compactAge( lastRefreshTs );
	}

	function updateAgeLabels() {
		if ( navAge ) navAge.textContent = compactAge( lastRefreshTs );
		document.querySelectorAll( '[data-afc-olt-result-age]' ).forEach( function ( node ) {
			node.textContent = compactAge( node.getAttribute( 'data-afc-olt-result-age' ) );
		} );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && typeof settings.data === 'object' ) return settings.data.action === action;
		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function runFullRefresh() {
		if ( ! navButton || navButton.dataset.state === 'loading' ) return;
		const refresh = document.querySelector( '[data-afc-olt-overview-refresh]' );
		if ( refresh ) {
			refresh.click();
			return;
		}
		window.location.hash = 'optical';
		window.setTimeout( function () {
			const retry = document.querySelector( '[data-afc-olt-overview-refresh]' );
			if ( retry ) retry.click();
		}, 120 );
	}

	function ensureNavRefresh() {
		if ( navButton && document.body.contains( navButton ) ) return;
		const optical = document.querySelector( '.afc-frontend-nav [data-afc-app-panel="optical"]' );
		if ( ! optical ) return;
		optical.classList.add( 'afc-optical-has-refresh' );

		const existing = optical.querySelector( '.afc-olt-nav-refresh' );
		if ( existing ) {
			navButton = existing;
			navAge = navButton.querySelector( '.afc-olt-nav-refresh-age' );
			setNavState( 'ready' );
			return;
		}

		navButton = document.createElement( 'span' );
		navButton.className = 'afc-olt-nav-refresh';
		navButton.setAttribute( 'role', 'button' );
		navButton.setAttribute( 'tabindex', '0' );
		navButton.setAttribute( 'aria-label', 'Refresh all OLT readings' );
		navButton.title = 'Refresh all OLT readings';
		navButton.innerHTML = '<span class="afc-olt-nav-refresh-icon"></span><sup class="afc-olt-nav-refresh-age"></sup>';
		optical.appendChild( navButton );
		navAge = navButton.querySelector( '.afc-olt-nav-refresh-age' );
		setNavState( 'ready' );

		[ 'pointerdown', 'mousedown', 'mouseup', 'click' ].forEach( function ( eventName ) {
			navButton.addEventListener( eventName, function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( eventName === 'click' ) runFullRefresh();
			}, true );
		} );
		navButton.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Enter' && event.key !== ' ' ) return;
			event.preventDefault();
			event.stopPropagation();
			runFullRefresh();
		}, true );
	}

	function opticalFromRecord( account ) {
		const key = accountKey( account );
		if ( opticalByAccount.has( key ) ) return opticalByAccount.get( key );
		if ( window.AFCSearchAjaxify && typeof window.AFCSearchAjaxify.get === 'function' ) {
			const record = window.AFCSearchAjaxify.get( account );
			if ( record && record.optical ) {
				opticalByAccount.set( key, record.optical );
				return record.optical;
			}
		}
		return null;
	}

	function resultAccount( result ) {
		return result.getAttribute( 'data-account' ) || result.getAttribute( 'data-afc-dashboard-payment-account' ) || '';
	}

	function opticalChip( optical ) {
		if ( ! optical ) return '<span class="afc-olt-result-chip is-empty">—</span>';
		if ( optical.status === 'offline' ) return '<span class="afc-olt-result-chip is-offline">Offline</span>';
		if ( optical.rx_power !== null && optical.rx_power !== undefined && Number( optical.rx_power ) < -1 ) {
			const tone = optical.status === 'critical' ? ' is-critical' : ( optical.status === 'warning' ? ' is-warning' : ' is-good' );
			return '<span class="afc-olt-result-chip' + tone + '">RX ' + esc( Number( optical.rx_power ).toFixed( 2 ) ) + ' dBm</span>';
		}
		return '<span class="afc-olt-result-chip is-empty">—</span>';
	}

	function resultToolsHtml( optical ) {
		const ts = Number( optical && optical.refreshed_ts ? optical.refreshed_ts : 0 );
		return '<span class="afc-olt-result-tools">' + opticalChip( optical ) +
			'<button type="button" class="afc-olt-result-refresh" aria-label="Refresh this customer optical reading" title="Refresh this customer only">' +
				'<span class="afc-olt-result-refresh-icon">' + icon( ts ? 'check' : 'refresh' ) + '</span>' +
				'<sup data-afc-olt-result-age="' + esc( ts ) + '">' + esc( compactAge( ts ) ) + '</sup>' +
			'</button></span>';
	}

	function decorateResult( result ) {
		const account = resultAccount( result );
		if ( ! account ) return;
		const live = result.querySelector( '.afc-search-ajaxify-live' );
		if ( ! live ) return;
		const optical = opticalFromRecord( account );
		let tools = live.querySelector( ':scope > .afc-olt-result-tools' );
		const html = resultToolsHtml( optical );
		if ( tools ) tools.outerHTML = html;
		else live.insertAdjacentHTML( 'beforeend', html );
	}

	function decorateResults() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( decorateResult );
	}

	function updateOpticalCacheFromSearch() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' ).forEach( function ( result ) {
			const account = resultAccount( result );
			if ( ! account || ! window.AFCSearchAjaxify || typeof window.AFCSearchAjaxify.get !== 'function' ) return;
			const record = window.AFCSearchAjaxify.get( account );
			if ( record && record.optical ) opticalByAccount.set( accountKey( account ), record.optical );
		} );
		decorateResults();
	}

	function refreshOne( button ) {
		const result = button.closest( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' );
		if ( ! result || ! cfg.ajaxUrl || ! cfg.nonce ) return;
		const account = resultAccount( result );
		if ( ! account ) return;
		button.disabled = true;
		button.classList.add( 'is-loading' );
		button.querySelector( '.afc-olt-result-refresh-icon' ).innerHTML = icon( 'loading' );

		$.post( cfg.ajaxUrl, { action: 'afc_refresh_customer_optical', nonce: cfg.nonce, account: account } )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.optical ) {
					opticalByAccount.set( accountKey( account ), response.data.optical );
					decorateResult( result );
				}
			} )
			.always( function () {
				const current = result.querySelector( '.afc-olt-result-refresh' );
				if ( current ) {
					current.disabled = false;
					current.classList.remove( 'is-loading' );
				}
			} );
	}

	function augmentPopover( result ) {
		const account = result ? resultAccount( result ) : '';
		const optical = opticalFromRecord( account );
		if ( ! optical ) return;
		window.setTimeout( function () {
			const popover = document.querySelector( '.afc-search-ajaxify-popover:not([hidden])' );
			const facts = popover ? popover.querySelector( '.afc-search-live-facts' ) : null;
			if ( ! facts || facts.querySelector( '[data-afc-optical-facts]' ) ) return;
			const rx = optical.rx_power !== null && optical.rx_power !== undefined && Number( optical.rx_power ) < -1 ? Number( optical.rx_power ).toFixed( 2 ) + ' dBm' : ( optical.status === 'offline' ? 'Offline' : 'No saved RX' );
			const location = optical.mapped ? ( optical.olt_name ? optical.olt_name + ' · ' : '' ) + 'PON ' + optical.pon + ' / ONU ' + optical.onu : 'Not mapped';
			const age = compactAge( optical.refreshed_ts || 0 );
			const wrapper = document.createElement( 'div' );
			wrapper.setAttribute( 'data-afc-optical-facts', '1' );
			wrapper.className = 'afc-olt-popover-facts';
			wrapper.innerHTML = '<dt>Optical RX</dt><dd>' + esc( rx ) + '</dd><small>' + esc( location + ' · ' + age ) + '</small>';
			facts.appendChild( wrapper );
		}, 0 );
	}

	function schedulePanelMarkup() {
		const schedule = cfg.schedule || { enabled: 1, times: [ '01:00', '06:00', '12:00', '18:00' ] };
		const times = Array.isArray( schedule.times ) ? schedule.times.slice( 0, 4 ) : [];
		while ( times.length < 4 ) times.push( [ '01:00', '06:00', '12:00', '18:00' ][ times.length ] );
		return '<section class="afc-olt-schedule-panel">' +
			'<div class="afc-olt-schedule-copy"><span>Saved optical readings</span><h3>Automatic OLT refresh</h3><p>Normal page visits use the saved record. The OLT is contacted only at these times or when you press a refresh icon.</p></div>' +
			'<label class="afc-olt-schedule-toggle"><input type="checkbox" data-afc-olt-schedule-enabled ' + ( schedule.enabled ? 'checked' : '' ) + '><span>Scheduled refresh</span></label>' +
			'<div class="afc-olt-schedule-times">' + times.map( function ( time, index ) { return '<label><span>Run ' + ( index + 1 ) + '</span><input type="time" value="' + esc( time ) + '" data-afc-olt-schedule-time></label>'; } ).join( '' ) + '</div>' +
			'<div class="afc-olt-schedule-actions"><button type="button" data-afc-olt-schedule-save>Save times</button><button type="button" class="is-secondary" data-afc-olt-cache-flush>Flush runtime cache</button><small data-afc-olt-schedule-message></small></div>' +
		'</section>';
	}

	function ensureSchedulePanel() {
		if ( document.querySelector( '.afc-olt-schedule-panel' ) ) return;
		const host = document.getElementById( 'afc-olt-host' );
		if ( ! host ) return;
		const container = host.closest( '.afc-admin-page' ) ? host.closest( '.afc-admin-page' ).querySelector( '.container-xl' ) : null;
		const row = container ? container.querySelector( '.row.row-cards' ) : null;
		if ( row ) row.insertAdjacentHTML( 'beforebegin', schedulePanelMarkup() );
	}

	function saveSchedule() {
		const panel = document.querySelector( '.afc-olt-schedule-panel' );
		if ( ! panel ) return;
		const button = panel.querySelector( '[data-afc-olt-schedule-save]' );
		const message = panel.querySelector( '[data-afc-olt-schedule-message]' );
		const enabled = panel.querySelector( '[data-afc-olt-schedule-enabled]' ).checked ? 1 : 0;
		const times = Array.from( panel.querySelectorAll( '[data-afc-olt-schedule-time]' ) ).map( function ( input ) { return input.value; } ).filter( Boolean );
		button.disabled = true;
		message.textContent = 'Saving…';
		$.post( cfg.ajaxUrl, { action: 'afc_save_olt_refresh_schedule', nonce: cfg.nonce, enabled: enabled, times: JSON.stringify( times ) } )
			.done( function ( response ) { message.textContent = response && response.success ? 'Saved' : 'Could not save'; } )
			.fail( function () { message.textContent = 'Could not save'; } )
			.always( function () { button.disabled = false; } );
	}

	function flushRuntimeCache() {
		const panel = document.querySelector( '.afc-olt-schedule-panel' );
		const message = panel ? panel.querySelector( '[data-afc-olt-schedule-message]' ) : null;
		if ( message ) message.textContent = 'Flushing…';
		$.post( cfg.ajaxUrl, { action: 'afc_flush_olt_runtime_cache', nonce: cfg.nonce } )
			.done( function ( response ) { if ( message ) message.textContent = response && response.success ? 'Runtime cache cleared; saved readings kept' : 'Could not flush'; } )
			.fail( function () { if ( message ) message.textContent = 'Could not flush'; } );
	}

	$( document ).ajaxSend( function ( event, xhr, settings ) {
		if ( requestHasAction( settings, 'afc_get_olt_overview' ) && String( settings.data || '' ).includes( 'refresh=1' ) ) setNavState( 'loading' );
	} );

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( ! requestHasAction( settings, 'afc_get_olt_overview' ) ) return;
		const data = xhr.responseJSON && xhr.responseJSON.success ? xhr.responseJSON.data : null;
		if ( data && data.last_refresh && data.last_refresh.refreshed_ts ) lastRefreshTs = Number( data.last_refresh.refreshed_ts );
		setNavState( 'ready' );
	} );

	document.addEventListener( 'afc:search-ajaxify:results', updateOpticalCacheFromSearch );

	document.addEventListener( 'pointerdown', function ( event ) {
		const refresh = event.target.closest && event.target.closest( '.afc-olt-result-refresh' );
		if ( refresh ) {
			event.preventDefault();
			event.stopPropagation();
		}
	}, true );

	document.addEventListener( 'click', function ( event ) {
		const refresh = event.target.closest && event.target.closest( '.afc-olt-result-refresh' );
		if ( refresh ) {
			event.preventDefault();
			event.stopPropagation();
			event.stopImmediatePropagation();
			refreshOne( refresh );
			return;
		}
		const save = event.target.closest && event.target.closest( '[data-afc-olt-schedule-save]' );
		if ( save ) { event.preventDefault(); saveSchedule(); return; }
		const flush = event.target.closest && event.target.closest( '[data-afc-olt-cache-flush]' );
		if ( flush ) { event.preventDefault(); flushRuntimeCache(); return; }
		const info = event.target.closest && event.target.closest( '.afc-search-ajaxify-info' );
		if ( info ) augmentPopover( info.closest( '.afc-basic-customer-result, .afc-dashboard-payment-result' ) );
	}, true );

	document.addEventListener( 'mouseover', function ( event ) {
		const result = event.target.closest && event.target.closest( '.afc-basic-customer-result[data-account], .afc-dashboard-payment-result[data-afc-dashboard-payment-account]' );
		if ( result ) window.setTimeout( function () { augmentPopover( result ); }, 310 );
	}, true );

	function boot() {
		ensureNavRefresh();
		ensureSchedulePanel();
		decorateResults();
		ageTimer = window.setInterval( updateAgeLabels, 30000 );
		window.setTimeout( function () { ensureNavRefresh(); ensureSchedulePanel(); }, 250 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
