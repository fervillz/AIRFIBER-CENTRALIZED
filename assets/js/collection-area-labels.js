( function () {
	'use strict';

	let updating = false;

	function escapeHtml( value ) {
		const element = document.createElement( 'div' );
		element.textContent = value || '';
		return element.innerHTML;
	}

	function isUnassignedBarangay( barangay ) {
		return /^(?:Other \/ Unassigned|Unassigned Area)$/i.test( String( barangay || '' ).trim() );
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

		if ( window.AFCTooltip ) {
			window.AFCTooltip.hideAll();
		}

		if ( opening ) {
			closeOtherGroups( group );
		}

		group.classList.toggle( 'is-open', opening );
		if ( heading ) {
			heading.setAttribute( 'aria-expanded', opening ? 'true' : 'false' );
		}
	}

	function printAreaFromTooltip( trigger, encodedArea ) {
		const group = trigger.closest( '.afc-barangay-group' );
		if ( ! group ) {
			return;
		}

		const targetCard = Array.from( group.querySelectorAll( '.afc-canonical-area' ) ).find( function ( card ) {
			return card.getAttribute( 'data-canonical-area' ) === encodedArea;
		} );

		if ( targetCard ) {
			targetCard.click();
		}
	}

	function openAreaManager() {
		document.dispatchEvent( new CustomEvent( 'afc:open-area-manager' ) );
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
			const plainSummary = [];
			group.querySelectorAll( '.afc-canonical-area' ).forEach( function ( card ) {
				const label = card.querySelector( '.afc-area-name' );
				const count = card.querySelector( '.afc-area-count' );
				const encodedArea = card.getAttribute( 'data-canonical-area' ) || '';
				const fullArea = decodeURIComponent( encodedArea );
				const countValue = count ? count.textContent.trim() : '0';
				let shortLabel = fullArea;

				if ( fullArea.toLowerCase() === barangay.toLowerCase() ) {
					shortLabel = 'No Zone';
				} else {
					shortLabel = fullArea
						.replace( new RegExp( '\\s*,\\s*' + barangay.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s*$', 'i' ), '' )
						.trim();
				}

				if ( label && label.textContent !== ( shortLabel || fullArea ) ) {
					label.textContent = shortLabel || fullArea;
				}

				plainSummary.push( ( shortLabel || fullArea ) + ': ' + countValue + ' due' );
				tooltipItems.push(
					'<button type="button" class="afc-tooltip-zone afc-tooltip-action" ' +
					'data-afc-tooltip-action="print-area" data-canonical-area="' + escapeHtml( encodedArea ) + '">' +
					'<strong>' + escapeHtml( shortLabel || fullArea ) + '</strong>' +
					'<span class="afc-tooltip-zone-count">' + escapeHtml( countValue ) + ' due</span></button>'
				);
			} );

			const managerAction = isUnassignedBarangay( barangay )
				? '<div class="afc-tooltip-action-row"><button type="button" class="afc-tooltip-action" data-afc-tooltip-action="manage-unassigned">Assign barangay and zones</button></div>'
				: '';
			const tooltipContent =
				'<div class="afc-tooltip-heading"><strong>' + escapeHtml( barangay ) + ' zones</strong>' +
				'<span>Click a zone to print</span></div>' +
				'<div class="afc-tooltip-zone-grid">' + tooltipItems.join( '' ) + '</div>' + managerAction;

			if ( window.AFCTooltip ) {
				heading.removeAttribute( 'title' );
				window.AFCTooltip.attach( heading, {
					content: tooltipContent,
					placement: 'top',
					offset: 11,
					interactive: true,
					className: 'afc-collection-tooltip',
					shouldShow: function () {
						return ! group.classList.contains( 'is-open' ) &&
							window.matchMedia( '(hover: hover) and (pointer: fine)' ).matches;
					},
					onAction: function ( details ) {
						const action = details.action.getAttribute( 'data-afc-tooltip-action' );
						if ( 'print-area' === action ) {
							printAreaFromTooltip( details.trigger, details.action.getAttribute( 'data-canonical-area' ) || '' );
						} else if ( 'manage-unassigned' === action ) {
							openAreaManager();
						}
					}
				} );
			} else {
				heading.setAttribute( 'title', plainSummary.join( '\n' ) );
			}
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
}() );
