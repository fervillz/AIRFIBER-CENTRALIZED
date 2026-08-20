( function ( $ ) {
	'use strict';

	let requestRunning = false;
	let autoTimer = null;
	let lastAutoAt = 0;
	const signalCache = {};

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function escapeAttr( value ) {
		return String( null == value ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function parseUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function writeUser( row, user ) {
		row.setAttribute( 'data-user', encodeURIComponent( JSON.stringify( user ) ) );
	}

	function opticalHtml( user ) {
		const optical = user.optical || {};
		if ( ! user.imported ) {
			return '<span class="badge bg-secondary-lt">Import first</span>';
		}
		if ( ! optical.mapped ) {
			if ( optical.suggested ) {
				const suggestion = optical.suggested || {};
				const label = suggestion.match_method === 'description_fuzzy' ? 'Name suggestion' : 'Description match';
				const confidence = suggestion.confidence ? ' · ' + escapeHtml( suggestion.confidence ) + '%' : '';
				return '<span class="badge bg-azure-lt">' + label + '</span>' +
					'<div class="small text-secondary">' + escapeHtml( suggestion.olt_name ? suggestion.olt_name + ' · ' : '' ) + 'PON ' + escapeHtml( suggestion.pon ) + ' · ONU ' + escapeHtml( suggestion.onu ) + confidence + '</div>' +
					( suggestion.description ? '<div class="small text-secondary">' + escapeHtml( suggestion.description ) + '</div>' : '' ) +
					'<button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Review mapping</button>';
			}
			return '<span class="badge bg-secondary-lt">Not mapped</span><div><button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Map ONU</button></div>';
		}

		const classes = {
			good: 'bg-success-lt',
			warning: 'bg-yellow-lt',
			critical: 'bg-danger-lt',
			offline: 'bg-secondary-lt',
			invalid: 'bg-secondary-lt',
			stale: 'bg-yellow-lt',
			unavailable: 'bg-secondary-lt'
		};
		const labels = {
			good: 'Good',
			warning: 'Warning',
			critical: 'Critical',
			offline: 'Offline',
			invalid: 'Signal unavailable',
			stale: 'Stale',
			unavailable: 'Unavailable'
		};
		const status = optical.status || 'unavailable';
		const credible = [ 'good', 'warning', 'critical' ].includes( status ) &&
			null !== optical.rx_power && undefined !== optical.rx_power && Number( optical.rx_power ) < -1;
		const reading = credible
			? '<span class="afc-optical-reading">' + escapeHtml( Number( optical.rx_power ).toFixed( 2 ) ) + ' dBm</span>'
			: '<span class="text-secondary">—</span>';
		const title = [
			( optical.olt_name ? optical.olt_name + ' · ' : '' ) + 'PON ' + optical.pon + ' / ONU ' + optical.onu,
			optical.description ? 'OLT description: ' + optical.description : '',
			optical.onu_mac ? 'ONU MAC: ' + optical.onu_mac : '',
			optical.onu_type ? 'ONU type: ' + optical.onu_type : '',
			optical.collected_at ? 'Collected: ' + optical.collected_at : '',
			optical.message || ''
		].filter( Boolean ).join( '\n' );
		const auto = optical.auto_matched ? '<div class="small text-success">Matched automatically by MAC</div>' : '';

		return '<div title="' + escapeAttr( title ) + '">' + reading +
			' <span class="badge ' + ( classes[ status ] || classes.unavailable ) + '">' + escapeHtml( labels[ status ] || labels.unavailable ) + '</span>' +
			'<div class="small text-secondary">' + escapeHtml( optical.olt_name ? optical.olt_name + ' · ' : '' ) + 'PON ' + escapeHtml( optical.pon ) + ' · ONU ' + escapeHtml( optical.onu ) + '</div>' +
			auto +
			'<button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Edit mapping</button></div>';
	}

	function renderSummary( summary ) {
		const status = document.getElementById( 'afc-optical-status' );
		if ( ! status ) return;

		if ( ! summary || ! summary.enabled ) {
			status.innerHTML = '<div class="alert alert-info py-2 mb-0">Optical monitoring is not enabled.</div>';
			return;
		}
		if ( ! summary.available ) {
			status.innerHTML = '<div class="alert alert-warning py-2 mb-0">' + escapeHtml( summary.message || 'Optical readings are currently unavailable.' ) + '</div>';
			return;
		}

		const stale = summary.stale ? ' · cached/stale' : ' · current';
		const valid = Number( summary.valid_count || 0 );
		const invalid = Number( summary.invalid_count || 0 );
		status.innerHTML = '<div class="alert alert-secondary py-2 mb-0">' +
			escapeHtml( summary.count || 0 ) + ' ONU rows · ' + valid + ' valid RX' + ( invalid ? ' · ' + invalid + ' invalid RX' : '' ) +
			' · ' + escapeHtml( summary.collected_at || 'time unavailable' ) + escapeHtml( stale ) + '</div>';
	}

	function currentRows() {
		return Array.from( document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ) );
	}

	function sameMapping( user, signal ) {
		const current = user && user.optical ? user.optical : {};
		if ( ! current.mapped || ! signal || ! signal.mapped ) return true;
		return String( current.olt_id || 'primary' ) === String( signal.olt_id || 'primary' ) && Number( current.pon || 0 ) === Number( signal.pon || 0 ) && Number( current.onu || 0 ) === Number( signal.onu || 0 );
	}

	function applyCachedSignals() {
		let mismatch = false;
		currentRows().forEach( function ( row ) {
			const user = parseUser( row );
			if ( ! user || ! user.customer_id ) return;
			const signal = signalCache[ String( user.customer_id ) ];
			if ( ! signal ) return;
			if ( ! sameMapping( user, signal ) ) {
				mismatch = true;
				return;
			}
			user.optical = signal;
			writeUser( row, user );
			if ( row.children[5] ) row.children[5].innerHTML = opticalHtml( user );
		} );
		return mismatch;
	}

	function targets() {
		const output = [];
		const seen = new Set();
		currentRows().forEach( function ( row ) {
			const user = parseUser( row );
			if ( ! user || ! user.imported || ! user.customer_id || seen.has( String( user.customer_id ) ) ) return;
			seen.add( String( user.customer_id ) );
			output.push( {
				id: Number( user.customer_id ),
				caller_id: user.caller_id || '',
				username: user.name || ''
			} );
		} );
		return output;
	}

	function mergeSignals( signals ) {
		Object.keys( signals || {} ).forEach( function ( customerId ) {
			signalCache[ String( customerId ) ] = signals[ customerId ];
		} );
		applyCachedSignals();
	}

	function loadOptical( force ) {
		if ( requestRunning || ! window.afcPPP || ! afcPPP.ajaxUrl || ! afcPPP.nonce ) return false;
		const customerTargets = targets();
		if ( ! customerTargets.length ) return false;

		requestRunning = true;
		if ( ! force ) lastAutoAt = Date.now();
		const button = document.getElementById( 'afc-refresh-optical' );
		if ( button ) {
			button.disabled = true;
			button.textContent = force ? 'Refreshing optical…' : 'Loading optical…';
		}
		const status = document.getElementById( 'afc-optical-status' );
		if ( status && ! force ) {
			status.innerHTML = '<div class="alert alert-secondary py-2 mb-0">PPP accounts are ready. Optical readings are loading separately in the background…</div>';
		}

		$.post( afcPPP.ajaxUrl, {
			action: 'afc_get_olt_customer_signals',
			nonce: afcPPP.nonce,
			refresh: force ? 1 : 0,
			customers: JSON.stringify( customerTargets )
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				renderSummary( response && response.data ? response.data.summary || { enabled: true, available: false, message: response.data.message || 'Optical request failed.' } : { enabled: true, available: false, message: 'Optical request failed.' } );
				return;
			}
			mergeSignals( response.data.signals || {} );
			renderSummary( response.data.summary || {} );
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Optical readings could not be loaded.';
			renderSummary( { enabled: true, available: false, message: message } );
		} ).always( function () {
			requestRunning = false;
			if ( button ) {
				button.disabled = false;
				button.textContent = afcPPP.opticalButton || 'Refresh Optical';
			}
		} );
		return true;
	}

	function scheduleAutoLoad( immediate ) {
		window.clearTimeout( autoTimer );
		autoTimer = window.setTimeout( function () {
			const mismatch = applyCachedSignals();
			const now = Date.now();
			if ( mismatch || immediate || now - lastAutoAt >= 60000 ) {
				loadOptical( false );
			}
		}, immediate ? 60 : 220 );
	}

	function boot() {
		const tableBody = document.querySelector( '#afc-ppp-table tbody' );
		if ( ! tableBody ) return;

		document.addEventListener( 'click', function ( event ) {
			const refresh = event.target.closest && event.target.closest( '#afc-refresh-optical' );
			if ( ! refresh ) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			loadOptical( true );
		}, true );

		const observer = new MutationObserver( function () {
			scheduleAutoLoad( false );
		} );
		observer.observe( tableBody, { childList: true } );
		scheduleAutoLoad( true );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
