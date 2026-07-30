( function () {
	'use strict';

	let updating = false;

	function shortenAreaLabels() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || updating ) {
			return;
		}

		updating = true;

		container.querySelectorAll( '.afc-barangay-group' ).forEach( function ( group ) {
			const barangayHeading = group.querySelector( '.afc-barangay-name' );
			const barangay = barangayHeading ? barangayHeading.textContent.trim() : '';
			if ( ! barangay ) {
				return;
			}

			group.querySelectorAll( '.afc-canonical-area' ).forEach( function ( card ) {
				const label = card.querySelector( '.afc-area-name' );
				const encodedArea = card.getAttribute( 'data-canonical-area' ) || '';
				const fullArea = decodeURIComponent( encodedArea );
				let shortLabel = fullArea;

				if ( fullArea.toLowerCase() === barangay.toLowerCase() ) {
					shortLabel = 'No Zone';
				} else {
					shortLabel = fullArea
						.replace( new RegExp( '\\s*,\\s*' + barangay.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*$', 'i' ), '' )
						.trim();
				}

				label.textContent = shortLabel || fullArea;
			} );
		} );

		updating = false;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container ) {
			return;
		}

		const observer = new MutationObserver( function () {
			window.requestAnimationFrame( shortenAreaLabels );
		} );

		observer.observe( container, { childList: true, subtree: true } );
		shortenAreaLabels();
	} );
}( ) );
