<?php
/**
 * Asset enqueuing
 *
 * @package britvic-promo-base
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_enqueue_scripts', function (): void {
    $theme_uri = get_template_directory_uri();
    $theme_dir = get_template_directory();

    // Deregister default jQuery and load from CDN
    wp_deregister_script('jquery');
    wp_enqueue_script(
        'jquery',
        'https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js',
        [],
        '3.6.0',
        false
    );

    // GSAP
    wp_enqueue_script(
        'gsap',
        $theme_uri . '/js/vendor/gsap.min.js',
        [],
        '3.12.5',
        true
    );

    // Main compiled JS — versioned by file modification time for cache busting
    $js_path = $theme_dir . '/js/dist/dist.js';
    wp_enqueue_script(
        'britvic-promo-main',
        $theme_uri . '/js/dist/dist.js',
        ['jquery', 'gsap'],
        file_exists($js_path) ? (string) filemtime($js_path) : '1.0.0',
        true
    );

    // Main compiled CSS
    $css_path = $theme_dir . '/css/dist/dist.css';
    wp_enqueue_style(
        'britvic-promo-main',
        $theme_uri . '/css/dist/dist.css',
        [],
        file_exists($css_path) ? (string) filemtime($css_path) : '1.0.0'
    );

    // Pass config to JS
    wp_localize_script('britvic-promo-main', 'bpm_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('bpm_frontend'),
    ]);

    if (function_exists('bpm_mechanic_type')) {
        wp_localize_script('britvic-promo-main', 'bpm_config', [
            'mechanic' => bpm_mechanic_type(),
            'flow'     => bpm_flow_steps(),
            'next_url' => bpm_next_step_url(),
        ]);
    }
});
