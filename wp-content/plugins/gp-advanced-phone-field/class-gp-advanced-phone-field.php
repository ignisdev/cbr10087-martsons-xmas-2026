<?php
use libphonenumber\PhoneNumberUtil;

if ( ! class_exists( 'GP_Plugin' ) ) {
	return;
}

class GP_Advanced_Phone_Field extends GP_Plugin {

	private static $instance = null;

	protected $_version     = GP_ADVANCED_PHONE_FIELD_VERSION;
	protected $_path        = 'gp-advanced-phone-field/gp-advanced-phone-field.php';
	protected $_full_path   = __FILE__;
	protected $_slug        = 'gp-advanced-phone-field';
	protected $_title       = 'Gravity Wiz Advanced Phone Field';
	protected $_short_title = 'Advanced Phone Field';

	/**
	 * Defines the capabilities needed for the Add-On.
	 *
	 * @var array $_capabilities The capabilities needed for the Add-On
	 */
	protected $_capabilities = array(
		'gp-advanced-phone-field',
		'gp-advanced-phone-field_uninstall',
		'gp-advanced-phone-field_settings',
	);

	/**
	 * Defines the capability needed to access the Add-On settings page.
	 *
	 * @var string $_capabilities_settings_page The capability needed to access the Add-On settings page.
	 */
	protected $_capabilities_settings_page = 'gp-advanced-phone-field_settings';

	/**
	 * Defines the capability needed to uninstall the Add-On.
	 *
	 * @var string $_capabilities_uninstall The capability needed to uninstall the Add-On.
	 */
	protected $_capabilities_uninstall = 'gp-advanced-phone-field_uninstall';

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function minimum_requirements() {
		return array(
			'gravityforms' => array(
				'version' => '2.3-rc-1',
			),
			'wordpress'    => array(
				'version' => '4.8',
			),
		);
	}

	public function init() {

		parent::init();

		require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-gpapf-phone-number.php';

		load_plugin_textdomain( 'gp-advanced-phone-field', false, basename( dirname( __file__ ) ) . '/languages/' );

		add_action( 'gperk_field_settings', array( $this, 'field_settings_ui' ) );

		add_filter( 'gform_register_init_scripts', array( $this, 'add_init_script' ), 10, 2 );

		add_filter( 'gform_field_validation', array( $this, 'validation' ), 10, 4 );

		add_filter( 'gform_entry_meta', array( $this, 'register_meta' ), 10, 2 );
		add_filter( 'gform_entry_post_save', array( $this, 'store_phone_proto_as_meta' ), 5, 2 ); // Lower priority so the meta is available to Feed Add-ons (priority 10)

		add_filter( 'gform_pre_replace_merge_tags', array( $this, 'replace_field_variable_matches' ), 10, 7 );

	}

	public function init_admin() {
		parent::init_admin();

		GravityPerks::enqueue_field_settings();
	}

	public function styles() {
		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

		$styles = array(
			array(
				'handle'  => 'gp-advanced-phone-field',
				'src'     => $this->get_base_url() . '/styles/frontend' . $min . '.css',
				'version' => $this->_version,
				'enqueue' => array(
					array( $this, 'should_enqueue_frontend' ),
				),
			),
		);

		return array_merge( parent::styles(), $styles );
	}

	public function scripts() {
		$scripts = array(
			array(
				'handle'    => 'gp-advanced-phone-field',
				'src'       => $this->get_base_url() . '/js/built/gp-advanced-phone-field.js',
				'version'   => $this->_version,
				'deps'      => array( 'gform_gravityforms' ),
				'in_footer' => true,
				'enqueue'   => array(
					array( $this, 'should_enqueue_frontend' ),
				),
				'callback'  => array( $this, 'localize_frontend_scripts' ),
			),
			array(
				'handle'    => 'gp-advanced-phone-field-form-editor',
				'src'       => $this->get_base_url() . '/js/built/gp-advanced-phone-field-form-editor.js',
				'version'   => $this->_version,
				'deps'      => array( 'gform_gravityforms', 'jquery' ),
				'in_footer' => true,
				'enqueue'   => array(
					array( 'admin_page' => array( 'form_editor' ) ),
				),
				'callback'  => array( $this, 'localize_admin_scripts' ),
			),
		);

		return array_merge( parent::scripts(), $scripts );
	}

	public function add_init_script( $form ) {
		$advanced_phone_fields = $this->get_advanced_phone_fields( $form );

		if ( empty( $advanced_phone_fields ) ) {
			return $form;
		}

		// Must manually require since plugins like Partial Entries and Nested Forms call gform_pre_render outside of the rendering context.
		require_once( GFCommon::get_base_path() . '/form_display.php' );

		foreach ( $advanced_phone_fields as $field ) {
			$form_id = $field['formId'];
			$id      = $field['id'];

			/**
			 * Filter the args to initialize Advanced Phone Field on the frontend.
			 *
			 * @param array     $args   Arguments used to initialize the JavaScript instance of GP_Advanced_Phone_Field.
			 * @param GF_Field  $field  The current field.
			 * @param array     $form   The current form.
			 *
			 * @since 1.0
			 */
			$args = gf_apply_filters( array( 'gpapf_init_args', $form_id, $id ), array(
				'fieldId'            => $id,
				'formId'             => $form_id,
				'defaultCountry'     => $this->get_plugin_setting( 'default_country' ),
				'preferredCountries' => $this->get_plugin_setting( 'preferred_countries' ),
				'countriesAction'    => $this->get_plugin_setting( 'countries_action' ),
				'countries'          => $this->get_plugin_setting( 'countries' ),
			), $field, $form );

			$slug   = 'gp_advanced_phone_field_' . $form_id . '_' . $id;
			$script = 'window.' . $slug . ' = new GPAdvancedPhoneField( ' . json_encode( $args ) . ' );';

			GFFormDisplay::add_init_script( $form_id, $slug, GFFormDisplay::ON_PAGE_RENDER, $script );
		}

		return $form;
	}

	/**
	 * @param $form
	 *
	 * @return boolean Whether this form has advanced phone fields.
	 */
	public function is_applicable_form( $form ) {
		return ! empty( $this->get_advanced_phone_fields( $form ) );
	}


	/**
	 * @param $form
	 *
	 * @return GF_Field[] List of fields with Advanced Phone Field enabled.
	 */
	public function get_advanced_phone_fields( $form ) {
		if ( empty( $form['fields'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $form['fields'] as $field ) {
			if ( $this->is_advanced_phone_field( $field ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * @param GF_Field $field
	 *
	 * @return boolean
	 */
	public function is_advanced_phone_field( $field ) {
		return rgar( $field, 'type' ) === 'phone' && rgar( $field, 'gpapfEnable' );
	}

	/**
	 * Determine if frontend scripts/styles should be enqueued. Loop through fields and check if gpapfEnable is true for
	 * any field.
	 *
	 * @param $form
	 *
	 * @return bool
	 */
	public function should_enqueue_frontend( $form ) {
		if ( GFCommon::is_form_editor() ) {
			return false;
		}

		return $this->is_applicable_form( $form );
	}

	public function localize_frontend_scripts( $form ) {
		static $localized_frontend = false;

		if ( $localized_frontend ) {
			return;
		}

		/**
		 * Expose URL of admin-ajax.php to scripts since this URL will change based on the site setup.
		 */
		wp_localize_script( 'gp-advanced-phone-field', 'GPAPF', array(
			'pluginUrl'          => $this->get_base_url(),
			'localizedCountries' => $this->get_countries_intltelinput(),
		) );

		$localized_frontend = true;
	}

	public function localize_admin_scripts( $form ) {
		wp_localize_script( 'gp-advanced-phone-field-form-editor', 'GPAPF_EDITOR', array(
			'strings' => array(
				'int_format_required' => esc_html__( 'The international format is required when Advanced Phone Field is enabled.' ),
			),
		) );
	}

	## Admin Field Settings

	public function field_settings_ui( $position ) {
		?>

		<li class="gpapf-field-setting field_setting" style="display:none;">
			<input type="checkbox" value="1" id="gpapf-enable" />
			<label for="gpapf-enable" class="inline">
				<?php _e( 'Enable Advanced Phone Field', 'gp-advanced-phone-field' ); ?>
				<?php gform_tooltip( $this->_slug . '_enable' ); ?>
			</label>
		</li>

		<?php
	}

	public function tooltips( $tooltips ) {
		$tooltips[ $this->_slug . '_enable' ] = sprintf(
			'<h6>%s</h6> %s',
			__( 'GP Advanced Phone Field', 'gp-advanced-phone-field' ),
			__( 'Enable GP Advanced Phone Field for the current field. Adds improved UI and validation.', 'gp-advanced-phone-field' )
		);

		return $tooltips;
	}

	/**
	 * @param array $result
	 * @param string $value
	 * @param array $form
	 * @param GF_Field_Phone $field
	 *
	 * @return array
	 */
	public function validation( $result, $value, $form, $field ) {
		if ( ! $this->is_advanced_phone_field( $field ) ) {
			return $result;
		}

		$field->phoneFormat = 'international'; // Suppress native Phone Field instructions for the format.

		// Skip validating blank phone numbers if not required.
		if ( rgblank( $value ) && ! $field->isRequired ) {
			return $result;
		}

		$phone_util = PhoneNumberUtil::getInstance();

		try {
			$phone_proto = $phone_util->parse( $value, null );
			$phone_proto->setRawInput( $value );

			if ( $phone_util->isValidNumber( $phone_proto ) ) {
				return $result;
			} else {
				// Get specific validation reason
				$validation_result = $phone_util->isPossibleNumberWithReason( $phone_proto );

				switch ( $validation_result ) {
					case \libphonenumber\ValidationResult::INVALID_COUNTRY_CODE:
						$validation_reason = 'Invalid country code';
						break;
					case \libphonenumber\ValidationResult::TOO_SHORT:
						$validation_reason = 'Number is too short';
						break;
					case \libphonenumber\ValidationResult::TOO_LONG:
						$validation_reason = 'Number is too long';
						break;
					case \libphonenumber\ValidationResult::INVALID_LENGTH:
						$validation_reason = 'Number has invalid length';
						break;
					default:
						$validation_reason = 'Number format is invalid';
				}

				gp_advanced_phone_field()->log_debug( __METHOD__ . '(): Phone number validation failed for form #' . $form['id'] . ', field #' . $field->id . ' - Input: "' . $value . '" - Reason: ' . $validation_reason );
			}
		} catch ( Exception $e ) {
			// Intentionally empty.
		}

		// If a proto could not be created, we know the value provided is mega invalid. Clear it out.
		if ( empty( $phone_proto ) ) {
			$_POST[ 'input_' . $field['id'] ] = '';
		}

		return array(
			'is_valid' => false,
			'message'  => empty( $field->errorMessage ) ? __( 'Please provide a valid phone number.', 'gp-advanced-phone-field' ) : $field->errorMessage,
		);
	}

	/**
	 * @param string $number Raw phone number provided by visitor.
	 *
	 * @return libphonenumber\PhoneNumber
	 */
	public function get_phone_number_proto( $number ) {
		try {
			$util  = PhoneNumberUtil::getInstance();
			$phone_number = new GPAPF_Phone_Number();
			$proto = $util->parse( $number, null, $phone_number );
			$proto->setRawInput( $number );
		} catch ( Exception $e ) {
			return null;
		}

		/* Type */
		$type        = $util->getNumberType( $proto );
		$values      = libphonenumber\PhoneNumberType::values();
		$proto->type = rgar( $values, $type );

		/* Description */
		$geocoder           = \libphonenumber\geocoding\PhoneNumberOfflineGeocoder::getInstance();
		$proto->description = $geocoder->getDescriptionForNumber( $proto, get_locale() );

		/* Carrier */
		$carrierMapper  = \libphonenumber\PhoneNumberToCarrierMapper::getInstance();
		$proto->carrier = $carrierMapper->getNameForNumber( $proto, get_locale() );

		/* Region Code */
		$proto->regionCode = $util->getRegionCodeForNumber( $proto );

		return $proto;
	}

	public function plugin_settings_init() {
		parent::plugin_settings_init();
		GFSettings::$addon_pages[ $this->_slug ]['tab_label'] = __( 'Adv Phone Field', 'gp-advanced-phone-field' );
	}

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return 'gform-icon--phone';
	}

	/**
	 * Configures the settings which should be rendered on the add-on settings tab.
	 *
	 * @return array
	 */
	public function plugin_settings_fields() {
		$country_choices = array_map( function ( $country_name, $country_code ) {
			return array(
				'label' => $country_name,
				'value' => $country_code,
			);
		}, $this->get_countries(), array_keys( $this->get_countries() ) );

		return array(
			array(
				'title'  => esc_html__( 'Advanced Phone Field', 'gp-advanced-phone-field' ),
				'fields' => array(
					array(
						'name'          => 'default_country',
						'label'         => esc_html__( 'Default Country', 'gp-advanced-phone-field' ),
						'description'   => __( 'Select the default country that should be used for the country code selector.', 'gp-advanced-phone-field' ),
						'type'          => 'select',
						'enhanced_ui'   => true,
						'default_value' => 'auto',
						'choices'       => array_merge( array(
							array(
								'label' => __( 'Determine by visitor location (GeoIP)', 'gp-advanced-phone-field' ),
								'value' => 'auto',
							),
						), $country_choices ),
					),
					array(
						'name'        => 'preferred_countries[]',
						'label'       => esc_html__( 'Preferred Countries', 'gp-advanced-phone-field' ),
						'description' => 'Select the countries that should be listed first in the country code selector.',
						'type'        => 'select',
						'enhanced_ui' => true,
						'multiple'    => true,
						'choices'     => $country_choices,
					),
					array(
						'name'          => 'countries_action',
						'label'         => esc_html__( 'Countries', 'gp-advanced-phone-field' ),
						'type'          => 'radio',
						'default_value' => 'all',
						'choices'       => array(
							array(
								'label' => esc_html__( 'Include all countries', 'gp-advanced-phone-field' ),
								'value' => 'all',
							),
							array(
								'label'   => esc_html__( 'Only include the following countries...', 'gp-advanced-phone-field' ),
								'value'   => 'include',
								'tooltip' => esc_html__( 'Only the countries selected below will be available in the country code selector.', 'gp-advanced-phone-field' ),
							),
							array(
								'label'   => esc_html__( 'Exclude the following countries...', 'gp-advanced-phone-field' ),
								'value'   => 'exclude',
								'tooltip' => esc_html__( 'Include all but the selected countries below in the country code selector.', 'gp-advanced-phone-field' ),
							),
						),
					),
					array(
						'name'        => 'countries[]',
						'type'        => 'select',
						'enhanced_ui' => true,
						'multiple'    => true,
						'choices'     => $country_choices,
						'dependency'  => array(
							'live'   => true,
							'fields' => array(
								array(
									'field'  => 'countries_action',
									'values' => array( 'include', 'exclude' ),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Gets localized country names for intl-tel-input if the user locale is not English.
	 *
	 * @return array|null Associative array of countries. Keys are abbreviations for the country.
	 */
	public function get_countries_intltelinput() {
		// Only output the localized country names if the user locale is not English.
		if ( strpos( get_user_locale(), 'en_' ) === 0 ) {
			return null;
		}

		$countries = $this->get_countries();

		// Make the array keys lowercase
		$countries = array_change_key_case( $countries, CASE_LOWER );

		return $countries;
	}

	/**
	 * List out countries in associative array format with abbreviation and full country name.
	 *
	 * @return array Associative array of countries. Keys are abbreviations for the country.
	 */
	public function get_countries() {
		$fake_address_field = new GF_Field_Address();

		if ( method_exists( $fake_address_field, 'get_default_countries' ) ) {
			$countries = $fake_address_field->get_default_countries();
		} else {
			$countries = array();
		}

		return $countries;
	}

	/**
	 * Register entry meta for things like the phone number type, country code, etc.
	 *
	 * @param array $entry_meta
	 * @param int $form_id
	 */
	public function register_meta( $entry_meta, $form_id ) {
		foreach ( $this->get_advanced_phone_fields( GFAPI::get_form( $form_id ) ) as $autocomplete_field ) {
			$label = GFCommon::get_label( $autocomplete_field );

			$defaults = array(
				'is_numeric'                 => false,
				/**
				 * @see store_phone_proto_as_meta
				 */
				'update_entry_meta_callback' => null,
				'is_default_column'          => false,
				'filter'                     => true, // Allow conditional logic
			);

			$entry_meta[ "gpapf_country_code_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label'      => sprintf( __( '%s (Country Dialing Code)', 'gp-advanced-phone-field' ), $label ),
				'is_numeric' => true,
			) );

			$entry_meta[ "gpapf_type_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (Type)', 'gp-advanced-phone-field' ), $label ),
			) );

			$entry_meta[ "gpapf_national_number_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (National Number)', 'gp-advanced-phone-field' ), $label ),
			) );

			$entry_meta[ "gpapf_raw_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (Raw)', 'gp-advanced-phone-field' ), $label ),
			) );

			$entry_meta[ "gpapf_description_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (Description)', 'gp-advanced-phone-field' ), $label ),
			) );

			$entry_meta[ "gpapf_carrier_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (Carrier)', 'gp-advanced-phone-field' ), $label ),
			) );

			$entry_meta[ "gpapf_region_code_{$autocomplete_field->id}" ] = array_merge( $defaults, array(
				// translators: placeholder is the label of the field with Advanced Phone Field enabled.
				'label' => sprintf( __( '%s (Region Code)', 'gp-advanced-phone-field' ), $label ),
			) );
		}

		return $entry_meta;
	}

	/**
	 * Store parsed phone number object from libphonenumber for re-use later.
	 *
	 * @param array $entry The submitted entry.
	 * @param array $form The current form.
	 *
	 * @return array
	 */
	public function store_phone_proto_as_meta( $entry, $form ) {
		foreach ( $this->get_advanced_phone_fields( $form ) as $advanced_phone_field ) {
			$raw_phone = rgpost( "input_{$advanced_phone_field->id}" );

			if ( ! $raw_phone ) {
				gform_delete_meta( $entry['id'], "gpapf_proto_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_country_code_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_type_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_national_number_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_raw_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_description_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_carrier_{$advanced_phone_field->id}" );
				gform_delete_meta( $entry['id'], "gpapf_region_code_{$advanced_phone_field->id}" );

				continue;
			}

			$proto = $this->get_phone_number_proto( $raw_phone );

			if ( is_a( $proto, 'libphonenumber\PhoneNumber' ) ) {
				gform_update_meta( $entry['id'], "gpapf_proto_{$advanced_phone_field->id}", $proto );
				gform_update_meta( $entry['id'], "gpapf_country_code_{$advanced_phone_field->id}", $proto->getCountryCode() );
				gform_update_meta( $entry['id'], "gpapf_type_{$advanced_phone_field->id}", $proto->type );
				gform_update_meta( $entry['id'], "gpapf_national_number_{$advanced_phone_field->id}", $proto->getNationalNumber() );
				gform_update_meta( $entry['id'], "gpapf_raw_{$advanced_phone_field->id}", $proto->getRawInput() );
				gform_update_meta( $entry['id'], "gpapf_description_{$advanced_phone_field->id}", $proto->description );
				gform_update_meta( $entry['id'], "gpapf_carrier_{$advanced_phone_field->id}", $proto->carrier );
				gform_update_meta( $entry['id'], "gpapf_region_code_{$advanced_phone_field->id}", $proto->regionCode );

				$entry[ "gpapf_proto_{$advanced_phone_field->id}" ]           = $proto;
				$entry[ "gpapf_country_code_{$advanced_phone_field->id}" ]    = $proto->getCountryCode();
				$entry[ "gpapf_type_{$advanced_phone_field->id}" ]            = $proto->type;
				$entry[ "gpapf_national_number_{$advanced_phone_field->id}" ] = $proto->getNationalNumber();
				$entry[ "gpapf_raw_{$advanced_phone_field->id}" ]             = $proto->getRawInput();
				$entry[ "gpapf_description_{$advanced_phone_field->id}" ]     = $proto->description;
				$entry[ "gpapf_carrier_{$advanced_phone_field->id}" ]         = $proto->carrier;
				$entry[ "gpapf_region_code_{$advanced_phone_field->id}" ]     = $proto->regionCode;
			}
		}

		return $entry;
	}

	/**
	 * Augments the merge tag for the phone field to pull data off of the libphonenumber proto if present in the
	 * entry meta.
	 */
	public function replace_field_variable_matches( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {
		preg_match_all( '/{[^{]*?:(\d+(\.\d+)?)(:(.*?))?}/mi', $text, $field_variable_matches, PREG_SET_ORDER );

		foreach ( $field_variable_matches as $match ) {
			$input_id      = $match[1];
			$field         = GFFormsModel::get_field( $form, $input_id );
			$i             = $match[0][0] === '{' ? 4 : 5;
			$modifiers_str = rgar( $match, $i );
			$modifiers     = $this->parse_modifiers( $modifiers_str );

			if ( ! $this->is_advanced_phone_field( $field ) ) {
				continue;
			}

			if ( ! isset( $modifiers['phone'] ) ) {
				continue;
			}

			/**
			 * @var $proto libphonenumber\PhoneNumber
			 */
			$proto = gform_get_meta( rgar( $entry, 'id' ), "gpapf_proto_{$field->id}" );

			/**
			 * Get proto from meta if set, otherwise try to create one on the fly for situations such as LMTs.
			 */
			if ( ! $proto ) {
				$entry_value = GFFormsModel::get_lead_field_value( $entry, $field );

				try {
					$proto = $this->get_phone_number_proto( $entry_value );
				} catch ( Exception $e ) {
					// Intentionally blank.
				}

				if ( ! $proto ) {
					continue;
				}
			}

			$replacement = null;

			switch ( $modifiers['phone'] ) {
				case 'type':
					$replacement = $proto->type;
					break;

				case 'countryCode':
					$replacement = $proto->getCountryCode();
					break;

				case 'nationalNumber':
					$replacement = $proto->getNationalNumber();
					break;

				case 'nationalNumberFormatted':
					$phone_number_util = PhoneNumberUtil::getInstance();
					$replacement       = $phone_number_util->format( $proto, \libphonenumber\PhoneNumberFormat::NATIONAL );
					break;

				case 'raw':
					$replacement = $proto->getRawInput();
					break;

				case 'description':
					$replacement = $proto->description;
					break;

				case 'carrier':
					$replacement = $proto->carrier;
					break;

				case 'regionCode':
					$replacement = $proto->regionCode;
					break;
			}

			if ( $replacement || $replacement === '' ) {
				$text = str_replace( $match[0], $replacement, $text );

				/**
				 * Filter the phone field merge tag text.
				 *
				 * @since 1.0.8
				 *
				 * @param string $text      The current phone merge text.
				 * @param string $modifiers The merge tag modifiers.
				 */
				$text = apply_filters( 'gpapf_merge_tag_value', $text, $modifiers );
			}
		}

		return $text;
	}

	public function parse_modifiers( $modifiers_str ) {
		preg_match_all( '/([a-z_]+)(?:(?:\[(.+?)\])|,?)/i', $modifiers_str, $modifiers, PREG_SET_ORDER );
		$parsed = array();

		foreach ( $modifiers as $modifier ) {

			list( $match, $modifier, $value ) = array_pad( $modifier, 3, null );
			if ( $value === null ) {
				$value = $modifier;
			}

			// Split '1,2,3' into array( 1, 2, 3 ).
			if ( strpos( $value, ',' ) !== false ) {
				$value = array_map( 'trim', explode( ',', $value ) );
			}

			$parsed[ strtolower( $modifier ) ] = $value;

		}

		return $parsed;
	}


}

function gp_advanced_phone_field() {
	return GP_Advanced_Phone_Field::get_instance();
}

GFAddOn::register( 'GP_Advanced_Phone_Field' );
