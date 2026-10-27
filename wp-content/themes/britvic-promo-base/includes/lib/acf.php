<?php
/**
 * ACF configuration
 *
 * @package britvic-promo-base
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('acf_add_options_page')) {
    return;
}

acf_add_options_page([
    'page_title' => 'Site Options',
    'menu_title' => 'Site Options',
    'menu_slug'  => 'site-options',
    'capability' => 'manage_options',
    'redirect'   => false,
]);
