( function () {
	'use strict';

	const dialogIds = [
		'afc-ppp-create-dialog',
		'afc-ppp-manage-dialog',
	];

	/**
	 * The Operations panel is hidden while the Advanced dashboard is open.
	 * PPP dialogs opened from dashboard actions must live directly under body so
	 * their modal box and backdrop stay together. The payment dialog is no longer
	 * moved because the dashboard now owns a dedicated body-level payment dialog;
	 * Operations keeps its original styled payment dialog in place.
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

			window.requestAnimationFrame( function () {
				if ( dialog.open && dialog.getClientRects().length === 0 ) {
					dialog.close();
				}
			} );
		} );
	}

	function initialize() {
		promoteSharedDialogs();

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

		document.addEventListener( 'pointerdown', function ( event ) {
			if ( event.target.closest( '[data-afc-dashboard-add-ppp]' ) ) {
				promoteSharedDialogs();
			}
		}, true );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target.closest( '[data-afc-dashboard-add-ppp]' ) ) {
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