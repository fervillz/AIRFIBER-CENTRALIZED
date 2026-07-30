( function () {
	'use strict';

	let updating = false;

	function escapeHtml( value ) {
		const element = document.createElement( 'div' );
		element.textContent = value || '';
		return element.innerHTML;
	}

	function closeOtherGroups( currentGroup ) {
		document.querySelectorAll( '#afc-area-summary .afc-barangay-group.is-open' ).forEach( function ( group ) {
			if ( group !== currentGroup ) {
				group.classList.remove( 'is-open' );
				const heading = group.querySelector( '.afc-barangay-heading' );
				if ( heading ) {
					heading.setAttribute( 'aria-expanded', 'false' );
				}
			}
		} );
	}

	function toggleGroup( group ) {
		const heading = group.querySelector( '.afc-barangay-heading' );
		const opening = ! group.classList.contains( 'is-open' );

		if ( opening ) {
			closeOtherGroups( group );
		}

		group.classList.toggle( 'is-open', opening );
		if ( heading ) {
			heading.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		}
	}

	function prepareCollectionGroups() {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container || updating ) {
			return;
		}

		updating = true;

		container.querySelectorAll( '.afc-barangay-group' ).forEach( function ( group, groupIndex ) {
			const heading = group.querySelector( '.afc-barangay-heading' );
			const barangayHeading = group.querySelector( '.afc-barangay-name' );
			const areas = group.querySelector( '.afc-barangay-areas' );
			const barangay = barangayHeading ? barangayHeading.textContent.trim() : '';

			if ( ! heading || ! areas || ! barangay ) {
				return;
			}

			const areaId = 'afc-barangay-areas-' + groupIndex;
			areas.id = areaId;
			heading.classList.add( 'afc-barangay-toggle' );
			heading.setAttribute( 'role', 'button' );
			heading.setAttribute( 'tabindex', '0' );
			heading.setAttribute( 'aria-controls', areaId );
			heading.setAttribute( 'aria-expanded', group.classList.contains( 'is-open' ) ? 'true' : 'false' );

			const tooltipItems = [];
			group.querySelectorAll( '.afc-canonical-area' ).forEach( function ( card ) {
				const label = card.querySelector( '.afc-area-name' );
				const count = card.querySelector( '.afc-area-count' );
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
				tooltipItems.push(
					'<span class="afc-tooltip-zone"><strong>' + escapeHtml( shortLabel || fullArea ) + '</strong>' +
					'<span>' + escapeHtml( count ? count.textContent.trim() : '0' ) + ' due</span></span>'
				);
			} );

			let tooltip = heading.querySelector( '.afc-barangay-tooltip' );
			if ( ! tooltip ) {
				tooltip = document.createElement( 'div' );
				tooltip.className = 'afc-barangay-tooltip';
				tooltip.setAttribute( 'role', 'tooltip' );
				heading.appendChild( tooltip );
			}
			tooltip.innerHTML = tooltipItems.join( '' );
		} );

		updating = false;
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		const container = document.getElementById( 'afc-area-summary' );
		if ( ! container ) {
			return;
		}

		container.addEventListener( 'click', function ( event ) {
			const heading = event.target.closest( '.afc-barangay-toggle' );
			if ( ! heading || event.target.closest( '.afc-canonical-area' ) ) {
				return;
			}
			toggleGroup( heading.closest( '.afc-barangay-group' ) );
		} );

		container.addEventListener( 'keydown', function ( event ) {
			const heading = event.target.closest( '.afc-barangay-toggle' );
			if ( ! heading || ( 'Enter' !== event.key && ' ' !== event.key ) ) {
				return;
			}
			event.preventDefault();
			toggleGroup( heading.closest( '.afc-barangay-group' ) );
		} );

		const observer = new MutationObserver( function () {
			window.requestAnimationFrame( prepareCollectionGroups );
		} );

		observer.observe( container, { childList: true, subtree: true } );
		prepareCollectionGroups();
	} );
}( ) );