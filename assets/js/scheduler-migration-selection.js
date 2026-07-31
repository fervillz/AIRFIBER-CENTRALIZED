( function ( $ ) {
	'use strict';

	if ( ! window.afcSchedulers ) {
		return;
	}

	const candidateIds = new Set();
	const candidateTypes = new Map();

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

	function requestOperation( options, originalOptions ) {
		const data = originalOptions && originalOptions.data ? originalOptions.data : options.data;
		if ( data && 'object' === typeof data ) {
			return String( data.operation || '' );
		}
		if ( 'string' === typeof data ) {
			try {
				return new URLSearchParams( data ).get( 'operation' ) || '';
			} catch ( error ) {
				return '';
			}
		}
		return '';
	}

	function requestIds( options, originalOptions ) {
		const data = originalOptions && originalOptions.data ? originalOptions.data : options.data;
		if ( data && 'object' === typeof data ) {
			const ids = Array.isArray( data.ids ) ? data.ids : ( data.ids ? [ data.ids ] : [] );
			return ids.map( function ( id ) { return String( id ); } );
		}
		if ( 'string' === typeof data ) {
			try {
				const params = new URLSearchParams( data );
				return params.getAll( 'ids[]' ).concat( params.getAll( 'ids' ) ).map( function ( id ) { return String( id ); } );
			} catch ( error ) {
				return [];
			}
		}
		return [];
	}

	function replaceAction( options, originalOptions, action ) {
		[ originalOptions, options ].forEach( function ( request ) {
			if ( ! request || ! request.data ) {
				return;
			}
			if ( 'object' === typeof request.data ) {
				request.data.action = action;
				return;
			}
			if ( 'string' === typeof request.data ) {
				const params = new URLSearchParams( request.data );
				params.set( 'action', action );
				request.data = params.toString();
			}
		} );
	}

	function isPastCutoff( row ) {
		if ( 'overdue' === String( row.status || '' ) ) {
			return true;
		}
		const value = String( row.cutoffDate || '' );
		if ( ! /^\d{4}-\d{2}-\d{2}$/.test( value ) ) {
			return false;
		}
		const today = new Date();
		const local = [
			today.getFullYear(),
			String( today.getMonth() + 1 ).padStart( 2, '0' ),
			String( today.getDate() ).padStart( 2, '0' )
		].join( '-' );
		return value <= local;
	}

	function migrationType( row ) {
		if ( ! row.schedulerId ) {
			return 'missing';
		}
		if ( ! row.managed ) {
			return 'legacy';
		}
		return '';
	}

	$.ajaxPrefilter( function ( options, originalOptions ) {
		const action = requestAction( options, originalOptions );

		if ( 'afc_scheduler_apply' === action && 'sync' === requestOperation( options, originalOptions ) ) {
			const ids = requestIds( options, originalOptions );
			if ( ids.length && ids.every( function ( id ) { return candidateIds.has( id ); } ) ) {
				replaceAction( options, originalOptions, 'afc_scheduler_migration_apply' );
				return;
			}
		}

		if ( 'afc_scheduler_preview' !== action ) {
			return;
		}

		const previousFilter = options.dataFilter;
		options.dataFilter = function ( raw, type ) {
			let filtered = raw;
			candidateIds.clear();
			candidateTypes.clear();

			try {
				const response = JSON.parse( raw );
				if ( response && response.success && response.data && Array.isArray( response.data.rows ) ) {
					response.data.rows = response.data.rows.map( function ( row ) {
						const kind = migrationType( row );
						const past = isPastCutoff( row );
						const candidate = Boolean( kind );

						row.bulkMigrationCandidate = candidate;
						row.bulkMigrationType = kind;
						row.pastCutoff = past;
						row.selectedByDefault = candidate;
						row.selectable = candidate;

						if ( candidate ) {
							candidateIds.add( String( row.pppId ) );
							candidateTypes.set( String( row.pppId ), kind );
							row.status = kind;
							if ( past ) {
								row.message = 'Past cutoff: Airfiber will ' + ( 'missing' === kind ? 'create' : 'upgrade' ) + ' this scheduler disabled. The next payment will move and enable it.';
							}
						}

						return row;
					} );

					const counts = {
						healthy: 0,
						missing: 0,
						legacy: 0,
						stale: 0,
						disabled: 0,
						overdue: 0,
						invalid: 0,
						duplicate: 0,
					};
					response.data.rows.forEach( function ( row ) {
						if ( Object.prototype.hasOwnProperty.call( counts, row.status ) ) {
							counts[ row.status ]++;
						}
					} );
					response.data.counts = counts;
					response.data.migrationCandidateCount = candidateIds.size;
					filtered = JSON.stringify( response );
				}
			} catch ( error ) {
				filtered = raw;
			}

			return previousFilter ? previousFilter( filtered, type ) : filtered;
		};
	} );

	function setText( element, text ) {
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
			setText( description, 'Only PPP users with no scheduler or an old legacy event are shown here. Past-cutoff candidates are migrated disabled so they cannot immediately disconnect a customer.' );

			const master = bulkPanel.querySelector( '[data-afc-scheduler-select-safe]' );
			if ( master && master.parentElement ) {
				const label = master.parentElement;
				let copy = label.querySelector( '[data-afc-migration-label-copy]' );
				if ( ! copy ) {
					Array.from( label.childNodes ).forEach( function ( node ) {
						if ( Node.TEXT_NODE === node.nodeType ) {
							node.remove();
						}
					} );
					copy = document.createElement( 'span' );
					copy.setAttribute( 'data-afc-migration-label-copy', '' );
					label.appendChild( copy );
				}
				setText( copy, 'Select all missing and legacy schedulers' );
			}

			const syncButton = bulkPanel.querySelector( '[data-afc-scheduler-bulk="sync"]' );
			setText( syncButton, 'Create / Upgrade Selected' );
		}

		return true;
	}

	function filterBulkCards() {
		const root = document.getElementById( 'afc-scheduler-center' );
		const list = root ? root.querySelector( '[data-afc-scheduler-bulk-list]' ) : null;
		if ( ! list ) {
			return;
		}

		let visible = 0;
		list.querySelectorAll( '[data-afc-scheduler-bulk-row]' ).forEach( function ( card ) {
			const id = String( card.dataset.id || '' );
			const candidate = candidateIds.has( id );
			card.hidden = ! candidate;
			if ( candidate ) {
				visible++;
				const kind = candidateTypes.get( id ) || '';
				card.classList.add( 'is-migration-candidate' );
				card.setAttribute( 'data-migration-type', kind );
			}
		} );

		let empty = list.querySelector( '[data-afc-migration-empty]' );
		if ( ! empty ) {
			empty = document.createElement( 'p' );
			empty.setAttribute( 'data-afc-migration-empty', '' );
			empty.textContent = 'No missing or legacy schedulers were found.';
			list.appendChild( empty );
		}
		empty.hidden = visible > 0;
	}

	function boot() {
		updateInterfaceCopy();
		filterBulkCards();

		const observer = new MutationObserver( function () {
			updateInterfaceCopy();
			filterBulkCards();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
		window.setTimeout( function () { observer.disconnect(); }, 30000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}( jQuery ) );
