( function ( $ ) {
	'use strict';

	$( function () {
		const $button = $( '#afc-test-mikrotik' );
		const $result = $( '#afc-mikrotik-test-result' );

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( afcMikroTik.testing );
			$result.html( '<div class="alert alert-info">Connecting to RouterOS…</div>' );

			$.post( afcMikroTik.ajaxUrl, {
				action: 'afc_test_mikrotik',
				nonce: afcMikroTik.nonce
			} )
				.done( function ( response ) {
					const successful = response && response.success;
					const message = response && response.data && response.data.message
						? response.data.message
						: 'The router returned an unexpected response.';
					const alertClass = successful ? 'alert-success' : 'alert-danger';

					$result.html(
						$( '<div>', {
							class: 'alert ' + alertClass,
							text: message
						} )
					);
				} )
				.fail( function ( xhr ) {
					let message = 'The connection test request failed.';
					if ( xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
						message = xhr.responseJSON.data.message;
					}
					$result.html( $( '<div>', { class: 'alert alert-danger', text: message } ) );
				} )
				.always( function () {
					$button.prop( 'disabled', false ).text( afcMikroTik.button );
				} );
		} );
	} );
}( jQuery ) );

