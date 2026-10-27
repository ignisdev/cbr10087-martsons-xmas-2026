<?php
defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	// Remove jQuery only on the game page — Gravity Forms needs it everywhere else
	if ( is_page_template( 'page-game.php' ) ) {
		wp_deregister_script( 'jquery' );
	}

	$dist     = PF_THEME_DIR . '/dist';
	// Live (filter-aware) URL — NOT the PF_THEME_URI const. The const
	// is defined in definitions.php before the tunnel shim's filter on
	// template_directory_uri registers, so it bakes localhost permanently
	// and asset URLs in the localized config would point at localhost from a
	// tunnel page. get_template_directory_uri() goes through the filter every
	// call, so it's correct on both hosts.
	$theme_uri = get_template_directory_uri();
	$dist_uri  = $theme_uri . '/dist';

	// ── Bundles (Vite ES-module output — see vite.config.ts) ───────────────────
	// site.bundle.js on every page; game.bundle.js (Three.js + the game) only on
	// /game/, so the other screens don't download it. Both are type="module"
	// (pf_module_script_handles()); shared code is in dist/chunks/, imported by
	// relative URL and content-hashed.
	wp_enqueue_script(
		'pepsi-xmas-site',
		$dist_uri . '/site.bundle.js',
		[],
		pf_dist_ver( 'site.bundle.js' ),
		true
	);
	if ( is_page_template( 'page-game.php' ) ) {
		wp_enqueue_script(
			'pepsi-xmas-game',
			$dist_uri . '/game.bundle.js',
			[ 'pepsi-xmas-site' ],
			pf_dist_ver( 'game.bundle.js' ),
			true
		);
	}

	// ── Game styles (Vite injects CSS; also enqueue the extracted sheet) ───────
	$style_file = $dist . '/style.css';
	$style_ver  = file_exists( $style_file ) ? filemtime( $style_file ) : PF_THEME_VERSION;

	if ( file_exists( $style_file ) ) {
		wp_enqueue_style(
			'pepsi-xmas-styles',
			$dist_uri . '/style.css',
			[],
			$style_ver
		);
	}

	// ── Resolve page URLs by template ──────────────────────────────────────────
	$resolve_page_url = function ( string $template ): string {
		$pages = get_posts( [
			'post_type'      => 'page',
			'meta_key'       => '_wp_page_template',
			'meta_value'     => $template,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		return ! empty( $pages ) ? (string) get_permalink( $pages[0] ) : '';
	};

	$congratulations_url = $resolve_page_url( 'page-congratulations.php' );
	$non_winner_url      = $resolve_page_url( 'page-non-winner.php' );
	$daily_limit_url     = $resolve_page_url( 'page-daily-limit.php' );

	// ── Game SFX file URLs ─────────────────────────────────────────────────────
	// Served directly from the theme's src/audio/files/ folder — no build-step
	// copy needed. The JS sounds module fetch()es each URL once and decodes
	// it into an AudioBuffer on first user gesture (autoplay policy).
	$audio_uri = $theme_uri . '/src/audio/files';
	// ambience / drop have no Xmas audio yet — the sounds module skips any
	// key without a URL. Add them here when the files are supplied.
	$sound_urls = (array) apply_filters( 'pf_game_sound_urls', [
		'cheer' => $audio_uri . '/cheer.aac',
		'groan' => $audio_uri . '/groan.aac',
	] );

	// One sprite per type — the 9 drop pieces (types from pf_drop_pieces())
	// plus the 4 decorative light strands. Built from the source PNGs by
	// scripts/build-baubles.py; ?v=mtime so a rebuilt sprite is never served stale.
	$piece_images = [];
	$sprites_dir  = '/src/images/baubles/web/';
	foreach ( [ 'cracker', 'present', 'bauble', 'mouth', 'heart', 'snowman', 'snowglobe', 'gift', 'star', 'lights1', 'lights2', 'lights3', 'lights4' ] as $piece_type ) {
		$sprite_file = get_template_directory() . $sprites_dir . $piece_type . '.webp';
		$piece_images[ $piece_type ] = add_query_arg( 'v', (int) @filemtime( $sprite_file ), $theme_uri . $sprites_dir . $piece_type . '.webp' );
	}

	// ── Pass WordPress config to the bundles ───────────────────────────────────
	$game_config = [
		// Bauble artwork on the loaders (game boot, photo PROCESSING).
		'loader_image'        => pf_loader_image_url(),
		// Sprite per type (drop pieces + light strands), built from the source
		// PNGs by scripts/build-baubles.py. Keys match the types in pf_drop_pieces().
		'piece_images'        => (object) apply_filters( 'pf_piece_image_urls', $piece_images ),
		'track_image'         => $theme_uri . '/src/images/bauble_track.png',
		'congratulations_url' => $congratulations_url,
		'non_winner_url'      => $non_winner_url,
		'daily_limit_url'     => $daily_limit_url,
		'rest_url'            => rest_url( 'pepsi-xmas/v1' ),
		'rest_nonce'          => wp_create_nonce( 'wp_rest' ),
		'competition_id'      => function_exists( 'pf_resolve_competition_id' ) ? pf_resolve_competition_id() : (int) get_option( 'pepsi_football_competition_id', 0 ),
		'sounds'              => $sound_urls,
	];

	// rest-security.php hooks into this filter to add `pf_nonce` — an anonymous
	// per-session token the JS replays via X-PF-Nonce on every game endpoint.
	$game_config = (array) apply_filters( 'pf_game_config_extra', $game_config );

	// How-to-play warms the HTTP cache with everything /game/ needs before its
	// loader can finish, while the player reads the instructions — so tapping
	// Play boots from cache. Done in JS (site.ts) rather than <link
	// rel=prefetch>, which iOS Safari doesn't support.
	if ( is_page_template( 'page-how-to-play.php' ) ) {
		$game_config['warm_urls'] = array_values( array_merge(
			[ add_query_arg( 'ver', pf_dist_ver( 'game.bundle.js' ), $dist_uri . '/game.bundle.js' ) ],
			pf_dist_entry_imports( 'src/game.ts' ),
			[
				pf_game_background_url(),
				$game_config['track_image'],
			],
			array_values( (array) $game_config['piece_images'] )
		) );
	}

	wp_localize_script( 'pepsi-xmas-site', 'PepsiXmasConfig', $game_config );
} );

/**
 * URLs of the chunks a Vite entry imports statically (dist/manifest.json), so
 * they can be preloaded / pre-cached instead of discovered only once the entry
 * has been parsed. Chunk names are content-hashed — no ?ver needed.
 */
function pf_dist_entry_imports( string $entry ): array {
	static $manifest = null;
	if ( $manifest === null ) {
		$file     = PF_THEME_DIR . '/dist/manifest.json';
		$manifest = file_exists( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : [];
	}
	$base = get_template_directory_uri() . '/dist/';
	$urls = [];
	$walk = function ( string $key ) use ( &$walk, &$urls, $manifest, $base ): void {
		foreach ( (array) ( $manifest[ $key ]['imports'] ?? [] ) as $import ) {
			$url = $base . ( $manifest[ $import ]['file'] ?? '' );
			if ( ! isset( $urls[ $url ] ) && ! empty( $manifest[ $import ]['file'] ) ) {
				$urls[ $url ] = true;
				$walk( $import );
			}
		}
	};
	$walk( $entry );
	return array_keys( $urls );
}

/** Cache-busting ver for a dist/ file — its mtime. */
function pf_dist_ver( string $file ): string {
	$path = PF_THEME_DIR . '/dist/' . $file;
	return file_exists( $path ) ? (string) filemtime( $path ) : (string) PF_THEME_VERSION;
}

/**
 * Loader bauble artwork — the game's own bauble sprite, so it's one cached
 * file (and already in warm_urls via piece_images). ?v=mtime like the sprites.
 */
function pf_loader_image_url(): string {
	$rel = '/src/images/baubles/web/bauble.webp';
	return add_query_arg( 'v', (int) @filemtime( get_template_directory() . $rel ), get_template_directory_uri() . $rel );
}

/** /game/ backdrop (wide_bg — game_bg.png extended sideways; see _game.scss). */
function pf_game_background_url(): string {
	return get_template_directory_uri() . '/src/images/wide_bg.webp';
}

/** Handles WordPress must print as <script type="module"> (Vite ES output). */
function pf_module_script_handles(): array {
	return [ 'pepsi-xmas-site', 'pepsi-xmas-game' ];
}

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( ! in_array( $handle, pf_module_script_handles(), true ) ) {
		return $tag;
	}
	// Only the external <script src> — leave the localized-config inline
	// script before it as a classic script.
	return (string) preg_replace(
		'/<script(?![^>]*\btype=)([^>]*\bsrc=)/',
		'<script type="module"$1',
		(string) preg_replace( '/(<script[^>]*\bsrc=[^>]*?)\s+type=([\'"])[^\'"]*\2/', '$1', (string) $tag )
	);
}, 10, 2 );

// ── Deregister Gravity Forms' jQuery-UI datepicker bundle (front-end only) ───
// Date fields render as plain DD/MM/YYYY text inputs (see methods.php), so
// the datepicker JS + jQuery UI dependencies are dead weight on every page
// that has a GF form (~18 KB across multiple scripts pre-paint). Using
// deregister (not dequeue) so dependents can't pull them back in.
// Runs at wp_print_scripts — the last hook before scripts emit — so it wins
// regardless of what order GF / WP / other plugins enqueued at.
//
// wp_print_scripts also fires in wp-admin and on wp-login.php. Deregistering
// core jQuery-UI handles in either context trips WP core's protection
// (_doing_it_wrong: "Do not deregister the jquery-ui-datepicker script in the
// administration area") and breaks the admin/login date pickers. is_admin()
// is false on wp-login.php, so guard that page explicitly as well.
add_action( 'wp_print_scripts', function () {
	if ( is_admin() || ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) ) {
		return;
	}
	$handles = [
		'gform_datepicker_init',
		'gforms_datepicker',
		'jquery-ui-datepicker',
	];
	foreach ( $handles as $handle ) {
		wp_dequeue_script( $handle );
		wp_deregister_script( $handle );
	}
}, 100 );

// ── <head> resource hints: preload campaign fonts + LCP background image ─────
// The two Pepsi woff2 files are used immediately by the age-gate headline;
// without preload Lighthouse measures them entering the network ~2.9 s in.
// The bg_deco_image is the LCP element on most page templates — preloading
// it lets the browser fetch in parallel with the HTML parse instead of
// waiting for the CSS that references it via background-image.
add_action( 'wp_head', function () {
	$theme_uri = get_template_directory_uri();
	$fonts     = [
		$theme_uri . '/fonts/PepsiOwners-Extended.woff2',
		$theme_uri . '/fonts/PepsiOwners-Compressed.woff2',
	];
	foreach ( $fonts as $font_url ) {
		printf(
			'<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>' . "\n",
			esc_url( $font_url )
		);
	}

	// /game/: start the game bundle (+ its chunks) and backdrop from <head>
	// rather than when the footer scripts are reached. (Usually already warm
	// from how-to-play — see warm_urls.) The loader image is an <img> in the
	// page, so the browser finds it on its own.
	if ( is_page_template( 'page-game.php' ) ) {
		printf(
			'<link rel="modulepreload" href="%s">' . "\n",
			esc_url( add_query_arg( 'ver', pf_dist_ver( 'game.bundle.js' ), $theme_uri . '/dist/game.bundle.js' ) )
		);
		foreach ( pf_dist_entry_imports( 'src/game.ts' ) as $chunk_url ) {
			printf( '<link rel="modulepreload" href="%s">' . "\n", esc_url( $chunk_url ) );
		}
		printf( '<link rel="preload" as="image" href="%s">' . "\n", esc_url( pf_game_background_url() ) );
	}

	// Resolve the current page's BPM background image (if any) and preload it.
	// pepsi_resolve_asset_url() returns '' when no image is configured.
	if ( ! function_exists( 'pepsi_resolve_asset_url' ) ) {
		return;
	}
	$page_key = '';
	if ( is_page_template( 'page-age-gate.php' ) || is_front_page() ) {
		$page_key = 'age-gate';
	} elseif ( is_page_template( 'page-how-to-play.php' ) ) {
		$page_key = 'how-to-play';
	}
	if ( $page_key === '' ) {
		return;
	}
	$bg_url = pepsi_resolve_asset_url( 'bg_deco_image', $page_key );
	if ( $bg_url !== '' ) {
		printf(
			'<link rel="preload" as="image" fetchpriority="high" href="%s">' . "\n",
			esc_url( $bg_url )
		);
	}
	// Age gate: warm the contact-step bg so the swap after the DOB step
	// doesn't flash (see page-age-gate.php).
	if ( $page_key === 'age-gate' ) {
		$contact_bg_url = pepsi_resolve_asset_url( 'bg_deco_image', 'age-gate-contact' );
		if ( $contact_bg_url !== '' && $contact_bg_url !== $bg_url ) {
			printf(
				'<link rel="preload" as="image" fetchpriority="low" href="%s">' . "\n",
				esc_url( $contact_bg_url )
			);
		}
	}
}, 1 );
