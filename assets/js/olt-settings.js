( function ( $ ) {
	'use strict';

	function toggleVersionFields() {
		const version = $( '#afc-olt-version' ).val();
		$( '.afc-olt-v2-fields' ).toggle( '2c' === version );
		$( '.afc-olt-v3-fields' ).toggle( '3' === version );
	}

	$( function () {
		const $button = $( '#afc-test-olt' );
		const $result = $( '#afc-olt-test-result' );

		toggleVersionFields();
		$( '#afc-olt-version' ).on( 'change', toggleVersionFields );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( afcOLT.testing );
			$result.html( '<div class="alert alert-info">Reading the OLT optical table&hellip;</div>' );

			$.post( afcOLT.ajaxUrl, {
				action: 'afc_test_olt',
				nonce: afcOLT.nonce
			} )
				.done( function ( response ) {
					const successful = response && response.success;
					const message = response && response.data && response.data.message
						? response.data.message
						: 'The OLT returned an unexpected response.';
					$result.html( $( '<div>', {
						class: 'alert ' + ( successful ? 'alert-success' : 'alert-danger' ),
						text: message
					} ) );
				} )
				.fail( function ( xhr ) {
					const message = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
						? xhr.responseJSON.data.message
						: 'The OLT connection test request failed.';
					$result.html( $( '<div>', { class: 'alert alert-danger', text: message } ) );
				} )
				.always( function () {
					$button.prop( 'disabled', false ).text( afcOLT.button );
				} );
		} );
	} );
}( jQuery ) );
