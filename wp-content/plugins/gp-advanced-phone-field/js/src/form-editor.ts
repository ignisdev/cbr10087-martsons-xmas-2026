const $ = window.jQuery!;

// Register field setting so Gravity Forms shows it in the sidebar.
$( function() {
	if ( window.fieldSettings.hasOwnProperty( 'phone' ) ) {
		window.fieldSettings.phone += ', .gpapf-field-setting';
	}

	bindEvents();
} );

const togglePhoneFormatNotice = ( enabled: boolean ) => {
	jQuery( '#gpapf-phone-format-notice' ).remove();

	const $phoneFormat = jQuery( '#field_phone_format' );

	if ( enabled ) {
		const $notice = jQuery( `<div id="gpapf-phone-format-notice" class="gform-accessibility-warning field_setting gform-alert gform-alert--accessibility gform-alert--inline">
					<span class="gform-icon gform-icon--password gform-alert__icon"></span>
					<div class="gform-alert__message-wrap">
						<p class="gform-alert__message" style="margin: 0;padding-top:2px;">${ window.GPAPF_EDITOR.strings?.int_format_required }</p>
					</div>
				</div>` );

		$phoneFormat.prop( 'disabled', true );
		$notice.insertAfter( $phoneFormat );

		// Change the format if it's not already international.
		if ( window.field?.phoneFormat !== 'international' ) {
			$( '#field_phone_format' ).val( 'international' );
			window.SetFieldPhoneFormat( 'international' );
		}
	} else {
		$phoneFormat.prop( 'disabled', false );
	}
};

const bindEvents = () => {
	$( '#gpapf-enable' ).on( 'change', function( this: HTMLInputElement ) {
		window.SetFieldProperty( 'gpapfEnable', this.checked );
		togglePhoneFormatNotice( this.checked );
	} );

	$( document ).on( 'gform_load_field_settings', function( event, field ) {
		const enabled = !! field.gpapfEnable;

		$( '#gpapf-enable' ).prop( 'checked', enabled );
		togglePhoneFormatNotice( enabled );
	} );
};

// Make this a module to avoid TypeScript error with block-scoped variables since we're not importing anything
export {};
