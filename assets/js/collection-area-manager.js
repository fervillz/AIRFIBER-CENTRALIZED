( function ( $ ) {
	'use strict';

	let allUsers = [];
	let managerUsers = [];
	let selectedNames = new Set();
	let zoneValues = new Map();
	let activeZoneFilter = '';
	let busy = false;

	const knownLocalityPatterns = [
		/\b(?:lingi[\s.\-]*on|lingoin|ligion|ligiom)(?=\b|mfb?\b)/i,
		/\b(?:sto|st)[\s.\-]*ni[\s.\-]*n?o\b/i,
		/\bdali?r[ei]g\b/i,
		/\bdickl[uo]m\b/i,
		/\bmangima\b/i,
		/\b(?:lower[\s.\-]*sosohon|lowersosohon|lowsersosohon)\b/i,
		/\bca+gsaman\b/i,
		/\bgabok\b/i,
		/\bbulagok\b/i,
		/\bvalle\b/i,
		/\bapad\b/i,
		/\b(?:bless|bliss)\b/i
	];

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
	}

	function escapeAttr( value ) {
		return String( value || '' )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#039;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	function plainText( value ) {
		const text = String( value || '' ).replace( /\uFFFD/g, 'n' );
		return 'function' === typeof text.normalize
			? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: text;
	}

	function detectedZone( address ) {
		const text = plainText( address );
		if ( /\b(?:bless|bliss)\b/i.test( text ) ) {
			return 6;
		}
		if ( /\bgabok\b/i.test( text ) ) {
			return 4;
		}
		const match = text.match( /\b(?:zone|zon|zine|zne|z|purok)\s*[-.:]?\s*0*(\d+)\s*[a-z]?\b/i );
		return match ? Number( match[1] ) : '';
	}

	function needsLocationAssignment( user ) {
		const address = plainText( user.address_text || '' ).trim();
		if ( ! address || /^(?:n\s*\/?\s*a|none|unknown|-)$/i.test( address ) || /^\d+$/.test( address ) ) {
			return true;
		}
		return ! knownLocalityPatterns.some( function ( pattern ) { return pattern.test( address ); } );
	}

	function zoneOptions( selected ) {
		let html = '<option value="">Needs zone</option><option value="0"' + ( '0' === String( selected ) ? ' selected' : '' ) + '>No zone</option>';
		for ( let zone = 1; zone <= 20; zone++ ) {
			html += '<option value="' + zone + '"' + ( String( zone ) === String( selected ) ? ' selected' : '' ) + '>Zone ' + zone + '</option>';
		}
		return html;
	}

	function ensureDialog() {
		if ( document.getElementById( 'afc-area-manager-dialog' ) ) {
			return;
		}

		document.body.insertAdjacentHTML( 'beforeend',
			'<dialog id="afc-area-manager-dialog" class="afc-dialog afc-area-manager-dialog">' +
			'<form method="dialog" id="afc-area-manager-form">' +
			'<div class="afc-dialog-header"><div><div class="text-secondary small">MikroTik PPP comments</div>' +
			'<h3 class="mb-0">Assign collection locations</h3></div>' +
			'<button class="btn-close" value="cancel" aria-label="Close"></button></div>' +
			'<div class="afc-dialog-body afc-area-manager-body">' +
			'<div class="alert alert-info mb-3"><strong>Safe bulk edit:</strong> only the <code>Address:</code> value in each PPP secret comment will change. Payment, plan, customer, Wi-Fi and other fields are preserved.</div>' +
			'<div class="afc-area-manager-layout">' +
			'<section class="afc-area-manager-accounts">' +
			'<div class="afc-area-manager-tools">' +
			'<input class="form-control" id="afc-area-manager-search" type="search" placeholder="Search account, customer or current address...">' +
			'<div class="btn-list"><button class="btn btn-sm btn-outline-primary" id="afc-area-select-visible" type="button">Select visible</button>' +
			'<button class="btn btn-sm btn-outline-secondary" id="afc-area-clear-selection" type="button">Clear</button></div></div>' +
			'<div class="afc-area-zone-filters" id="afc-area-zone-filters"></div>' +
			'<div class="afc-area-manager-table-wrap"><table class="table table-sm table-vcenter mb-0">' +
			'<thead><tr><th></th><th>PPP account</th><th>Current location</th><th>New zone</th></tr></thead>' +
			'<tbody id="afc-area-manager-rows"></tbody></table></div></section>' +
			'<aside class="afc-area-manager-assignment">' +
			'<label class="form-label" for="afc-area-barangay">Assign barangay</label>' +
			'<select class="form-select" id="afc-area-barangay"><option value="Lingion">Lingion</option><option value="Sto. Nino">Sto. Nino</option><option value="Dalirig">Dalirig</option><option value="custom">Other barangay...</option></select>' +
			'<input class="form-control mt-2 d-none" id="afc-area-custom-barangay" type="text" maxlength="80" placeholder="Enter barangay name">' +
			'<div class="mt-3"><label class="form-label" for="afc-area-bulk-zone">Set one zone for selected</label>' +
			'<div class="input-group"><select class="form-select" id="afc-area-bulk-zone">' + zoneOptions( '' ) + '</select>' +
			'<button class="btn btn-outline-primary" id="afc-area-apply-zone" type="button">Apply</button></div>' +
			'<div class="form-hint">Existing detected zones are already filled in. Use this only when several selected accounts belong to the same zone.</div></div>' +
			'<div class="afc-area-manager-summary mt-3" id="afc-area-manager-summary"></div>' +
			'<div class="afc-area-manager-preview mt-3"><div class="fw-bold mb-2">Preview</div><div id="afc-area-manager-preview"></div></div>' +
			'<div class="afc-area-manager-progress d-none mt-3" id="afc-area-manager-progress"><div class="progress"><div class="progress-bar" style="width:0%"></div></div><div class="small text-secondary mt-1"></div></div>' +
			'</aside></div></div>' +
			'<div class="afc-dialog-footer"><button class="btn btn-link" value="cancel">Cancel</button>' +
			'<button class="btn btn-primary" id="afc-confirm-area-update" type="button">Update MikroTik</button></div>' +
			'</form></dialog>'
		);

		bindDialogEvents();
	}

	function currentBarangay() {
		const selected = $( '#afc-area-barangay' ).val();
		return 'custom' === selected ? String( $( '#afc-area-custom-barangay' ).val() || '' ).trim() : String( selected || '' ).trim();
	}

	function visibleManagerUsers() {
		const query = String( $( '#afc-area-manager-search' ).val() || '' ).toLowerCase();
		return managerUsers.filter( function ( user ) {
			const zone = detectedZone( user.address_text );
			const matchesZone = '' === activeZoneFilter || String( zone || 'none' ) === activeZoneFilter;
			const matchesSearch = ! query || [ user.name, user.customer_name, user.address_text, user.comment ].join( ' ' ).toLowerCase().includes( query );
			return matchesZone && matchesSearch;
		} );
	}

	function renderZoneFilters() {
		const counts = {};
		managerUsers.forEach( function ( user ) {
			const zone = detectedZone( user.address_text );
			const key = String( zone || 'none' );
			counts[ key ] = ( counts[ key ] || 0 ) + 1;
		} );

		const keys = Object.keys( counts ).sort( function ( first, second ) {
			if ( 'none' === first ) { return 1; }
			if ( 'none' === second ) { return -1; }
			return Number( first ) - Number( second );
		} );
		let html = '<button class="afc-area-zone-filter' + ( '' === activeZoneFilter ? ' is-active' : '' ) + '" type="button" data-zone="">All <span>' + managerUsers.length + '</span></button>';
		keys.forEach( function ( key ) {
			const label = 'none' === key ? 'No zone' : 'Zone ' + key;
			html += '<button class="afc-area-zone-filter' + ( key === activeZoneFilter ? ' is-active' : '' ) + '" type="button" data-zone="' + key + '">' + label + ' <span>' + counts[ key ] + '</span></button>';
		} );
		$( '#afc-area-zone-filters' ).html( html );
	}

	function renderRows() {
		const visible = visibleManagerUsers();
		$( '#afc-area-manager-rows' ).html( visible.length ? visible.map( function ( user ) {
			const zone = zoneValues.has( user.name ) ? zoneValues.get( user.name ) : detectedZone( user.address_text );
			return '<tr data-name="' + escapeAttr( user.name ) + '"><td><input class="form-check-input afc-area-user-check" type="checkbox"' +
				( selectedNames.has( user.name ) ? ' checked' : '' ) + '></td>' +
				'<td><div class="fw-bold">' + escapeHtml( user.name ) + '</div><div class="small text-secondary">' + escapeHtml( user.customer_name || 'Customer name missing' ) + '</div></td>' +
				'<td><div class="afc-area-current">' + escapeHtml( user.address_text || 'No Address value' ) + '</div></td>' +
				'<td><select class="form-select form-select-sm afc-area-row-zone">' + zoneOptions( zone ) + '</select></td></tr>';
		} ).join( '' ) : '<tr><td colspan="4" class="text-center text-secondary py-4">No matching unassigned PPP accounts.</td></tr>' );
		updateAssignmentSummary();
	}

	function updateAssignmentSummary() {
		const barangay = currentBarangay();
		const selected = managerUsers.filter( function ( user ) { return selectedNames.has( user.name ); } );
		const unresolved = selected.filter( function ( user ) { return '' === String( zoneValues.get( user.name ) ?? detectedZone( user.address_text ) ); } );
		const ready = selected.length - unresolved.length;

		$( '#afc-area-manager-summary' ).html(
			'<div><strong>' + selected.length + '</strong> selected</div>' +
			'<div><strong class="text-success">' + ready + '</strong> ready</div>' +
			'<div><strong class="' + ( unresolved.length ? 'text-danger' : 'text-secondary' ) + '">' + unresolved.length + '</strong> need a zone</div>'
		);

		const preview = selected.slice( 0, 5 ).map( function ( user ) {
			const zone = String( zoneValues.get( user.name ) ?? detectedZone( user.address_text ) );
			const address = '' === zone || ! barangay ? 'Incomplete assignment' : ( '0' === zone ? barangay : 'Zone ' + zone + ', ' + barangay );
			return '<div class="afc-area-preview-row"><span>' + escapeHtml( user.name ) + '</span><strong>' + escapeHtml( address ) + '</strong></div>';
		} ).join( '' );
		$( '#afc-area-manager-preview' ).html( preview || '<div class="text-secondary">Select at least one account.</div>' );
		if ( selected.length > 5 ) {
			$( '#afc-area-manager-preview' ).append( '<div class="small text-secondary mt-1">+' + ( selected.length - 5 ) + ' more account(s)</div>' );
		}

		$( '#afc-confirm-area-update' )
			.prop( 'disabled', busy || ! selected.length || unresolved.length > 0 || ! barangay )
			.text( busy ? 'Updating MikroTik...' : 'Update ' + ready + ' on MikroTik' );
	}

	function openManager() {
		ensureDialog();
		managerUsers = allUsers.filter( needsLocationAssignment ).sort( function ( first, second ) {
			return ( Number( detectedZone( first.address_text ) ) || 999 ) - ( Number( detectedZone( second.address_text ) ) || 999 ) ||
				String( first.name ).localeCompare( String( second.name ) );
		} );
		selectedNames = new Set( managerUsers.map( function ( user ) { return user.name; } ) );
		zoneValues = new Map();
		managerUsers.forEach( function ( user ) {
			const zone = detectedZone( user.address_text );
			zoneValues.set( user.name, '' === zone ? '' : String( zone ) );
		} );
		activeZoneFilter = '';
		$( '#afc-area-manager-search' ).val( '' );
		$( '#afc-area-barangay' ).val( 'Lingion' );
		$( '#afc-area-custom-barangay' ).val( '' ).addClass( 'd-none' );
		$( '#afc-area-bulk-zone' ).val( '' );
		$( '#afc-area-manager-progress' ).addClass( 'd-none' );
		renderZoneFilters();
		renderRows();
		document.getElementById( 'afc-area-manager-dialog' ).showModal();
	}

	function assignmentPayload() {
		const barangay = currentBarangay();
		return managerUsers.filter( function ( user ) { return selectedNames.has( user.name ); } ).map( function ( user ) {
			const zone = String( zoneValues.get( user.name ) ?? detectedZone( user.address_text ) );
			return {
				id: user.id,
				name: user.name,
				comment: user.comment || '',
				address: '0' === zone ? barangay : 'Zone ' + zone + ', ' + barangay
			};
		} );
	}

	function setProgress( complete, total, label ) {
		const percent = total ? Math.round( ( complete / total ) * 100 ) : 0;
		const progress = $( '#afc-area-manager-progress' ).removeClass( 'd-none' );
		progress.find( '.progress-bar' ).css( 'width', percent + '%' ).attr( 'aria-valuenow', percent );
		progress.find( '.small' ).text( label || ( complete + ' of ' + total + ' updated' ) );
	}

	function finishUpdate( total, updated, failures ) {
		busy = false;
		setProgress( total, total, updated + ' updated; ' + failures.length + ' failed.' );
		updateAssignmentSummary();
		const failedNames = failures.slice( 0, 5 ).map( function ( failure ) { return failure.name; } ).join( ', ' );
		const message = updated + ' PPP location(s) updated in MikroTik.' +
			( failures.length ? ' Failed: ' + failedNames + ( failures.length > 5 ? ' and ' + ( failures.length - 5 ) + ' more.' : '.' ) : '' );
		$( '#afc-ppp-notice' ).html( $( '<div>', { class: 'alert alert-' + ( failures.length ? 'warning' : 'success' ), text: message } ) );
		document.getElementById( 'afc-area-manager-dialog' ).close();
		$( '#afc-refresh-ppp' ).trigger( 'click' );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function runAssignmentBatches( assignments ) {
		const batchSize = 20;
		let offset = 0;
		let updated = 0;
		let failures = [];
		busy = true;
		updateAssignmentSummary();
		setProgress( 0, assignments.length, 'Starting MikroTik update...' );

		function next() {
			if ( offset >= assignments.length ) {
				finishUpdate( assignments.length, updated, failures );
				return;
			}
			const batch = assignments.slice( offset, offset + batchSize );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_ppp_bulk_assign_area',
				nonce: afcPPP.nonce,
				assignments: batch
			} ).done( function ( response ) {
				if ( response.success ) {
					updated += ( response.data.updated || [] ).length;
					failures = failures.concat( response.data.failures || [] );
				} else {
					failures = failures.concat( batch.map( function ( item ) { return { name: item.name, message: 'Request failed' }; } ) );
				}
			} ).fail( function ( xhr ) {
				const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'MikroTik update request failed';
				failures = failures.concat( batch.map( function ( item ) { return { name: item.name, message: message }; } ) );
			} ).always( function () {
				offset += batch.length;
				setProgress( offset, assignments.length, 'Processed ' + offset + ' of ' + assignments.length + ' account(s)...' );
				next();
			} );
		}

		next();
	}

	function enhanceUnassignedPanels() {
		const count = allUsers.filter( needsLocationAssignment ).length;
		document.querySelectorAll( '#afc-area-summary .afc-barangay-group' ).forEach( function ( group ) {
			const name = group.querySelector( '.afc-barangay-name' );
			const areas = group.querySelector( '.afc-barangay-areas' );
			if ( ! name || ! areas || ! /^(?:Other \/ Unassigned|Unassigned Area)$/i.test( name.textContent.trim() ) ) {
				return;
			}
			let banner = areas.querySelector( '.afc-area-manager-banner' );
			if ( ! banner ) {
				banner = document.createElement( 'div' );
				banner.className = 'afc-area-manager-banner';
				areas.prepend( banner );
			}
			banner.innerHTML = '<div><strong>' + count + ' total PPP account(s) need a proper barangay.</strong>' +
				'<span>This includes accounts outside the current due-date filter.</span></div>' +
				'<button class="btn btn-sm btn-primary afc-open-area-manager" type="button">Assign locations</button>';
		} );
	}

	function bindDialogEvents() {
		$( '#afc-area-manager-search' ).on( 'input', renderRows );
		$( '#afc-area-zone-filters' ).on( 'click', '.afc-area-zone-filter', function () {
			activeZoneFilter = String( $( this ).data( 'zone' ) );
			renderZoneFilters();
			renderRows();
		} );
		$( '#afc-area-manager-rows' )
			.on( 'change', '.afc-area-user-check', function () {
				const name = $( this ).closest( 'tr' ).data( 'name' );
				if ( this.checked ) { selectedNames.add( name ); } else { selectedNames.delete( name ); }
				updateAssignmentSummary();
			} )
			.on( 'change', '.afc-area-row-zone', function () {
				const name = $( this ).closest( 'tr' ).data( 'name' );
				zoneValues.set( name, String( this.value ) );
				updateAssignmentSummary();
			} );
		$( '#afc-area-select-visible' ).on( 'click', function () {
			visibleManagerUsers().forEach( function ( user ) { selectedNames.add( user.name ); } );
			renderRows();
		} );
		$( '#afc-area-clear-selection' ).on( 'click', function () {
			selectedNames.clear();
			renderRows();
		} );
		$( '#afc-area-barangay' ).on( 'change', function () {
			$( '#afc-area-custom-barangay' ).toggleClass( 'd-none', 'custom' !== this.value );
			updateAssignmentSummary();
		} );
		$( '#afc-area-custom-barangay' ).on( 'input', updateAssignmentSummary );
		$( '#afc-area-apply-zone' ).on( 'click', function () {
			const zone = String( $( '#afc-area-bulk-zone' ).val() );
			if ( '' === zone ) { return; }
			managerUsers.forEach( function ( user ) {
				if ( selectedNames.has( user.name ) ) { zoneValues.set( user.name, zone ); }
			} );
			renderRows();
		} );
		$( '#afc-confirm-area-update' ).on( 'click', function () {
			const assignments = assignmentPayload();
			if ( ! assignments.length || busy ) { return; }
			if ( ! window.confirm( 'Update the Address value for ' + assignments.length + ' PPP account(s) in MikroTik? Other comment fields will be preserved.' ) ) {
				return;
			}
			runAssignmentBatches( assignments );
		} );
	}

	$( function () {
		ensureDialog();
		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( String( settings.data || '' ).includes( 'action=afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				allUsers = xhr.responseJSON.data.users || [];
				window.setTimeout( enhanceUnassignedPanels, 0 );
			}
		} );

		$( '#afc-area-summary' ).on( 'click', '.afc-open-area-manager', function ( event ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			openManager();
		} );
		document.addEventListener( 'afc:open-area-manager', openManager );

		const container = document.getElementById( 'afc-area-summary' );
		if ( container ) {
			new MutationObserver( function () { window.requestAnimationFrame( enhanceUnassignedPanels ); } )
				.observe( container, { childList: true, subtree: true } );
		}
	} );
}( jQuery ) );
