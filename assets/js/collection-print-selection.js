( function ( $ ) {
	'use strict';

	const preferredBarangays = [ 'Lingion', 'Sto. Nino', 'Dalirig' ];
	let collectionUsers = [];
	let enhancing = false;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
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

	function originalArea( address ) {
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
		localities.sort( function ( first, second ) { return first.index - second.index; } );

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
			const clean = normalized
				.replace( /\([^)]*\)/g, ' ' )
				.replace( /\b(?:at|sa|purok|center)\b/gi, ' ' )
				.replace( /[^a-z0-9]+/gi, ' ' )
				.replace( /\s+/g, ' ' )
				.trim();
			if ( clean ) {
				result.push( closestKnownArea( clean ) || clean.replace( /\b[a-z]/g, function ( letter ) { return letter.toUpperCase(); } ) );
			}
		}

		return result.join( ', ' ) || 'Unassigned Area';
	}

	function canonicalArea( area ) {
		const text = plainText( area ).trim();
		const lower = text.toLowerCase();
		let zoneMatch;

		if ( /\b(?:bless|bliss)\b/i.test( text ) ) {
			return 'Zone 6, Lingion';
		}
		if ( /\bgabok\b/i.test( text ) ) {
			return 'Zone 4, Lingion';
		}
		if ( lower.includes( 'lingion' ) ) {
			zoneMatch = text.match( /\bZone\s+(\d+)/i );
			return zoneMatch ? 'Zone ' + Number( zoneMatch[1] ) + ', Lingion' : 'Lingion';
		}
		if ( lower.includes( 'sto. nino' ) || lower.includes( 'sto nino' ) ) {
			zoneMatch = text.match( /\bZone\s+(\d+)/i );
			return zoneMatch ? 'Zone ' + Number( zoneMatch[1] ) + ', Sto. Nino' : 'Sto. Nino';
		}
		if ( lower.includes( 'dalirig' ) ) {
			zoneMatch = text.match( /\bZone\s+(\d+)/i );
			return zoneMatch ? 'Zone ' + Number( zoneMatch[1] ) + ', Dalirig' : 'Dalirig';
		}
		return text;
	}

	function getBarangay( area ) {
		const matched = preferredBarangays.find( function ( barangay ) {
			return String( area ).toLowerCase().includes( barangay.toLowerCase() );
		} );
		if ( matched ) {
			return matched;
		}
		const withoutZone = String( area )
			.replace( /\bZone\s+\d+[A-Z]?\b/gi, '' )
			.replace( /^[\s,.-]+|[\s,.-]+$/g, '' );
		return withoutZone || 'Other / Unassigned';
	}

	function zoneNumber( area ) {
		const match = String( area ).match( /\bZone\s+(\d+)/i );
		return match ? Number( match[1] ) : 9999;
	}

	function barangayOrder( first, second ) {
		const firstIndex = preferredBarangays.indexOf( first );
		const secondIndex = preferredBarangays.indexOf( second );
		const firstRank = -1 === firstIndex ? preferredBarangays.length : firstIndex;
		const secondRank = -1 === secondIndex ? preferredBarangays.length : secondIndex;
		return firstRank - secondRank || first.localeCompare( second, undefined, { numeric: true } );
	}

	function dateNumber( value ) {
		const match = String( value || '' ).match( /^(\d{4})-(\d{1,2})-(\d{1,2})$/ );
		return match ? ( Number( match[1] ) * 10000 ) + ( Number( match[2] ) * 100 ) + Number( match[3] ) : 0;
	}

	function dueUsers() {
		const cutoff = dateNumber( $( '#afc-due-cutoff' ).val() );
		return collectionUsers.filter( function ( user ) {
			const due = dateNumber( user.payment_date );
			return ! user.disabled && due && cutoff && due <= cutoff;
		} );
	}

	function shortAreaLabel( area, barangay ) {
		if ( String( area ).toLowerCase() === String( barangay ).toLowerCase() ) {
			return 'No Zone';
		}
		return String( area ).replace(
			new RegExp( '\\s*,\\s*' + String( barangay ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*$', 'i' ),
			''
		).trim();
	}

	function selectedAreas( group ) {
		return Array.from( group.querySelectorAll( '.afc-zone-print-check:checked' ) ).map( function ( checkbox ) {
			return decodeURIComponent( checkbox.getAttribute( 'data-canonical-area' ) || '' );
		} ).filter( Boolean );
	}

	function groupAreas( group ) {
		return Array.from( group.querySelectorAll( '.afc-canonical-area' ) ).map( function ( card ) {
			return decodeURIComponent( card.getAttribute( 'data-canonical-area' ) || '' );
		} ).filter( Boolean );
	}

	function dueCountForAreas( areas ) {
		const selected = new Set( areas );
		return dueUsers().reduce( function ( total, user ) {
			const area = canonicalArea( originalArea( user.address_text ) );
			return total + ( selected.has( area ) ? 1 : 0 );
		}, 0 );
	}

	function updateGroupToolbar( group ) {
		const toolbar = group.querySelector( '.afc-zone-print-toolbar' );
		if ( ! toolbar ) {
			return;
		}

		const selected = selectedAreas( group );
		const selectedDue = dueCountForAreas( selected );
		const selectedButton = toolbar.querySelector( '.afc-print-selected-zones' );
		const clearButton = toolbar.querySelector( '.afc-clear-selected-zones' );
		const status = toolbar.querySelector( '.afc-zone-print-status' );

		group.querySelectorAll( '.afc-zone-print-item' ).forEach( function ( item ) {
			const checkbox = item.querySelector( '.afc-zone-print-check' );
			item.classList.toggle( 'is-selected', Boolean( checkbox && checkbox.checked ) );
		} );

		if ( status ) {
			status.textContent = selected.length
				? selected.length + ' zone' + ( 1 === selected.length ? '' : 's' ) + ' selected · ' + selectedDue + ' due'
				: 'Select zone checkboxes to combine them';
		}
		if ( selectedButton ) {
			selectedButton.disabled = ! selected.length;
			selectedButton.textContent = selected.length ? 'Print Selected · ' + selectedDue + ' due' : 'Print Selected';
		}
		if ( clearButton ) {
			clearButton.disabled = ! selected.length;
		}
	}

	function printCollectionList( areas, title ) {
		const selected = new Set( areas || [] );
		const cutoff = $( '#afc-due-cutoff' ).val();
		let printable = dueUsers().map( function ( user ) {
			return {
				area: canonicalArea( originalArea( user.address_text ) ),
				name: user.customer_name || user.name,
				plan: user.profile || user.actual_profile || '',
				due: user.payment_date
			};
		} );

		if ( selected.size ) {
			printable = printable.filter( function ( row ) { return selected.has( row.area ); } );
		}
		printable.sort( function ( first, second ) {
			const firstBarangay = getBarangay( first.area );
			const secondBarangay = getBarangay( second.area );
			return barangayOrder( firstBarangay, secondBarangay ) ||
				zoneNumber( first.area ) - zoneNumber( second.area ) ||
				first.name.localeCompare( second.name );
		} );

		if ( ! printable.length ) {
			return;
		}

		const printWindow = window.open( '', '_blank', 'width=950,height=720' );
		if ( ! printWindow ) {
			return;
		}

		const rows = printable.map( function ( row, index ) {
			return '<tr><td>' + ( index + 1 ) + '</td><td>' + escapeHtml( row.area ) +
				'</td><td>' + escapeHtml( row.name ) + '</td><td>' + escapeHtml( row.plan ) +
				'</td><td>' + escapeHtml( row.due ) + '</td></tr>';
		} ).join( '' );

		printWindow.document.write(
			'<!doctype html><html><head><meta charset="utf-8"><title>Airfiber Collection List</title>' +
			'<style>@page{size:A4;margin:12mm}body{font:13px Arial,sans-serif;color:#111}h1{font-size:20px;margin:0 0 4px}' +
			'p{margin:0 0 14px;color:#444}table{width:100%;border-collapse:collapse}th,td{padding:7px 6px;border-bottom:1px solid #bbb;text-align:left}' +
			'th{background:#eee;font-size:11px;text-transform:uppercase}td:first-child{width:34px}.meta{display:flex;justify-content:space-between}</style>' +
			'</head><body><h1>Airfiber - Centralized</h1><div class="meta"><p><strong>' + escapeHtml( title || 'Selected Collection Areas' ) +
			'</strong></p><p>Due until: <strong>' + escapeHtml( cutoff ) + '</strong></p></div>' +
			'<table><thead><tr><th>#</th><th>Zone / Area</th><th>Customer Name</th><th>Plan</th><th>Due Date</th></tr></thead>' +
			'<tbody>' + rows + '</tbody></table></body></html>'
		);
		printWindow.document.close();
		printWindow.focus();
		window.setTimeout( function () { printWindow.print(); }, 250 );
	}

	function enhanceGroups() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || enhancing ) {
			return;
		}
		enhancing = true;

		container.querySelectorAll( '.afc-barangay-group' ).forEach( function ( group ) {
			const areas = group.querySelector( '.afc-barangay-areas' );
			const name = group.querySelector( '.afc-barangay-name' );
			const total = group.querySelector( '.afc-barangay-total' );
			if ( ! areas || ! name ) {
				return;
			}

			const barangay = name.textContent.trim();
			if ( ! areas.querySelector( '.afc-zone-print-toolbar' ) ) {
				const toolbar = document.createElement( 'div' );
				toolbar.className = 'afc-zone-print-toolbar';
				toolbar.innerHTML =
					'<span class="afc-zone-print-status">Select zone checkboxes to combine them</span>' +
					'<div class="afc-zone-print-actions">' +
					'<button class="btn btn-sm btn-link afc-clear-selected-zones" type="button" disabled>Clear</button>' +
					'<button class="btn btn-sm btn-outline-primary afc-print-selected-zones" type="button" disabled>Print Selected</button>' +
					'<button class="btn btn-sm btn-primary afc-print-all-barangay" type="button">Print All · ' +
					escapeHtml( total ? total.textContent.trim() : dueCountForAreas( groupAreas( group ) ) + ' due' ) + '</button></div>';
				areas.prepend( toolbar );
			}

			group.querySelectorAll( '.afc-canonical-area' ).forEach( function ( card ) {
				if ( card.closest( '.afc-zone-print-item' ) ) {
					return;
				}
				const encodedArea = card.getAttribute( 'data-canonical-area' ) || '';
				const fullArea = decodeURIComponent( encodedArea );
				const wrapper = document.createElement( 'div' );
				wrapper.className = 'afc-zone-print-item';
				card.parentNode.insertBefore( wrapper, card );
				wrapper.appendChild( card );

				const checkbox = document.createElement( 'input' );
				checkbox.type = 'checkbox';
				checkbox.className = 'form-check-input afc-zone-print-check';
				checkbox.setAttribute( 'data-canonical-area', encodedArea );
				checkbox.setAttribute( 'aria-label', 'Select ' + shortAreaLabel( fullArea, barangay ) + ' for combined printing' );
				checkbox.title = 'Select ' + shortAreaLabel( fullArea, barangay ) + ' for combined printing';
				wrapper.insertBefore( checkbox, card );
			} );

			updateGroupToolbar( group );
		} );

		enhancing = false;
	}

	$( function () {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container ) {
			return;
		}

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( String( settings.data || '' ).includes( 'action=afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				collectionUsers = xhr.responseJSON.data.users || [];
				window.setTimeout( enhanceGroups, 0 );
			}
		} );

		container.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( '.afc-zone-print-check' ) ) {
				return;
			}
			updateGroupToolbar( event.target.closest( '.afc-barangay-group' ) );
		} );

		container.addEventListener( 'click', function ( event ) {
			const group = event.target.closest( '.afc-barangay-group' );
			if ( ! group ) {
				return;
			}
			const barangayElement = group.querySelector( '.afc-barangay-name' );
			const barangay = barangayElement ? barangayElement.textContent.trim() : 'Barangay';

			if ( event.target.closest( '.afc-clear-selected-zones' ) ) {
				event.preventDefault();
				group.querySelectorAll( '.afc-zone-print-check' ).forEach( function ( checkbox ) { checkbox.checked = false; } );
				updateGroupToolbar( group );
				return;
			}
			if ( event.target.closest( '.afc-print-selected-zones' ) ) {
				event.preventDefault();
				const selected = selectedAreas( group );
				if ( selected.length ) {
					const labels = selected.map( function ( area ) { return shortAreaLabel( area, barangay ); } );
					printCollectionList( selected, barangay + ' — ' + labels.join( ', ' ) );
				}
				return;
			}
			if ( event.target.closest( '.afc-print-all-barangay' ) ) {
				event.preventDefault();
				printCollectionList( groupAreas( group ), barangay + ' — All zones' );
			}
		} );

		new MutationObserver( function () {
			window.requestAnimationFrame( enhanceGroups );
		} ).observe( container, { childList: true, subtree: true } );

		enhanceGroups();
	} );
}( jQuery ) );