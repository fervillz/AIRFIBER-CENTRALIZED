( function () {
	'use strict';

	let enhancing = false;

	function decodeArea( value ) {
		try {
			return decodeURIComponent( value || '' );
		} catch ( error ) {
			return String( value || '' );
		}
	}

	function enhanceZoneCards() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || enhancing ) {
			return;
		}

		enhancing = true;
		container.querySelectorAll( '.afc-zone-print-item .afc-canonical-area' ).forEach( function ( card ) {
			const label = card.querySelector( '.afc-area-name' );
			if ( ! label ) {
				return;
			}

			const fullArea = decodeArea( card.getAttribute( 'data-canonical-area' ) );
			const zoneMatch = fullArea.match( /\bZone\s+(\d+)([A-Z])?\b/i );
			const zoneNumber = zoneMatch
				? String( Number( zoneMatch[1] ) ) + ( zoneMatch[2] ? zoneMatch[2].toUpperCase() : '' )
				: '—';

			card.classList.add( 'has-zone-visual' );
			label.classList.add( 'afc-zone-visual-label' );
			label.setAttribute( 'data-zone-number', zoneNumber );
			label.setAttribute( 'data-zone-caption', zoneMatch ? 'ZONE' : 'NO ZONE' );
			label.classList.toggle( 'is-no-zone', ! zoneMatch );
		} );
		enhancing = false;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container ) {
			return;
		}

		new MutationObserver( function () {
			window.requestAnimationFrame( enhanceZoneCards );
		} ).observe( container, { childList: true, subtree: true } );

		enhanceZoneCards();
	} );
}() );
