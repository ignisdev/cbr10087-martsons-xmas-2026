/**
 * gulpfile.js — Britvic Promo Base
 * ─────────────────────────────────
 * Tasks
 *   gulp styles   — compile scss/style.scss → dist/dist.css
 *   gulp scripts  — bundle js/src/PromoApp.js → dist/dist.js  (via Rollup)
 *   gulp watch    — watch & rebuild on changes
 *   gulp build    — styles + scripts (one-off)
 *
 * Set NODE_ENV=production for minified output with no sourcemaps.
 */

'use strict';

const { src, dest, watch, series, parallel } = require('gulp');
const sass        = require('gulp-sass')(require('sass'));
const autoprefixer = require('gulp-autoprefixer');
const cleanCSS    = require('gulp-clean-css');
const sourcemaps  = require('gulp-sourcemaps');
const rename      = require('gulp-rename');
const { rollup }  = require('rollup');
const nodeResolve = require('@rollup/plugin-node-resolve');
const babel       = require('@rollup/plugin-babel');
const replace     = require('@rollup/plugin-replace');

const isProd = process.env.NODE_ENV === 'production';

// ─── Paths ───────────────────────────────────────────────────────────────────

const paths = {
  scss:   { src: 'scss/style.scss',       watch: 'scss/**/*.scss', dest: 'dist' },
  js:     { src: 'js/src/PromoApp.js',    watch: 'js/src/**/*.js',  dest: 'dist' },
};

// ─── SCSS ─────────────────────────────────────────────────────────────────────

function styles() {
  return src(paths.scss.src)
    .pipe(isProd ? src(paths.scss.src) : sourcemaps.init())
    .pipe(
      sass({ quietDeps: true }).on('error', sass.logError)
    )
    .pipe(autoprefixer())
    .pipe(isProd ? cleanCSS({ level: 2 }) : cleanCSS({ level: 0 }))
    .pipe(rename('dist.css'))
    .pipe(isProd ? dest(paths.scss.dest) : sourcemaps.write('.'))
    .pipe(dest(paths.scss.dest));
}

// ─── JS (Rollup) ─────────────────────────────────────────────────────────────

async function scripts() {
  const bundle = await rollup({
    input:   paths.js.src,
    plugins: [
      nodeResolve.default({ browser: true }),
      replace.default({
        'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'development'),
        preventAssignment: true,
      }),
      babel.default({
        babelHelpers: 'bundled',
        presets: [['@babel/preset-env', { targets: '> 0.5%, last 2 versions, not dead' }]],
        exclude: 'node_modules/**',
      }),
    ],
    external: [], // GSAP bundled in; jQuery assumed global (window.jQuery)
  });

  await bundle.write({
    file:      `${paths.js.dest}/dist.js`,
    format:    'iife',
    name:      'PromoApp',
    sourcemap: !isProd,
    compact:   isProd,
  });
}

// ─── Watch ───────────────────────────────────────────────────────────────────

function watchFiles() {
  watch(paths.scss.watch, styles);
  watch(paths.js.watch,   scripts);
}

// ─── Exports ─────────────────────────────────────────────────────────────────

exports.styles  = styles;
exports.scripts = scripts;
exports.watch   = series(parallel(styles, scripts), watchFiles);
exports.build   = parallel(styles, scripts);
exports.default = exports.build;
