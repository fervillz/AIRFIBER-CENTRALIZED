( function ( $ ) {
	'use strict';

	/*
	 * comment-migration.js uses jQuery traditional serialization. Traditional
	 * arrays arrive in PHP as one scalar value, which caused every batch to
	 * return HTTP 400. Keep the existing UI intact but serialize migration IDs
	 * as ids[]=... so all selected PPP IDs reach the server.
	 */
	const originalAjax = $.ajax;
	$.ajax = function ( url, options ) {
		let settings = 'object' === typeof url ? url : options;

		if (
			settings &&
			settings.data &&
			'afc_apply_comment_migration' === settings.data.action &&
			Array.isArray( settings.data.ids )
		) {
			settings = $.extend( {}, settings, {
				data: $.extend( {}, settings.data ),
				traditional: false,
			} );

			if ( 'object' === typeof url ) {
				return originalAjax.call( $, settings );
			}

			return originalAjax.call( $, url, settings );
		}

		return originalAjax.apply( $, arguments );
	};

	function requestHasAction( settings, action ) {
		if ( ! settings ) {
			return false;
		}
		if ( settings.data && 'object' === typeof settings.data ) {
			return settings.data.action === action;
		}

		return String( settings.data || '' ).includes( 'action=' + action );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function explainReview( data ) {
		const root = document.getElementById( 'afc-comment-migration' );
		if ( ! root ) {
			return;
		}

		const summaryCards = root.querySelectorAll( '[data-afc-migration-summary] > div' );
		if ( summaryCards[2] ) {
			const label = summaryCards[2].querySelector( 'span' );
			if ( label ) {
				label.textContent = 'Missing / invalid data';
			}
		}

		const reviewOption = root.querySelector( '[data-afc-migration-filter] option[value="review"]' );
		if ( reviewOption ) {
			reviewOption.textContent = 'Missing / invalid data';
		}

		root.querySelectorAll( '.afc-migration-status.is-review' ).forEach( function ( status ) {
			status.textContent = 'Missing data';
		} );

		let explanation = root.querySelector( '[data-afc-migration-review-explanation]' );
		if ( ! explanation ) {
			explanation = document.createElement( 'div' );
			explanation.className = 'afc-migration-review-explanation';
			explanation.setAttribute( 'data-afc-migration-review-explanation', '' );
			const toolbar = root.querySelector( '.afc-comment-migration-toolbar' );
			if ( toolbar ) {
				toolbar.insertAdjacentElement( 'beforebegin', explanation );
			}
		}

		const reasons = Array.isArray( data.review_summary ) ? data.review_summary : [];
		let reasonHtml = '';
		if ( reasons.length ) {
			reasonHtml = '<div class="afc-migration-review-reasons">' + reasons.map( function ( reason ) {
				return '<button type="button" data-afc-show-review><strong>' + Number( reason.count || 0 ) + '</strong><span>' + escapeHtml( reason.label ) + '</span></button>';
			} ).join( '' ) + '</div>';
		}

		explanation.innerHTML =
			'<div class="afc-migration-review-copy">' +
				'<strong>What does “Missing data” mean?</strong>' +
				'<span>These accounts are not changed automatically. They are missing a usable installed date, payment date, or have an invalid grace/billing value. Normal early or late payments are now matched to the nearest monthly due date.</span>' +
				'<small>Grace 0–6 is accepted. Blank grace uses 6. New installations with grace:3 continue using 3.</small>' +
			'</div>' + reasonHtml;

		explanation.querySelectorAll( '[data-afc-show-review]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				const filter = root.querySelector( '[data-afc-migration-filter]' );
				if ( filter ) {
					filter.value = 'review';
					filter.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				const table = root.querySelector( '.afc-comment-migration-table-wrap' );
				if ( table ) {
					table.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
		} );
	}

	$( document ).ajaxSuccess( function ( event, xhr, settings ) {
		if ( ! requestHasAction( settings, 'afc_preview_comment_migration' ) ) {
			return;
		}
		if ( ! xhr.responseJSON || ! xhr.responseJSON.success ) {
			return;
		}

		window.setTimeout( function () {
			explainReview( xhr.responseJSON.data || {} );
		}, 0 );
	} );
}( jQuery ) );
