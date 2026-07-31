( function ( $ ) {
	'use strict';

	if ( ! window.afcCommentMigration ) {
		return;
	}

	const state = {
		rows: [],
		counts: { safe: 0, review: 0, complete: 0 },
		selected: new Set(),
		filter: 'all',
		busy: false,
	};

	let root = null;

	function escapeHtml( value ) {
		return $( '<div>' ).text( null == value ? '' : String( value ) ).html();
	}

	function request( action, data ) {
		return $.ajax( {
			url: afcCommentMigration.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			traditional: true,
			data: Object.assign( {
				action: action,
				nonce: afcCommentMigration.nonce,
			}, data || {} ),
		} );
	}

	function responseMessage( response, fallback ) {
		return response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message
			? response.responseJSON.data.message
			: fallback;
	}

	function createMarkup() {
		return '' +
			'<section class="afc-comment-migration" id="afc-comment-migration">' +
				'<header class="afc-comment-migration-head">' +
					'<div>' +
						'<span class="afc-comment-fields-kicker">Safe MikroTik migration</span>' +
						'<h2>Apply Billing Fields to PPP Users</h2>' +
						'<p>Preview the calculations first. Airfiber adds only missing values and never overwrites an existing billing field.</p>' +
					'</div>' +
					'<div class="afc-comment-migration-head-actions">' +
						'<button type="button" class="btn btn-primary" data-afc-migration-preview>Preview PPP Updates</button>' +
						'<button type="button" class="btn btn-outline-secondary" data-afc-migration-export hidden>Export Review List</button>' +
						'<button type="button" class="btn btn-outline-secondary" data-afc-migration-clear hidden>Clear Preview</button>' +
					'</div>' +
				'</header>' +
				'<div class="afc-comment-migration-notice" data-afc-migration-notice aria-live="polite"></div>' +
				'<div class="afc-comment-migration-results" data-afc-migration-results hidden>' +
					'<div class="afc-comment-migration-summary" data-afc-migration-summary></div>' +
					'<div class="afc-comment-migration-toolbar">' +
						'<label class="afc-comment-migration-filter">Show <select data-afc-migration-filter>' +
							'<option value="all">All accounts</option>' +
							'<option value="safe">Ready to apply</option>' +
							'<option value="review">Needs review</option>' +
							'<option value="complete">Already complete</option>' +
						'</select></label>' +
						'<label class="afc-comment-migration-select-all"><input type="checkbox" data-afc-migration-select-all checked> Select all safe users</label>' +
						'<button type="button" class="btn btn-success" data-afc-migration-apply disabled>Apply to Selected Users</button>' +
					'</div>' +
					'<div class="afc-comment-migration-confirm" data-afc-migration-confirm hidden>' +
						'<div><strong data-afc-confirm-title></strong><span>Latest PPP comments will be fetched again before each update. Existing values stay unchanged.</span></div>' +
						'<div><button type="button" class="btn btn-outline-secondary" data-afc-confirm-cancel>Cancel</button><button type="button" class="btn btn-success" data-afc-confirm-apply>Confirm Apply</button></div>' +
					'</div>' +
					'<div class="afc-comment-migration-progress" data-afc-migration-progress hidden>' +
						'<div><span data-afc-progress-label></span><strong data-afc-progress-count></strong></div>' +
						'<div class="afc-comment-migration-progress-track"><span data-afc-progress-bar></span></div>' +
					'</div>' +
					'<div class="afc-comment-migration-table-wrap">' +
						'<table class="afc-comment-migration-table">' +
							'<thead><tr><th class="afc-migration-check"></th><th>Customer / PPP</th><th>Source</th><th>Billing fields after apply</th><th>Status</th></tr></thead>' +
							'<tbody data-afc-migration-body></tbody>' +
						'</table>' +
					'</div>' +
				'</div>' +
			'</section>';
	}

	function initialize() {
		const shell = document.querySelector( '.afc-comment-fields-shell' );
		if ( ! shell || document.getElementById( 'afc-comment-migration' ) ) {
			return false;
		}

		shell.insertAdjacentHTML( 'beforeend', createMarkup() );
		root = document.getElementById( 'afc-comment-migration' );
		bindEvents();
		return true;
	}

	function setNotice( message, type ) {
		const notice = root && root.querySelector( '[data-afc-migration-notice]' );
		if ( ! notice ) {
			return;
		}
		notice.className = 'afc-comment-migration-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function setBusy( busy, label ) {
		state.busy = busy;
		root.classList.toggle( 'is-busy', busy );
		root.querySelectorAll( 'button, select, input' ).forEach( function ( control ) {
			if ( busy ) {
				control.dataset.afcMigrationWasDisabled = control.disabled ? '1' : '0';
				control.disabled = true;
			} else if ( Object.prototype.hasOwnProperty.call( control.dataset, 'afcMigrationWasDisabled' ) ) {
				control.disabled = '1' === control.dataset.afcMigrationWasDisabled;
				delete control.dataset.afcMigrationWasDisabled;
			}
		} );
		const preview = root.querySelector( '[data-afc-migration-preview]' );
		if ( preview ) {
			preview.textContent = busy && label ? label : 'Preview PPP Updates';
		}
		if ( ! busy ) {
			updateApplyButton();
			const selectAll = root.querySelector( '[data-afc-migration-select-all]' );
			if ( selectAll ) {
				selectAll.disabled = ! state.rows.some( function ( row ) { return 'safe' === row.status; } );
			}
		}
	}

	function statusLabel( status ) {
		return {
			safe: 'Ready',
			review: 'Needs review',
			complete: 'Complete',
			updated: 'Updated',
			failed: 'Failed',
		}[ status ] || status;
	}

	function sourceHtml( row ) {
		return '<div class="afc-migration-source">' +
			'<span><small>Installed</small><strong>' + escapeHtml( row.installed || 'Missing' ) + '</strong></span>' +
			'<span><small>Payment</small><strong>' + escapeHtml( row.paymentDate || 'Missing' ) + '</strong></span>' +
			'<span><small>Grace</small><strong>' + escapeHtml( '' === row.grace ? '6 default' : row.grace ) + '</strong></span>' +
		'</div>';
	}

	function fieldsHtml( row ) {
		const values = row.calculated || {};
		return '<div class="afc-migration-fields">' +
			'<code>billingDay:<b>' + escapeHtml( values.billingDay || '—' ) + '</b></code>' +
			'<code>paidThrough:<b>' + escapeHtml( values.paidThrough || '—' ) + '</b></code>' +
			'<code>nextDue:<b>' + escapeHtml( values.nextDue || '—' ) + '</b></code>' +
			'<code>cutoffDate:<b>' + escapeHtml( values.cutoffDate || '—' ) + '</b></code>' +
		'</div>';
	}

	function rowHtml( row ) {
		const selectable = 'safe' === row.status;
		const checked = selectable && state.selected.has( row.id );
		return '<tr data-afc-migration-row data-id="' + escapeHtml( row.id ) + '" data-status="' + escapeHtml( row.status ) + '">' +
			'<td class="afc-migration-check" data-label="Select"><input type="checkbox" data-afc-migration-row-check ' + ( selectable ? '' : 'disabled ' ) + ( checked ? 'checked ' : '' ) + 'aria-label="Select ' + escapeHtml( row.name ) + '"></td>' +
			'<td data-label="Customer / PPP"><strong class="afc-migration-customer">' + escapeHtml( row.customer || row.name ) + '</strong><small>' + escapeHtml( row.name ) + ( row.profile ? ' · ' + escapeHtml( row.profile ) : '' ) + '</small></td>' +
			'<td data-label="Source">' + sourceHtml( row ) + '</td>' +
			'<td data-label="Billing fields">' + fieldsHtml( row ) + '</td>' +
			'<td data-label="Status"><span class="afc-migration-status is-' + escapeHtml( row.status ) + '">' + escapeHtml( statusLabel( row.status ) ) + '</span><p>' + escapeHtml( row.message ) + '</p></td>' +
		'</tr>';
	}

	function renderSummary() {
		const summary = root.querySelector( '[data-afc-migration-summary]' );
		summary.innerHTML = '' +
			'<div><strong>' + Number( state.rows.length ) + '</strong><span>Total PPP users</span></div>' +
			'<div class="is-safe"><strong>' + Number( state.counts.safe || 0 ) + '</strong><span>Ready to apply</span></div>' +
			'<div class="is-review"><strong>' + Number( state.counts.review || 0 ) + '</strong><span>Needs review</span></div>' +
			'<div class="is-complete"><strong>' + Number( state.counts.complete || 0 ) + '</strong><span>Already complete</span></div>';
	}

	function renderRows() {
		const body = root.querySelector( '[data-afc-migration-body]' );
		body.innerHTML = state.rows.map( rowHtml ).join( '' );
		applyFilter();
		updateApplyButton();
	}

	function applyFilter() {
		root.querySelectorAll( '[data-afc-migration-row]' ).forEach( function ( tr ) {
			tr.hidden = 'all' !== state.filter && tr.dataset.status !== state.filter;
		} );
	}

	function updateApplyButton() {
		const button = root && root.querySelector( '[data-afc-migration-apply]' );
		if ( ! button ) {
			return;
		}
		const count = state.selected.size;
		button.disabled = state.busy || 0 === count;
		button.textContent = count ? 'Apply to ' + count + ' Selected User' + ( 1 === count ? '' : 's' ) : 'Apply to Selected Users';
	}

	function renderPreview() {
		root.querySelector( '[data-afc-migration-results]' ).hidden = false;
		root.querySelector( '[data-afc-migration-export]' ).hidden = !( state.counts.review > 0 );
		root.querySelector( '[data-afc-migration-clear]' ).hidden = false;
		root.querySelector( '[data-afc-migration-select-all]' ).checked = true;
		renderSummary();
		renderRows();
	}

	function preview( options ) {
		options = options || {};
		setBusy( true, afcCommentMigration.labels.previewing );
		if ( ! options.keepNotice ) {
			setNotice( '', '' );
		}
		return request( 'afc_preview_comment_migration' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					setNotice( response && response.data && response.data.message ? response.data.message : afcCommentMigration.labels.failed, 'error' );
					return;
				}
				state.rows = response.data.rows || [];
				state.counts = response.data.counts || { safe: 0, review: 0, complete: 0 };
				state.selected = new Set( state.rows.filter( function ( row ) { return 'safe' === row.status; } ).map( function ( row ) { return row.id; } ) );
				renderPreview();
			} )
			.fail( function ( xhr ) {
				setNotice( responseMessage( xhr, afcCommentMigration.labels.failed ), 'error' );
			} )
			.always( function () {
				setBusy( false );
			} );
	}

	function clearPreview() {
		state.rows = [];
		state.selected.clear();
		state.counts = { safe: 0, review: 0, complete: 0 };
		root.querySelector( '[data-afc-migration-results]' ).hidden = true;
		root.querySelector( '[data-afc-migration-export]' ).hidden = true;
		root.querySelector( '[data-afc-migration-clear]' ).hidden = true;
		root.querySelector( '[data-afc-migration-confirm]' ).hidden = true;
		setNotice( '', '' );
	}

	function toggleAllSafe( checked ) {
		state.rows.forEach( function ( row ) {
			if ( 'safe' !== row.status ) {
				return;
			}
			if ( checked ) {
				state.selected.add( row.id );
			} else {
				state.selected.delete( row.id );
			}
		} );
		renderRows();
	}

	function showConfirmation() {
		if ( ! state.selected.size ) {
			setNotice( afcCommentMigration.labels.noSelection, 'warning' );
			return;
		}
		const box = root.querySelector( '[data-afc-migration-confirm]' );
		box.hidden = false;
		box.querySelector( '[data-afc-confirm-title]' ).textContent = 'Apply missing billing fields to ' + state.selected.size + ' PPP account' + ( 1 === state.selected.size ? '?' : 's?' );
		box.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
	}

	function setProgress( done, total, label ) {
		const progress = root.querySelector( '[data-afc-migration-progress]' );
		progress.hidden = false;
		progress.querySelector( '[data-afc-progress-label]' ).textContent = label || afcCommentMigration.labels.applying;
		progress.querySelector( '[data-afc-progress-count]' ).textContent = done + ' / ' + total;
		progress.querySelector( '[data-afc-progress-bar]' ).style.width = ( total ? Math.round( done / total * 100 ) : 0 ) + '%';
	}

	async function applySelected() {
		const ids = Array.from( state.selected );
		if ( ! ids.length ) {
			setNotice( afcCommentMigration.labels.noSelection, 'warning' );
			return;
		}

		root.querySelector( '[data-afc-migration-confirm]' ).hidden = true;
		setBusy( true, afcCommentMigration.labels.applying );
		setNotice( '', '' );
		let done = 0;
		let updated = 0;
		let skipped = 0;
		let failed = 0;
		const batchSize = Math.max( 1, Number( afcCommentMigration.batchSize || 20 ) );
		setProgress( 0, ids.length );

		for ( let offset = 0; offset < ids.length; offset += batchSize ) {
			const batch = ids.slice( offset, offset + batchSize );
			try {
				const response = await request( 'afc_apply_comment_migration', { ids: batch } );
				if ( ! response || ! response.success ) {
					throw new Error( response && response.data && response.data.message ? response.data.message : afcCommentMigration.labels.failed );
				}
				updated += ( response.data.updated || [] ).length;
				skipped += ( response.data.skipped || [] ).length;
				failed += ( response.data.failed || [] ).length;
			} catch ( error ) {
				failed += batch.length;
				setNotice( error && error.message ? error.message : afcCommentMigration.labels.failed, 'error' );
			}
			done += batch.length;
			setProgress( done, ids.length, 'Updating batch ' + Math.ceil( done / batchSize ) + ' of ' + Math.ceil( ids.length / batchSize ) );
		}

		setBusy( false );
		setNotice( 'Updated ' + updated + ' account' + ( 1 === updated ? '' : 's' ) + '. Skipped ' + skipped + '. Failed ' + failed + '.', failed ? 'warning' : 'success' );
		window.setTimeout( function () {
			preview( { keepNotice: true } );
		}, 450 );
	}

	function exportReview() {
		const rows = state.rows.filter( function ( row ) { return 'review' === row.status; } );
		if ( ! rows.length ) {
			return;
		}
		const csv = [ [ 'Customer', 'PPP Account', 'Installed', 'Payment Date', 'Grace', 'Reason' ] ].concat(
			rows.map( function ( row ) {
				return [ row.customer, row.name, row.installed, row.paymentDate, row.grace, row.message ];
			} )
		).map( function ( columns ) {
			return columns.map( function ( value ) {
				return '"' + String( value || '' ).replace( /"/g, '""' ) + '"';
			} ).join( ',' );
		} ).join( '\r\n' );
		const blob = new Blob( [ '\ufeff' + csv ], { type: 'text/csv;charset=utf-8' } );
		const link = document.createElement( 'a' );
		const objectUrl = URL.createObjectURL( blob );
		link.href = objectUrl;
		link.download = 'airfiber-ppp-billing-review.csv';
		document.body.appendChild( link );
		link.click();
		link.remove();
		window.setTimeout( function () { URL.revokeObjectURL( objectUrl ); }, 1000 );
	}

	function bindEvents() {
		root.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-migration-preview]' ) ) {
				preview();
				return;
			}
			if ( event.target.closest( '[data-afc-migration-clear]' ) ) {
				clearPreview();
				return;
			}
			if ( event.target.closest( '[data-afc-migration-export]' ) ) {
				exportReview();
				return;
			}
			if ( event.target.closest( '[data-afc-migration-apply]' ) ) {
				showConfirmation();
				return;
			}
			if ( event.target.closest( '[data-afc-confirm-cancel]' ) ) {
				root.querySelector( '[data-afc-migration-confirm]' ).hidden = true;
				return;
			}
			if ( event.target.closest( '[data-afc-confirm-apply]' ) ) {
				applySelected();
			}
		} );

		root.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-afc-migration-filter]' ) ) {
				state.filter = event.target.value;
				applyFilter();
				return;
			}
			if ( event.target.matches( '[data-afc-migration-select-all]' ) ) {
				toggleAllSafe( event.target.checked );
				return;
			}
			if ( event.target.matches( '[data-afc-migration-row-check]' ) ) {
				const tr = event.target.closest( '[data-afc-migration-row]' );
				if ( ! tr ) {
					return;
				}
				if ( event.target.checked ) {
					state.selected.add( tr.dataset.id );
				} else {
					state.selected.delete( tr.dataset.id );
				}
				const safeIds = state.rows.filter( function ( row ) { return 'safe' === row.status; } ).map( function ( row ) { return row.id; } );
				root.querySelector( '[data-afc-migration-select-all]' ).checked = safeIds.length > 0 && safeIds.every( function ( id ) { return state.selected.has( id ); } );
				updateApplyButton();
			}
		} );
	}

	function boot() {
		if ( initialize() ) {
			return;
		}
		const observer = new MutationObserver( function () {
			if ( initialize() ) {
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
