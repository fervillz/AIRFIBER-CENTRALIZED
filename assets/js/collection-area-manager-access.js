( function ( $ ) {
	'use strict';

	$( function () {
		const printButton = document.getElementById( 'afc-print-all-due' );
		if ( ! printButton || document.getElementById( 'afc-manage-ppp-locations' ) ) {
			return;
		}

		const wrapper = document.createElement( 'div' );
		wrapper.className = 'col-sm-auto';
		wrapper.innerHTML = '<button class="btn btn-outline-primary" id="afc-manage-ppp-locations" type="button">Manage Locations</button>';
		printButton.closest( '.col-sm-auto' ).insertAdjacentElement( 'afterend', wrapper );

		$( '#afc-manage-ppp-locations' ).on( 'click', function () {
			document.dispatchEvent( new CustomEvent( 'afc:open-area-manager' ) );
		} );
	} );
}( jQuery ) );
