( function ( $ ) {
	'use strict';

	const preferredBarangays = [ 'Lingion', 'Sto. Nino', 'Dalirig' ];
	let organizing = false;

	function escapeHtml( value ) {
		return $( '<div>' ).text( value || '' ).html();
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
		const match = String( area ).match( /\bZone\s+(\d+)([A-Z])?\b/i );
		if ( ! match ) {
			return { number: 9999, suffix: '' };
		}

		return {
			number: Number( match[1] ),
			suffix: String( match[2] || '' ).toUpperCase()
		};
	}

	function barangayOrder( first, second ) {
		const firstIndex = preferredBarangays.indexOf( first );
		const secondIndex = preferredBarangays.indexOf( second );
		const firstRank = -1 === firstIndex ? preferredBarangays.length : firstIndex;
		const secondRank = -1 === secondIndex ? preferredBarangays.length : secondIndex;

		return firstRank - secondRank || first.localeCompare( second, undefined, { numeric: true } );
	}

	function areaOrder( first, second ) {
		const firstZone = zoneNumber( $( first ).find( '.afc-area-name' ).text() );
		const secondZone = zoneNumber( $( second ).find( '.afc-area-name' ).text() );

		return firstZone.number - secondZone.number ||
			firstZone.suffix.localeCompare( secondZone.suffix ) ||
			$( first ).find( '.afc-area-name' ).text().localeCompare(
				$( second ).find( '.afc-area-name' ).text(),
				undefined,
				{ numeric: true }
			);
	}

	function organizeCollectionAreas() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || organizing ) {
			return;
		}

		const cards = Array.from( container.querySelectorAll( ':scope > .afc-area-card' ) );
		if ( ! cards.length ) {
			return;
		}

		organizing = true;
		const groups = {};

		cards.forEach( function ( card ) {
			const area = $( card ).find( '.afc-area-name' ).text().trim();
			const barangay = getBarangay( area );
			if ( ! groups[ barangay ] ) {
				groups[ barangay ] = [];
			}
			groups[ barangay ].push( card );
		} );

		const html = Object.keys( groups ).sort( barangayOrder ).map( function ( barangay ) {
			const groupCards = groups[ barangay ].sort( areaOrder );
			const total = groupCards.reduce( function ( count, card ) {
				return count + Number( $( card ).find( '.afc-area-count' ).text() || 0 );
			}, 0 );

			return '<section class="afc-barangay-group" data-barangay="' + encodeURIComponent( barangay ) + '">' +
				'<div class="afc-barangay-heading">' +
				'<div><span class="afc-barangay-label">Barangay</span>' +
				'<h3 class="afc-barangay-name">' + escapeHtml( barangay ) + '</h3></div>' +
				'<span class="afc-barangay-total">' + total + ' due</span></div>' +
				'<div class="afc-barangay-areas"></div></section>';
		} ).join( '' );

		container.innerHTML = html;

		Object.keys( groups ).forEach( function ( barangay ) {
			const section = container.querySelector( '[data-barangay="' + encodeURIComponent( barangay ) + '"] .afc-barangay-areas' );
			groups[ barangay ].forEach( function ( card ) {
				section.appendChild( card );
			} );
		} );

		organizing = false;
	}

	$( function () {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container ) {
			return;
		}

		const observer = new MutationObserver( function () {
			window.requestAnimationFrame( organizeCollectionAreas );
		} );

		observer.observe( container, { childList: true } );
		organizeCollectionAreas();
	} );
}( jQuery ) );
