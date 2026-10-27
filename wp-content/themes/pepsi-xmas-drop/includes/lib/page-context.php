<?php
/**
 * Page-context helper for theme templates.
 *
 * Every page template was duplicating ~25 lines of asset/bg-deco resolution
 * + the wrapper-background sprintf. `pf_page_context()` rolls all of that
 * into a single call so templates collapse to their unique markup.
 *
 * Usage in a template:
 *
 *     $ctx = pf_page_context( 'how-to-play', [
 *         'assets' => [ 'hero_image', 'header_graphic' ],
 *     ] );
 *     get_header();
 *     ?>
 *     <main style="background-image:url('<?php echo esc_url($ctx['hero_image_url']); ?>')">
 *       <img src="<?php echo esc_url($ctx['brand_logo_url']); ?>" />
 *       …
 *     </main>
 *
 * Always available on `$ctx` (no opts needed):
 *   - page_key, bg_deco (array), bg_deco_url, bg_deco_position, bg_deco_size
 *   - brand_logo_url, brand_logo_style
 *   - partner_logo_url, partner_logo_style
 *
 * Per-page assets requested via `assets`:
 *   - $ctx[$slot]['url'|'style'|'size'|'alignment']
 *   - shortcut keys: $ctx[$slot.'_url'], $ctx[$slot.'_style']
 *
 * Options:
 *   - assets           list<string>  per-page asset slots to resolve (default [])
 *   - multi_fallback   list<string>  fallback page keys for bg deco + asset URLs
 *                                    (forwards to *_multi resolvers). When omitted
 *                                    the single-key resolvers are used.
 *   - set_wrapper_style bool         write $GLOBALS['pepsi_site_wrapper_style']
 *                                    (default true — required by header.php)
 */
defined( 'ABSPATH' ) || exit;

function pf_page_context( string $page_key, array $opts = [] ): array {
	$opts = array_merge( [
		'assets'            => [],
		'multi_fallback'    => null,
		'set_wrapper_style' => true,
	], $opts );

	$multi_keys = is_array( $opts['multi_fallback'] ) && ! empty( $opts['multi_fallback'] )
		? array_values( array_filter( array_map( 'strval', $opts['multi_fallback'] ) ) )
		: null;

	// ── Background decoration ────────────────────────────────────────────────
	if ( $multi_keys !== null ) {
		$bg_deco = function_exists( 'pepsi_resolve_bg_deco_multi' )
			? pepsi_resolve_bg_deco_multi( $multi_keys )
			: [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
		$bg_deco_url = function_exists( 'pepsi_resolve_asset_url_multi' )
			? pepsi_resolve_asset_url_multi( 'bg_deco_image', $multi_keys )
			: '';
	} else {
		$bg_deco = function_exists( 'pepsi_resolve_bg_deco' )
			? pepsi_resolve_bg_deco( $page_key )
			: [ 'url' => '', 'position' => '50% 100%', 'size' => '100%' ];
		$bg_deco_url = function_exists( 'pepsi_resolve_asset_url' )
			? pepsi_resolve_asset_url( 'bg_deco_image', $page_key )
			: ( function_exists( 'bpm_img_url' ) ? (string) bpm_img_url( 'bg_deco_image', $page_key ) : '' );
	}

	// ── Brand + partner logos (global, never per-page) ────────────────────────
	$brand_logo_url = function_exists( 'pepsi_resolve_asset_url' )
		? pepsi_resolve_asset_url( 'brand_logo', null )
		: ( function_exists( 'bpm_img_url' ) ? (string) bpm_img_url( 'brand_logo', null ) : '' );
	$partner_logo_url = function_exists( 'pepsi_resolve_asset_url' )
		? pepsi_resolve_asset_url( 'partner_logo', null )
		: ( function_exists( 'bpm_img_url' ) ? (string) bpm_img_url( 'partner_logo', null ) : '' );

	$brand_logo_style = function_exists( 'pepsi_asset_style_attr' )
		? pepsi_asset_style_attr( 'brand_logo' )
		: '';
	$partner_logo_style = function_exists( 'pepsi_asset_style_attr' )
		? pepsi_asset_style_attr( 'partner_logo' )
		: '';

	$ctx = [
		'page_key'           => $page_key,
		'bg_deco'            => $bg_deco,
		'bg_deco_url'        => (string) $bg_deco_url,
		'bg_deco_position'   => (string) ( $bg_deco['position'] ?? '50% 100%' ),
		'bg_deco_size'       => (string) ( $bg_deco['size'] ?? '100%' ),
		'brand_logo_url'     => (string) $brand_logo_url,
		'brand_logo_style'   => (string) $brand_logo_style,
		'partner_logo_url'   => (string) $partner_logo_url,
		'partner_logo_style' => (string) $partner_logo_style,
		'assets'             => [],
	];

	// ── Per-page asset slots ─────────────────────────────────────────────────
	foreach ( (array) $opts['assets'] as $slot ) {
		$slot = (string) $slot;
		if ( $slot === '' ) {
			continue;
		}

		if ( $multi_keys !== null ) {
			$url = function_exists( 'pepsi_resolve_asset_url_multi' )
				? pepsi_resolve_asset_url_multi( $slot, $multi_keys )
				: '';
		} else {
			$url = function_exists( 'pepsi_resolve_asset_url' )
				? pepsi_resolve_asset_url( $slot, $page_key )
				: ( function_exists( 'bpm_img_url' ) ? (string) bpm_img_url( $slot, $page_key ) : '' );
		}

		$style = function_exists( 'pepsi_page_asset_style_attr' )
			? pepsi_page_asset_style_attr( $slot, $page_key )
			: '';
		$size = function_exists( 'pepsi_resolve_page_asset_size' )
			? pepsi_resolve_page_asset_size( $slot, $page_key )
			: '';
		$alignment = function_exists( 'pepsi_resolve_page_asset_alignment' )
			? pepsi_resolve_page_asset_alignment( $slot, $page_key )
			: 'center';

		$ctx['assets'][ $slot ] = [
			'url'       => (string) $url,
			'style'     => (string) $style,
			'size'      => (string) $size,
			'alignment' => (string) $alignment,
		];
		// Top-level shortcut keys so templates can read $ctx['hero_image_url']
		// instead of $ctx['assets']['hero_image']['url'].
		$ctx[ $slot . '_url' ]   = (string) $url;
		$ctx[ $slot . '_style' ] = (string) $style;
	}

	// ── Site wrapper background (header.php reads this global) ───────────────
	if ( $opts['set_wrapper_style'] ) {
		$GLOBALS['pepsi_site_wrapper_style'] = sprintf(
			"background-image: url('%s'); background-position: %s; background-size: %s;",
			esc_url( $ctx['bg_deco_url'] ),
			esc_attr( $ctx['bg_deco_position'] ),
			esc_attr( $ctx['bg_deco_size'] )
		);
	}

	return $ctx;
}

/**
 * Render the brand-logo image tag using the resolved context.
 * Skips silently if the URL is empty so a missing logo never leaves a broken <img>.
 *
 * @param array  $ctx     pf_page_context() return value
 * @param string $extra_class  optional extra class names appended to the BEM defaults
 */
function pf_render_brand_logo( array $ctx, string $extra_class = '' ): void {
	if ( empty( $ctx['brand_logo_url'] ) ) {
		return;
	}
	printf(
		'<img class="scene__brand-logo %s" src="%s" alt="Brand logo" style="%s" />',
		esc_attr( trim( $extra_class ) ),
		esc_url( $ctx['brand_logo_url'] ),
		esc_attr( $ctx['brand_logo_style'] )
	);
}

/**
 * Render the partner-logo image tag using the resolved context. Same contract
 * as pf_render_brand_logo() — silent when URL is empty.
 */
function pf_render_partner_logo( array $ctx, string $extra_class = '' ): void {
	if ( empty( $ctx['partner_logo_url'] ) ) {
		return;
	}
	printf(
		'<img class="scene__partner-logo %s" src="%s" alt="Partner logo" style="%s" />',
		esc_attr( trim( $extra_class ) ),
		esc_url( $ctx['partner_logo_url'] ),
		esc_attr( $ctx['partner_logo_style'] )
	);
}
