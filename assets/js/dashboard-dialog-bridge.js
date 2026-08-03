( function () {
	'use strict';

	const dialogIds = [
		'afc-payment-dialog',
		'afc-ppp-create-dialog',
		'afc-ppp-manage-dialog',
	];

	/**
	 * The Operations panel is hidden while the Advanced dashboard is open.
	 * Native dialogs left inside that hidden panel can still create a modal
	 * backdrop, but the dialog itself remains invisible. That makes the entire
	 * page appear frozen. Keep shared dialogs directly under <body> so they can
	 * be opened safely from either Dashboard or Operations.
	 */
	function promoteDialog( dialog ) {
		if ( ! dialog || dialog.parentNode === document.body ) return;
		document.body.appendChild( dialog );
	}

	function promoteSharedDialogs() {
		dialogIds.forEach( function ( id ) {
			promoteDialog( document.getElementById( id ) );
		} );
	}

	function recoverInvisibleModal() {
		dialogIds.forEach( function ( id ) {
			const dialog = document.getElementById( id );
			if ( ! dialog || ! dialog.open ) return;
			promoteDialog( dialog );

			// A modal should always have a visible box. If another legacy rule still
			// hides it, close it rather than leaving an invisible backdrop over the app.
			window.requestAnimationFrame( function () {
				if ( dialog.open && dialog.getClientRects().length === 0 ) {
					dialog.close();
				}
			} );
		} );
	}

	function initialize() {
		promoteSharedDialogs();

		// Some modules render or re-render their dialog markup after initial boot.
		// Re-promote only matching dialog nodes; this observer does not touch cards,
		// forms, tables, or the dashboard layout.
		const observer = new MutationObserver( function ( mutations ) {
			let shouldCheck = false;
			mutations.forEach( function ( mutation ) {
				mutation.addedNodes.forEach( function ( node ) {
					if ( node.nodeType !== 1 ) return;
					if ( dialogIds.includes( node.id ) || ( node.querySelector && dialogIds.some( function ( id ) { return node.querySelector( '#' + id ); } ) ) ) {
						shouldCheck = true;
					}
				} );
			} );
			if ( shouldCheck ) promoteSharedDialogs();
		} );
		observer.observe( document.body, { childList: true, subtree: true } );

		// Promote before dashboard actions reach the existing PPP/payment handlers.
		document.addEventListener( 'pointerdown', function ( event ) {
			if ( event.target.closest( '[data-afc-dashboard-add-ppp], [data-afc-dashboard-payment-account]' ) ) {
				promoteSharedDialogs();
			}
		}, true );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-dashboard-add-ppp], [data-afc-dashboard-payment-account]' ) ) {
				promoteSharedDialogs();
				window.setTimeout( recoverInvisibleModal, 80 );
				window.setTimeout( recoverInvisibleModal, 700 );
			}
		}, true );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initialize );
	} else {
		initialize();
	}
}() );