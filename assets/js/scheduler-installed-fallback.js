( function ( $ ) {
	'use strict';

	if ( ! window.afcSchedulerInstalledFallback || ! window.afcSchedulers ) {
		return;
	}

	const candidates = new Map();

	function post( action, data ) {
		return $.ajax( {
			url: afcSchedulerInstalledFallback.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			traditional: false,
			data: Object.assign( { action: action, nonce: afcSchedulerInstalledFallback.nonce }, data || {} ),
		} );
	}

	function notice( text, type ) {
		const box = document.querySelector( '#afc-scheduler-center [data-afc-scheduler-notice]' );
		if ( ! box ) {
			return;
		}
		box.className = 'afc-scheduler-notice is-visible is-' + ( type || 'info' );
		box.textContent = text;
	}

	function rowForId( id ) {
		return Array.from( document.querySelectorAll( '#afc-scheduler-center [data-afc-scheduler-row]' ) ).find( function ( row ) {
			return String( row.dataset.id || '' ) === String( id );
		} );
	}

	function cardForId( id ) {
		return Array.from( document.querySelectorAll( '#afc-scheduler-center [data-afc-scheduler-bulk-row]' ) ).find( function ( card ) {
			return String( card.dataset.id || '' ) === String( id );
		} );
	}

	function updateSelectedCount() {
		const root = document.getElementById( 'afc-scheduler-center' );
		if ( ! root ) {
			return;
		}
		const checked = root.querySelectorAll( '[data-afc-scheduler-bulk-row]:not([hidden]) [data-afc-scheduler-check]:checked:not(:disabled)' ).length;
		const label = root.querySelector( '[data-afc-scheduler-selected-count]' );
		if ( label ) {
			label.textContent = checked + ' selected';
		}
		const sync = root.querySelector( '[data-afc-scheduler-bulk="sync"]' );
		if ( sync ) {
			sync.disabled = 0 === checked;
		}
	}

	function decorate() {
		candidates.forEach( function ( item, id ) {
			const row = rowForId( id );
			if ( row ) {
				row.dataset.status = 'missing';
				const billing = row.querySelector( '[data-label="Billing source"]' );
				if ( billing && ! billing.querySelector( '[data-afc-installed-fallback-source]' ) ) {
					billing.insertAdjacentHTML( 'afterbegin', '<div data-afc-installed-fallback-source><small>installed</small><strong>' + item.installed + '</strong></div>' );
				}
				const scheduler = row.querySelector( '[data-label="Scheduler"]' );
				if ( scheduler ) {
					scheduler.innerHTML = '<div class="afc-scheduler-missing-label">No scheduler</div><small>Expected ' + item.expiry + ' from Installed Date</small>';
				}
				const statusCell = row.querySelector( '[data-label="Status"]' );
				if ( statusCell ) {
					const badge = statusCell.querySelector( '.afc-scheduler-status' );
					if ( badge ) {
						badge.className = 'afc-scheduler-status is-missing';
						badge.textContent = 'Missing';
					}
					const p = statusCell.querySelector( 'p' );
					if ( p ) {
						p.textContent = 'No billing dates yet. Airfiber will use the Installed Date day and create the next expiry on ' + item.expiry + '.';
					}
				}
				const actions = row.querySelector( '[data-label="Actions"] .afc-scheduler-row-actions' );
				if ( actions ) {
					actions.innerHTML = '<button type="button" class="btn btn-sm btn-primary" data-afc-installed-create data-id="' + String( id ).replace( /"/g, '&quot;' ) + '">Create</button>';
				}
				const filter = document.querySelector( '#afc-scheduler-center [data-afc-scheduler-filter]' );
				if ( filter && ( 'all' === filter.value || 'missing' === filter.value ) ) {
					row.hidden = false;
				}
			}

			const card = cardForId( id );
			if ( card ) {
				card.hidden = false;
				card.dataset.installedFallback = '1';
				card.classList.add( 'is-missing', 'is-migration-candidate' );
				const check = card.querySelector( '[data-afc-scheduler-check]' );
				if ( check ) {
					check.disabled = false;
					check.checked = true;
				}
				const strongs = card.querySelectorAll( 'div > strong' );
				if ( strongs.length ) {
					strongs[0].textContent = item.expiry;
				}
				const p = card.querySelector( 'p' );
				if ( p ) {
					p.textContent = 'Installed ' + item.installed + ' → create next expiry ' + item.expiry + '.';
				}
			}
		} );
		updateSelectedCount();
	}

	function refreshCandidates() {
		post( 'afc_scheduler_installed_candidates' ).done( function ( response ) {
			candidates.clear();
			if ( response && response.success && response.data && Array.isArray( response.data.candidates ) ) {
				response.data.candidates.forEach( function ( item ) {
					candidates.set( String( item.id ), item );
				} );
			}
			decorate();
		} );
	}

	function runInstalled( ids ) {
		return post( 'afc_scheduler_installed_apply', { ids: ids } );
	}

	function runNormal( ids ) {
		return post( 'afc_scheduler_migration_apply', { ids: ids } );
	}

	async function runBatches( ids, runner ) {
		const size = Number( afcSchedulers.batchSize || 20 );
		let updated = 0;
		let failed = 0;
		for ( let i = 0; i < ids.length; i += size ) {
			const response = await runner( ids.slice( i, i + size ) );
			if ( response && response.success ) {
				updated += ( response.data.updated || [] ).length;
				failed += ( response.data.failed || [] ).length;
			} else {
				failed += Math.min( size, ids.length - i );
			}
		}
		return { updated: updated, failed: failed };
	}

	document.addEventListener( 'click', function ( event ) {
		const create = event.target.closest( '[data-afc-installed-create]' );
		if ( create ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			const id = String( create.dataset.id || '' );
			create.disabled = true;
			create.textContent = 'Creating…';
			notice( 'Creating scheduler from Installed Date…', 'info' );
			runInstalled( [ id ] ).done( function ( response ) {
				if ( response && response.success && response.data && response.data.updated && response.data.updated.length ) {
					notice( 'Scheduler created. nextDue and cutoffDate were filled from the Installed Date.', 'success' );
					window.setTimeout( function () {
						const refresh = document.querySelector( '#afc-scheduler-center [data-afc-scheduler-refresh]' );
						if ( refresh ) { refresh.click(); }
					}, 350 );
				} else {
					notice( response && response.data && response.data.message ? response.data.message : 'Scheduler could not be created.', 'error' );
					create.disabled = false;
					create.textContent = 'Create';
				}
			} ).fail( function ( xhr ) {
				notice( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Scheduler could not be created.', 'error' );
				create.disabled = false;
				create.textContent = 'Create';
			} );
			return;
		}

		const bulk = event.target.closest( '#afc-scheduler-center [data-afc-scheduler-bulk="sync"]' );
		if ( bulk ) {
			const selectedCards = Array.from( document.querySelectorAll( '#afc-scheduler-center [data-afc-scheduler-bulk-row]:not([hidden])' ) ).filter( function ( card ) {
				const check = card.querySelector( '[data-afc-scheduler-check]' );
				return check && check.checked && ! check.disabled;
			} );
			const installedIds = selectedCards.filter( function ( card ) { return candidates.has( String( card.dataset.id || '' ) ); } ).map( function ( card ) { return String( card.dataset.id ); } );
			if ( ! installedIds.length ) {
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			const normalIds = selectedCards.filter( function ( card ) { return ! candidates.has( String( card.dataset.id || '' ) ); } ).map( function ( card ) { return String( card.dataset.id ); } );
			bulk.disabled = true;
			bulk.textContent = 'Creating…';
			notice( 'Creating missing schedulers…', 'info' );
			( async function () {
				let updated = 0;
				let failed = 0;
				if ( normalIds.length ) {
					const normal = await runBatches( normalIds, runNormal );
					updated += normal.updated;
					failed += normal.failed;
				}
				if ( installedIds.length ) {
					const fallback = await runBatches( installedIds, runInstalled );
					updated += fallback.updated;
					failed += fallback.failed;
				}
				notice( 'Created / upgraded ' + updated + ' scheduler' + ( 1 === updated ? '' : 's' ) + '. Failed ' + failed + '.', failed ? 'warning' : 'success' );
				bulk.textContent = 'Create / Upgrade Selected';
				window.setTimeout( function () {
					const refresh = document.querySelector( '#afc-scheduler-center [data-afc-scheduler-refresh]' );
					if ( refresh ) { refresh.click(); }
				}, 450 );
			} )().catch( function () {
				notice( 'The scheduler batch stopped because a request failed.', 'error' );
				bulk.disabled = false;
				bulk.textContent = 'Create / Upgrade Selected';
			} );
		}
	}, true );

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.matches( '#afc-scheduler-center [data-afc-scheduler-select-safe]' ) ) {
			window.setTimeout( function () {
				candidates.forEach( function ( item, id ) {
					const card = cardForId( id );
					if ( card ) {
						card.hidden = false;
						const check = card.querySelector( '[data-afc-scheduler-check]' );
						if ( check ) {
							check.disabled = false;
							check.checked = event.target.checked;
						}
					}
				} );
				updateSelectedCount();
			}, 0 );
		}
		if ( event.target.matches( '#afc-scheduler-center [data-afc-scheduler-check]' ) ) {
			window.setTimeout( updateSelectedCount, 0 );
		}
	} );

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		const data = settings && settings.data ? String( settings.data ) : '';
		if ( data.indexOf( 'action=afc_scheduler_preview' ) !== -1 || ( settings && settings.data && 'object' === typeof settings.data && 'afc_scheduler_preview' === settings.data.action ) ) {
			window.setTimeout( refreshCandidates, 30 );
		}
	} );
}( jQuery ) );
