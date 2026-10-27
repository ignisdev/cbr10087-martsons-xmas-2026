<?php
/**
 * Template Name: Stripe Return
 */
defined( 'ABSPATH' ) || exit;

if ( function_exists( 'pf_process_stripe_return_page' ) ) {
	pf_process_stripe_return_page();
}

wp_safe_redirect( home_url( '/' ) );
exit;
