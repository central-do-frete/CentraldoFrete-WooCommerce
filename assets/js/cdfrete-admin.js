( function ( $ ) {
	'use strict';

	$( function () {
		var $button = $( '#cdfrete-refresh-cargo-types' );

		if ( ! $button.length ) {
			return;
		}

		var $status = $( '#cdfrete-cargo-types-status' );
		var params  = window.cdfreteAdminParams || {};

		$button.on( 'click', function () {
			$button.prop( 'disabled', true ).text( params.loading );
			$status.text( '' );

			$.post( params.ajaxUrl, {
				action: 'cdfrete_refresh_cargo_types',
				nonce: params.nonce,
				instance_id: $button.data( 'instance-id' )
			} )
				.done( function ( response ) {
					$button.prop( 'disabled', false ).text( params.buttonLabel );

					if ( response && response.success ) {
						$status.text( response.data.message );
						window.location.reload();
						return;
					}

					$status.text(
						( response && response.data && response.data.message ) || params.error
					);
				} )
				.fail( function () {
					$button.prop( 'disabled', false ).text( params.buttonLabel );
					$status.text( params.connectionError );
				} );
		} );
	} );
}( jQuery ) );
