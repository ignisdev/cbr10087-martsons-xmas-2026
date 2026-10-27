<?php
/**
 * Template Name: Stripe Refresh
 */
defined( 'ABSPATH' ) || exit;

if ( function_exists( 'pf_process_stripe_refresh_page' ) ) {
	pf_process_stripe_refresh_page();
}

wp_safe_redirect( home_url( '/' ) );
exit;
