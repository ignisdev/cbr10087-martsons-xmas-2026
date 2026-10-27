<?php
/**
 * Theme Methods — security hardening and theme setup.
 *
 * @package britvic-promo-base
 */
if (!defined('ABSPATH')) {
    exit;
}

// Theme supports
add_action('after_setup_theme', function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['script', 'style', 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
});

// ── Security ──────────────────────────────────────────────────────────────────

// Hide WordPress version
add_filter('the_generator', '__return_empty_string');
remove_action('wp_head', 'wp_generator');

// Disable XML-RPC
add_filter('xmlrpc_enabled', '__return_false');

// Disable feeds (promotions don't need them)
add_action('do_feed',       'bpm_theme_disable_feed', 1);
add_action('do_feed_rdf',   'bpm_theme_disable_feed', 1);
add_action('do_feed_rss',   'bpm_theme_disable_feed', 1);
add_action('do_feed_rss2',  'bpm_theme_disable_feed', 1);
add_action('do_feed_atom',  'bpm_theme_disable_feed', 1);

function bpm_theme_disable_feed(): void {
    wp_die(__('This site does not have feeds.'));
}

remove_action('wp_head', 'feed_links',       2);
remove_action('wp_head', 'feed_links_extra', 3);

// Remove unnecessary head items
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_shortlink_wp_head');
remove_action('wp_head', 'wp_oembed_add_discovery_links');

// Restrict REST API to authenticated users
add_filter('rest_authentication_errors', function ($result) {
    if (!empty($result)) {
        return $result;
    }
    if (!is_user_logged_in()) {
        return new WP_Error('rest_not_logged_in', 'REST API restricted.', ['status' => 401]);
    }
    return $result;
});

// Hide admin bar on frontend
add_filter('show_admin_bar', '__return_false');
