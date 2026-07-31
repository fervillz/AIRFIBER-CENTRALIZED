( function ( $ ) {
	'use strict';

	if ( ! window.afcCommentFormatting ) {
		return;
	}

	let rows = [];
	let busy = false;

	function request( action, data ) {
		return $.ajax( {
			url: afcCommentFormatting.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			traditional: false,
			data: Object.assign( {
				action: action,
				nonce: afcCommentFormatting.nonce,
			}, data || {} ),
		} );
	}

	function setup() {
		const host = document.querySelector( '.afc-comment-migration-head-actions' ) || document.querySelector( '.afc-comment-fields-header' );
		if ( ! host || document.getElementById( 'afc-recheck-comment-lines' ) ) {
			return false;
		}

		const actions = document.createElement( 'div' );
		actions.className = 'afc-comment-formatting-actions';

		const recheck = document.createElement( 'button' );
		recheck.type = 'button';
		recheck.id = 'afc-recheck-comment-lines';
		recheck.className = 'btn btn-outline-primary';
		recheck.textContent = 'Recheck Comments';

		const fix = document.createElement( 'button' );
		fix.type = 'button';
		fix.id = 'afc-fix-comment-lines';
		fix.className = 'btn btn-warning afc-fix-comment-lines';
		fix.textContent = 'Fix Comment Lines';
		fix.disabled = true;

		actions.appendChild( recheck );
		actions.appendChild( fix );
		host.appendChild( actions );

		const notice = document.createElement( 'div' );
		notice.id = 'afc-comment-formatting-notice';
		notice.className = 'afc-comment-formatting-notice';
		const panel = document.getElementById( 'afc-comment-migration' );
		if ( panel ) {
			panel.querySelector( '.afc-comment-migration-head' ).insertAdjacentElement( 'afterend', notice );
		} else {
			host.insertAdjacentElement( 'afterend', notice );
		}

		recheck.addEventListener( 'click', function () {
			if ( ! busy ) {
				previewFormatting( recheck, fix, notice );
			}
		} );
		fix.addEventListener( 'click', function () {
			if ( ! busy && rows.length ) {
				applyFormatting( recheck, fix, notice );
			}
		} );

		return true;
	}

	function showNotice( notice, message, type ) {
		notice.className = 'afc-comment-formatting-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function setBusy( recheck, fix, state ) {
		busy = state;
		recheck.disabled = state;
		fix.disabled = state || ! rows.length;
	}

	function resetFixButton( fix ) {
		fix.textContent = rows.length ? 'Fix ' + rows.length + ' Comments' : 'Fix Comment Lines';
		fix.disabled = busy || ! rows.length;
	}

	function previewFormatting( recheck, fix, notice, afterApply ) {
		setBusy( recheck, fix, true );
		recheck.textContent = 'Checking comments…';
		showNotice( notice, '', '' );

		return request( 'afc_preview_comment_formatting' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showNotice( notice, response && response.data && response.data.message ? response.data.message : 'Comments could not be checked.', 'error' );
					return;
				}

				rows = response.data.rows || [];
				const counts = response.data.counts || {};
				const joined = Number( counts.joined || 0 );
				const endings = Number( counts.line_endings || 0 );

				if ( ! rows.length ) {
					showNotice( notice, afterApply ? 'Recheck complete. All PPP comments now use CRLF and one field per line.' : 'All PPP comments use CRLF and one field per line.', 'success' );
					return;
				}

				const details = [];
				if ( joined ) {
					details.push( joined + ' with joined fields' );
				}
				if ( endings ) {
					details.push( endings + ' with LF/incorrect line endings' );
				}
				showNotice( notice, rows.length + ' comments need repair' + ( details.length ? ': ' + details.join( ', ' ) : '' ) + '. Click Fix Comments.', 'warning' );
			} )
			.fail( function ( xhr ) {
				rows = [];
				showNotice( notice, xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Comments could not be checked.', 'error' );
			} )
			.always( function () {
				recheck.textContent = 'Recheck Comments';
				setBusy( recheck, fix, false );
				resetFixButton( fix );
			} );
	}

	async function applyFormatting( recheck, fix, notice ) {
		setBusy( recheck, fix, true );
		const ids = rows.map( function ( row ) { return row.id; } );
		const batchSize = Number( afcCommentFormatting.batchSize || 20 );
		let updated = 0;
		let skipped = 0;
		let failed = 0;

		for ( let offset = 0; offset < ids.length; offset += batchSize ) {
			const batch = ids.slice( offset, offset + batchSize );
			fix.textContent = 'Formatting ' + Math.min( offset + batch.length, ids.length ) + ' / ' + ids.length;
			try {
				const response = await request( 'afc_apply_comment_formatting', { ids: batch } );
				if ( ! response || ! response.success ) {
					throw new Error( 'Batch failed' );
				}
				updated += Number( response.data.updated || 0 );
				skipped += Number( response.data.skipped || 0 );
				failed += ( response.data.failed || [] ).length;
			} catch ( error ) {
				failed += batch.length;
			}
		}

		rows = [];
		setBusy( recheck, fix, false );
		resetFixButton( fix );
		showNotice( notice, 'Formatted ' + updated + ' comments. Skipped ' + skipped + '. Failed ' + failed + '. Rechecking MikroTik…', failed ? 'warning' : 'info' );

		window.setTimeout( function () {
			previewFormatting( recheck, fix, notice, true );
		}, 350 );

		const migrationPreview = document.querySelector( '[data-afc-migration-preview]' );
		if ( migrationPreview ) {
			window.setTimeout( function () { migrationPreview.click(); }, 500 );
		}
	}

	function boot() {
		if ( setup() ) {
			return;
		}
		const observer = new MutationObserver( function () {
			if ( setup() ) {
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
