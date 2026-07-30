( function ( $ ) {
	'use strict';

	const preferredBarangays = [ 'Lingion', 'Sto. Nino', 'Dalirig' ];
	let collectionUsers = [];
	let organizing = false;

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

	function printCollectionList( selectedArea ) {
		const cutoff = $( '#afc-due-cutoff' ).val();
		let printable = dueUsers().map( function ( user ) {
			return {
				area: canonicalArea( originalArea( user.address_text ) ),
				name: user.customer_name || user.name,
				plan: user.profile || user.actual_profile || '',
				due: user.payment_date
			};
		} );

		if ( selectedArea ) {
			printable = printable.filter( function ( row ) { return row.area === selectedArea; } );
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
		const title = selectedArea || 'All Collection Areas';

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

	function organizeCollectionAreas() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || organizing ) {
			return;
		}

		const sourceCards = Array.from( container.querySelectorAll( ':scope > .afc-area-card' ) );
		if ( ! sourceCards.length ) {
			return;
		}

		organizing = true;
		const areas = {};

		sourceCards.forEach( function ( card ) {
			const original = $( card ).find( '.afc-area-name' ).text().trim();
			const canonical = canonicalArea( original );
			if ( ! areas[ canonical ] ) {
				areas[ canonical ] = 0;
			}
			areas[ canonical ] += Number( $( card ).find( '.afc-area-count' ).text() || 0 );
		} );

		const barangays = {};
		Object.keys( areas ).forEach( function ( area ) {
			const barangay = getBarangay( area );
			if ( ! barangays[ barangay ] ) {
				barangays[ barangay ] = [];
			}
			barangays[ barangay ].push( area );
		} );

		container.innerHTML = Object.keys( barangays ).sort( barangayOrder ).map( function ( barangay ) {
			const groupAreas = barangays[ barangay ].sort( function ( first, second ) {
				return zoneNumber( first ) - zoneNumber( second ) || first.localeCompare( second, undefined, { numeric: true } );
			} );
			const total = groupAreas.reduce( function ( count, area ) { return count + areas[ area ]; }, 0 );
			const cards = groupAreas.map( function ( area ) {
				return '<button class="afc-area-card afc-canonical-area" type="button" data-canonical-area="' + encodeURIComponent( area ) +
					'" title="Print due accounts for ' + escapeHtml( area ) + '">' +
					'<span class="afc-area-name">' + escapeHtml( area ) + '</span>' +
					'<span class="afc-area-count">' + areas[ area ] + '</span></button>';
			} ).join( '' );

			return '<section class="afc-barangay-group"><div class="afc-barangay-heading">' +
				'<div><span class="afc-barangay-label">Barangay</span><h3 class="afc-barangay-name">' + escapeHtml( barangay ) + '</h3></div>' +
				'<span class="afc-barangay-total">' + total + ' due</span></div>' +
				'<div class="afc-barangay-areas">' + cards + '</div></section>';
		} ).join( '' );

		organizing = false;
	}

	$( function () {
		const container = document.getElementById( 'afc-area-summary' );
		const printAllButton = document.getElementById( 'afc-print-all-due' );
		if ( ! container ) {
			return;
		}

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( String( settings.data || '' ).includes( 'action=afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				collectionUsers = xhr.responseJSON.data.users || [];
				window.setTimeout( organizeCollectionAreas, 0 );
			}
		} );

		container.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '.afc-canonical-area' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			printCollectionList( decodeURIComponent( button.getAttribute( 'data-canonical-area' ) ) );
		}, true );

		if ( printAllButton ) {
			printAllButton.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopImmediatePropagation();
				printCollectionList( '' );
			}, true );
		}

		const observer = new MutationObserver( function () {
			if ( container.querySelector( ':scope > .afc-area-card' ) ) {
				window.requestAnimationFrame( organizeCollectionAreas );
			}
		} );
		observer.observe( container, { childList: true } );
	} );
}( jQuery ) );