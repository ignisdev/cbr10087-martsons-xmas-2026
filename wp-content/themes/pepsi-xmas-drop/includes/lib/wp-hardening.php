<?php
/**
 * WordPress hardening — surface reduction for the public site.
 *
 * Adapted from the Ignis security ruleset. Anything that would block the
 * theme's own anonymous endpoints (the `pepsi-xmas/v1/*` REST namespace,
 * Stripe webhooks) is whitelisted; everything else gets the strict default.
 */
defined( 'ABSPATH' ) || exit;

// ── Disable XML sitemap (we use a separate sitemap solution if at all). ──────
add_filter( 'wp_sitemaps_enabled', '__return_false' );

// ── Remove WordPress version disclosure ──────────────────────────────────────
add_filter( 'the_generator', '__return_empty_string' );

// Strip `?ver=` from core/plugin CSS/JS URLs (version disclosure). The theme's
// own dist/ bundles are exempt: their ver is the file's mtime (assets.php) and
// is the only cache-buster they have — stripping it left browsers running a
// stale style.css / game.bundle.js after every build.
add_filter( 'style_loader_src', 'pf_strip_version_query', 9999 );
add_filter( 'script_loader_src', 'pf_strip_version_query', 9999 );
function pf_strip_version_query( $src ) {
	if ( ! is_string( $src ) || strpos( $src, 'ver=' ) === false ) {
		return $src;
	}
	$theme_dist = '/themes/' . get_template() . '/dist/';
	if ( strpos( (string) wp_parse_url( $src, PHP_URL_PATH ), $theme_dist ) !== false ) {
		return $src;
	}
	return remove_query_arg( 'ver', $src );
}

// ── Block REST user-enumeration endpoints ────────────────────────────────────
// `/wp-json/wp/v2/users` and `…/users/<id>` will leak the admin username if
// any post is authored. Drop them entirely.
add_filter( 'rest_endpoints', function ( $endpoints ) {
	unset( $endpoints['/wp/v2/users'] );
	unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	return $endpoints;
} );

// ── REST authentication wall (with theme + Stripe whitelist) ─────────────────
//
// The Ignis preset demands authenticated admin for ALL REST traffic. That
// would 401 the game's anonymous endpoints (/wp-json/pepsi-xmas/v1/*)
// and Stripe's webhook hits. We allow:
//   - Anything under our own namespace (`pepsi-xmas/v1/`)
//   - The Stripe webhook hits (already signature-verified)
//   - GF AJAX / front-end form REST if present
// Everything else inherits the "must be admin" rule.
add_filter( 'rest_authentication_errors', function ( $result ) {
	if ( ! empty( $result ) ) {
		return $result; // earlier filter already decided
	}

	$rest_route = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
	$rest_route = strtolower( $rest_route );

	$open_prefixes = [
		'/wp-json/pepsi-xmas/v1/', // our game + stripe webhook
		'/index.php?rest_route=/pepsi-xmas/v1/',
		'/wp-json/gf/v2/',             // Gravity Forms public form endpoints (AJAX submit)
		'/index.php?rest_route=/gf/v2/',
		'/wp-json/wordfence/v1/',      // Wordfence scanner + firewall comms
		'/index.php?rest_route=/wordfence/v1/',
	];
	foreach ( $open_prefixes as $prefix ) {
		if ( strpos( $rest_route, $prefix ) === 0 || strpos( $rest_route, $prefix ) !== false ) {
			return $result; // let route-level permission_callback handle it
		}
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'You are not currently logged in.', [ 'status' => 401 ] );
	}
	if ( ! current_user_can( 'administrator' ) ) {
		return new WP_Error( 'rest_not_admin', 'You are not an administrator.', [ 'status' => 401 ] );
	}
	return $result;
} );

// JSONP is a legacy CORS workaround we don't use; disable so it can't be
// abused for data exfiltration via callback parameter.
add_filter( 'rest_jsonp_enabled', '__return_false' );

// ── Disable RSS / Atom / RDF feeds (campaign site has no blog) ───────────────
function pf_disable_feeds() {
	wp_safe_redirect( home_url() );
	die;
}
add_action( 'do_feed',               'pf_disable_feeds', -1 );
add_action( 'do_feed_rdf',           'pf_disable_feeds', -1 );
add_action( 'do_feed_rss',           'pf_disable_feeds', -1 );
add_action( 'do_feed_rss2',          'pf_disable_feeds', -1 );
add_action( 'do_feed_atom',          'pf_disable_feeds', -1 );
add_action( 'do_feed_rss2_comments', 'pf_disable_feeds', -1 );
add_action( 'do_feed_atom_comments', 'pf_disable_feeds', -1 );

// Strip feed <link> tags from the <head>.
add_filter( 'feed_links_show_posts_feed',    '__return_false', -1 );
add_filter( 'feed_links_show_comments_feed', '__return_false', -1 );
remove_action( 'wp_head', 'feed_links',       2 );
remove_action( 'wp_head', 'feed_links_extra', 3 );

// ── Hide WordPress version meta + remove EditURI / wlwmanifest / shortlink ──
remove_action( 'wp_head', 'wp_generator' );
remove_action( 'wp_head', 'rsd_link' );
remove_action( 'wp_head', 'wlwmanifest_link' );
remove_action( 'wp_head', 'wp_shortlink_wp_head' );
