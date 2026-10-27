<?php
/**
 * Plugin Name: GP Advanced Phone Field
 * Description: Level up your Phone fields with automatic country code selection, real number validation, and stylish visuals.
 * Plugin URI: https://gravitywiz.com/documentation/gravity-forms-advanced-phone-field/
 * Version: 1.1.13
 * Author: Gravity Wiz
 * Author URI: https://gravitywiz.com/
 * License: GPL2
 * Perk: True
 * Text Domain: gp-advanced-phone-field
 * Domain Path: /languages
 */

define( 'GP_ADVANCED_PHONE_FIELD_VERSION', '1.1.13' );

require plugin_dir_path( __FILE__ ) . 'includes/class-gp-bootstrap.php';

$GP_Advanced_Phone_Field_bootstrap = new GP_Bootstrap( 'class-gp-advanced-phone-field.php', __FILE__ );
