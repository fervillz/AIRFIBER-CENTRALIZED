( function ( $ ) {
	'use strict';

	if ( ! window.afcSchedulers ) {
		return;
	}

	const state = {
		rows: [],
		counts: {},
		orphans: [],
		selected: new Set(),
		loaded: false,
		busy: false,
		search: '',
		filter: 'all',
	};

	let root = null;

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function request( action, data ) {
		return $.ajax( {
			url: afcSchedulers.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			traditional: false,
			data: Object.assign( {
				action: action,
				nonce: afcSchedulers.nonce,
			}, data || {} ),
		} );
	}

	function responseMessage( xhr, fallback ) {
		return xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
			? xhr.responseJSON.data.message
			: fallback;
	}

	function addMainNavigation() {
		const nav = document.querySelector( '.afc-frontend-nav' );
		if ( ! nav ) {
			return false;
		}

		let button = nav.querySelector( '[data-afc-app-panel="schedulers"]' );
		if ( ! button ) {
			button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'afc-advanced-only';
			button.setAttribute( 'data-afc-app-panel', 'schedulers' );
			button.setAttribute( 'aria-pressed', 'false' );
			button.textContent = afcSchedulers.labels && afcSchedulers.labels.nav ? afcSchedulers.labels.nav : 'Schedulers';
			nav.appendChild( button );
		}

		const comments = nav.querySelector( '[data-afc-app-panel="comment-fields"]' );
		const mikrotik = nav.querySelector( '[data-afc-app-panel="mikrotik"]' );
		const desired = [ comments, button, mikrotik ].filter( Boolean );
		let changed = false;
		desired.forEach( function ( item, index ) {
			const previous = index ? desired[ index - 1 ] : null;
			const inPlace = previous ? previous.nextElementSibling === item : true;
			if ( ! inPlace ) {
				nav.insertBefore( item, previous.nextElementSibling );
				changed = true;
			}
		} );
		return changed || Boolean( button );
	}

	function showNotice( message, type ) {
		const notice = root && root.querySelector( '[data-afc-scheduler-notice]' );
		if ( ! notice ) {
			return;
		}
		notice.className = 'afc-scheduler-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function setBusy( busy, message ) {
		state.busy = busy;
		if ( root ) {
			root.classList.toggle( 'is-busy', busy );
			root.querySelectorAll( 'button, input, select' ).forEach( function ( control ) {
				if ( busy ) {
					control.dataset.afcSchedulerDisabled = control.disabled ? '1' : '0';
					control.disabled = true;
				} else if ( Object.prototype.hasOwnProperty.call( control.dataset, 'afcSchedulerDisabled' ) ) {
					control.disabled = '1' === control.dataset.afcSchedulerDisabled;
					delete control.dataset.afcSchedulerDisabled;
				}
			} );
		}
		if ( message ) {
			showNotice( message, 'info' );
		}
		if ( ! busy ) {
			updateBulkControls();
		}
	}

	function activateView( view, scroll ) {
		if ( ! root || ! root.querySelector( '[data-afc-scheduler-panel="' + view + '"]' ) ) {
			view = 'overview';
		}
		try {
			window.sessionStorage.setItem( 'afcSchedulerView', view );
		} catch ( error ) {
			// Navigation still works when storage is blocked.
		}

		root.querySelectorAll( '[data-afc-scheduler-view]' ).forEach( function ( button ) {
			const active = button.dataset.afcSchedulerView === view;
			button.classList.toggle( 'is-active', active );
			button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		} );
		root.querySelectorAll( '[data-afc-scheduler-panel]' ).forEach( function ( panel ) {
			const active = panel.dataset.afcSchedulerPanel === view;
			panel.classList.toggle( 'is-active', active );
			panel.hidden = ! active;
		} );
		if ( scroll ) {
			const menu = root.querySelector( '.afc-scheduler-mega' );
			if ( menu ) {
				menu.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		}
	}

	function statusLabel( status ) {
		return {
			healthy: 'Healthy',
			missing: 'Missing',
			legacy: 'Legacy script',
			stale: 'Needs sync',
			disabled: 'Disabled',
			overdue: 'Past cutoff',
			invalid: 'Missing data',
			duplicate: 'Duplicate',
		}[ status ] || status;
	}

	function syncCount() {
		return Number( state.counts.legacy || 0 ) + Number( state.counts.stale || 0 ) + Number( state.counts.disabled || 0 );
	}

	function reviewCount() {
		return Number( state.counts.overdue || 0 ) + Number( state.counts.invalid || 0 ) + Number( state.counts.duplicate || 0 );
	}

	function renderSummary() {
		root.querySelectorAll( '[data-afc-scheduler-summary]' ).forEach( function ( summary ) {
			summary.innerHTML = '' +
				'<div><strong>' + Number( state.rows.length ) + '</strong><span>PPP users</span></div>' +
				'<div class="is-healthy"><strong>' + Number( state.counts.healthy || 0 ) + '</strong><span>Healthy</span></div>' +
				'<div class="is-missing"><strong>' + Number( state.counts.missing || 0 ) + '</strong><span>Missing</span></div>' +
				'<div class="is-sync"><strong>' + syncCount() + '</strong><span>Need sync</span></div>' +
				'<div class="is-review"><strong>' + reviewCount() + '</strong><span>Review</span></div>';
		} );
	}

	function billingHtml( row ) {
		return '<div class="afc-scheduler-billing">' +
			'<span><small>nextDue</small><strong>' + escapeHtml( row.nextDue || 'Missing' ) + '</strong></span>' +
			'<span><small>cutoffDate</small><strong>' + escapeHtml( row.cutoffDate || 'Missing' ) + '</strong></span>' +
			( row.promisedPayDate ? '<span class="is-promise"><small>Promise</small><strong>' + escapeHtml( row.promisedPayDate ) + '</strong></span>' : '' ) +
		'</div>';
	}

	function scheduleHtml( row ) {
		if ( ! row.schedulerId ) {
			return '<div class="afc-scheduler-missing-label">No scheduler</div><small>Expected ' + escapeHtml( row.expectedDate || '—' ) + ' ' + escapeHtml( row.expectedTime || '' ) + '</small>';
		}
		return '<div class="afc-scheduler-date-edit">' +
			'<input type="date" value="' + escapeHtml( row.currentDate || row.expectedDate || '' ) + '" data-afc-scheduler-date aria-label="Scheduler date for ' + escapeHtml( row.name ) + '">' +
			'<input type="time" step="1" value="' + escapeHtml( row.currentTime || row.expectedTime || '' ) + '" data-afc-scheduler-time aria-label="Scheduler time for ' + escapeHtml( row.name ) + '">' +
		'</div><small>Expected ' + escapeHtml( row.expectedDate || '—' ) + ' ' + escapeHtml( row.expectedTime || '' ) + ( row.runCount ? ' · ran ' + Number( row.runCount ) + '×' : '' ) + '</small>';
	}

	function actionHtml( row ) {
		const buttons = [];
		if ( row.schedulerId ) {
			buttons.push( '<button type="button" class="btn btn-sm btn-outline-primary" data-afc-row-action="adjust">Save time</button>' );
		}
		if ( ! [ 'invalid', 'duplicate' ].includes( row.status ) ) {
			buttons.push( '<button type="button" class="btn btn-sm btn-primary" data-afc-row-action="sync">' + ( row.schedulerId ? 'Sync' : 'Create' ) + '</button>' );
		}
		if ( row.schedulerId ) {
			buttons.push( '<button type="button" class="btn btn-sm btn-outline-secondary" data-afc-row-action="' + ( row.disabled ? 'enable' : 'disable' ) + '">' + ( row.disabled ? 'Enable' : 'Disable' ) + '</button>' );
			buttons.push( '<button type="button" class="btn btn-sm btn-outline-danger" data-afc-row-action="delete">Delete</button>' );
		}
		return '<div class="afc-scheduler-row-actions">' + buttons.join( '' ) + '</div>';
	}

	function rowHtml( row ) {
		return '<tr data-afc-scheduler-row data-id="' + escapeHtml( row.pppId ) + '" data-status="' + escapeHtml( row.status ) + '" data-search="' + escapeHtml( ( row.customer + ' ' + row.name ).toLowerCase() ) + '">' +
			'<td data-label="Customer / PPP"><strong>' + escapeHtml( row.customer || row.name ) + '</strong><small>' + escapeHtml( row.name ) + ( row.profile ? ' · ' + escapeHtml( row.profile ) : '' ) + '</small></td>' +
			'<td data-label="Billing source">' + billingHtml( row ) + '</td>' +
			'<td data-label="Scheduler">' + scheduleHtml( row ) + '</td>' +
			'<td data-label="Status"><span class="afc-scheduler-status is-' + escapeHtml( row.status ) + '">' + escapeHtml( statusLabel( row.status ) ) + '</span><p>' + escapeHtml( row.message || '' ) + '</p></td>' +
			'<td data-label="Actions">' + actionHtml( row ) + '</td>' +
		'</tr>';
	}

	function applyTableFilter() {
		const search = state.search.toLowerCase();
		root.querySelectorAll( '[data-afc-scheduler-row]' ).forEach( function ( row ) {
			const matchesSearch = ! search || String( row.dataset.search || '' ).includes( search );
			const matchesStatus = 'all' === state.filter || row.dataset.status === state.filter;
			row.hidden = ! ( matchesSearch && matchesStatus );
		} );
	}

	function renderAccounts() {
		const body = root.querySelector( '[data-afc-scheduler-body]' );
		if ( ! body ) {
			return;
		}
		body.innerHTML = state.rows.length
			? state.rows.map( rowHtml ).join( '' )
			: '<tr><td colspan="5" class="afc-scheduler-empty">No PPP accounts were returned.</td></tr>';
		applyTableFilter();

		let orphanBox = root.querySelector( '[data-afc-scheduler-orphans]' );
		if ( ! orphanBox ) {
			orphanBox = document.createElement( 'div' );
			orphanBox.setAttribute( 'data-afc-scheduler-orphans', '' );
			orphanBox.className = 'afc-scheduler-orphans';
			const wrap = root.querySelector( '.afc-scheduler-table-wrap' );
			if ( wrap ) {
				wrap.insertAdjacentElement( 'afterend', orphanBox );
			}
		}
		if ( orphanBox ) {
			orphanBox.innerHTML = state.orphans.length
				? '<strong>' + state.orphans.length + ' managed scheduler' + ( 1 === state.orphans.length ? '' : 's' ) + ' no longer match a PPP user.</strong><span>' + state.orphans.map( function ( item ) { return escapeHtml( item.name ); } ).join( ', ' ) + '</span>'
				: '';
			orphanBox.hidden = ! state.orphans.length;
		}
	}

	function bulkCardHtml( row ) {
		const checked = state.selected.has( row.pppId );
		const selectable = row.selectable;
		return '<article class="afc-scheduler-bulk-card is-' + escapeHtml( row.status ) + '" data-afc-scheduler-bulk-row data-id="' + escapeHtml( row.pppId ) + '">' +
			'<label><input type="checkbox" data-afc-scheduler-check ' + ( selectable ? '' : 'disabled ' ) + ( checked ? 'checked ' : '' ) + '><span><strong>' + escapeHtml( row.customer || row.name ) + '</strong><small>' + escapeHtml( row.name ) + '</small></span></label>' +
			'<div><span class="afc-scheduler-status is-' + escapeHtml( row.status ) + '">' + escapeHtml( statusLabel( row.status ) ) + '</span><strong>' + escapeHtml( row.expectedDate || row.cutoffDate || 'No cutoff' ) + ' ' + escapeHtml( row.expectedTime || '' ) + '</strong><p>' + escapeHtml( row.message || '' ) + '</p></div>' +
		'</article>';
	}

	function renderBulk() {
		const list = root.querySelector( '[data-afc-scheduler-bulk-list]' );
		if ( ! list ) {
			return;
		}
		list.innerHTML = state.rows.length
			? state.rows.map( bulkCardHtml ).join( '' )
			: '<p>Read MikroTik schedulers first.</p>';
		updateBulkControls();
	}

	function updateBulkControls() {
		if ( ! root ) {
			return;
		}
		const count = state.selected.size;
		const countLabel = root.querySelector( '[data-afc-scheduler-selected-count]' );
		if ( countLabel ) {
			countLabel.textContent = count + ' selected';
		}
		root.querySelectorAll( '[data-afc-scheduler-bulk]' ).forEach( function ( button ) {
			button.disabled = state.busy || 0 === count;
		} );
		const hasOverdue = state.rows.some( function ( row ) { return state.selected.has( row.pppId ) && 'overdue' === row.status; } );
		const box = root.querySelector( '[data-afc-scheduler-overdue-confirm]' );
		if ( box ) {
			box.hidden = ! hasOverdue;
			if ( ! hasOverdue ) {
				const checkbox = box.querySelector( '[data-afc-scheduler-allow-overdue]' );
				if ( checkbox ) {
					checkbox.checked = false;
				}
			}
		}
	}

	function renderLastSync( lastSync ) {
		const box = root.querySelector( '[data-afc-scheduler-last-sync]' );
		if ( ! box ) {
			return;
		}
		if ( ! lastSync || ! lastSync.time ) {
			box.hidden = true;
			return;
		}
		box.hidden = false;
		box.className = 'afc-scheduler-last-sync is-' + escapeHtml( lastSync.status || 'info' );
		box.innerHTML = '<strong>Last automatic sync</strong><span>' + escapeHtml( lastSync.time ) + ' · ' + escapeHtml( lastSync.source || '' ) + ' · ' + escapeHtml( lastSync.message || '' ) + '</span>';
	}

	function readSchedulers( openAccounts ) {
		if ( state.busy ) {
			return;
		}
		setBusy( true, 'Reading PPP users and MikroTik schedulers…' );
		request( 'afc_scheduler_preview' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showNotice( response && response.data && response.data.message ? response.data.message : 'Schedulers could not be read.', 'error' );
					return;
				}
				state.rows = response.data.rows || [];
				state.counts = response.data.counts || {};
				state.orphans = response.data.orphans || [];
				state.selected = new Set( state.rows.filter( function ( row ) { return row.selectedByDefault; } ).map( function ( row ) { return row.pppId; } ) );
				state.loaded = true;
				renderSummary();
				renderAccounts();
				renderBulk();
				renderLastSync( response.data.lastSync || {} );
				showNotice( 'Read ' + state.rows.length + ' PPP users. ' + Number( state.counts.healthy || 0 ) + ' schedulers are healthy.', 'success' );
				if ( openAccounts ) {
					activateView( 'accounts', true );
				}
			} )
			.fail( function ( xhr ) {
				showNotice( responseMessage( xhr, 'Schedulers could not be read.' ), 'error' );
			} )
			.always( function () {
				setBusy( false );
			} );
	}

	function rowById( id ) {
		return state.rows.find( function ( row ) { return String( row.pppId ) === String( id ); } );
	}

	async function applyOperation( ids, operation, allowOverdue ) {
		const batchSize = Number( afcSchedulers.batchSize || 20 );
		let updated = 0;
		let failed = 0;
		const progress = root.querySelector( '[data-afc-scheduler-progress]' );
		if ( progress ) {
			progress.hidden = false;
		}

		for ( let offset = 0; offset < ids.length; offset += batchSize ) {
			const batch = ids.slice( offset, offset + batchSize );
			const done = Math.min( offset + batch.length, ids.length );
			if ( progress ) {
				progress.querySelector( '[data-afc-scheduler-progress-label]' ).textContent = operation + ' schedulers';
				progress.querySelector( '[data-afc-scheduler-progress-count]' ).textContent = done + ' / ' + ids.length;
				progress.querySelector( '[data-afc-scheduler-progress-bar]' ).style.width = ( done / ids.length * 100 ) + '%';
			}
			try {
				const response = await request( 'afc_scheduler_apply', {
					ids: batch,
					operation: operation,
					allow_overdue: allowOverdue ? 1 : 0,
				} );
				if ( ! response || ! response.success ) {
					throw new Error( 'Batch failed' );
				}
				updated += ( response.data.updated || [] ).length;
				failed += ( response.data.failed || [] ).length;
			} catch ( error ) {
				failed += batch.length;
			}
		}

		showNotice( 'Completed ' + updated + ' scheduler action' + ( 1 === updated ? '' : 's' ) + '. Failed ' + failed + '.', failed ? 'warning' : 'success' );
		window.setTimeout( function () { readSchedulers( false ); }, 400 );
	}

	function runBulk( operation ) {
		if ( state.busy || ! state.selected.size ) {
			return;
		}
		const ids = Array.from( state.selected );
		const hasOverdue = state.rows.some( function ( row ) { return state.selected.has( row.pppId ) && 'overdue' === row.status; } );
		const allowBox = root.querySelector( '[data-afc-scheduler-allow-overdue]' );
		const allowOverdue = Boolean( allowBox && allowBox.checked );
		if ( 'sync' === operation && hasOverdue && ! allowOverdue ) {
			showNotice( 'Past-cutoff accounts are selected. Confirm the immediate-cutoff warning first.', 'warning' );
			return;
		}
		if ( 'delete' === operation && ! window.confirm( 'Delete the selected MikroTik schedulers? PPP users will not be deleted.' ) ) {
			return;
		}
		setBusy( true, 'Running scheduler actions…' );
		applyOperation( ids, operation, allowOverdue ).finally( function () {
			setBusy( false );
		} );
	}

	function runRowOperation( tr, operation ) {
		const id = tr.dataset.id;
		const row = rowById( id );
		if ( ! row || state.busy ) {
			return;
		}
		if ( 'adjust' === operation ) {
			const date = tr.querySelector( '[data-afc-scheduler-date]' );
			const time = tr.querySelector( '[data-afc-scheduler-time]' );
			setBusy( true, 'Updating scheduler date and time…' );
			request( 'afc_scheduler_adjust', {
				scheduler_id: row.schedulerId,
				date: date ? date.value : '',
				time: time ? time.value : '',
			} ).done( function ( response ) {
				showNotice( response && response.success ? response.data.message : 'Scheduler could not be adjusted.', response && response.success ? 'success' : 'error' );
			} ).fail( function ( xhr ) {
				showNotice( responseMessage( xhr, 'Scheduler could not be adjusted.' ), 'error' );
			} ).always( function () {
				setBusy( false );
				window.setTimeout( function () { readSchedulers( false ); }, 300 );
			} );
			return;
		}

		if ( 'delete' === operation && ! window.confirm( 'Delete the scheduler for ' + row.name + '? The PPP user will remain.' ) ) {
			return;
		}
		let allowOverdue = false;
		if ( 'sync' === operation && 'overdue' === row.status ) {
			allowOverdue = window.confirm( row.name + ' is past cutoff. Schedule the managed cutoff to run in about 2 minutes?' );
			if ( ! allowOverdue ) {
				return;
			}
		}
		setBusy( true, 'Updating ' + row.name + '…' );
		applyOperation( [ row.pppId ], operation, allowOverdue ).finally( function () {
			setBusy( false );
		} );
	}

	function saveSettings( form ) {
		if ( state.busy ) {
			return;
		}
		const data = new FormData( form );
		setBusy( true, 'Saving scheduler settings…' );
		request( 'afc_scheduler_save_settings', {
			base_time: data.get( 'base_time' ) || '',
			stagger_seconds: data.get( 'stagger_seconds' ) || 0,
			expired_profile: data.get( 'expired_profile' ) || '',
			auto_sync_payments: data.get( 'auto_sync_payments' ) ? 1 : 0,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				showNotice( response.data.message, 'success' );
			} else {
				showNotice( 'Scheduler settings could not be saved.', 'error' );
			}
		} ).fail( function ( xhr ) {
			showNotice( responseMessage( xhr, 'Scheduler settings could not be saved.' ), 'error' );
		} ).always( function () {
			setBusy( false );
		} );
	}

	function bindEvents() {
		root.addEventListener( 'click', function ( event ) {
			const view = event.target.closest( '[data-afc-scheduler-view]' );
			if ( view ) {
				activateView( view.dataset.afcSchedulerView, true );
				return;
			}
			const go = event.target.closest( '[data-afc-scheduler-go]' );
			if ( go ) {
				activateView( go.dataset.afcSchedulerGo, true );
				return;
			}
			const refresh = event.target.closest( '[data-afc-scheduler-refresh]' );
			if ( refresh ) {
				readSchedulers( false );
				return;
			}
			const bulk = event.target.closest( '[data-afc-scheduler-bulk]' );
			if ( bulk ) {
				runBulk( bulk.dataset.afcSchedulerBulk );
				return;
			}
			const rowAction = event.target.closest( '[data-afc-row-action]' );
			if ( rowAction ) {
				const tr = rowAction.closest( '[data-afc-scheduler-row]' );
				if ( tr ) {
					runRowOperation( tr, rowAction.dataset.afcRowAction );
				}
			}
		} );

		root.addEventListener( 'input', function ( event ) {
			if ( event.target.matches( '[data-afc-scheduler-search]' ) ) {
				state.search = event.target.value || '';
				applyTableFilter();
			}
		} );
		root.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-afc-scheduler-filter]' ) ) {
				state.filter = event.target.value || 'all';
				applyTableFilter();
				return;
			}
			if ( event.target.matches( '[data-afc-scheduler-check]' ) ) {
				const card = event.target.closest( '[data-afc-scheduler-bulk-row]' );
				if ( card ) {
					if ( event.target.checked ) {
						state.selected.add( card.dataset.id );
					} else {
						state.selected.delete( card.dataset.id );
					}
					updateBulkControls();
				}
				return;
			}
			if ( event.target.matches( '[data-afc-scheduler-select-safe]' ) ) {
				state.rows.forEach( function ( row ) {
					if ( row.selectedByDefault ) {
						if ( event.target.checked ) {
							state.selected.add( row.pppId );
						} else {
							state.selected.delete( row.pppId );
						}
					}
				} );
				renderBulk();
			}
		} );

		const settingsForm = root.querySelector( '[data-afc-scheduler-settings-form]' );
		if ( settingsForm ) {
			settingsForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				saveSettings( settingsForm );
			} );
		}

		document.addEventListener( 'afc:admin-mode-change', function ( event ) {
			const mode = event.detail && event.detail.mode ? event.detail.mode : '';
			if ( 'basic' === mode && ! root.closest( '[data-afc-panel="schedulers"]' ).hidden ) {
				const operations = document.querySelector( '[data-afc-app-panel="operations"]' );
				if ( operations ) {
					operations.click();
				}
			}
		} );
	}

	function initialize() {
		root = document.getElementById( 'afc-scheduler-center' );
		if ( ! root ) {
			return;
		}
		addMainNavigation();
		bindEvents();
		const preview = root.querySelector( '[data-afc-scheduler-script-preview]' );
		if ( preview ) {
			preview.textContent = afcSchedulers.scriptPreview || '';
		}
		let initial = 'overview';
		try {
			initial = window.sessionStorage.getItem( 'afcSchedulerView' ) || 'overview';
		} catch ( error ) {
			initial = 'overview';
		}
		activateView( initial, false );

		const observer = new MutationObserver( addMainNavigation );
		const nav = document.querySelector( '.afc-frontend-nav' );
		if ( nav ) {
			observer.observe( nav, { childList: true } );
			window.setTimeout( function () { observer.disconnect(); }, 8000 );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}( jQuery ) );
