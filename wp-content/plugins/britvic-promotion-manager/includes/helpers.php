<?php
/**
 * Template Helper Functions
 *
 * Global functions for use in britvic-promo-base page templates.
 * All functions are thin wrappers over BPM_Brand_Config methods.
 *
 * Usage example:
 *   bpm_img('hero_image', 'age-gate')
 *   bpm_color('primary')
 *   bpm_flow_steps()
 *
 * @package BritvicPromotionManager
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Guard — only define once; BPM_Brand_Config must be loaded first.
if (!class_exists('BPM_Brand_Config')) {
    return;
}

// -----------------------------------------------------------------------------
// Assets
// -----------------------------------------------------------------------------

/**
 * Output an <img> tag for a named asset slot.
 *
 * @param string      $slot  Global slot name (e.g. 'brand_logo') or per-page slot
 * @param string|null $page  Page step key (e.g. 'age-gate') for per-page slots
 * @param string      $class Additional CSS class to add to the <img>
 * @param string      $alt   Alt text (falls back to attachment alt)
 * @return void
 */
function bpm_img(string $slot, ?string $page = null, string $class = '', string $alt = ''): void {
    echo BPM_Brand_Config::get_asset_img($slot, $page, $class, $alt);
}

/**
 * Return the URL for a named asset slot.
 *
 * @param string      $slot
 * @param string|null $page
 * @return string URL or empty string
 */
function bpm_img_url(string $slot, ?string $page = null): string {
    return BPM_Brand_Config::get_asset_url($slot, $page);
}

/**
 * Return configured display width for a page asset slot.
 *
 * @param string $slot
 * @param string $page
 * @return string
 */
function bpm_img_size(string $slot, string $page): string {
    return BPM_Brand_Config::get_page_asset_size($slot, $page);
}

/**
 * Return configured horizontal alignment for a page asset slot.
 *
 * @param string $slot
 * @param string $page
 * @return string start|center|end
 */
function bpm_img_alignment(string $slot, string $page): string {
    return BPM_Brand_Config::get_page_asset_alignment($slot, $page);
}

// -----------------------------------------------------------------------------
// Colours & fonts
// -----------------------------------------------------------------------------

/**
 * Return a hex colour value for a token.
 *
 * @param string $token  e.g. 'primary', 'cta', 'bg'
 * @return string Hex string or empty string
 */
function bpm_color(string $token): string {
    return BPM_Brand_Config::get_color($token);
}

/**
 * Return a font-family string for a token.
 *
 * @param string $token  'primary' | 'secondary' | 'body'
 * @return string font-family value
 */
function bpm_font(string $token): string {
    return BPM_Brand_Config::get_font($token);
}

// -----------------------------------------------------------------------------
// Page flow
// -----------------------------------------------------------------------------

/**
 * Return the ordered list of active page steps for the current competition.
 *
 * @return list<string>  e.g. ['age-gate', 'entry', 'game', 'winner', 'thanks']
 */
function bpm_flow_steps(): array {
    return BPM_Brand_Config::get_flow_steps();
}

/**
 * Check whether a page step is active.
 *
 * @param string $step  e.g. 'photo', 'partner', 'game'
 * @return bool
 */
function bpm_is_step_active(string $step): bool {
    return BPM_Brand_Config::is_step_active($step);
}

/**
 * Return the URL of the next step in the flow from the current page.
 *
 * @return string Absolute URL
 */
function bpm_next_step_url(): string {
    return BPM_Brand_Config::get_next_step_url();
}

/**
 * Return the current competition's mechanic type.
 *
 * @return string 'instant_win' | 'spin_wheel' | 'prize_draw'
 */
function bpm_mechanic_type(): string {
    return BPM_Brand_Config::get_mechanic_type();
}

/**
 * Return the current competition ID (detected from page ID).
 *
 * @return int  0 if not detected
 */
function bpm_current_competition_id(): int {
    return BPM_Brand_Config::get_current_competition_id();
}

// -----------------------------------------------------------------------------
// Gravity Forms
// -----------------------------------------------------------------------------

/**
 * Return the Gravity Forms ID for a named form key.
 *
 * Form keys: 'entry', 'photo', 'draw_entry', 'winner_claim', 'draw_winner'
 *
 * @param string $form_key
 * @return int  Gravity Forms form ID or 0
 */
function bpm_get_gf_id(string $form_key): int {
    return BPM_Brand_Config::get_gf_id($form_key);
}

// -----------------------------------------------------------------------------
// Background deco
// -----------------------------------------------------------------------------

/**
 * Output inline style attributes for the decorative background layer on a page.
 * Use inside a style="" attribute or a <style> block.
 *
 * Example in a template:
 *   <div class="site-wrapper" <?php bpm_bg_deco_style('age-gate'); ?>>
 *
 * @param string $page  Page step key
 * @return void
 */
function bpm_bg_deco_style(string $page): void {
    $deco = BPM_Brand_Config::get_bg_deco($page);
    if ($deco['url']) {
        printf(
            'style="--asset-bg-deco:url(\'%s\');--asset-bg-deco-position:%s;--asset-bg-deco-size:%s"',
            esc_url($deco['url']),
            esc_attr($deco['position']),
            esc_attr($deco['size'])
        );
    }
}
