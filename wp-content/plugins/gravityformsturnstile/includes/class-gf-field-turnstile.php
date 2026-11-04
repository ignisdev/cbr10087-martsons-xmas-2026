<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

/**
 * The Turnstile field renders a replacement for reCaptcha.
 *
 * @since 1.0
 *
 * Class GF_Field_Turnstile
 */
class GF_Field_Turnstile extends GF_Field {

	/**
	 * Field type.
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	public $type = 'turnstile';

	/**
	 * Whether there can be more than one of this field type per form.
	 *
	 * @since 1.5.1
	 *
	 * @var bool
	 */
	public $duplicatable = false;

	/**
	 * Whether the field can be used in a repeater.
	 *
	 * @since 1.5.1
	 *
	 * @var bool
	 */
	public $repeatable = false;

	/**
	 * Field is display only.
	 *
	 * @since 1.0
	 *
	 * @var bool
	 */
	public $displayOnly = true;

	/**
	 * Get field button title.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'Turnstile', 'gravityformsturnstile' );
	}

	/**
	 * Returns the field's form editor icon.
	 *
	 * This could be an icon url or a dashicons class.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_icon() {
		return gf_turnstile()->is_gravityforms_supported( '2.7.8.1' ) ? 'gform-icon--cloudflare-turnstile' : gf_turnstile()->get_base_url() . '/assets/img/cloudflare.svg';
	}

	/**
	 * Returns the field's form editor description.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_form_editor_field_description() {
		return esc_attr__( 'Protects your form from spam submissions using Cloudflare\'s Turnstile system.', 'gravityformsturnstile' );
	}

	/**
	 * Get field settings in the form editor.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_form_editor_field_settings() {
		return array(
			'error_message_setting',
			'turnstile_widget_theme_setting',
			'turnstile_widget_size_setting',
			'label_setting',
		);
	}

	/**
	 * Get form editor button.
	 *
	 * @since 1.0
	 *
	 * @return array
	 */
	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Returns the warning message to be displayed in the form editor sidebar.
	 *
	 * @since 1.1
	 *
	 * @return string
	 */
	public function get_field_sidebar_messages() {
		if ( ! empty( gf_turnstile()->get_plugin_setting( 'site_key' ) ) && ! empty( gf_turnstile()->get_plugin_setting( 'site_secret' ) ) ) {
			return '';
		}

		return array(
			'type'             => 'notice',
			'content'          => sprintf(
				'%s<div class="gform-spacing gform-spacing--top-1">%s</div>',
				__( 'Configuration Required', 'gravityformsgravityformsturnstile' ),
				// Translators: 1. Opening <a> tag with link to the Forms > Settings > Cloudflare Turnstile page. 2. closing <a> tag.
				sprintf( __( 'To use the Turnstile field, please configure your %1$sTurnstile settings%2$s.', 'gravityformsturnstile' ), '<a href="?page=gf_settings&subview=gravityformsturnstile" target="_blank">', '</a>' )
			),
			'icon_helper_text' => __( 'This field requires additional configuration', 'gravityformsturnstile' ),
		);
	}

	/**
	 * Get the field input markup.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function field_input_markup() {
		$key        = gf_turnstile()->get_plugin_setting( 'site_key' );
		$theme      = $this->turnstileWidgetTheme;
		$data_size  = $this->turnstileWidgetSize;

		if ( empty( $theme ) ) {
			$theme = gf_turnstile()->get_plugin_setting( 'theme' );
		}
		if ( empty( $data_size ) ) {
			$data_size = gf_turnstile()->get_plugin_setting( 'data-size' );
		}

		// Always output the widget container, rendering the actual widget is controlled by the JS side.
		$div = '<div class="cf-turnstile" id="cf-turnstile_' . $this->formId . '" data-js-turnstile data-response-field-name="cf-turnstile-response_' . $this->formId . '" data-theme="' . esc_attr( $theme ) . '" data-size="' . esc_attr( $data_size ) . '" data-sitekey="' . esc_attr( $key ) . '"></div>' ;

		return sprintf( "<div class='ginput_container ginput_container_turnstile'>%s</div>", $div );
	}

	/**
	 * Get field input.
	 *
	 * @since 1.0
	 *
	 * @param array      $form  The Form Object currently being processed.
	 * @param array      $value The field value. From default/dynamic population, $_POST, or a resumed incomplete submission.
	 * @param null|array $entry Null or the Entry Object currently being edited.
	 *
	 * @return string
	 */
	public function get_field_input( $form, $value = array(), $entry = null ) {
		$response = $this->field_input_markup();

		if ( $this->failed_validation ) {
			$response .= sprintf( '<div class="gfield_description validation_message gfield_validation_message">%1$s</div>', $this->validation_message );
		}

		return $response;
	}

	/**
	 * Returns the field markup; including field label, description, validation, and the form editor admin buttons.
	 *
	 * The {FIELD} placeholder will be replaced in GFFormDisplay::get_field_content with the markup returned by GF_Field::get_field_input().
	 *
	 * @since 1.0
	 *
	 * @param string|array $value                The field value. From default/dynamic population, $_POST, or a resumed incomplete submission.
	 * @param bool         $force_frontend_label Should the frontend label be displayed in the admin even if an admin label is configured.
	 * @param array        $form                 The Form Object currently being processed.
	 *
	 * @return string
	 */
	public function get_field_content( $value, $force_frontend_label, $form ) {
		$form_id             = $form['id'];
		$admin_buttons       = $this->get_admin_buttons();
		$is_entry_detail     = $this->is_entry_detail();
		$is_form_editor      = $this->is_form_editor();
		$is_admin            = $is_entry_detail || $is_form_editor || ( rgget( 'context' ) === 'edit' && ! empty( rgget( 'post_id' ) ) );
		$field_label         = $this->get_field_label( $force_frontend_label, $value );
		$field_id            = $is_admin || $form_id == 0 ? "input_{$this->id}" : 'input_' . $form_id . "_{$this->id}";
		$admin_hidden_markup = ( $this->visibility == 'hidden' ) ? $this->get_hidden_admin_markup() : '';
		$field_content       = ! $is_admin ? '{FIELD}' : sprintf( "%s%s<label class='gfield_label gform-field-label' for='%s'>%s</label><div class='ginput_container'>%s</div>", $admin_buttons, $admin_hidden_markup, $field_id, esc_html( $field_label ), $this->get_editor_field_content() );

		if ( ! $is_admin && ! gf_turnstile()->has_valid_credentials() ) {
			return '';
		}

		return $field_content;
	}

	private function get_editor_field_content() {
		$site_key    = gf_turnstile()->get_plugin_setting( 'site_key' );
		$site_secret = gf_turnstile()->get_plugin_setting( 'site_secret' );

		if ( empty( $site_key ) || empty( $site_secret ) ) {
			return '<div class="ginput_container ginput_container_addon_message ginput_container_addon_message_turnstile">
                <div class="gform-alert gform-alert--info gform-alert--theme-cosmos gform-spacing gform-spacing--bottom-0 gform-theme__disable">
                    <span
                        class="gform-icon gform-icon--information-simple gform-icon--preset-active gform-icon-preset--status-info gform-alert__icon"
                        aria-hidden="true"
                    ></span>
                    <div class="gform-alert__message-wrap">
                        <div class="gform-alert__message">
                            '. __( 'Configuration Required', 'gravityformsturnstile' ) .'
	                        <div class="gform-spacing gform-spacing--top-1">'. sprintf(
								'%s %s%s%s.',
								__( 'To use the Turnstile field, please configure your', 'gravityformsturnstile' ),
								'<a href="?page=gf_settings&subview=gravityformsturnstile" target="_blank">',
								__( 'Turnstile settings', 'gravityformsturnstile' ),
								'</a>'
							) .'</div>
                        </div>
                    </div>
                </div>
            </div>';
		}

		$theme      = empty( $this->turnstileWidgetTheme ) ? gf_turnstile()->get_plugin_setting( 'theme' ) : $this->turnstileWidgetTheme;
		$data_size  = empty( $this->turnstileWidgetSize ) ? gf_turnstile()->get_plugin_setting( 'data-size' ) : $this->turnstileWidgetSize;
		if ( $data_size === 'normal' ) {
			// Using the normal size images
			$preview = $theme === 'dark' ? 'preview-dark-normal.svg' : 'preview-light-normal.svg';
		} elseif ( $data_size === 'compact' ) {
			// Using the compact size images
			$preview = $theme === 'dark' ? 'preview-dark-compact.svg' : 'preview-light-compact.svg';
		} else {
			// When flexible is selected, use fallback images
			$preview = $theme === 'dark' ? 'preview-dark.svg' : 'preview-light.svg';
		}

		return sprintf( "<img class='gfield--turnstile-preview' style='border: none; padding: 0;' src='%s' />", gf_turnstile()->get_base_url() . "/assets/img/{$preview}" );
	}

	/**
	 * Get the correct value to evaluate on submission.
	 *
	 * @since 1.0
	 *
	 * @param array $field_values             The current field values.
	 * @param bool  $get_from_post_global_var Whether to draw value from POST
	 *
	 * @return mixed
	 */
	public function get_value_submission( $field_values, $get_from_post_global_var = true ) {
		return rgpost( 'cf-turnstile-response_' . $this->formId );
	}

	/**
	 * Validate the Turnstile field value.
	 *
	 * @since 1.0
	 *
	 * @param string $value The current value.
	 * @param array  $form  The form being evaluated.
	 *
	 * @return void
	 */
	public function validate( $value, $form ) {

		// Don't validate until the form is submitted, or the validation will happen twice and fail as a duplicate.
		if ( rgpost( 'action' ) === 'gfcf_validate_field' ) {
			return;
		}

		if ( ! gf_turnstile()->has_valid_credentials() ) {
			gf_turnstile()->log_debug( __METHOD__ . '(): Invalid credentials detected. Not running validation.' );
			return;
		}

		gf_turnstile()->log_debug( __METHOD__ . '(): Beginning Turnstile field validation with value: ' . $value );

		$has_pages   = \GFCommon::has_pages( $form );
		$target_page = rgpost( 'gform_target_page_number_' . $form['id'] );

		if ( gf_turnstile()->form_has_errors( $form ) ) {
			gf_turnstile()->log_debug( __METHOD__ . '(): Form failed validation, postpone Turnstile challenge.' );
			return;
		}

		if ( $has_pages && (int) $target_page !== 0 && ! empty( $value ) ) {
			gf_turnstile()->log_debug( __METHOD__ . '(): Turnstile field submitted as part of pagination request, deferring until submission.' );
			return;
		}

		if ( empty( $value ) ) {
			gf_turnstile()->log_debug( __METHOD__ . '(): Turnstile field was empty, failing validation.' );
			$this->failed_validation = true;
			$this->validation_message = $this->errorMessage ? $this->errorMessage : __( 'Invalid Turnstile captcha response.', 'gravityformsturnstile' );
			return;
		}

		$challenge_result = $this->make_turnstile_challenge( $value );

		if ( $challenge_result ) {
			gf_turnstile()->log_debug( __METHOD__ . '(): Turnstile challenge successfully passed validation.' );
			return;
		}

		gf_turnstile()->log_debug( __METHOD__ . '(): Turnstile challenge failed validation.' );

		$this->failed_validation  = true;
		$this->validation_message = $this->errorMessage ? $this->errorMessage :__( 'Invalid Turnstile captcha response.', 'gravityformsturnstile' );
	}

	/**
	 * Make a POST request to perform the Turnstile challenge.
	 *
	 * @since 1.0
	 *
	 * @param string $value The submitted field value to verify.
	 *
	 * @return bool
	 */
	private function make_turnstile_challenge( $value ) {
		return gf_turnstile()->verify_token( $value, true );
	}

	/**
	 * Set some defaults for the field via JS hooks.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_form_editor_inline_script_on_page_render() {
		// set the default field label for the field
		$script = sprintf( "function SetDefaultValues_%s(field) {field.label = '%s';}", $this->type, $this->get_form_editor_field_title() ) . PHP_EOL;

		return $script;
	}
}

GF_Fields::register( new GF_Field_Turnstile() );
