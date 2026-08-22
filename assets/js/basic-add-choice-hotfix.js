( function () {
	'use strict';

	function mode() {
		const shell = document.getElementById( 'afc-frontend-app' );
		if ( shell && shell.dataset.afcMode ) return shell.dataset.afcMode;
		return document.body.classList.contains( 'afc-admin-mode-advanced' ) ? 'advanced' : 'basic';
	}

	function openChooser() {
		const dialog = document.getElementById( 'afc-basic-add-choice-dialog' );
		if ( dialog && ! dialog.open ) {
			dialog.showModal();
			return true;
		}
		return false;
	}

	window.addEventListener( 'click', function ( event ) {
		if ( 'basic' !== mode() || ! event.isTrusted ) return;
		const trigger = event.target.closest && event.target.closest(
			'#afc-add-ppp-account, #afc-basic-add-ppp, [data-afc-dashboard-add-ppp]'
		);
		if ( ! trigger ) return;

		/* Capture on window so this runs before older document-level PPP handlers. */
		if ( openChooser() ) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	}, true );
}() );
