( function ( $ ) {
	'use strict';

	let overviewLoaded = false;
	let overviewLoading = false;
	let overviewData = null;
	let selectedPon = '';

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
		link.href = base + '/assets/css/olt-overview.css?v=2.10.5';
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
				'<p>Select a PON to see only the ONUs connected to that optical port.</p></div>' +
				'<button type="button" class="afc-olt-overview-refresh" data-afc-olt-overview-refresh>Refresh optical data</button>' +
			'</div>' +
			'<div class="afc-olt-overview-status" data-afc-olt-overview-status>Open Optical to load the latest cached snapshot.</div>' +
			'<div class="afc-olt-overview-metrics">' +
				'<div class="afc-olt-overview-metric"><span>ONU rows</span><em data-afc-olt-count="readings">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Mapped</span><em data-afc-olt-count="mapped">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Good</span><em data-afc-olt-count="good">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Warning</span><em data-afc-olt-count="warning">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Critical</span><em data-afc-olt-count="critical">—</em></div>' +
				'<div class="afc-olt-overview-metric"><span>Invalid RX</span><em data-afc-olt-count="invalid">—</em></div>' +
			'</div>' +
			'<div class="afc-olt-overview-toolbar">' +
				'<input type="search" data-afc-olt-filter="search" placeholder="Search description, MAC, PPP username or ONU">' +
				'<select data-afc-olt-filter="status"><option value="">All signal states</option><option value="good">Good</option><option value="warning">Warning</option><option value="critical">Critical</option><option value="invalid">Invalid RX</option><option value="offline">Offline</option><option value="unmapped">Unmapped</option></select>' +
			'</div>' +
			'<div class="afc-olt-pon-tabs" role="tablist" aria-label="PON ports" data-afc-olt-pons></div>' +
			'<div class="afc-olt-tab-panel" role="tabpanel" data-afc-olt-tab-panel>' +
				'<div class="afc-olt-tab-summary" data-afc-olt-tab-summary></div>' +
				'<div class="afc-olt-table-wrap"><table class="afc-olt-overview-table"><thead><tr><th>ONU</th><th>Description</th><th>MAC Address</th><th>Signal / RX power</th></tr></thead><tbody data-afc-olt-onus><tr><td colspan="4" class="afc-olt-overview-empty">Optical data has not loaded yet.</td></tr></tbody></table></div>' +
			'</div>';

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
		return [ 'good', 'warning', 'critical', 'invalid', 'offline' ].includes( status ) ? status : 'offline';
	}

	function renderCounts( counts ) {
		Object.keys( counts || {} ).forEach( function ( key ) {
			const node = document.querySelector( '[data-afc-olt-count="' + key + '"]' );
			if ( node ) node.textContent = counts[ key ];
		} );
	}

	function activePonSummary() {
		if ( ! overviewData || ! Array.isArray( overviewData.pons ) ) return null;
		return overviewData.pons.find( function ( item ) { return String( item.key || item.pon ) === String( selectedPon ); } ) || null;
	}

	function renderPonTabs( pons ) {
		const target = document.querySelector( '[data-afc-olt-pons]' );
		if ( ! target ) return;
		if ( ! pons || ! pons.length ) {
			target.innerHTML = '';
			selectedPon = '';
			return;
		}

		if ( ! selectedPon || ! pons.some( function ( item ) { return String( item.key || item.pon ) === String( selectedPon ); } ) ) {
			selectedPon = String( pons[0].key || pons[0].pon );
		}

		target.innerHTML = pons.map( function ( item ) {
			const itemKey = String( item.key || item.pon );
			const active = itemKey === String( selectedPon );
			const weak = Number( item.warning || 0 ) + Number( item.critical || 0 );
			const oltLabel = item.olt_name ? item.olt_name + ' · ' : '';
			return '<button type="button" role="tab" class="afc-olt-pon-tab' + ( active ? ' is-active' : '' ) + '" data-afc-olt-pon-tab="' + escapeAttr( itemKey ) + '" aria-selected="' + ( active ? 'true' : 'false' ) + '">' +
				'<span>' + escapeHtml( oltLabel ) + 'PON ' + escapeHtml( item.pon ) + '</span>' +
				'<small>' + escapeHtml( item.total ) + ' ONU · ' + escapeHtml( item.valid ) + ' valid · ' + escapeHtml( item.invalid ) + ' invalid' + ( weak ? ' · ' + escapeHtml( weak ) + ' weak' : '' ) + '</small>' +
			'</button>';
		} ).join( '' );
	}

	function renderTabSummary() {
		const target = document.querySelector( '[data-afc-olt-tab-summary]' );
		if ( ! target ) return;
		const item = activePonSummary();
		if ( ! item ) {
			target.innerHTML = '';
			return;
		}
		const weakest = null === item.weakest || undefined === item.weakest ? 'No valid RX yet' : 'Weakest valid RX ' + Number( item.weakest ).toFixed( 2 ) + ' dBm';
		const title = ( item.olt_name ? item.olt_name + ' · ' : '' ) + 'PON ' + item.pon;
		target.innerHTML = '<h4>' + escapeHtml( title ) + '</h4><p>' + escapeHtml( item.total ) + ' ONU · ' + escapeHtml( item.mapped ) + ' mapped · ' + escapeHtml( item.valid ) + ' valid signal · ' + escapeHtml( item.invalid ) + ' invalid RX · ' + escapeHtml( item.offline ) + ' offline · ' + escapeHtml( weakest ) + '</p>';
	}

	function filteredOnus() {
		if ( ! overviewData || ! Array.isArray( overviewData.onus ) ) return [];
		const searchNode = document.querySelector( '[data-afc-olt-filter="search"]' );
		const statusNode = document.querySelector( '[data-afc-olt-filter="status"]' );
		const search = String( searchNode ? searchNode.value : '' ).trim().toLowerCase();
		const status = statusNode ? statusNode.value : '';

		return overviewData.onus.filter( function ( item ) {
			const itemKey = String( item.olt_id === 'primary' || ! item.olt_id ? item.pon + ':0' : item.olt_id + ':' + item.pon + ':0' );
			if ( selectedPon && itemKey !== String( selectedPon ) ) return false;
			if ( status === 'unmapped' && item.mapped ) return false;
			if ( status && status !== 'unmapped' && item.status !== status ) return false;
			if ( ! search ) return true;
			const customer = item.customer || {};
			const macs = Array.isArray( item.mac_addresses ) ? item.mac_addresses.join( ' ' ) : '';
			return [ item.olt_name, item.technology, item.pon, item.onu, item.description, macs, customer.name, customer.username, customer.onu_mac ].join( ' ' ).toLowerCase().includes( search );
		} );
	}

	function macHtml( item ) {
		const macs = Array.isArray( item.mac_addresses ) ? item.mac_addresses.filter( Boolean ) : [];
		if ( ! macs.length ) return '<span class="afc-olt-unmapped">—</span>';
		return '<div class="afc-olt-macs">' + macs.map( function ( mac ) { return '<code>' + escapeHtml( mac ) + '</code>'; } ).join( '' ) + '</div>';
	}

	function descriptionHtml( item ) {
		const customer = item.customer || {};
		const description = item.description || customer.name || '';
		if ( ! description ) return '<span class="afc-olt-unmapped">—</span>';
		const detail = customer.username ? '<small>' + escapeHtml( customer.username ) + '</small>' : '';
		return '<div class="afc-olt-description"><span>' + escapeHtml( description ) + '</span>' + detail + '</div>';
	}

	function signalHtml( item ) {
		if ( item.status === 'offline' ) {
			return '<div class="afc-olt-signal"><span class="afc-olt-status-pill is-offline">Offline</span><small>No current RX row</small></div>';
		}
		if ( ! item.signal_valid || null === item.rx_power || undefined === item.rx_power || Number( item.rx_power ) >= -1 ) {
			const raw = item.raw_rx_text || ( null !== item.raw_rx && undefined !== item.raw_rx ? String( item.raw_rx ) : '' );
			const title = raw ? 'OLT returned ' + raw + '. This is not a credible subscriber receive-power value.' : 'No credible subscriber receive-power value was returned.';
			return '<div class="afc-olt-signal" title="' + escapeAttr( title ) + '"><span class="afc-olt-status-pill is-invalid">Signal unavailable</span><small>Invalid RX value</small></div>';
		}
		return '<div class="afc-olt-signal"><span class="afc-olt-rx">' + escapeHtml( Number( item.rx_power ).toFixed( 2 ) ) + ' dBm</span><span class="afc-olt-status-pill is-' + statusClass( item.status ) + '">' + escapeHtml( item.status_label || item.status ) + '</span></div>';
	}

	function renderOnus() {
		const target = document.querySelector( '[data-afc-olt-onus]' );
		if ( ! target ) return;
		const onus = filteredOnus();
		if ( ! onus.length ) {
			target.innerHTML = '<tr><td colspan="4" class="afc-olt-overview-empty">No ONU matches this PON and the current filters.</td></tr>';
			return;
		}
		target.innerHTML = onus.map( function ( item ) {
			return '<tr>' +
				'<td class="afc-olt-onu-number"><span>' + escapeHtml( item.onu ) + '</span><small>' + escapeHtml( item.olt_name ? item.olt_name + ' · ' : '' ) + 'PON ' + escapeHtml( item.pon ) + '</small></td>' +
				'<td>' + descriptionHtml( item ) + '</td>' +
				'<td>' + macHtml( item ) + '</td>' +
				'<td>' + signalHtml( item ) + '</td>' +
			'</tr>';
		} ).join( '' );
	}

	function renderOverview( data ) {
		overviewData = data || {};
		const summary = overviewData.summary || {};
		const diagnostics = overviewData.diagnostics || {};
		const counts = overviewData.counts || {};
		renderCounts( counts );
		renderPonTabs( overviewData.pons || [] );
		renderTabSummary();
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
		if ( summary.partial ) {
			setOverviewStatus( ( overviewData.olt && overviewData.olt.name ? overviewData.olt.name : 'OLT monitoring' ) + ' is partially available' + when + '. ' + ( summary.message || 'One OLT did not return data.' ), 'stale' );
			return;
		}

		const invalidCount = Number( diagnostics.invalid_rx || 0 );
		const readingCount = Number( counts.readings || 0 );
		if ( invalidCount > 0 ) {
			const majority = readingCount > 0 && invalidCount >= Math.ceil( readingCount / 2 );
			const oid = diagnostics.rx_oid ? ' OID ' + diagnostics.rx_oid + '.' : '';
			setOverviewStatus(
				( overviewData.olt && overviewData.olt.name ? overviewData.olt.name : 'OLT' ) + ' responded' + when + '. ' +
				invalidCount + ' of ' + readingCount + ' ONU row' + ( readingCount === 1 ? '' : 's' ) + ' did not contain a credible negative dBm receive level.' + oid +
				( majority ? ' Most rows are invalid, so the configured RX-power OID or its value scale still needs verification.' : '' ),
				'stale'
			);
			return;
		}

		setOverviewStatus( ( overviewData.olt && overviewData.olt.name ? overviewData.olt.name : 'OLT' ) + ' is available' + when + '. All returned RX values are credible negative dBm readings.', 'good' );
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
			const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'The OLT overview request failed.';
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

		$( document ).on( 'click', '[data-afc-olt-overview-refresh]', function () { loadOverview( true ); } );
		$( document ).on( 'input change', '[data-afc-olt-filter]', renderOnus );
		$( document ).on( 'click', '[data-afc-olt-pon-tab]', function () {
			selectedPon = String( $( this ).attr( 'data-afc-olt-pon-tab' ) || '' );
			renderPonTabs( overviewData && overviewData.pons ? overviewData.pons : [] );
			renderTabSummary();
			renderOnus();
		} );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( afcOLT.testing );
			$result.html( '<div class="alert alert-info">Reading the OLT optical table&hellip;</div>' );
			$.post( afcOLT.ajaxUrl, { action: 'afc_test_olt', nonce: afcOLT.nonce } )
				.done( function ( response ) {
					const successful = response && response.success;
					const message = response && response.data && response.data.message ? response.data.message : 'The OLT returned an unexpected response.';
					$result.html( $( '<div>', { class: 'alert ' + ( successful ? 'alert-success' : 'alert-danger' ), text: message } ) );
					if ( successful ) {
						overviewLoaded = false;
						window.setTimeout( function () { loadOverview( false ); }, 60 );
					}
				} )
				.fail( function ( xhr ) {
					const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'The OLT connection test request failed.';
					$result.html( $( '<div>', { class: 'alert alert-danger', text: message } ) );
				} )
				.always( function () { $button.prop( 'disabled', false ).text( afcOLT.button ); } );
		} );
	} );
}( jQuery ) );
