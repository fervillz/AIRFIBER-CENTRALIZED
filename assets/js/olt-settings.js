( function ( $ ) {
	'use strict';

	let overviewLoaded = false;
	let overviewLoading = false;
	let overviewData = null;

	function toggleVersionFields() {
		const version = $( '#afc-olt-version' ).val();
		$( '.afc-olt-v2-fields' ).toggle( '2c' === version );
		$( '.afc-olt-v3-fields' ).toggle( '3' === version );
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

	function scriptBase() {
		const script = document.querySelector( 'script[src*="/assets/js/olt-settings.js"]' );
		if ( ! script || ! script.src ) return '';
		const marker = '/assets/js/olt-settings.js';
		const index = script.src.indexOf( marker );
		return index >= 0 ? script.src.slice( 0, index ) : '';
	}

	function loadOverviewStyle() {
		if ( document.getElementById( 'afc-olt-overview-style' ) ) return;
		const base = scriptBase();
		if ( ! base ) return;
		const link = document.createElement( 'link' );
		link.id = 'afc-olt-overview-style';
		link.rel = 'stylesheet';
		link.href = base + '/assets/css/olt-overview.css?v=2.10.1';
		document.head.appendChild( link );
	}

	function makeOpticalNavClickable() {
		const button = document.querySelector( '.afc-frontend-nav [data-afc-app-panel="optical"]' );
		if ( ! button || button.tagName === 'A' ) return;

		const link = document.createElement( 'a' );
		link.href = '#optical';
		link.className = ( button.className || '' ) + ' afc-optical-direct-link';
		link.setAttribute( 'data-afc-app-panel', 'optical' );
		link.setAttribute( 'aria-pressed', button.getAttribute( 'aria-pressed' ) || 'false' );
		link.textContent = button.textContent;
		button.replaceWith( link );
	}

	function frontendOpticalPanel() {
		if ( ! document.getElementById( 'afc-frontend-app' ) ) return null;
		return document.querySelector( '[data-afc-panel="optical"] .afc-admin-page .container-xl' );
	}

	function ensureOverview() {
		const container = frontendOpticalPanel();
		if ( ! container ) return null;
		let overview = document.getElementById( 'afc-olt-overview' );
		if ( overview ) return overview;

		loadOverviewStyle();
		overview = document.createElement( 'section' );
		overview.id = 'afc-olt-overview';
		overview.className = 'afc-olt-overview';
		overview.innerHTML =
			'<div class="afc-olt-overview-head">' +
				'<div><span class="afc-olt-overview-kicker">Optical network</span><h3>ONU signal overview</h3>' +
				'<p>One cached OLT snapshot, grouped by PON and matched to saved customer ONU mappings.</p></div>' +
				'<button type="button" class="afc-olt-overview-refresh" data-afc-olt-overview-refresh>Refresh optical data</button>' +
			'</div>' +
			'<div class="afc-olt-overview-status" data-afc-olt-overview-status>Open Optical to load the latest cached snapshot.</div>' +
			'<div class="afc-olt-overview-metrics">' +
				'<div class="afc-olt-overview-metric"><span>ONU readings</span><em data-afc-olt-count="readings">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Mapped</span><em data-afc-olt-count="mapped">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Good</span><em data-afc-olt-count="good">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Warning</span><em data-afc-olt-count="warning">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Critical</span><em data-afc-olt-count="critical">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>No RX / offline</span><em data-afc-olt-count="offline">—</em></div>' +
			'</div>' +
			'<div class="afc-olt-overview-toolbar">' +
				'<input type="search" data-afc-olt-filter="search" placeholder="Search customer, PPP username, PON or ONU">' +
				'<select data-afc-olt-filter="status"><option value="">All signal states</option><option value="good">Good</option><option value="warning">Warning</option><option value="critical">Critical</option><option value="offline">No RX / Offline</option><option value="unmapped">Unmapped</option></select>' +
				'<select data-afc-olt-filter="pon"><option value="">All PONs</option></select>' +
			'</div>' +
			'<div class="afc-olt-pon-grid" data-afc-olt-pons></div>' +
			'<div class="afc-olt-table-wrap"><table class="afc-olt-overview-table"><thead><tr><th>PON</th><th>ONU</th><th>RX power</th><th>Signal</th><th>Customer</th></tr></thead><tbody data-afc-olt-onus><tr><td colspan="5" class="afc-olt-overview-empty">Optical data has not loaded yet.</td></tr></tbody></table></div>';

		const row = container.querySelector( '.row.row-cards' );
		if ( row ) container.insertBefore( overview, row );
		else container.appendChild( overview );
		return overview;
	}

	function setOverviewStatus( message, state ) {
		const node = document.querySelector( '[data-afc-olt-overview-status]' );
		if ( ! node ) return;
		node.className = 'afc-olt-overview-status' + ( state ? ' is-' + state : '' );
		node.textContent = message;
	}

	function statusClass( status ) {
		return [ 'good', 'warning', 'critical', 'offline' ].includes( status ) ? status : 'offline';
	}

	function renderCounts( counts ) {
		Object.keys( counts || {} ).forEach( function ( key ) {
			const node = document.querySelector( '[data-afc-olt-count="' + key + '"]' );
			if ( node ) node.textContent = counts[ key ];
		} );
	}

	function renderPonOptions( pons ) {
		const select = document.querySelector( '[data-afc-olt-filter="pon"]' );
		if ( ! select ) return;
		const current = select.value;
		select.innerHTML = '<option value="">All PONs</option>' + ( pons || [] ).map( function ( item ) {
			return '<option value="' + escapeAttr( item.pon ) + '">PON ' + escapeHtml( item.pon ) + '</option>';
		} ).join( '' );
		select.value = current;
	}

	function renderPons( pons ) {
		const target = document.querySelector( '[data-afc-olt-pons]' );
		if ( ! target ) return;
		if ( ! pons || ! pons.length ) {
			target.innerHTML = '';
			return;
		}
		target.innerHTML = pons.map( function ( item ) {
			const weak = null === item.weakest || undefined === item.weakest ? 'No valid RX' : Number( item.weakest ).toFixed( 2 ) + ' dBm';
			return '<article class="afc-olt-pon-card">' +
				'<div class="afc-olt-pon-card-head"><h4>PON ' + escapeHtml( item.pon ) + '</h4><span>' + escapeHtml( item.total ) + ' ONU</span></div>' +
				'<div class="afc-olt-pon-card-meta">' +
					'<span>' + escapeHtml( item.mapped ) + ' mapped</span>' +
					'<span>' + escapeHtml( item.warning + item.critical ) + ' weak</span>' +
					'<span>' + escapeHtml( item.offline ) + ' no RX</span>' +
					'<span>Weakest ' + escapeHtml( weak ) + '</span>' +
				'</div></article>';
		} ).join( '' );
	}

	function filteredOnus() {
		if ( ! overviewData || ! Array.isArray( overviewData.onus ) ) return [];
		const searchNode = document.querySelector( '[data-afc-olt-filter="search"]' );
		const statusNode = document.querySelector( '[data-afc-olt-filter="status"]' );
		const ponNode = document.querySelector( '[data-afc-olt-filter="pon"]' );
		const search = String( searchNode ? searchNode.value : '' ).trim().toLowerCase();
		const status = statusNode ? statusNode.value : '';
		const pon = ponNode ? ponNode.value : '';

		return overviewData.onus.filter( function ( item ) {
			if ( pon && String( item.pon ) !== pon ) return false;
			if ( status === 'unmapped' && item.mapped ) return false;
			if ( status && status !== 'unmapped' && item.status !== status ) return false;
			if ( ! search ) return true;
			const customer = item.customer || {};
			return [ item.pon, item.onu, customer.name, customer.username, customer.onu_mac ].join( ' ' ).toLowerCase().includes( search );
		} );
	}

	function renderOnus() {
		const target = document.querySelector( '[data-afc-olt-onus]' );
		if ( ! target ) return;
		const onus = filteredOnus();
		if ( ! onus.length ) {
			target.innerHTML = '<tr><td colspan="5" class="afc-olt-overview-empty">No ONU matches the current filters.</td></tr>';
			return;
		}

		target.innerHTML = onus.map( function ( item ) {
			const customer = item.customer || {};
			const rx = null === item.rx_power || undefined === item.rx_power ? 'No RX' : Number( item.rx_power ).toFixed( 2 ) + ' dBm';
			const customerHtml = item.mapped
				? '<div class="afc-olt-customer"><span>' + escapeHtml( customer.name || 'Customer' ) + '</span><small>' + escapeHtml( customer.username || 'No PPP username' ) + '</small></div>'
				: '<span class="afc-olt-unmapped">Not mapped</span>';
			return '<tr>' +
				'<td>' + escapeHtml( item.pon ) + '</td>' +
				'<td>' + escapeHtml( item.onu ) + '</td>' +
				'<td class="afc-olt-rx">' + escapeHtml( rx ) + '</td>' +
				'<td><span class="afc-olt-status-pill is-' + statusClass( item.status ) + '">' + escapeHtml( item.status_label || item.status ) + '</span></td>' +
				'<td>' + customerHtml + '</td>' +
			'</tr>';
		} ).join( '' );
	}

	function renderOverview( data ) {
		overviewData = data || {};
		const summary = overviewData.summary || {};
		const diagnostics = overviewData.diagnostics || {};
		const counts = overviewData.counts || {};
		renderCounts( counts );
		renderPonOptions( overviewData.pons || [] );
		renderPons( overviewData.pons || [] );
		renderOnus();

		if ( ! summary.enabled ) {
			setOverviewStatus( 'Optical monitoring is disabled. Enable and save the OLT connection below.', 'error' );
			return;
		}
		if ( ! summary.available ) {
			setOverviewStatus( summary.message || 'Optical readings are unavailable. Check the saved OLT connection.', 'error' );
			return;
		}
		const when = summary.collected_at ? ' · collected ' + summary.collected_at : '';
		if ( summary.stale ) {
			setOverviewStatus( 'Showing the last successful OLT snapshot' + when + '. A live refresh failed, so treat these readings as stale.', 'stale' );
			return;
		}

		const zeroCount = Number( diagnostics.zero_rx || 0 );
		const readingCount = Number( counts.readings || 0 );
		if ( zeroCount > 0 ) {
			const majority = readingCount > 0 && zeroCount >= Math.ceil( readingCount / 2 );
			setOverviewStatus(
				( overviewData.olt && overviewData.olt.name ? overviewData.olt.name : 'OLT' ) + ' responded' + when +
				'. ' + zeroCount + ' ONU row' + ( zeroCount === 1 ? '' : 's' ) + ' reported 0.00 dBm. ' +
				'0.00 dBm is not treated as a valid subscriber RX level; it is shown as No RX / Offline.' +
				( majority ? ' Because most rows are zero, the configured RX-power OID or this OLT firmware needs to be verified.' : '' ),
				'stale'
			);
			return;
		}

		setOverviewStatus( ( overviewData.olt && overviewData.olt.name ? overviewData.olt.name : 'OLT' ) + ' is available' + when + '.', 'good' );
	}

	function loadOverview( force ) {
		if ( ! ensureOverview() || overviewLoading ) return;
		if ( overviewLoaded && ! force ) return;
		if ( ! window.afcOLT || ! afcOLT.ajaxUrl || ! afcOLT.nonce ) {
			setOverviewStatus( 'OLT frontend API is not ready.', 'error' );
			return;
		}

		overviewLoading = true;
		const button = document.querySelector( '[data-afc-olt-overview-refresh]' );
		if ( button ) {
			button.disabled = true;
			button.textContent = force ? 'Refreshing…' : 'Loading…';
		}
		setOverviewStatus( force ? 'Refreshing the OLT optical snapshot…' : 'Loading the OLT optical snapshot…', '' );

		$.post( afcOLT.ajaxUrl, {
			action: 'afc_get_olt_overview',
			nonce: afcOLT.nonce,
			refresh: force ? 1 : 0
		} ).done( function ( response ) {
			if ( ! response || ! response.success ) {
				setOverviewStatus( response && response.data && response.data.message ? response.data.message : 'The OLT overview request failed.', 'error' );
				return;
			}
			overviewLoaded = true;
			renderOverview( response.data || {} );
		} ).fail( function ( xhr ) {
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
				? xhr.responseJSON.data.message
				: 'The OLT overview request failed.';
			setOverviewStatus( message, 'error' );
		} ).always( function () {
			overviewLoading = false;
			if ( button ) {
				button.disabled = false;
				button.textContent = 'Refresh optical data';
			}
		} );
	}

	function opticalIsActive() {
		const panel = document.querySelector( '[data-afc-panel="optical"]' );
		return Boolean( panel && ! panel.hidden && panel.classList.contains( 'is-active' ) );
	}

	function maybeLoadOverview() {
		makeOpticalNavClickable();
		ensureOverview();
		if ( opticalIsActive() || '#optical' === window.location.hash ) loadOverview( false );
	}

	$( function () {
		const $button = $( '#afc-test-olt' );
		const $result = $( '#afc-olt-test-result' );

		loadOverviewStyle();
		makeOpticalNavClickable();
		toggleVersionFields();
		$( '#afc-olt-version' ).on( 'change', toggleVersionFields );
		maybeLoadOverview();

		$( document ).on( 'click', '[data-afc-app-panel="optical"], [data-afc-ws-panel="optical"]', function () {
			window.setTimeout( function () { loadOverview( false ); }, 40 );
		} );
		$( window ).on( 'hashchange', maybeLoadOverview );

		$( document ).on( 'click', '[data-afc-olt-overview-refresh]', function () {
			loadOverview( true );
		} );
		$( document ).on( 'input change', '[data-afc-olt-filter]', renderOnus );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( afcOLT.testing );
			$result.html( '<div class="alert alert-info">Reading the OLT optical table&hellip;</div>' );

			$.post( afcOLT.ajaxUrl, {
				action: 'afc_test_olt',
				nonce: afcOLT.nonce
			} )
				.done( function ( response ) {
					const successful = response && response.success;
					const message = response && response.data && response.data.message
						? response.data.message
						: 'The OLT returned an unexpected response.';
					$result.html( $( '<div>', {
						class: 'alert ' + ( successful ? 'alert-success' : 'alert-danger' ),
						text: message
					} ) );
					if ( successful ) {
						overviewLoaded = false;
						window.setTimeout( function () { loadOverview( false ); }, 60 );
					}
				} )
				.fail( function ( xhr ) {
					const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: 'The OLT connection test request failed.';
					$result.html( $( '<div>', { class: 'alert alert-danger', text: message } ) );
				} )
				.always( function () {
					$button.prop( 'disabled', false ).text( afcOLT.button );
				} );
		} );
	} );
}( jQuery ) );
