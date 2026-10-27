# Pepsi Xmas Drop 2026 — Implementation Skills

Reusable patterns and procedures. Reference when implementing new features or templates.

---

## Build

```bash
cd wp-content/themes/pepsi-xmas-drop
npm ci && npm run build      # tsc --noEmit && vite build → dist/game.bundle.js + dist/style.css
npm run watch                # rebuild on change (game runs through WordPress, not the Vite dev server)
composer install             # Stripe PHP SDK (first time)
```

Single theme — no brand-variant twin to mirror.

---

## Tune the Game

All rules live in `includes/lib/drop-engine.php` and reach the client via
`/session/start`, so tuning is a PHP-only change:

- Level speed / tolerance → `pf_drop_levels()` (or the `pf_drop_levels` filter).
- Piece order / types / anchor x → `pf_drop_pieces()` (x measured on the key visual,
  see `PF_DROP_REF_CENTRE_X`). Piece height / size / rotation / layer, and the
  light strands → `src/renderer/baubles.ts` (`cy` / `layer` / optional
  per-sprite `scale`, all in key-visual px; the tree is finalised — the old
  layout editor was removed once it was).
- Star assist width → `PF_KILLER_ASSIST_MULT`.

After any change: run the engine self-test (below) and play a round on `/game/`.
Keep `pf_drop_engine_self_test()`'s config-shape case passing (9 pieces,
3-3-2-1, speed↑ tolerance↓).

## Bauble Artwork

Pieces and light strands are flat sprites. The numbered source PNGs (crops from one master, so their sizes are true to each other) live in
`src/images/baubles/`; after changing one, rebuild the web assets:

```sh
python3 scripts/build-baubles.py   # needs Pillow
```

This writes `src/images/baubles/web/<type>.webp` (what the game loads) and
regenerates `src/renderer/bauble-outlines.ts` (each sprite's silhouette, traced
from its alpha channel, used for the dashed placeholder and the layout
editor's hit-testing). File → type mapping is `PIECES` in the script. Then
`npm run build` (only needed if the outlines changed — sprite URLs carry
`?v=<mtime>`).

Sprite URLs are passed as `piece_images` and can be overridden with the
`pf_piece_image_urls` filter. A sprite that fails to load is drawn as a white
silhouette (logged as `[pieces] … failed` in the console); one still loading
after 6 s starts as a silhouette and is swapped in when it arrives.

(The source GLBs in `src/images/baubles/glbs/` are not used by the game and
are gitignored.)

---

## Add a BPM Schema Migration

Schema changes for `wp_bpm_*` tables live in `BPM_Database_Manager::run_migrations()` in the plugin. The runner checks `bpm_migrations_run` option (array of already-run keys) and fires on `admin_init`.

```php
// In britvic-promotion-manager/includes/classes/class-database-manager.php
// Add a new entry to the migrations array:

'add_my_column_to_bpm_prize_entries' => function() use ( $wpdb ) {
    $wpdb->query(
        "ALTER TABLE {$wpdb->prefix}bpm_prize_entries
         ADD COLUMN my_column VARCHAR(255) NULL AFTER existing_column"
    );
},
```

Key naming convention: `create_bpm_<table>` or `add_<column>_to_<table>`.
Existing installs pick up the diff on the next admin page load.

---

## Add a New Page Template

Use `pf_page_context()` as the first call. Never copy-paste asset-resolution ladders into templates.

```php
<?php
/**
 * Template Name: My New Page
 */
defined( 'ABSPATH' ) || exit;

$ctx = pf_page_context( 'my-page-key', [
    'assets' => [ 'hero_image', 'header_graphic' ],
    // 'multi_fallback' => [ 'winner', 'non-winner' ],  // only for genuinely shared pages
    // 'set_wrapper_style' => false,                    // only for full-bleed templates
] );

get_header();
?>

<main class="page-my-page" data-js="my-page">
    <?php pf_render_brand_logo( $ctx, 'page-my-page__brand-logo' ); ?>

    <?php if ( $ctx['header_graphic_url'] ) : ?>
        <img class="page-my-page__headline-graphic"
             src="<?php echo esc_url( $ctx['header_graphic_url'] ); ?>"
             style="<?php echo esc_attr( $ctx['header_graphic_style'] ); ?>"
             alt="" />
    <?php else : ?>
        <h1 class="page-my-page__headline"><?php the_title(); ?></h1>
    <?php endif; ?>

    <div class="page-my-page__content">
        <?php echo apply_filters( 'the_content', get_the_content() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>

    <div class="scene__footer page-my-page__footer">
        <?php pf_render_partner_logo( $ctx, 'page-my-page__partner-logo' ); ?>
    </div>
</main>

<?php get_footer(); ?>
```

### `$ctx` keys always available

| Key | Value |
|---|---|
| `page_key` | slug passed in |
| `bg_deco_url`, `bg_deco_position`, `bg_deco_size` | background decoration |
| `brand_logo_url`, `brand_logo_style` | global brand logo |
| `partner_logo_url`, `partner_logo_style` | global partner logo |
| `<slot>_url`, `<slot>_style` | shortcut for each requested asset slot |

`$GLOBALS['pepsi_site_wrapper_style']` is set automatically (so `header.php` paints the bg deco) unless `set_wrapper_style: false` is passed.

### Rules
- Page keys: kebab-case, matching the BPM page-step slug (`how-to-play`, `non-winner`, etc.).
- Multi-fallback only for genuinely shared pages — avoid casual fallback chains.
- Never add page-specific DB queries or competition-ID resolvers in a template. For GF ID resolution: `pf_resolve_gf_id($key)` from `flow-guard.php`.

---

## Form UI Standard

All campaign user-facing form controls must follow the Age Gate visual system.

### Inputs
- Height: `50px` (`box-sizing: border-box`)
- Border radius: pill (`999px`)
- Background: white
- Placeholder color: `#999999`

### Primary buttons
- Height: `50px`
- Border radius: pill (`999px`)
- Background: `#0025ff`
- Text: white, uppercase, `PepsiOwners-Extended`, `14px`

### Widths
- Full-width: `302px`
- Compact: `98px`

### Implementation
Reuse Age Gate SASS patterns from `src/scss/pages/_age-gate.scss`.

**Single source-of-truth selector** for campaign pill buttons:
```scss
.pepsi-pill-button,
.page-age-gate__form .gform_button,
.page-age-gate__form .gform_next_button,
.page-age-gate__form .gform_previous_button {
  // shared pill button styles
}
```

Do not redefine font-size/family per-page unless explicitly approved.

---

## Frontend Architecture

### SASS structure
- Entry: `src/scss/style.scss`
- `scss/_tokens.scss` — design tokens
- `scss/_utilities.scss` — shared utilities
- `scss/components/*` — reusable components
- `scss/pages/*` — per-page styles
- `src/style.css` — deprecation stub (reduce over time, never expand)
- `src/scss/legacy/_base-legacy.scss` — migrated legacy styles

### BEM class naming
```
page-game__score-header    (block__element)
scene__headline            (block__element)
score-header__cell--scored (block__element--modifier)
```

### JS hooks
Use `data-js="..."` for behaviour targeting. Never use styling classes as JS selectors. Avoid id-based selectors unless required by third-party plugins.

### TS modules
Interactive logic → `src/ui/<name>.ts`. Initialised from `src/main.ts`.

---

## Test Win-Gating

Both required before shipping changes to `drop-engine.php` or `pf_api_drop_attempt()`:

```
# 1. Self-test harness (as admin):
GET /wp-json/pepsi-xmas/v1/debug/dropflow-test
# Must return: { "all_pass": true }

# 1b. Pure engine, no WordPress (any PHP 8.1+):
php -r 'require "wp-content/themes/pepsi-xmas-drop/includes/lib/drop-engine.php";
        $r = pf_drop_engine_self_test(); var_dump($r["all_pass"]);'

# 2. Live DEBUG panel on /game/ (Settings → PF Debug ticked):
# PERFECT RUN         — star dead-centre: wins with a live slot, falls without
# STAR IN ASSIST BAND — star at 2× tolerance: wins ONLY with a live slot
# WIDE STAR           — star at 3.5× tolerance: never wins
# RUN SELF-TEST       — calls the harness, prints pass/fail
```

The PF DEBUG header shows whether a prize is live this minute; pair each run
with a live and an empty minute. PHP log line per run end:
`PF FLOW drop/attempt: … piece=8 killer=yes … slot=yes|no outcome=…`.

---

## Enable Debug Panel

**Production / staging:** wp-admin → Settings → PF Debug → tick "Show debug panel" → Save. Untick to hide.

**Game page (`/game/`):** the on-page debug bar only appears when the option is ticked — admins don't see it by default. Other admin debug surfaces still use `pf_is_debug_panel_visible()` (option OR admin).

Never use URL flags, cookies, or `WP_DEBUG` checks to gate debug surfaces. Always call `pf_is_debug_panel_visible()`.
