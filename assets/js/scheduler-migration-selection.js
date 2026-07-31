( function ( $ ) {
	'use strict';

	if ( ! window.afcSchedulers ) {
		return;
	}

	const eligibleStatuses = [ 'missing', 'legacy' ];

	function requestAction( options, originalOptions ) {
		const data = originalOptions && originalOptions.data ? originalOptions.data : options.data;
		if ( data && 'object' === typeof data && data.action ) {
			return String( data.action );
		}
		if ( 'string' === typeof data ) {
			try {
				return new URLSearchParams( data ).get( 'action' ) || '';
			} catch ( error ) {
				return '';
			}
		}
		return '';
	}

	/**
	 * Adjust the preview response before schedulers.js builds its local state.
	 * This keeps bulk selection limited to migration candidates only.
	 */
	$.ajaxPrefilter( function ( options, originalOptions ) {
		if ( 'afc_scheduler_preview' !== requestAction( options, originalOptions ) ) {
			return;
		}

		const previousFilter = options.dataFilter;
		options.dataFilter = function ( raw, type ) {
			let filtered = raw;

			try {
				const response = JSON.parse( raw );
				if ( response && response.success && response.data && Array.isArray( response.data.rows ) ) {
					response.data.rows = response.data.rows.map( function ( row ) {
						const candidate = eligibleStatuses.includes( String( row.status || '' ) );
						row.bulkMigrationCandidate = candidate;
						row.selectedByDefault = candidate;
						row.selectable = candidate;
						return row;
					} );
					response.data.migrationCandidateCount = response.data.rows.filter( function ( row ) {
						return row.bulkMigrationCandidate;
					} ).length;
					filtered = JSON.stringify( response );
				}
			} catch ( error ) {
				filtered = raw;
			}

			return previousFilter ? previousFilter( filtered, type ) : filtered;
		};
	} );

	function replaceText( selector, text ) {
		const element = document.querySelector( selector );
		if ( element && element.textContent !== text ) {
			element.textContent = text;
		}
	}

	function updateInterfaceCopy() {
		const root = document.getElementById( 'afc-scheduler-center' );
		if ( ! root ) {
			return false;
		}

		const bulkPanel = root.querySelector( '[data-afc-scheduler-panel="bulk"]' );
		if ( bulkPanel ) {
			const description = bulkPanel.querySelector( '.afc-scheduler-section-head p' );
			if ( description ) {
				description.textContent = 'Only PPP users with no scheduler or an old legacy event are available for bulk upgrade. Other scheduler statuses must be reviewed individually.';
			}

			const master = bulkPanel.querySelector( '[data-afc-scheduler-select-safe]' );
			if ( master && master.parentElement ) {
				const label = master.parentElement;
				Array.from( label.childNodes ).forEach( function ( node ) {
					if ( Node.TEXT_NODE === node.nodeType ) {
						node.remove();
					}
				} );
				label.appendChild( document.createTextNode( ' Select missing and legacy only' ) );
			}

			const syncButton = bulkPanel.querySelector( '[data-afc-scheduler-bulk="sync"]' );
			if ( syncButton ) {
				syncButton.textContent = 'Create / Upgrade Selected';
			}
		}

		const overviewCards = root.querySelectorAll( '.afc-scheduler-overview-grid article' );
		overviewCards.forEach( function ( card ) {
			const heading = card.querySelector( 'h3' );
			const paragraph = card.querySelector( 'p' );
			if ( heading && 'Generate missing jobs' === heading.textContent.trim() && paragraph ) {
				paragraph.textContent = 'Bulk migration selects only missing schedulers and old legacy scripts. Existing managed schedulers stay untouched.';
			}
		} );

		return true;
	}

	function boot() {
		if ( updateInterfaceCopy() ) {
			return;
		}

		const observer = new MutationObserver( function () {
			if ( updateInterfaceCopy() ) {
				observer.disconnect();
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () { observer.disconnect(); }, 10000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( jQuery ) );
