( function ( $ ) {
	'use strict';

	const cfg = window.afcOLTSmartRX || {};
	let testClickedAt = 0;

	if ( window.afcOLTManager && cfg.defaultRxOid ) {
		/* New GPON OLTs start with the known V1600G family RX table. Existing
		 * records keep their saved OID until Test Connection validates/replaces it. */
		window.afcOLTManager.defaultRxOid = cfg.defaultRxOid;
	}

	function q( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function modal() {
		return q( '[data-afc-olt-modal]' );
	}

	function logBody() {
		const root = modal();
		return root ? q( '[data-afc-olt-test-log-body]', root ) : null;
	}

	function toneColor( tone ) {
		if ( tone === 'error' ) return '#d76873';
		if ( tone === 'warning' ) return '#d2a24a';
		if ( tone === 'success' ) return '#5bc47a';
		return '';
	}

	function appendRow( message, tone, elapsedMs ) {
		const body = logBody();
		if ( ! body || ! message ) return;

		const row = document.createElement( 'div' );
		const seconds = Number.isFinite( Number( elapsedMs ) ) ? ( Number( elapsedMs ) / 1000 ).toFixed( 1 ) + 's' : 'auto';
		row.textContent = '[' + seconds + '] ' + message;
		row.setAttribute( 'data-afc-smart-rx-row', '' );
		const color = toneColor( tone );
		if ( color ) row.style.color = color;
		body.appendChild( row );
		body.scrollTop = body.scrollHeight;
	}

	function appendDiagnostics( data ) {
		if ( ! data || ! Array.isArray( data.diagnostics ) ) return;
		data.diagnostics.forEach( function ( item ) {
			if ( ! item || ! item.message ) return;
			appendRow( item.message, item.tone || 'neutral', item.elapsed_ms );
		} );
	}

	function updateDetectedOid( data ) {
		if ( ! data || ! data.detected_rx_oid ) return;
		const root = modal();
		const field = root ? q( '[name="rx_oid"]', root ) : null;
		if ( ! field ) return;

		const changedOnScreen = field.value !== data.detected_rx_oid;
		field.value = data.detected_rx_oid;
		field.setAttribute( 'data-afc-rx-auto-detected', '1' );
		field.title = 'Automatically detected and saved by Test Connection';

		if ( data.oid_changed || changedOnScreen ) {
			field.animate(
				[
					{ boxShadow: '0 0 0 0 rgba(47,158,86,0)', background: '#ffffff' },
					{ boxShadow: '0 0 0 4px rgba(47,158,86,.16)', background: '#f0faf4' },
					{ boxShadow: '0 0 0 0 rgba(47,158,86,0)', background: '#ffffff' }
				],
				{ duration: 900, easing: 'cubic-bezier(.22,1,.36,1)' }
			);
		}
	}

	function isTestRequest( settings ) {
		if ( ! settings ) return false;
		const data = typeof settings.data === 'string' ? settings.data : '';
		return data.indexOf( 'action=afc_olt_manager_test' ) !== -1;
	}

	function improveOidHelp() {
		const root = modal();
		if ( ! root ) return;
		const field = q( '[name="rx_oid"]', root );
		if ( ! field ) return;
		const wrapper = field.closest( '.afc-olt-field' );
		const hint = wrapper ? q( 'small', wrapper ) : null;
		const help = wrapper ? q( '.afc-olt-term-help', wrapper ) : null;
		if ( hint ) hint.textContent = 'Airfiber normally detects and saves this automatically during Test Connection. Change it only for unusual firmware.';
		if ( help ) help.setAttribute( 'data-help', 'This is the technical SNMP OID where ONU RX readings are stored. Airfiber now probes known VSOL GPON/EPON tables and can discover the RX column automatically, so manual editing is normally unnecessary.' );
	}

	function bindTestHint() {
		document.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-afc-olt-test]' );
			if ( ! button ) return;
			testClickedAt = Date.now();
			window.setTimeout( function () {
				appendRow( 'Smart RX detection is enabled. If the saved OID fails, Airfiber will probe known VSOL GPON/EPON optical tables and then try targeted RX-column discovery.', 'neutral', Date.now() - testClickedAt );
			}, 180 );
		}, false );
	}

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		if ( ! isTestRequest( settings ) ) return;
		const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
		const data = response && response.data ? response.data : null;
		if ( ! data ) return;

		appendDiagnostics( data );
		updateDetectedOid( data );

		if ( response.success && data.detected_rx_oid ) {
			appendRow( 'RX discovery complete. Using ' + data.detected_rx_oid + '.', 'success', Date.now() - testClickedAt );
		}
	} );

	function boot() {
		improveOidHelp();
		bindTestHint();
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
