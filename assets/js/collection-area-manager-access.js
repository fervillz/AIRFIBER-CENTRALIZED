( function ( $ ) {
	'use strict';

	$( function () {
		const printButton = document.getElementById( 'afc-print-all-due' );
		if ( ! printButton || document.getElementById( 'afc-manage-ppp-locations' ) ) {
			return;
		}

		const wrapper = document.createElement( 'div' );
		wrapper.className = 'col-sm-auto';
		wrapper.innerHTML = '<button class="btn btn-outline-primary" id="afc-manage-ppp-locations" type="button" disabled>Manage Locations</button>';
		printButton.closest( '.col-sm-auto' ).insertAdjacentElement( 'afterend', wrapper );

		const button = $( '#afc-manage-ppp-locations' );
		button.on( 'click', function () {
			document.dispatchEvent( new CustomEvent( 'afc:open-area-manager' ) );
		} );

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( String( settings.data || '' ).includes( 'action=afc_get_ppp_users' ) && xhr.responseJSON && xhr.responseJSON.success ) {
				button.prop( 'disabled', false );
			}
		} );
	} );
}( jQuery ) );
