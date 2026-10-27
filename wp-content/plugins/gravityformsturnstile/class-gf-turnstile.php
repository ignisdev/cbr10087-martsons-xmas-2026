<?php

// don't load directly
if ( ! defined( 'ABSPATH' ) ) {
	die();
}

GFForms::include_addon_framework();

/**
 * Gravity Forms Cloudflare Turnstile Add-On.
 *
 * @since     1.0
 * @package   GravityForms
 * @author    Rocketgenius
 * @copyright Copyright (c) 2016, Rocketgenius
 */
class GFTurnstile extends GFAddon {

	/**
	 * Contains an instance of this class, if available.
	 *
	 * @since  1.0
	 * @access private
	 * @var    GFTurnstile $_instance If available, contains an instance of this class.
	 */
	private static $_instance = null;

	/**
	 * Defines the version of the Cloudflare Turnstile Add-On.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_version Contains the version, defined from turnstile.php
	 */
	protected $_version = GF_TURNSTILE_VERSION;

	/**
	 * Defines the minimum Gravity Forms version required.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_min_gravityforms_version The minimum version required.
	 */
	protected $_min_gravityforms_version = '2.6';

	/**
	 * Defines the plugin slug.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_slug The slug used for this plugin.
	 */
	protected $_slug = 'gravityformsturnstile';

	/**
	 * Defines the main plugin file.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_path The path to the main plugin file, relative to the plugins folder.
	 */
	protected $_path = 'gravityformsturnstile/turnstile.php';

	/**
	 * Defines the full path to this class file.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_full_path The full path.
	 */
	protected $_full_path = __FILE__;

	/**
	 * Defines the URL where this Add-On can be found.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string The URL of the Add-On.
	 */
	protected $_url = 'http://www.gravityforms.com';

	/**
	 * Defines the title of this Add-On.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_title The title of the Add-On.
	 */
	protected $_title = 'Gravity Forms Cloudflare Turnstile Add-On';

	/**
	 * Defines the short title of the Add-On.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_short_title The short title.
	 */
	protected $_short_title = 'Cloudflare Turnstile';

	/**
	 * Defines if Add-On should use Gravity Forms servers for update data.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    bool
	 */
	protected $_enable_rg_autoupgrade = true;

	/**
	 * Defines the capabilities needed for the Cloudflare Turnstile Add-On
	 *
	 * @since  1.0
	 * @access protected
	 * @var    array $_capabilities The capabilities needed for the Add-On
	 */
	protected $_capabilities = array( 'gravityforms_turnstile', 'gravityforms_turnstile_uninstall' );

	/**
	 * Defines the capability needed to access the Add-On settings page.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_capabilities_settings_page The capability needed to access the Add-On settings page.
	 */
	protected $_capabilities_settings_page = 'gravityforms_turnstile';

	/**
	 * Defines the capability needed to access the Add-On form settings page.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_capabilities_form_settings The capability needed to access the Add-On form settings page.
	 */
	protected $_capabilities_form_settings = 'gravityforms_turnstile';

	/**
	 * Defines the capability needed to uninstall the Add-On.
	 *
	 * @since  1.0
	 * @access protected
	 * @var    string $_capabilities_uninstall The capability needed to uninstall the Add-On.
	 */
	protected $_capabilities_uninstall = 'gravityforms_turnstile_uninstall';

	/**
	 * Defines the asset min string.
	 *
	 * @var string $_asset_min
	 */
	protected $_asset_min;

	const VERIFY_TOKEN_ACTION = 'gravityforms_turnstile_verify_token';

	const VALID_KEYS_CACHE_KEY = 'gravityforms_turnstile_valid_keys';

	/**
	 * Get an instance of this class.
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @return GFTurnstile
	 */
	public static function get_instance() {

		if ( null === self::$_instance ) {
			self::$_instance = new self;
		}

		if ( ! isset( self::$_instance->_asset_min ) ) {
			self::$_instance->_asset_min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';
		}

		return self::$_instance;

	}

	// --------------------------------------------------------------
	// # Initializers -----------------------------------------------
	// --------------------------------------------------------------

	/**
	 * Autoload the required libraries.
	 *
	 * @since  1.0
	 *
	 * @return void
	 */
	public function pre_init() {
		parent::pre_init();

		if ( ! $this->is_gravityforms_supported() ) {
			return;
		}

		require_once 'includes/class-gf-field-turnstile.php';
	}

	/**
	 * Initialize required hooks for admin and theme.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function init() {
		parent::init();

		add_action( 'wp_enqueue_scripts', array( $this, 'handle_no_conflict' ), 999, 0 );
		add_action( 'wp_ajax_' . self::VERIFY_TOKEN_ACTION, array( $this, 'ajax_verify_token' ), 10, 0 );
		add_filter( 'gform_pre_validation', array( $this, 'move_turnstile_field_to_last' ), 999, 1 );
		add_filter( 'gform_validation', array( $this, 'reset_turnstile_field_position' ), 999, 1 );
	}

	/**
	 * Initialize AJAX functions.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function init_ajax() {
		parent::init_ajax();

		add_filter( 'gform_duplicate_field_link', array( $this, 'prevent_duplication' ) );
	}

	/**
	 * Initialize hooks required for admin.
	 *
	 * @since 1.0
	 *
	 * @return void
	 */
	public function init_admin() {
		parent::init_admin();

		add_action( 'admin_footer', array( $this, 'localize_config_data' ), 10, 0 );
		add_action( 'gform_field_appearance_settings', array( $this, 'render_widget_theme_field_setting' ), 0, 1 );
        add_action( 'gform_field_appearance_settings', array( $this, 'render_widget_field_size_setting'), 0, 1);
		add_filter( 'gform_duplicate_field_link', array( $this, 'prevent_duplication' ) );
	}

	// --------------------------------------------------------------
	// # Assets -----------------------------------------------------
	// --------------------------------------------------------------

	/**
	 * Enqueue scripts for theme and admin.
	 *
	 * @since 1.0
	 *
	 * @return array[]
	 */
	public function scripts() {

		return array(
			array(
				'handle'    => 'gform_turnstile_vendor_script',
				'src'       => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				'in_footer' => true,
			),
			array(
				'handle'    => 'gform_turnstile_vendor_admin',
				'src'       => trailingslashit( $this->get_base_url() ) . "assets/js/dist/vendor-admin{$this->_asset_min}.js",
				'version'   => $this->_version,
				'deps'      => array( 'gform_turnstile_vendor_script' ),
				'in_footer' => true,
			),
			array(
				'handle'    => 'gform_turnstile_admin',
				'src'       => trailingslashit( $this->get_base_url() ) . "assets/js/dist/scripts-admin{$this->_asset_min}.js",
				'version'   => $this->_version,
				'deps'      => array( 'gform_turnstile_vendor_admin', 'gform_turnstile_vendor_script' ),
				'in_footer' => true,
                'enqueue'   => array(
	                array( 'admin_page' => array( 'plugin_settings' ), 'tab' => $this->_slug ),
	                array( 'admin_page' => array( 'form_editor' ) ),
			    ),
			),
			array(
				'handle'    => 'gform_turnstile_vendor_theme',
				'src'       => trailingslashit( $this->get_base_url() ) . "assets/js/dist/vendor-theme{$this->_asset_min}.js",
				'version'   => $this->_version,
				'in_footer' => true,
			),
			array(
				'handle'    => 'gform_turnstile_theme',
				'src'       => trailingslashit( $this->get_base_url() ) . "assets/js/dist/scripts-theme{$this->_asset_min}.js",
				'version'   => $this->_version,
				'deps'      => array( 'gform_turnstile_vendor_theme', 'gform_turnstile_vendor_script' ),
				'in_footer' => true,
				'enqueue'   => array(
					array( $this, 'frontend_script_callback' ),
				),
			),
		);

	}

	/**
	 * Localize config data needed for turnstile admin.
	 *
	 * @since 1.0
	 *
	 * @action admin_footer 10, 2
	 *
	 * @return void
	 */
	public function localize_config_data() {
		wp_localize_script( 'gform_turnstile_admin', 'gform_turnstile_config', array(
			'data'      => array(
				'site_key'           => $this->get_plugin_setting( 'site_key' ),
				'verify_token_nonce' => wp_create_nonce( self::VERIFY_TOKEN_ACTION ),
			),
			'i18n'      => array(
				'render_error' => esc_html__( 'There was an error rendering the field. This typically means your site key is incorrect. Please check your credentials and try again.', 'gravityformsturnstile' ),
				'unique_error' => esc_html__( 'A form can only contain one Turnstile field.', 'gravityformsturnstile' ),
				'token_error'  => esc_html__( 'There was an error verifying the challenge token. This typically means your secret key is incorrect. Please check your credentials and try again.', 'gravityformsturnstile' ),
			),
			'endpoints' => array(
				'verify_token_url' => admin_url( 'admin-ajax.php?action=' . self::VERIFY_TOKEN_ACTION ),
			),
		) );
	}

	/**
	 * Register the plugin settings fields needed to render Turnstile.
	 *
	 * @since 1.0
	 *
	 * @return array[]
	 */
	public function plugin_settings_fields() {
		return array(
			// Credentials group
			array(
				'title'  => esc_html__( 'Turnstile Credentials', 'gravityformsturnstile' ),
				// translators: %1 is an opening <a> tag, and %2 is a closing </a> tag.
				'description' => sprintf( esc_html__( 'To connect your site to Turnstile, create a site on your %1$sCloudflare Dashboard%2$s and enter the associated Site Key and Secret Key below.', 'gravityformsturnstile' ), '<a href="https://dash.cloudflare.com/" target="_blank">', '</a>' ),
				'fields' => array(
					array(
						'name'  => 'site_key',
						'type'  => 'text',
						'label' => esc_html__( 'Site Key', 'gravityformsturnstile' ),
					),
					array(
						'name'  => 'site_secret',
						'type'  => 'text',
						'label' => esc_html__( 'Secret Key', 'gravityformsturnstile' ),
					),
				),
			),

			// Options Group
			array(
				'title'  => esc_html__( 'Field Options', 'gravityformsturnstile' ),
				'fields' => array(
					array(
						'name'          => 'theme',
						'label'         => esc_html__( 'Theme', 'gravityformsturnstile' ),
						'description'   => esc_html__( 'Choose between a Light or Dark theme to tailor the field\'s appearance to your website, or select Auto to allow the field to inherit its theme from the user\'s system.', 'gravityformsturnstile' ),
						'type'          => 'select',
						'default_value' => 'auto',
						'choices'       => array(
							array(
								'label' => __( 'Auto', 'gravityformsturnstile' ),
								'value' => 'auto',
							),
							array(
								'label' => __( 'Light', 'gravityformsturnstile' ),
								'value' => 'light',
							),
							array(
								'label' => __( 'Dark', 'gravityformsturnstile' ),
								'value' => 'dark',
							),
						),
					),
					// Sizing Group
					array(
						'name'          => 'data-size',
						'label'         => esc_html__( 'Field Size', 'gravityformsturnstile' ),
						'description'   => esc_html__( 'Choose between a Normal, Flexible, or Compact size option to tailor the field\'s appearance to your website.', 'gravityformsturnstile' ),
						'type'          => 'select',
						'default_value' => 'auto',
						'choices'       => array(
							array(
								'label' => __( 'Normal', 'gravityformsturnstile' ),
								'value' => 'normal',
							),
							array(
								'label' => __( 'Flexible', 'gravityformsturnstile' ),
								'value' => 'flexible',
							),
							array(
								'label' => __( 'Compact', 'gravityformsturnstile' ),
								'value' => 'compact',
							),
						),
					),
				),
			),

			// Preview group
			array(
				'title' => esc_html__( 'Field Preview', 'gravityformsturnstile' ),
				'description' => '<p>' . esc_html__( 'Below is a preview of how the field will appear in your forms. If you see an error message, check your credentials and try again.', 'gravityformsturnstile' ) . '<p><strong>' . esc_html__( 'Note: ', 'gravityformsturnstile' ) . '</strong>' . esc_html__( 'If your field is set to the "Invisible" type in Cloudflare, this preview will appear empty.', 'gravityformsturnstile' ),
				'dependency' => array(
					'live' => false,
					'fields' => array(
						array( 'field' => 'site_key' ),
						array( 'field' => 'site_secret' ),
					),
				),
				'fields' => array(
					array(
						'name' => 'preview',
						'type' => 'html',
						'html' => array( $this, 'get_preview_html' ),
					),
				),
			),
		);
	}

	/**
	 * Determine whether or not to enqueue the script on the frontend.
	 *
     * @since 1.0
     *
	 * @param array $form The form being evaluated.
	 *
	 * @return bool
	 */
	public function frontend_script_callback( $form ) {
		// Bail if in entry page or form editor
        if ( GFCommon::is_entry_detail() || GFCommon::is_form_editor() || $this->is_form_settings() ) {
            return false;
        }
        // Only enqueue on forms that have a turnstile field.
        return $this->has_turnstile_field( $form );
	}


	/**
	 * Dequeue other captcha scripts if no-conflict is enabled.
	 *
	 * @since 1.0
	 *
	 * @action wp_enqueue_scripts 999, 0
	 *
	 * @return void
	 */
	public function handle_no_conflict() {
		/**
		 * Allows users to enable a No-Conflict mode for turnstile, which dequeues any other popular captcha
		 * scripts to avoid conflicts. Should only be used at support's direction.
		 *
		 * Example: add_filter( 'gform_turnstile_enable_no_conflict', '__return_true' );
		 *
		 * @since 1.0
		 *
		 * @param bool $enabled Whether no-conflict is enabled.
		 *
		 * @return bool
		 */
		$enabled = apply_filters( 'gform_turnstile_enable_no_conflict', false );

		if ( ! $enabled ) {
			return;
		}

		$this->log_debug( __METHOD__ . '(): Beginning Turnstile no-conflict process.' );

		$scripts       = wp_scripts();
		$urls_to_check = array(
			'google.com/recaptcha',
			'gstatic.com/recaptcha',
			'hcaptcha.com/1'
		);

		foreach ( $scripts->queue as $script ) {
			$src = $scripts->registered[ $script ]->src;

			foreach ( $urls_to_check as $check ) {
				if ( strpos( $src, $check ) === false ) {
					continue;
				}

				$this->log_debug( __METHOD__ . '(): Turnstile no-conflict is dequeueing script: ' . $script );

				wp_deregister_script( $script );
				wp_dequeue_script( $script );
			}
		}
	}

	/**
	 * Validates the response token from the settings page preview with Cloudflare.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	public function ajax_verify_token() {
		check_ajax_referer( self::VERIFY_TOKEN_ACTION, 'secret' );
		if ( $this->verify_token( rgpost( 'token' ) ) ) {
			wp_send_json_success();
		} else {
			wp_send_json_error();
		}
	}

	/**
	 * Moves the turnstile field to be the last field of the form.
	 *
	 * Turnstile field must be the last field to be validated, because if another field failed validation after turnstile passed, this means turnstile validation will run again during the next request, consuming the frontend verification token again, which should be verified only once.
	 *
	 * @since 1.0
	 *
	 * @param array $form The current form being validated.
	 *
	 * @return array.
	 */
	public function move_turnstile_field_to_last( $form ) {
		if ( ! $this->has_turnstile_field( $form ) ) {
			return $form;
		}

		$idx = null;
		$fields = $form['fields'];

		foreach ( $fields as $i => $field ) {
			if ( $field->type === 'turnstile' ) {
				$form['turnstile_original_position'] = $i;
				$idx = $i;
				break;
			}
		}

		if ( is_null( $idx ) ) {
			return $form;
		}

		unset( $fields[ $idx ] );
		$fields[] = $field;

		$form['fields'] = $fields;

		return $form;
	}

	/**
	 * Put the turnstile field back to its original position.
	 *
	 * We put the field at the end of the form fields array to make sure it gets validated after all other fields passed validation.
	 * If one of the fields fails validation we postpone sending a request to verify the turnstile token.
	 *
	 * @see GFTurnstile::move_turnstile_field_to_last()
	 *
	 * @since 1.0
	 *
	 * @param array $validation_result the validation result after all the fields in the form have been validated.
	 *
	 * @return array The validation result that contains the form after resetting the turnstile position.
	 */
	public function reset_turnstile_field_position( $validation_result ) {
		$form = $validation_result['form'];

		if ( ! $this->has_turnstile_field( $form ) ) {
			return $validation_result;
		}

		$field_position = $validation_result['form']['turnstile_original_position'];
		unset( $form['turnstile_original_position'] );
		if ( $field_position !== 0 && ! $field_position ) {
			return $validation_result;
		}

		$fields          = $form['fields'];
		$turnstile_field = array_pop( $fields );

		// Put the field back to its original index.
		$fields = array_merge(
			array_slice(
				$fields,
				0,
				$field_position
			),
			array( $turnstile_field ),
			array_slice( $fields, $field_position )
		);

		$form['fields']            = $fields;
		$validation_result['form'] = $form;

		return $validation_result;
	}

	/**
	 * Checks if any of the form fields has failed validation so we can postpone turnstile validation to the next request if so.
	 *
	 * @param array $form The current form being validated.
	 *
	 * @return bool whether any of the form fields failed validation or not.
	 */
	public function form_has_errors( $form ) {
		foreach ( $form['fields'] as $field ) {
			if ( $field->failed_validation ) {
				return true;
			}
		}

		return false;
	}

	// --------------------------------------------------------------
	// # Markup -----------------------------------------------------
	// --------------------------------------------------------------

	/**
	 * Get the HTML to display when previewing the widget on the settings page.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_preview_html() {
		$key        = $this->get_plugin_setting( 'site_key' );
		$secret     = $this->get_plugin_setting( 'site_secret' );
		$theme      = $this->get_plugin_setting( 'theme' );
        $data_size  = $this->get_plugin_setting( 'data-size' );

		if ( empty( $key ) || empty( $secret ) ) {
			$this->log_debug( __METHOD__ . '(): Missing secret or key values, returning empty preview.' );
			return '';
		}

		return '<div id="gform_turnstile_preview" class="gform_turnstile_preview" data-theme="' . esc_attr( $theme ) . '" data-size="'. esc_attr( $data_size ) .'"></div>';
	}

	/**
	 * Render the setting field for choosing a Widget Theme.
	 *
	 * @since 1.0
	 *
	 * @action gform_field_appearance_settings 0, 1
	 *
	 * @param int $position The current position being rendered in the sidebar.
	 *
	 * @return void
	 */
	public function render_widget_theme_field_setting( $position ) {
		if ( (int) $position !== 20 ) {
			return;
		}

		?>
		<li class="turnstile_widget_theme_setting field_setting">
			<label for="field_turnstile_widget_theme" class="section_label">
				<?php esc_html_e( 'Field Theme', 'gravityforms' ); ?>
				<?php gform_tooltip( esc_html__( 'Select a theme for this instance of the Turnstile field. This value will override the theme selected in your Cloudflare Turnstile plugin settings.', 'gravityformsturnstile' ) ); ?>
			</label>
			<select id="field_turnstile_widget_theme">
				<option value=""><?php esc_html_e( 'Select a Theme', 'gravityformsturnstile' ); ?></option>
				<option value="auto"><?php esc_html_e( 'Auto', 'gravityformsturnstile' ); ?></option>
				<option value="light"><?php esc_html_e( 'Light', 'gravityformsturnstile' ); ?></option>
				<option value="dark"><?php esc_html_e( 'Dark', 'gravityformsturnstile' ); ?></option>
			</select>
		</li>
		<?php
	}

	/**
	 * Render the setting field for choosing a Widget Size.
	 *
	 * @since 1.5
	 *
	 * @action gform_field_appearance_settings 0, 1
	 *
	 * @param int $position The current position being rendered in the sidebar.
	 *
	 * @return void
	 */
	public function render_widget_field_size_setting( $position ) {
		if ( (int) $position !== 20 ) {
			return;
		}
		?>
		<li class="turnstile_widget_size_setting field_setting">
			<label for="field_turnstile_widget_size" class="section_label">
				<?php esc_html_e( 'Field Size', 'gravityforms' ); ?>
				<?php gform_tooltip( esc_html__( 'Select a size for this instance of the Turnstile field. This value will override the size selected in your Cloudflare Turnstile plugin settings.', 'gravityformsturnstile' ) ); ?>
			</label>
			<select id="field_turnstile_widget_size">
				<option value=""><?php esc_html_e( 'Select a Size', 'gravityformsturnstile' ); ?></option>
				<option value="normal"><?php esc_html_e( 'Normal', 'gravityformsturnstile' ); ?></option>
				<option value="flexible"><?php esc_html_e( 'Flexible', 'gravityformsturnstile' ); ?></option>
				<option value="compact"><?php esc_html_e( 'Compact', 'gravityformsturnstile' ); ?></option>
			</select>
		</li>
		<?php
	}

	/**
	 * Prevent the duplicate field link from rendering.
	 *
	 * @since 1.0
	 *
	 * @param string $dupe_link The current duplicate link.
	 *
	 * @return string
	 */
	public function prevent_duplication( $dupe_link ) {
		if ( strpos( $dupe_link, 'turnstile' ) === false ) {
			return $dupe_link;
		}

		return '';
	}

	// --------------------------------------------------------------
	// # Helpers ----------------------------------------------------
	// --------------------------------------------------------------

	/**
	 * Used on form display and submission to check if valid keys are available.
	 *
	 * The cached result is also set/updated by the following:
	 * - The Ajax request from the settings page preview callback.
	 * - Field validation during form submission.
	 *
	 * @since 1.0
	 * @since 1.2.0 Updated to use verify_token().
	 *
	 * @return bool
	 */
	public function has_valid_credentials() {
		$secret = $this->get_plugin_setting( 'site_secret' );

		// Missing credentials, no need to check further.
		if ( empty( $secret ) || empty( $this->get_plugin_setting( 'site_key' ) ) ) {
			$this->log_debug( __METHOD__ . '(): Missing Turnstile credentials, aborting.' );

			return false;
		}

		static $is_valid;

		if ( ! is_null( $is_valid ) ) {
			return $is_valid;
		}

		$is_valid = GFCache::get( self::VALID_KEYS_CACHE_KEY, $found );
		if ( $found ) {
			return (bool) $is_valid;
		}

		// Fallback for when the cached result from the settings page and field validation has expired.
		$is_valid = $this->verify_token( 'test', false, $secret );

		return $is_valid;
	}

	/**
	 * Determine if a given form has a turnstile field.
	 *
	 * @since 1.0
	 *
	 * @param array $form The form being evaluated.
	 *
	 * @return bool
	 */
	public function has_turnstile_field( $form ) {
		$fields = \GFAPI::get_fields_by_type( $form, array( 'turnstile' ) );

		return ! empty( $fields );
	}

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 1.0
	 *
	 * @return string
	 */
	public function get_menu_icon() {
		return $this->is_gravityforms_supported( '2.7.8.1' ) ? 'gform-icon--cloudflare-turnstile' : file_get_contents( $this->get_base_path() . '/assets/img/cloudflare.svg' );
	}

	/**
	 * Validates the response token with Cloudflare.
	 *
	 * Also updates the cached valid keys result.
	 *
	 * @since 1.2.0
	 *
	 * @param string $token              The challenge response token.
	 * @param bool   $is_form_submission Indicates if the token is from a form submission.
	 * @param string $secret             The site secret.
	 *
	 * @return bool
	 */
	public function verify_token( $token, $is_form_submission = false, $secret = '' ) {
		if ( empty( $token ) ) {
			if ( ! $is_form_submission ) {
				GFCache::set( self::VALID_KEYS_CACHE_KEY, false, true, MINUTE_IN_SECONDS * 15 );
			}

			return false;
		}

		$args = array(
			'body' => array(
				'secret'   => empty( $secret ) ? $this->get_plugin_setting( 'site_secret' ) : $secret,
				'response' => $token,
				'remoteip' => $_SERVER['REMOTE_ADDR'],
			),
		);

		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $args );

		if ( is_wp_error( $response ) ) {
			$this->log_debug( __METHOD__ . '(): Request to Turnstile encountered an error: ' . $response->get_error_message() );

			return false;
		}

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->log_debug( __METHOD__ . '(): Turnstile challenge API encountered a server error.' );

			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( $is_form_submission ) {
			$this->log_debug( __METHOD__ . '(): Result => ' . print_r( $body, true ) );
		}

		$body_array   = json_decode( $body, true );
		$valid_secret = ! in_array( 'invalid-input-secret', rgar( $body_array, 'error-codes', array() ) );
		$is_valid     = rgar( $body_array, 'success' ) || ( $token === 'test' && $valid_secret );
		$cache_result = $is_form_submission && $valid_secret ? true : $is_valid;

		GFCache::set( self::VALID_KEYS_CACHE_KEY, $cache_result, true, MINUTE_IN_SECONDS * 15 );

		return $is_valid;
	}

	/**
	 * Updates the plugin settings with the given field values and deletes the cached keys validation result.
	 *
	 * @since 1.2.0
	 *
	 * @param array $settings The settings to be saved.
	 *
	 * @return void
	 */
	public function update_plugin_settings( $settings ) {
		GFCache::delete( self::VALID_KEYS_CACHE_KEY );

		parent::update_plugin_settings( $settings );
	}

	/**
	 * Add-on specific cleanup tasks to be performed on uninstallation.
	 *
	 * @since 1.2.0
	 *
	 * @return true
	 */
	public function uninstall() {
		delete_option( 'gf_turnstile_api_url' );
		GFCache::delete( self::VALID_KEYS_CACHE_KEY );

		return true;
	}

}
