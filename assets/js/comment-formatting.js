( function ( $ ) {
	'use strict';

	if ( ! window.afcCommentFormatting ) {
		return;
	}

	let rows = [];
	let readyToApply = false;
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
		if ( ! host || document.getElementById( 'afc-fix-comment-lines' ) ) {
			return false;
		}

		const button = document.createElement( 'button' );
		button.type = 'button';
		button.id = 'afc-fix-comment-lines';
		button.className = 'btn btn-outline-primary afc-fix-comment-lines';
		button.textContent = 'Fix Comment Lines';
		host.appendChild( button );

		const notice = document.createElement( 'div' );
		notice.id = 'afc-comment-formatting-notice';
		notice.className = 'afc-comment-formatting-notice';
		const panel = document.getElementById( 'afc-comment-migration' );
		if ( panel ) {
			panel.querySelector( '.afc-comment-migration-head' ).insertAdjacentElement( 'afterend', notice );
		} else {
			host.insertAdjacentElement( 'afterend', notice );
		}

		button.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			if ( readyToApply ) {
				applyFormatting( button, notice );
			} else {
				previewFormatting( button, notice );
			}
		} );
		return true;
	}

	function showNotice( notice, message, type ) {
		notice.className = 'afc-comment-formatting-notice' + ( message ? ' is-visible is-' + ( type || 'info' ) : '' );
		notice.textContent = message || '';
	}

	function previewFormatting( button, notice ) {
		busy = true;
		button.disabled = true;
		button.textContent = 'Checking comments…';
		showNotice( notice, '', '' );

		request( 'afc_preview_comment_formatting' )
			.done( function ( response ) {
				if ( ! response || ! response.success ) {
					showNotice( notice, response && response.data && response.data.message ? response.data.message : 'Comments could not be checked.', 'error' );
					return;
				}
				rows = response.data.rows || [];
				if ( ! rows.length ) {
					readyToApply = false;
					button.textContent = 'Fix Comment Lines';
					showNotice( notice, 'All PPP comments already use one field per line.', 'success' );
					return;
				}
				readyToApply = true;
				button.textContent = 'Apply Line Breaks to ' + rows.length + ' Users';
				button.classList.remove( 'btn-outline-primary' );
				button.classList.add( 'btn-warning' );
				showNotice( notice, rows.length + ' PPP comments are compact. Click the button again to format them. Values will not change.', 'warning' );
			} )
			.fail( function ( xhr ) {
				showNotice( notice, xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Comments could not be checked.', 'error' );
			} )
			.always( function () {
				busy = false;
				button.disabled = false;
				if ( ! readyToApply && 'Checking comments…' === button.textContent ) {
					button.textContent = 'Fix Comment Lines';
				}
			} );
	}

	async function applyFormatting( button, notice ) {
		busy = true;
		button.disabled = true;
		const ids = rows.map( function ( row ) { return row.id; } );
		const batchSize = Number( afcCommentFormatting.batchSize || 20 );
		let updated = 0;
		let skipped = 0;
		let failed = 0;

		for ( let offset = 0; offset < ids.length; offset += batchSize ) {
			const batch = ids.slice( offset, offset + batchSize );
			button.textContent = 'Formatting ' + Math.min( offset + batch.length, ids.length ) + ' / ' + ids.length;
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
		readyToApply = false;
		busy = false;
		button.disabled = false;
		button.classList.remove( 'btn-warning' );
		button.classList.add( 'btn-outline-primary' );
		button.textContent = 'Fix Comment Lines';
		showNotice( notice, 'Formatted ' + updated + ' comments. Skipped ' + skipped + '. Failed ' + failed + '.', failed ? 'warning' : 'success' );

		const migrationPreview = document.querySelector( '[data-afc-migration-preview]' );
		if ( migrationPreview ) {
			window.setTimeout( function () { migrationPreview.click(); }, 400 );
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
