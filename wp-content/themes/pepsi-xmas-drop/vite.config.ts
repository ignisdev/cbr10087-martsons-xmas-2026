import { defineConfig } from 'vite';
import { resolve }      from 'path';

export default defineConfig( ( { mode } ) => ( {
	root: '.',
	base: './',

	// Treat binary 3D model formats as static assets (returns a URL string on import).
	assetsInclude: [ '**/*.glb', '**/*.gltf' ],

	build: {
		outDir:    'dist',
		emptyOutDir: true,

		// Two ES-module entries (WordPress enqueues them as type="module" — see
		// assets.php):
		//   site.bundle.js — every page: transitions, age gate, forms, photo.
		//   game.bundle.js — /game/ only: Three.js, GSAP and the game.
		// Code both use (transitions, sounds, loader) is split into a shared
		// chunk, so it runs once per page and is cached across pages.
		rollupOptions: {
			input: {
				site: resolve( __dirname, 'src/site.ts' ),
				game: resolve( __dirname, 'src/game.ts' ),
			},
			output: {
				format:         'es',
				entryFileNames: '[name].bundle.js',
				chunkFileNames: 'chunks/[name]-[hash].js',
				assetFileNames: ( assetInfo ) => {
					// Output the extracted CSS to a predictable name so WordPress
					// can enqueue it from a fixed path.
					if ( assetInfo.names?.some( n => n.endsWith( '.css' ) ) ) {
						return 'style.css';
					}
					return 'assets/[name][extname]';
				},
			},
		},

		// No HTML entry, so no modulepreload polyfill to inject.
		modulePreload: false,

		// dist/manifest.json maps each entry to its (content-hashed) chunk
		// imports, so assets.php can preload / pre-cache them by name.
		manifest: 'manifest.json',

		// Keep binary assets as separate files; 1 byte threshold = never inline
		assetsInlineLimit: 1,

		minify:    mode === 'production' ? 'esbuild' : false,
		sourcemap: mode !== 'production',

		// Target modern mobile browsers (iOS Safari 15+, Chrome Android 90+)
		target: [ 'es2020', 'chrome90', 'safari15' ],
	},

	// Dev server (unused — game runs through WordPress, use `npm run watch` instead)
	server: {
		port: 3000,
	},
} ) );
