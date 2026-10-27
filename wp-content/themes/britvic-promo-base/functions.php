<?php
/**
 * Britvic Promo Base — Theme Functions
 *
 * @package britvic-promo-base
 * @since   1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load theme includes in dependency order
$includes = [
    'includes/lib/definitions.php',
    'includes/lib/acf.php',
    'includes/lib/methods.php',
    'includes/lib/assets.php',
];

foreach ($includes as $include) {
    $path = get_template_directory() . '/' . $include;
    if (file_exists($path)) {
        require_once $path;
    }
}
