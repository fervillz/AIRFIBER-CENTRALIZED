( function ( $ ) {
	'use strict';

	const cache = new Map();
	let requestRunning = false;
	let loadTimer = null;
	let patchTimer = null;
	let lastLoadAt = 0;

	function key( value ) {
		return String( value || '' ).trim().toLowerCase();
	}

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

	function parseRowUser( row ) {
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function currentRows() {
		return Array.from( document.querySelectorAll( '#afc-ppp-table tbody tr[data-user]' ) );
	}

	function accounts() {
		const out = [];
		const seen = new Set();
		currentRows().forEach( function ( row ) {
			const user = parseRowUser( row );
			const accountKey = user && user.name ? key( user.name ) : '';
			if ( ! accountKey || seen.has( accountKey ) ) return;
			seen.add( accountKey );
			out.push( {
				username: user.name,
				caller_id: user.caller_id || '',
				customer_id: Number( user.customer_id || 0 ),
				imported: Boolean( user.imported )
			} );
		} );
		return out;
	}

	function statusMeta( status ) {
		const meta = {
			good: { label: 'Good', badge: 'bg-success-lt', chip: 'is-safe' },
			warning: { label: 'Warning', badge: 'bg-yellow-lt', chip: 'is-due' },
			critical: { label: 'Critical', badge: 'bg-danger-lt', chip: 'is-expired' },
			offline: { label: 'Offline', badge: 'bg-secondary-lt', chip: 'is-loading' },
			invalid: { label: 'No signal', badge: 'bg-secondary-lt', chip: 'is-loading' },
			stale: { label: 'Stale', badge: 'bg-yellow-lt', chip: 'is-upcoming' },
			unavailable: { label: 'Unavailable', badge: 'bg-secondary-lt', chip: 'is-loading' },
			unmatched: { label: 'No OLT match', badge: 'bg-secondary-lt', chip: 'is-loading' }
		};
		return meta[ status ] || meta.unavailable;
	}

	function matchLabel( signal ) {
		if ( 'mac' === signal.match_method ) return 'MAC match';
		if ( 'description' === signal.match_method ) return 'ONU description match';
		if ( 'description_fuzzy' === signal.match_method ) return 'ONU name match';
		return signal.temporary ? 'Detected from OLT' : '';
	}

	function hasReading( signal ) {
		return signal && null !== signal.rx_power && undefined !== signal.rx_power && Number.isFinite( Number( signal.rx_power ) ) && Number( signal.rx_power ) < -1;
	}

	function advancedHtml( user, signal ) {
		if ( ! signal || ( ! signal.mapped && ! signal.detected ) ) {
			return '<span class="badge bg-secondary-lt">No OLT match</span>' +
				'<div class="small text-secondary">PPP account has no confident ONU match yet.</div>';
		}

		const status = statusMeta( signal.status || 'unavailable' );
		const reading = hasReading( signal )
			? '<strong class="afc-optical-reading">' + escapeHtml( Number( signal.rx_power ).toFixed( 2 ) ) + ' dBm</strong>'
			: '<span class="text-secondary">No live reading</span>';
		const detected = matchLabel( signal );
		const confidence = signal.confidence ? ' · ' + escapeHtml( signal.confidence ) + '%' : '';
		const note = ! user.imported
			? '<div class="small text-primary">' + escapeHtml( detected || 'Detected from OLT' ) + confidence + ' · import to save mapping</div>'
			: ( signal.temporary ? '<div class="small text-primary">' + escapeHtml( detected ) + confidence + '</div>' : '' );
		const title = [
			'PON ' + signal.pon + ' / ONU ' + signal.onu,
			signal.description ? 'OLT description: ' + signal.description : '',
			signal.onu_mac ? 'ONU MAC: ' + signal.onu_mac : '',
			signal.collected_at ? 'Collected: ' + signal.collected_at : '',
			signal.message || ''
		].filter( Boolean ).join( '\n' );

		return '<div title="' + escapeAttr( title ) + '">' + reading +
			' <span class="badge ' + status.badge + '">' + escapeHtml( status.label ) + '</span>' +
			'<div class="small text-secondary">PON ' + escapeHtml( signal.pon ) + ' · ONU ' + escapeHtml( signal.onu ) + '</div>' +
			note + '</div>';
	}

	function patchAdvanced() {
		currentRows().forEach( function ( row ) {
			const user = parseRowUser( row );
			if ( ! user || ! user.name || user.imported ) return;
			const signal = cache.get( key( user.name ) );
			if ( ! signal || ! row.children[5] ) return;
			const html = advancedHtml( user, signal );
			if ( row.children[5].dataset.afcPppOpticalSignature === html ) return;
			row.children[5].dataset.afcPppOpticalSignature = html;
			row.children[5].innerHTML = html;
		} );
	}

	function basicChipHtml( signal ) {
		if ( ! signal || ( ! signal.mapped && ! signal.detected ) ) return '';
		const status = statusMeta( signal.status || 'unavailable' );
		const label = hasReading( signal )
			? 'RX ' + Number( signal.rx_power ).toFixed( 2 ) + ' dBm'
			: 'RX ' + status.label.toUpperCase();
		const title = 'PON ' + signal.pon + ' / ONU ' + signal.onu +
			( signal.description ? ' · ' + signal.description : '' ) +
			( signal.collected_at ? ' · ' + signal.collected_at : '' );
		return '<span class="afc-polished-signal ' + status.chip + '" title="' + escapeAttr( title ) + '"><b>' + escapeHtml( label ) + '</b></span>';
	}

	function patchBasic() {
		document.querySelectorAll( '.afc-basic-customer-result[data-account]' ).forEach( function ( result ) {
			const signal = cache.get( key( result.getAttribute( 'data-account' ) ) );
			let row = result.querySelector( ':scope > .afc-optical-basic-row' );
			const html = basicChipHtml( signal );

			if ( ! html ) {
				if ( row ) row.remove();
				return;
			}
			if ( ! row ) {
				row = document.createElement( 'span' );
				row.className = 'afc-polished-signal-row afc-optical-basic-row';
				row.style.gridColumn = '1 / -1';
				result.appendChild( row );
			}
			if ( row.dataset.afcPppOpticalSignature === html ) return;
			row.dataset.afcPppOpticalSignature = html;
			row.innerHTML = html;
		} );
	}

	function patchAll() {
		patchAdvanced();
		patchBasic();
	}

	function schedulePatch() {
		window.clearTimeout( patchTimer );
		patchTimer = window.setTimeout( patchAll, 40 );
	}

	function requestHasAction( settings, action ) {
		if ( ! settings ) return false;
		if ( settings.data && 'object' === typeof settings.data ) return settings.data.action === action;
		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function loadSignals( force ) {
		if ( requestRunning || ! window.afcPPP || ! afcPPP.ajaxUrl || ! afcPPP.nonce ) return;
		const list = accounts();
		if ( ! list.length ) return;

		requestRunning = true;
		lastLoadAt = Date.now();
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_get_olt_ppp_signals',
			nonce: afcPPP.nonce,
			refresh: force ? 1 : 0,
			accounts: JSON.stringify( list )
		} ).done( function ( response ) {
			if ( ! response || ! response.success || ! response.data ) return;
			Object.keys( response.data.signals || {} ).forEach( function ( account ) {
				cache.set( key( account ), response.data.signals[ account ] );
			} );
			patchAll();
		} ).always( function () {
			requestRunning = false;
		} );
	}

	function scheduleLoad( delay ) {
		window.clearTimeout( loadTimer );
		loadTimer = window.setTimeout( function () {
			patchAll();
			if ( Date.now() - lastLoadAt > 15000 ) loadSignals( false );
		}, null == delay ? 260 : delay );
	}

	function boot() {
		const tableBody = document.querySelector( '#afc-ppp-table tbody' );
		if ( ! tableBody ) return;

		const tableObserver = new MutationObserver( function () {
			scheduleLoad( 180 );
		} );
		tableObserver.observe( tableBody, { childList: true } );

		const pageObserver = new MutationObserver( function ( mutations ) {
			for ( let index = 0; index < mutations.length; index++ ) {
				const target = mutations[ index ].target;
				if ( target && target.closest && ( target.closest( '#afc-basic-payment-results' ) || target.closest( '.afc-basic-customer-result' ) ) ) {
					schedulePatch();
					break;
				}
			}
		} );
		pageObserver.observe( document.body, { childList: true, subtree: true } );

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( requestHasAction( settings, 'afc_get_olt_customer_signals' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				window.setTimeout( function () { loadSignals( false ); }, 120 );
			}
			if ( requestHasAction( settings, 'afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				scheduleLoad( 220 );
			}
		} );

		/* Let the normal imported-customer optical request start first. If it is
		 * already refreshing the OLT, this request will reuse the resulting cache
		 * on the follow-up ajaxSuccess above. */
		scheduleLoad( 650 );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
