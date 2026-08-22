( function ( $ ) {
	'use strict';

	const cfg = window.afcGPONProvisioning || {};
	let modal = null;
	let form = null;
	let activeUser = null;
	let activePlan = null;

	function q( selector, scope ) {
		return ( scope || document ).querySelector( selector );
	}

	function qa( selector, scope ) {
		return Array.from( ( scope || document ).querySelectorAll( selector ) );
	}

	function text( value ) {
		return null == value || '' === value ? '—' : String( value );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( text( value ) ).html();
	}

	function parseUser( element ) {
		const row = element.closest( 'tr[data-user]' );
		if ( ! row ) return null;
		try {
			return JSON.parse( decodeURIComponent( row.getAttribute( 'data-user' ) || '' ) );
		} catch ( error ) {
			return null;
		}
	}

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, Object.assign( { action: action, nonce: cfg.nonce }, data || {} ) );
	}

	function errorMessage( response, fallback ) {
		if ( response && response.data && response.data.message ) return response.data.message;
		return fallback;
	}

	function setStatus( message, tone ) {
		const status = q( '[data-afc-gpon-status]', modal );
		if ( ! status ) return;
		status.className = 'afc-gpon-status is-' + ( tone || 'neutral' );
		q( 'p', status ).textContent = message || '';
	}

	function setTab( name ) {
		qa( '[data-afc-gpon-tab]', modal ).forEach( function ( button ) {
			button.classList.toggle( 'is-active', button.getAttribute( 'data-afc-gpon-tab' ) === name );
		} );
		qa( '[data-afc-gpon-panel]', modal ).forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.getAttribute( 'data-afc-gpon-panel' ) === name );
		} );
	}

	function openModal() {
		modal.hidden = false;
		document.body.classList.add( 'afc-gpon-modal-open' );
		window.requestAnimationFrame( function () { modal.classList.add( 'is-open' ); } );
	}

	function closeModal() {
		modal.classList.remove( 'is-open' );
		document.body.classList.remove( 'afc-gpon-modal-open' );
		window.setTimeout( function () { modal.hidden = true; }, 180 );
	}

	function normalizeSerial( value ) {
		const compact = String( value || '' ).replace( /[^a-z0-9]/gi, '' );
		return compact.slice( 0, 4 ).toUpperCase() + compact.slice( 4 ).toLowerCase();
	}

	function fillNodes() {
		const select = q( '[name="olt_id"]', form );
		select.innerHTML = '';
		( cfg.nodes || [] ).forEach( function ( node ) {
			const option = document.createElement( 'option' );
			option.value = node.id;
			option.textContent = node.name + ( node.host ? ' · ' + node.host : '' );
			select.appendChild( option );
		} );
	}

	function flattenValues( value, prefix, rows ) {
		if ( Array.isArray( value ) ) {
			value.forEach( function ( child, index ) { flattenValues( child, prefix ? prefix + ' ' + ( index + 1 ) : String( index + 1 ), rows ); } );
			return;
		}
		if ( value && typeof value === 'object' ) {
			Object.keys( value ).forEach( function ( key ) {
				flattenValues( value[ key ], prefix ? prefix + ' · ' + key : key, rows );
			} );
			return;
		}
		if ( null != value && '' !== String( value ) ) rows.push( [ prefix || 'Value', value ] );
	}

	function keyValues( value, emptyMessage ) {
		const rows = [];
		flattenValues( value, '', rows );
		if ( ! rows.length ) return '<div class="afc-gpon-empty"><p>' + escapeHtml( emptyMessage || 'No data returned.' ) + '</p></div>';
		return rows.slice( 0, 80 ).map( function ( row ) {
			return '<div><span>' + escapeHtml( String( row[ 0 ] ).replace( /[_-]/g, ' ' ) ) + '</span><strong>' + escapeHtml( row[ 1 ] ) + '</strong></div>';
		} ).join( '' );
	}

	function dataTable( rows ) {
		if ( ! Array.isArray( rows ) || ! rows.length ) {
			return '<div class="afc-gpon-empty"><p>No configuration returned.</p></div>';
		}
		const keys = [];
		rows.forEach( function ( row ) {
			Object.keys( row || {} ).forEach( function ( key ) {
				if ( ! keys.includes( key ) && keys.length < 7 ) keys.push( key );
			} );
		} );
		return '<div class="afc-gpon-table-wrap"><table><thead><tr>' + keys.map( function ( key ) {
			return '<th>' + escapeHtml( key.replace( /[_-]/g, ' ' ) ) + '</th>';
		} ).join( '' ) + '</tr></thead><tbody>' + rows.map( function ( row ) {
			return '<tr>' + keys.map( function ( key ) { return '<td>' + escapeHtml( row[ key ] ) + '</td>'; } ).join( '' ) + '</tr>';
		} ).join( '' ) + '</tbody></table></div>';
	}

	function serialFromSettings( settings ) {
		const detail = settings && settings.detail ? settings.detail : {};
		for ( const key of Object.keys( detail ) ) {
			if ( /^(sn|serial|onusn|serialnumber)$/i.test( key.replace( /[^a-z]/gi, '' ) ) ) return detail[ key ];
		}
		return '';
	}

	function renderSettings( payload ) {
		const target = payload.target || {};
		const settings = payload.settings || {};
		q( '[data-afc-gpon-summary-olt]', modal ).textContent = text( target.olt_name );
		q( '[data-afc-gpon-summary-location]', modal ).textContent = target.pon && target.onu ? 'PON ' + target.pon + ' / ONU ' + target.onu : '—';
		const serial = serialFromSettings( settings );
		q( '[data-afc-gpon-summary-serial]', modal ).textContent = text( serial );
		if ( serial ) q( '[name="serial"]', form ).value = normalizeSerial( serial );
		q( '[data-afc-gpon-overview]', modal ).innerHTML = keyValues( Object.assign( {}, settings.detail || {}, settings.overview || {} ), 'No overview data returned by this ONU.' );
		q( '[data-afc-gpon-tr069]', modal ).innerHTML = keyValues( settings.tr069 || {}, 'This ONU did not return TR-069 data.' );
		[ 'tconts', 'gemports', 'services', 'service_ports' ].forEach( function ( name ) {
			q( '[data-afc-gpon-table="' + name + '"]', modal ).innerHTML = dataTable( settings[ name ] || [] );
		} );
		const readErrors = settings.read_errors && typeof settings.read_errors === 'object'
			? Object.keys( settings.read_errors )
			: [];
		if ( readErrors.length ) {
			setStatus( 'Connected, but ' + readErrors.length + ' ONU section(s) could not be read. Provisioning stays blocked until all line tables are available.', 'warning' );
		} else {
			setStatus( 'Connected to ' + text( target.olt_name ) + ' and read PON ' + target.pon + ' / ONU ' + target.onu + '.', 'success' );
		}
	}

	function loadSettings() {
		if ( ! activeUser || ! activeUser.customer_id ) return;
		const button = q( '[data-afc-gpon-refresh]', modal );
		button.disabled = true;
		setStatus( 'Opening a read-only OLT management session…', 'loading' );
		post( 'afc_gpon_onu_settings', {
			customer_id: activeUser.customer_id,
			olt_id: q( '[name="olt_id"]', form ).value,
			pon: q( '[name="pon"]', form ).value,
			onu: q( '[name="onu"]', form ).value
		} ).done( function ( response ) {
			if ( response && response.success ) renderSettings( response.data );
			else setStatus( errorMessage( response, 'The ONU settings could not be read.' ), 'error' );
		} ).fail( function ( xhr ) {
			setStatus( errorMessage( xhr.responseJSON, 'The ONU settings request failed.' ), 'error' );
		} ).always( function () { button.disabled = false; } );
	}

	function resetPlan() {
		activePlan = null;
		q( '[data-afc-gpon-plan]', modal ).innerHTML = '<div class="afc-gpon-empty"><strong>No changes are sent during preview.</strong><p>Airfiber will first inspect the ONU and show which items will be created or reused.</p></div>';
	}

	function renderPlan( plan ) {
		activePlan = plan;
		q( '[name="onu"]', form ).value = plan.onu;
		q( '[name="serial"]', form ).value = plan.serial;
		const steps = ( plan.steps || [] ).map( function ( step ) {
			return '<li class="is-' + escapeHtml( step.action ) + '"><span>' + ( step.action === 'create' ? '+' : '✓' ) + '</span><div><strong>' + escapeHtml( step.label ) + '</strong><p>' + escapeHtml( step.summary ) + '</p></div><em>' + escapeHtml( step.action === 'create' ? 'Will create' : 'Reuse' ) + '</em></li>';
		} ).join( '' );
		q( '[data-afc-gpon-plan]', modal ).innerHTML =
			'<div class="afc-gpon-plan-head"><div><span>Provisioning preview</span><strong>' + escapeHtml( plan.olt_name ) + ' · PON ' + escapeHtml( plan.pon ) + ' / ONU ' + escapeHtml( plan.onu ) + '</strong></div><b>' + escapeHtml( plan.serial ) + '</b></div>' +
			'<ol>' + steps + '</ol>' +
			'<div class="afc-gpon-confirm"><label>Type <code>' + escapeHtml( plan.confirmation ) + '</code> to apply</label><input type="text" data-afc-gpon-confirm autocomplete="off"><button type="button" class="afc-gpon-danger" data-afc-gpon-execute disabled>Provision ONU</button><small>Execution stops on the first rejected step. Existing matching items are reused.</small></div>';
		q( '[data-afc-gpon-confirm]', modal ).addEventListener( 'input', function () {
			q( '[data-afc-gpon-execute]', modal ).disabled = this.value !== plan.confirmation;
		} );
		q( '[data-afc-gpon-execute]', modal ).addEventListener( 'click', executePlan );
	}

	function previewPlan( event ) {
		event.preventDefault();
		if ( ! form.reportValidity() ) return;
		const button = q( '[data-afc-gpon-preview]', form );
		button.disabled = true;
		setStatus( 'Inspecting the OLT and building an idempotent preview…', 'loading' );
		post( 'afc_gpon_provision_preview', {
			customer_id: q( '[name="customer_id"]', form ).value,
			olt_id: q( '[name="olt_id"]', form ).value,
			pon: q( '[name="pon"]', form ).value,
			onu: q( '[name="onu"]', form ).value,
			serial: q( '[name="serial"]', form ).value,
			vlans: q( '[name="vlans"]', form ).value
		} ).done( function ( response ) {
			if ( response && response.success ) {
				renderPlan( response.data.plan );
				setStatus( 'Preview ready. Review every step before provisioning.', 'warning' );
			} else setStatus( errorMessage( response, 'The provisioning preview failed.' ), 'error' );
		} ).fail( function ( xhr ) {
			setStatus( errorMessage( xhr.responseJSON, 'The provisioning preview request failed.' ), 'error' );
		} ).always( function () { button.disabled = false; } );
	}

	function executePlan() {
		if ( ! activePlan ) return;
		const button = q( '[data-afc-gpon-execute]', modal );
		const confirmation = q( '[data-afc-gpon-confirm]', modal ).value;
		button.disabled = true;
		button.textContent = 'Provisioning…';
		setStatus( 'Applying the confirmed GPON plan. Do not close this window…', 'loading' );
		post( 'afc_gpon_provision_execute', { token: activePlan.token, confirmation: confirmation } ).done( function ( response ) {
			if ( response && response.success ) {
				setStatus( response.data.message, 'success' );
				button.textContent = 'Provisioned';
				activeUser.optical = Object.assign( {}, activeUser.optical || {}, { mapped: true, olt_id: response.data.target.olt_id, pon: response.data.target.pon, onu: response.data.target.onu, technology: 'GPON' } );
				window.setTimeout( loadSettings, 1500 );
				$( '#afc-refresh-optical' ).trigger( 'click' );
			} else {
				setStatus( errorMessage( response, 'Provisioning stopped.' ), 'error' );
				button.disabled = false;
				button.textContent = 'Retry provisioning';
			}
		} ).fail( function ( xhr ) {
			setStatus( errorMessage( xhr.responseJSON, 'Provisioning failed.' ), 'error' );
			button.disabled = false;
			button.textContent = 'Retry provisioning';
		} );
	}

	function prepareUser( user ) {
		activeUser = user;
		activePlan = null;
		form.reset();
		fillNodes();
		resetPlan();
		const optical = user.optical || {};
		q( '[name="customer_id"]', form ).value = user.customer_id || 0;
		q( '[data-afc-gpon-customer]', modal ).textContent = user.customer_name || user.name || 'PPP customer';
		if ( optical.olt_id ) q( '[name="olt_id"]', form ).value = optical.olt_id;
		q( '[name="pon"]', form ).value = optical.pon || '';
		q( '[name="onu"]', form ).value = optical.onu || '';
		q( '[name="serial"]', form ).value = optical.serial || '';
		q( '[name="vlans"]', form ).value = '510,399';
		q( '[data-afc-gpon-vlan-template]', form ).value = '510,399';
		q( '[data-afc-gpon-summary-olt]', modal ).textContent = optical.olt_name || '—';
		q( '[data-afc-gpon-summary-location]', modal ).textContent = optical.pon && optical.onu ? 'PON ' + optical.pon + ' / ONU ' + optical.onu : 'Not mapped';
		q( '[data-afc-gpon-summary-rx]', modal ).textContent = null != optical.rx_power ? Number( optical.rx_power ).toFixed( 2 ) + ' dBm' : '—';
		q( '[data-afc-gpon-summary-serial]', modal ).textContent = '—';
		q( '[data-afc-gpon-overview]', modal ).innerHTML = '';
		q( '[data-afc-gpon-tr069]', modal ).innerHTML = '';
		[ 'tconts', 'gemports', 'services', 'service_ports' ].forEach( function ( name ) { q( '[data-afc-gpon-table="' + name + '"]', modal ).innerHTML = ''; } );
		openModal();
		if ( optical.mapped && String( optical.technology || '' ).toUpperCase() === 'GPON' ) {
			setTab( 'overview' );
			loadSettings();
		} else {
			setTab( 'provision' );
			setStatus( 'Enter the ONU serial, PON, and VLAN template. Preview is read-only.', 'neutral' );
		}
	}

	function boot() {
		modal = q( '[data-afc-gpon-modal]' );
		if ( ! modal || ! cfg.ajaxUrl || ! cfg.nonce ) return;
		form = q( '[data-afc-gpon-form]', modal );
		fillNodes();

		document.addEventListener( 'click', function ( event ) {
			const opener = event.target.closest( '.afc-onu-settings' );
			if ( opener ) {
				event.preventDefault();
				event.stopPropagation();
				const user = parseUser( opener );
				if ( user ) prepareUser( user );
				return;
			}
			const tab = event.target.closest( '[data-afc-gpon-tab]' );
			if ( tab && modal.contains( tab ) ) setTab( tab.getAttribute( 'data-afc-gpon-tab' ) );
		} );
		qa( '[data-afc-gpon-close]', modal ).forEach( function ( item ) { item.addEventListener( 'click', closeModal ); } );
		q( '[data-afc-gpon-refresh]', modal ).addEventListener( 'click', loadSettings );
		q( '[data-afc-gpon-vlan-template]', form ).addEventListener( 'change', function () {
			if ( this.value !== 'custom' ) q( '[name="vlans"]', form ).value = this.value;
			else q( '[name="vlans"]', form ).focus();
			resetPlan();
		} );
		q( '[name="serial"]', form ).addEventListener( 'blur', function () { this.value = normalizeSerial( this.value ); } );
		form.addEventListener( 'input', function () { if ( activePlan ) resetPlan(); } );
		form.addEventListener( 'submit', previewPlan );
		document.addEventListener( 'keydown', function ( event ) { if ( event.key === 'Escape' && ! modal.hidden ) closeModal(); } );
	}

	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', boot );
	else boot();
}( jQuery ) );
