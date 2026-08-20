( function ( $ ) {
	'use strict';

	let users = [];
	let paymentUser = null;
	let opticalUser = null;
	let opticalSummary = {};
	const selectedNames = new Set();
	const sortState = { key: 'name', direction: 'asc' };

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

	function notice( message, type ) {
		$( '#afc-ppp-notice' ).html(
			$( '<div>', { class: 'alert alert-' + type, text: message } )
		);
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	}

	function getError( response, fallback ) {
		return response && response.data && response.data.message ? response.data.message : fallback;
	}

	function isExpired( user ) {
		return String( user.actual_profile || '' ).toLowerCase() === 'expired';
	}

	function renderOpticalStatus() {
		const $status = $( '#afc-optical-status' );
		if ( ! opticalSummary.enabled ) {
			$status.html(
				'<div class="alert alert-info py-2 mb-0">Optical monitoring is not enabled. ' +
				'<a href="' + escapeAttr( afcPPP.oltSettingsUrl ) + '">Configure the OLT connection</a>.</div>'
			);
			return;
		}

		if ( ! opticalSummary.available ) {
			$status.html( '<div class="alert alert-warning py-2 mb-0">' +
				escapeHtml( opticalSummary.message || 'Optical readings are currently unavailable.' ) + '</div>' );
			return;
		}

		const stale = opticalSummary.stale
			? ' <span class="badge bg-yellow-lt">Stale snapshot</span>'
			: ' <span class="badge bg-success-lt">Current</span>';
		$status.html(
			'<div class="alert alert-secondary py-2 mb-0"><strong>' +
			escapeHtml( opticalSummary.count ) + ' ONU reading(s)</strong> · ' +
			escapeHtml( opticalSummary.collected_at || 'time unavailable' ) + stale + '</div>'
		);
	}

	function opticalHtml( user ) {
		const optical = user.optical || {};
		if ( ! user.imported ) {
			return '<span class="badge bg-secondary-lt">Import first</span>';
		}

		if ( ! optical.mapped ) {
			if ( optical.suggested ) {
				return '<span class="badge bg-azure-lt">Detected</span>' +
					'<div class="small text-secondary">' + escapeHtml( optical.suggested.olt_name ? optical.suggested.olt_name + ' · ' : '' ) + 'PON ' + escapeHtml( optical.suggested.pon ) +
					' · ONU ' + escapeHtml( optical.suggested.onu ) + '</div>' +
					'<button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Review mapping</button>';
			}
			return '<span class="badge bg-secondary-lt">Not mapped</span>' +
				'<div><button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Map ONU</button></div>';
		}

		const classes = {
			good: 'bg-success-lt',
			warning: 'bg-yellow-lt',
			critical: 'bg-danger-lt',
			offline: 'bg-secondary-lt',
			stale: 'bg-yellow-lt',
			unavailable: 'bg-secondary-lt'
		};
		const labels = {
			good: 'Good',
			warning: 'Warning',
			critical: 'Critical',
			offline: 'Offline',
			stale: 'Stale',
			unavailable: 'Unavailable'
		};
		const status = optical.status || 'unavailable';
		const reading = null !== optical.rx_power && '' !== optical.rx_power && undefined !== optical.rx_power
			? '<strong>' + escapeHtml( Number( optical.rx_power ).toFixed( 2 ) ) + ' dBm</strong>'
			: '<span class="text-secondary">No live reading</span>';
		const title = [
			( optical.olt_name ? optical.olt_name + ' · ' : '' ) + 'PON ' + optical.pon + ' / ONU ' + optical.onu,
			optical.collected_at ? 'Collected: ' + optical.collected_at : '',
			optical.message || ''
		].filter( Boolean ).join( '\n' );

		return '<div title="' + escapeAttr( title ) + '">' + reading +
			' <span class="badge ' + ( classes[ status ] || classes.unavailable ) + '">' +
			escapeHtml( labels[ status ] || labels.unavailable ) + '</span>' +
			'<div class="small text-secondary">' + escapeHtml( optical.olt_name ? optical.olt_name + ' · ' : '' ) + 'PON ' + escapeHtml( optical.pon ) + ' · ONU ' + escapeHtml( optical.onu ) + '</div>' +
			'<button class="btn btn-link btn-sm p-0 afc-map-onu" type="button">Edit mapping</button></div>';
	}

	function updateSummary() {
		$( '[data-summary="total"]' ).text( users.length );
		$( '[data-summary="online"]' ).text( users.filter( function ( user ) { return user.active; } ).length );
		$( '[data-summary="expired"]' ).text( users.filter( isExpired ).length );
		$( '[data-summary="imported"]' ).text( users.filter( function ( user ) { return user.imported; } ).length );
	}

	function titleCase( value ) {
		return value.toLowerCase().replace( /\b[a-z]/g, function ( letter ) {
			return letter.toUpperCase();
		} );
	}

	function plainText( value ) {
		const text = String( value || '' ).replace( /\uFFFD/g, 'n' );
		return 'function' === typeof text.normalize
			? text.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' )
			: text;
	}

	function areaDistance( first, second ) {
		const rows = [];
		let row;
		let column;

		for ( row = 0; row <= second.length; row++ ) {
			rows[ row ] = [ row ];
		}
		for ( column = 0; column <= first.length; column++ ) {
			rows[ 0 ][ column ] = column;
		}
		for ( row = 1; row <= second.length; row++ ) {
			for ( column = 1; column <= first.length; column++ ) {
				rows[ row ][ column ] = second.charAt( row - 1 ) === first.charAt( column - 1 )
					? rows[ row - 1 ][ column - 1 ]
					: Math.min(
						rows[ row - 1 ][ column - 1 ] + 1,
						rows[ row ][ column - 1 ] + 1,
						rows[ row - 1 ][ column ] + 1
					);
			}
		}
		return rows[ second.length ][ first.length ];
	}

	function closestKnownArea( value ) {
		const compact = value.toLowerCase().replace( /[^a-z0-9]/g, '' );
		const known = {
			lingion: 'Lingion',
			stonino: 'Sto. Nino',
			dalirig: 'Dalirig',
			dicklum: 'Dicklum',
			mangima: 'Mangima',
			lowersosohon: 'Lower Sosohon',
			caagsaman: 'Caagsaman',
			gabok: 'Gabok',
			bulagok: 'Bulagok',
			valle: 'Valle',
			apad: 'Apad'
		};
		let best = '';
		let bestDistance = 99;

		if ( known[ compact ] ) {
			return known[ compact ];
		}
		if ( compact.length < 5 ) {
			return '';
		}

		Object.keys( known ).forEach( function ( candidate ) {
			const distance = areaDistance( compact, candidate );
			if ( distance < bestDistance ) {
				best = known[ candidate ];
				bestDistance = distance;
			}
		} );

		return bestDistance <= ( compact.length >= 7 ? 2 : 1 ) ? best : '';
	}

	function normalizeArea( address ) {
		let normalized = plainText( address ).trim();
		let zone = '';
		const localities = [];
		const localityRules = [
			{ name: 'Lingion', pattern: /\b(?:lingi[\s.\-]*on|lingoin|ligion|ligiom)(?=\b|mfb?\b)/i },
			{ name: 'Sto. Nino', pattern: /\b(?:sto|st)[\s.\-]*ni[\s.\-]*n?o\b/i },
			{ name: 'Lower Sosohon', pattern: /\b(?:lower[\s.\-]*sosohon|lowersosohon|lowsersosohon)\b/i },
			{ name: 'Dalirig', pattern: /\bdali?r[ei]g\b/i },
			{ name: 'Dicklum', pattern: /\bdickl[uo]m\b/i },
			{ name: 'Mangima', pattern: /\bmangima\b/i },
			{ name: 'Caagsaman', pattern: /\bca+gsaman\b/i },
			{ name: 'Gabok', pattern: /\bgabok\b/i },
			{ name: 'Bulagok', pattern: /\bbulagok\b/i },
			{ name: 'Valle', pattern: /\bvalle\b/i },
			{ name: 'Apad', pattern: /\bapad\b/i }
		];

		if ( ! normalized || /^(?:n\s*\/?\s*a|none|unknown|-)\b/i.test( normalized ) || /^\d+$/.test( normalized ) ) {
			return 'Unassigned Area';
		}

		normalized = normalized
			.replace( /\b(?:barangay|brgy)\.?\b/gi, ' ' )
			.replace( /\b(?:manolo\s+fortich|manolo|mfb|mf|bukidnon|buk)\.?\b/gi, ' ' );

		const zoneMatch = normalized.match( /\b(?:zone|zon|zine|zne|z|purok)\s*[-.:]?\s*0*(\d+)\s*([a-z])?\b/i );
		if ( zoneMatch ) {
			zone = 'Zone ' + Number( zoneMatch[1] ) + ( zoneMatch[2] ? zoneMatch[2].toUpperCase() : '' );
			normalized = normalized.replace( zoneMatch[0], ' ' );
		}

		localityRules.forEach( function ( rule ) {
			const match = normalized.match( rule.pattern );
			if ( match ) {
				localities.push( { name: rule.name, index: match.index } );
				normalized = normalized.replace( new RegExp( rule.pattern.source, 'gi' ), ' ' );
			}
		} );
		localities.sort( function ( first, second ) {
			return first.index - second.index;
		} );

		const result = [];
		if ( zone ) {
			result.push( zone );
		}
		localities.forEach( function ( locality ) {
			if ( ! result.includes( locality.name ) ) {
				result.push( locality.name );
			}
		} );

		if ( ! localities.length ) {
			normalized
				.replace( /\([^)]*\)/g, ' ' )
				.replace( /\b(?:at|sa|purok|center)\b/gi, ' ' )
				.replace( /[^a-z0-9]+/gi, ' ' )
				.replace( /\s+/g, ' ' )
				.trim()
				.split( /\s*,\s*/ )
				.filter( Boolean )
				.forEach( function ( part ) {
					const known = closestKnownArea( part );
					const clean = known || titleCase( part );
					if ( clean && ! result.includes( clean ) ) {
						result.push( clean );
					}
				} );
		}

		return result.join( ', ' ) || 'Unassigned Area';
	}

	function dateNumber( value ) {
		const match = String( value || '' ).match( /^(\d{4})-(\d{1,2})-(\d{1,2})$/ );
		if ( ! match ) {
			return 0;
		}
		return ( Number( match[1] ) * 10000 ) + ( Number( match[2] ) * 100 ) + Number( match[3] );
	}

	function dueUsers() {
		const cutoff = dateNumber( $( '#afc-due-cutoff' ).val() );
		return users.filter( function ( user ) {
			const due = dateNumber( user.payment_date );
			return ! user.disabled && due && cutoff && due <= cutoff;
		} );
	}

	function collectionGroups() {
		const groups = {};
		dueUsers().forEach( function ( user ) {
			const area = normalizeArea( user.address_text );
			if ( ! groups[ area ] ) {
				groups[ area ] = [];
			}
			groups[ area ].push( user );
		} );
		return groups;
	}

	function renderCollectionAreas() {
		const groups = collectionGroups();
		const areas = Object.keys( groups ).sort( function ( a, b ) {
			return a.localeCompare( b, undefined, { numeric: true } );
		} );
		const total = areas.reduce( function ( count, area ) {
			return count + groups[ area ].length;
		}, 0 );

		$( '#afc-due-total' ).text( total );
		if ( ! areas.length ) {
			$( '#afc-area-summary' ).html( '<div class="text-secondary">No accounts are due by the selected date.</div>' );
			return;
		}

		$( '#afc-area-summary' ).html( areas.map( function ( area ) {
			return '<button class="afc-area-card" type="button" data-area="' + encodeURIComponent( area ) +
				'" title="Print due accounts for ' + escapeAttr( area ) + '">' +
				'<span class="afc-area-name">' + escapeHtml( area ) + '</span>' +
				'<span class="afc-area-count">' + groups[ area ].length + '</span></button>';
		} ).join( '' ) );
	}

	function printCollectionList( area ) {
		const cutoff = $( '#afc-due-cutoff' ).val();
		let printable = dueUsers().map( function ( user ) {
			return {
				area: normalizeArea( user.address_text ),
				name: user.customer_name || user.name,
				plan: user.profile || user.actual_profile || '',
				due: user.payment_date
			};
		} );

		if ( area ) {
			printable = printable.filter( function ( row ) { return row.area === area; } );
		}
		printable.sort( function ( first, second ) {
			return first.area.localeCompare( second.area, undefined, { numeric: true } ) ||
				first.name.localeCompare( second.name );
		} );

		if ( ! printable.length ) {
			notice( 'No due accounts are available for this print selection.', 'warning' );
			return;
		}

		const printWindow = window.open( '', '_blank', 'width=950,height=720' );
		if ( ! printWindow ) {
			notice( 'The browser blocked the print window. Allow pop-ups for this WordPress site.', 'warning' );
			return;
		}

		const rows = printable.map( function ( row, index ) {
			return '<tr><td>' + ( index + 1 ) + '</td><td>' + escapeHtml( row.area ) +
				'</td><td>' + escapeHtml( row.name ) + '</td><td>' + escapeHtml( row.plan ) +
				'</td><td>' + escapeHtml( row.due ) + '</td></tr>';
		} ).join( '' );
		const title = area ? area : 'All Collection Areas';

		printWindow.document.write(
			'<!doctype html><html><head><meta charset="utf-8"><title>Airfiber Collection List</title>' +
			'<style>@page{size:A4;margin:12mm}body{font:13px Arial,sans-serif;color:#111}h1{font-size:20px;margin:0 0 4px}' +
			'p{margin:0 0 14px;color:#444}table{width:100%;border-collapse:collapse}th,td{padding:7px 6px;border-bottom:1px solid #bbb;text-align:left}' +
			'th{background:#eee;font-size:11px;text-transform:uppercase}td:first-child{width:34px}.meta{display:flex;justify-content:space-between}</style>' +
			'</head><body><h1>Airfiber - Centralized</h1><div class="meta"><p><strong>' + escapeHtml( title ) +
			'</strong></p><p>Due until: <strong>' + escapeHtml( cutoff ) + '</strong></p></div>' +
			'<table><thead><tr><th>#</th><th>Zone / Area</th><th>Customer Name</th><th>Plan</th><th>Due Date</th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></body></html>'
		);
		printWindow.document.close();
		printWindow.focus();
		window.setTimeout( function () { printWindow.print(); }, 250 );
	}

	function sortUsers( visible ) {
		return visible.sort( function ( first, second ) {
			let a = first[ sortState.key ];
			let b = second[ sortState.key ];

			if ( 'active' === sortState.key ) {
				a = a ? 1 : 0;
				b = b ? 1 : 0;
			} else {
				a = String( a || '' ).toLowerCase();
				b = String( b || '' ).toLowerCase();
			}

			if ( a < b ) {
				return 'asc' === sortState.direction ? -1 : 1;
			}
			if ( a > b ) {
				return 'asc' === sortState.direction ? 1 : -1;
			}
			return String( first.name ).localeCompare( String( second.name ) );
		} );
	}

	function rowHtml( user ) {
		const expired = isExpired( user );
		const checkbox = user.imported
			? ''
			: '<input class="form-check-input afc-user-check" type="checkbox"' +
				( selectedNames.has( user.name ) ? ' checked' : '' ) + '>';
		const imported = user.imported
			? '<span class="badge bg-blue-lt">In WordPress</span>'
			: '<span class="badge bg-yellow-lt">Not imported</span>';
		const payment = user.payment_date
			? '<strong>' + escapeHtml( user.payment_date ) + '</strong>'
			: '<span class="text-danger">No date</span>';
		const service = expired
			? '<span class="badge bg-danger-lt">Expired</span>'
			: '<span class="badge bg-primary-lt">' + escapeHtml( user.actual_profile || user.profile ) + '</span>';
		const connection = user.active
			? '<span class="badge bg-success-lt">Online</span><div class="small text-secondary">' + escapeHtml( user.uptime ) + '</div>'
			: '<span class="badge bg-secondary-lt">Offline</span>';
		const details = [
			'Installed: ' + ( user.installed || 'N/A' ),
			'Grace: ' + ( user.grace || '0' ) + ' day(s)',
			'Wi-Fi: ' + ( user.wifi || 'N/A' ),
			'Address: ' + ( user.address_text || 'N/A' )
		].join( '\n' );
		const serviceTitle = expired
			? 'Planned profile: ' + ( user.profile || 'Not available' )
			: 'Current RouterOS profile: ' + ( user.actual_profile || user.profile );
		const secondaryAction = expired
			? '<button class="btn btn-sm btn-outline-primary afc-reconnect" type="button">Reconnect</button>'
			: '<button class="btn btn-sm btn-outline-danger afc-expire" type="button">Expire</button>';

		return '<tr data-user="' + encodeURIComponent( JSON.stringify( user ) ) + '">' +
			'<td>' + checkbox + '</td>' +
			'<td><div class="fw-bold">' + escapeHtml( user.name ) + '</div>' +
				'<div class="small text-secondary">' + escapeHtml( user.customer_name || 'Customer name missing' ) + '</div>' +
				'<div class="mt-1">' + imported + ( user.disabled ? ' <span class="badge bg-danger-lt">Disabled</span>' : '' ) + '</div></td>' +
			'<td><div>' + payment + '</div>' +
				'<div class="small text-secondary">₱' + escapeHtml( user.payment_amount || '0' ) + ' · ' + escapeHtml( user.payment_method || 'cash' ) + '</div></td>' +
			'<td title="' + escapeAttr( serviceTitle ) + '">' + service +
				( expired && user.profile ? '<div class="small text-secondary mt-1">Restore: ' + escapeHtml( user.profile ) + '</div>' : '' ) + '</td>' +
			'<td>' + connection + '</td>' +
			'<td>' + opticalHtml( user ) + '</td>' +
			'<td title="' + escapeAttr( details ) + '"><div>' + escapeHtml( user.phone || 'No phone' ) + '</div>' +
				'<div class="small text-secondary text-truncate afc-location">' + escapeHtml( user.address_text || user.wifi || 'No address' ) + '</div>' +
				'<span class="small text-primary">Hover for details</span></td>' +
			'<td class="text-end"><div class="afc-row-actions">' +
				'<button class="btn btn-sm btn-success afc-pay-today" type="button">Paid Today</button>' +
				secondaryAction +
			'</div></td></tr>';
	}

	function render() {
		const query = String( $( '#afc-ppp-search' ).val() || '' ).toLowerCase();
		const filter = $( '#afc-service-filter' ).val();
		let visible = users.filter( function ( user ) {
			const matchesSearch = ! query || [
				user.name, user.customer_name, user.phone, user.profile,
				user.actual_profile, user.comment, user.wifi, user.address_text
			].join( ' ' ).toLowerCase().includes( query );
			const matchesFilter =
				! filter ||
				( 'online' === filter && user.active ) ||
				( 'offline' === filter && ! user.active ) ||
				( 'expired' === filter && isExpired( user ) );
			return matchesSearch && matchesFilter;
		} );

		visible = sortUsers( visible );
		$( '#afc-ppp-table tbody' ).html(
			visible.length
				? visible.map( rowHtml ).join( '' )
				: '<tr><td colspan="8" class="text-center py-5">No matching PPP accounts.</td></tr>'
		);
		$( '.afc-sort-indicator' ).text( '' );
		$( '.afc-sort[data-sort="' + sortState.key + '"] .afc-sort-indicator' )
			.text( 'asc' === sortState.direction ? '▲' : '▼' );
	}

	function loadUsers( forceOptical ) {
		forceOptical = true === forceOptical;
		$( '#afc-refresh-ppp, #afc-refresh-optical' ).prop( 'disabled', true );
		$( '#afc-refresh-optical' ).text( forceOptical ? afcPPP.opticalLoading : afcPPP.opticalButton );
		$( '#afc-ppp-table tbody' ).html( '<tr><td colspan="8" class="text-center py-5">' +
			( forceOptical ? afcPPP.opticalLoading : afcPPP.loading ) + '</td></tr>' );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_get_ppp_users',
			nonce: afcPPP.nonce,
			refresh_optical: forceOptical ? 1 : 0
		} )
			.done( function ( response ) {
				if ( ! response.success ) {
					notice( getError( response, 'Could not load PPP accounts.' ), 'danger' );
					return;
				}
				users = response.data.users;
				opticalSummary = response.data.optical || {};
				selectedNames.clear();
				updateSummary();
				renderOpticalStatus();
				renderCollectionAreas();
				render();
			} )
			.fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'Could not load PPP accounts from MikroTik.' ), 'danger' );
			} )
			.always( function () {
				$( '#afc-refresh-ppp, #afc-refresh-optical' ).prop( 'disabled', false );
				$( '#afc-refresh-optical' ).text( afcPPP.opticalButton );
			} );
	}

	function saveOpticalBinding( clear ) {
		if ( ! opticalUser || ! opticalUser.customer_id ) {
			return;
		}
		if ( clear && ! window.confirm( 'Remove this customer\'s ONU mapping?' ) ) {
			return;
		}

		const $buttons = $( '#afc-save-olt-binding, #afc-clear-olt-binding' );
		$buttons.prop( 'disabled', true );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_save_olt_binding',
			nonce: afcPPP.nonce,
			customer_id: opticalUser.customer_id,
			olt_id: $( '#afc-olt-node' ).val(),
			pon: $( '#afc-olt-pon' ).val(),
			onu: $( '#afc-olt-onu' ).val(),
			onu_mac: $( '#afc-olt-onu-mac' ).val(),
			clear: clear ? 1 : 0
		} ).done( function ( response ) {
			notice( getError( response, 'ONU mapping updated.' ), response.success ? 'success' : 'danger' );
			if ( response.success ) {
				document.getElementById( 'afc-olt-binding-dialog' ).close();
				loadUsers( false );
			}
		} ).fail( function ( xhr ) {
			notice( getError( xhr.responseJSON, 'The ONU mapping could not be saved.' ), 'danger' );
		} ).always( function () {
			$buttons.prop( 'disabled', false );
		} );
	}

	function userFromButton( button ) {
		return JSON.parse( decodeURIComponent( $( button ).closest( 'tr' ).attr( 'data-user' ) ) );
	}

	function changeService( user, change, button ) {
		const wording = 'expire' === change
			? 'Set ' + user.name + ' to the Expired profile? Their current online session will not be disconnected.'
			: 'Restore ' + user.name + ' to ' + user.profile + ' and reconnect their expired session?';
		if ( ! window.confirm( wording ) ) {
			return;
		}

		$( button ).prop( 'disabled', true );
		$.post( afcPPP.ajaxUrl, {
			action: 'afc_ppp_change_service',
			nonce: afcPPP.nonce,
			change: change,
			user: user
		} ).done( function ( response ) {
			notice( getError( response, 'PPP service updated.' ), response.success ? 'success' : 'danger' );
			if ( response.success ) {
				loadUsers();
			}
		} ).fail( function ( xhr ) {
			notice( getError( xhr.responseJSON, 'The PPP service update failed.' ), 'danger' );
		} ).always( function () {
			$( button ).prop( 'disabled', false );
		} );
	}

	$( function () {
		const dialog = document.getElementById( 'afc-payment-dialog' );
		const opticalDialog = document.getElementById( 'afc-olt-binding-dialog' );
		loadUsers();

		$( '#afc-refresh-ppp' ).on( 'click', function () { loadUsers( false ); } );
		$( '#afc-refresh-optical' ).on( 'click', function () { loadUsers( true ); } );
		$( '#afc-due-cutoff' ).on( 'change', renderCollectionAreas );
		$( '#afc-print-all-due' ).on( 'click', function () {
			printCollectionList( '' );
		} );
		$( '#afc-area-summary' ).on( 'click', '.afc-area-card', function () {
			printCollectionList( decodeURIComponent( $( this ).data( 'area' ) ) );
		} );
		$( '#afc-ppp-search, #afc-service-filter' ).on( 'input change', render );
		$( '.afc-sort' ).on( 'click', function () {
			const key = $( this ).data( 'sort' );
			if ( sortState.key === key ) {
				sortState.direction = 'asc' === sortState.direction ? 'desc' : 'asc';
			} else {
				sortState.key = key;
				sortState.direction = 'asc';
			}
			render();
		} );
		$( '#afc-ppp-table' )
			.on( 'change', '.afc-user-check', function () {
				const user = userFromButton( this );
				if ( this.checked ) {
					selectedNames.add( user.name );
				} else {
					selectedNames.delete( user.name );
				}
			} )
			.on( 'click', '.afc-pay-today', function () {
				paymentUser = userFromButton( this );
				$( '#afc-payment-customer' ).text( paymentUser.customer_name || paymentUser.name );
				$( '#afc-payment-amount' ).val( paymentUser.payment_amount || '' );
				$( '#afc-payment-method' ).val( String( paymentUser.payment_method || 'cash' ).toLowerCase() );
				dialog.showModal();
			} )
			.on( 'click', '.afc-expire', function () {
				changeService( userFromButton( this ), 'expire', this );
			} )
			.on( 'click', '.afc-reconnect', function () {
				changeService( userFromButton( this ), 'reconnect', this );
			} )
			.on( 'click', '.afc-map-onu', function () {
				opticalUser = userFromButton( this );
				const optical = opticalUser.optical || {};
				const suggested = optical.suggested || {};
				$( '#afc-olt-binding-customer' ).text( opticalUser.customer_name || opticalUser.name );
				$( '#afc-olt-customer-id' ).val( opticalUser.customer_id );
				$( '#afc-olt-node' ).val( optical.olt_id || suggested.olt_id || 'primary' );
				$( '#afc-olt-pon' ).val( optical.pon || suggested.pon || '' );
				$( '#afc-olt-onu' ).val( optical.onu || suggested.onu || '' );
				$( '#afc-olt-onu-mac' ).val( optical.onu_mac || '' );
				$( '#afc-clear-olt-binding' ).toggle( Boolean( optical.mapped ) );
				opticalDialog.showModal();
			} );

		$( '#afc-save-olt-binding' ).on( 'click', function () {
			const form = document.getElementById( 'afc-olt-binding-form' );
			if ( ! form.reportValidity() ) {
				return;
			}
			saveOpticalBinding( false );
		} );
		$( '#afc-clear-olt-binding' ).on( 'click', function () { saveOpticalBinding( true ); } );

		$( '#afc-select-all' ).on( 'change', function () {
			const checked = this.checked;
			$( '.afc-user-check' ).each( function () {
				const user = userFromButton( this );
				$( this ).prop( 'checked', checked );
				if ( checked ) {
					selectedNames.add( user.name );
				} else {
					selectedNames.delete( user.name );
				}
			} );
		} );

		$( '#afc-confirm-payment' ).on( 'click', function () {
			const button = this;
			const amount = $( '#afc-payment-amount' ).val();
			const method = $( '#afc-payment-method' ).val();
			if ( ! paymentUser || '' === amount ) {
				return;
			}
			$( button ).prop( 'disabled', true ).text( 'Recording...' );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_ppp_record_payment',
				nonce: afcPPP.nonce,
				user: paymentUser,
				amount: amount,
				method: method
			} ).done( function ( response ) {
				dialog.close();
				notice( getError( response, 'Payment recorded.' ), response.success ? 'success' : 'danger' );
				if ( response.success ) {
					loadUsers();
				}
			} ).fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'The payment could not be recorded.' ), 'danger' );
			} ).always( function () {
				$( button ).prop( 'disabled', false ).text( 'Confirm Paid Today' );
			} );
		} );

		$( '#afc-import-ppp' ).on( 'click', function () {
			const button = this;
			const selected = users.filter( function ( user ) { return selectedNames.has( user.name ); } );
			if ( ! selected.length ) {
				notice( afcPPP.noSelection, 'warning' );
				return;
			}
			$( button ).prop( 'disabled', true ).text( afcPPP.importing );
			$.post( afcPPP.ajaxUrl, {
				action: 'afc_import_ppp_users',
				nonce: afcPPP.nonce,
				users: selected
			} ).done( function ( response ) {
				notice( getError( response, 'Import complete.' ), response.success ? 'success' : 'danger' );
				if ( response.success ) {
					loadUsers();
				}
			} ).fail( function ( xhr ) {
				notice( getError( xhr.responseJSON, 'The import request failed.' ), 'danger' );
			} ).always( function () {
				$( button ).prop( 'disabled', false ).text( 'Import Selected' );
			} );
		} );
	} );
}( jQuery ) );
