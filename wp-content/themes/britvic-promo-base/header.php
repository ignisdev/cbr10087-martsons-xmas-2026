<?php
/**
 * Site header — outputs <head>, opens <body> and .site-wrapper.
 * CSS custom properties are injected here by BPM_Brand_Config::output_css_vars()
 * hooked to wp_head at priority 20.
 *
 * @package britvic-promo-base
 */
if (!session_id()) {
    session_start();
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); // BPM brand CSS vars injected here ?>
</head>
<body <?php body_class(); ?>>

<!-- Loading screen — hidden by JS after page ready -->
<div class="loading-screen" aria-hidden="true">
    <div class="loading-screen__spinner"></div>
</div>

<!-- Terms & Conditions modal -->
<div class="terms-wrapper" role="dialog" aria-modal="true" aria-label="Terms and Conditions">
    <div class="terms-wrapper__inner">
        <button id="termsClose" class="terms-wrapper__close" aria-label="Close terms">&times;</button>
        <div class="terms-wrapper__content">
            <?php
            $terms_page = get_page_by_path('terms-and-conditions');
            if ($terms_page) {
                echo apply_filters('the_content', $terms_page->post_content);
            } else {
                echo '<p>Terms and conditions content not found. Please create a page with the slug <code>terms-and-conditions</code>.</p>';
            }
            ?>
        </div>
    </div>
</div>

<div class="site-wrapper">
