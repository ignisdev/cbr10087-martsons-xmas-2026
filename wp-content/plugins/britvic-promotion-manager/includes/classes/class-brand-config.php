<?php
/**
 * Brand Configuration Class
 *
 * Manages per-competition brand identity, per-page asset slots, and page flow
 * configuration. Outputs CSS custom properties to wp_head and provides PHP
 * helper functions for use in page templates.
 *
 * @package BritvicPromotionManager
 * @since 1.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Brand configuration management for promotional microsites.
 *
 * Stores brand identity (colours, fonts, logos), per-page image slots,
 * and page flow order per competition. Injects CSS custom properties
 * into wp_head so templates never need hardcoded brand values.
 */
class BPM_Brand_Config {

    /**
     * Meta key prefix for competition settings.
     */
    private const OPTION_PREFIX = 'bpm_brand_';

    /**
     * Valid competition mechanic types.
     */
    public const MECHANIC_TYPES = ['instant_win', 'spin_wheel', 'prize_draw'];

    /**
     * All available page steps with labels.
     */
    public const PAGE_STEPS = [
        'partner'       => 'Partner Landing',
        'age-gate'      => 'Age Gate',
        'how-to-play'   => 'How To Play',
        'qualification' => 'Qualification / Code Entry',
        'entry'         => 'Entry Form',
        'photo'         => 'Photo Upload',
        'game'          => 'Spin Wheel Game',
        'reveal'        => 'Instant Win Reveal',
        'draw-entry'    => 'Prize Draw Entry',
        'winner'        => 'Winner',
        'non-winner'    => 'Non-Winner / Sorry',
        'draw-confirm'  => 'Draw Entry Confirmation',
        'thanks'        => 'Thank You',
        'closed'        => 'Competition Closed',
    ];

    /**
     * Default page flows per mechanic type.
     */
    public const DEFAULT_FLOWS = [
        'instant_win' => ['age-gate', 'entry', 'reveal', 'winner', 'non-winner', 'thanks', 'closed'],
        'spin_wheel'  => ['age-gate', 'entry', 'game', 'winner', 'non-winner', 'thanks', 'closed'],
        'prize_draw'  => ['age-gate', 'draw-entry', 'draw-confirm', 'closed'],
    ];

    /**
     * All colour token keys.
     */
    private const COLOR_TOKENS = [
        'primary', 'secondary', 'accent', 'bg', 'bg_alt',
        'text', 'text_muted', 'cta', 'cta_text', 'cta_hover', 'error',
    ];

    /**
     * All image asset slot keys (global).
     */
    private const GLOBAL_ASSET_SLOTS = [
        'brand_logo', 'brand_logo_alt',
        'partner_logo', 'partner_logo_alt',
        'bg_base_image',
        'bg_deco_image_alt',
    ];

    /**
     * Slots that support a per-route variant. For each entry the admin UI
     * automatically renders a `{slot}_alt` companion alongside the original
     * wherever the original appears (global or per-page).
     */
    private const ROUTE_VARIANT_SLOTS = [
        'brand_logo',
        'partner_logo',
        'header_graphic',
        'hero_image',
        'bg_deco_image',
    ];

    /**
     * Per-page asset slot keys.
     */
    private const PAGE_ASSET_SLOTS = [
        'age-gate'      => ['hero_image', 'headline_graphic', 'bg_deco_image'],
        'qualification' => ['headline_graphic', 'bg_deco_image'],
        'entry'         => ['product_image', 'headline_graphic', 'bg_deco_image'],
        'photo'         => ['camera_image', 'bg_deco_image'],
        'game'          => ['wheel_image', 'bg_deco_image'],
        'reveal'        => ['bg_deco_image'],
        'draw-entry'    => ['headline_graphic', 'bg_deco_image'],
        'winner'        => ['win_graphic', 'bg_deco_image'],
        'non-winner'    => ['bg_deco_image'],
        'draw-confirm'  => ['bg_deco_image'],
        'thanks'        => ['thanks_graphic', 'bg_deco_image'],
        'closed'        => ['bg_deco_image'],
        'partner'       => ['hero_image', 'bg_deco_image'],
        'how-to-play'   => ['header_graphic', 'hero_image', 'bg_deco_image'],
    ];

    /**
     * Asset slots used for pages that do not have an explicit slot mapping.
     */
    private const DEFAULT_PAGE_ASSET_SLOTS = ['hero_image', 'headline_graphic', 'header_graphic', 'bg_deco_image'];

    /**
     * Legacy/alternate page key aliases mapped to canonical slugs.
     */
    private const PAGE_KEY_ALIASES = [
        'how_to_play' => 'how-to-play',
    ];

    // -------------------------------------------------------------------------
    // Static accessor — current competition ID
    // -------------------------------------------------------------------------

    /**
     * Detect the current competition ID from the active page.
     * Checks BPM competitions table for a competition whose page IDs match the current page.
     */
    public static function get_current_competition_id(): int {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        if (!is_page()) {
            return $cached = 0;
        }

        global $wpdb, $post;
        $table = $wpdb->prefix . 'bpm_competitions';
        $page_id = (int) ($post->ID ?? 0);

        if ($page_id === 0) {
            return $cached = 0;
        }

        $competition = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE live_page_id = %d
                OR closed_page_id = %d
                OR prize_page_id = %d
                OR terms_page_id = %d
             LIMIT 1",
            $page_id, $page_id, $page_id, $page_id
        ));

        if ($competition) {
            return $cached = (int) $competition->id;
        }

        // Generic promo-template fallback:
        // many campaign pages (e.g. custom static templates like page-how-to-play.php)
        // are not stored in bpm_competitions page-id columns, so use active competition.
        $template_file = (string) get_page_template_slug($page_id);
        if ($template_file !== '' && preg_match('/^page-[a-z0-9\-]+\.php$/i', $template_file)) {
            if (function_exists('bpm_get_active_competition_id')) {
                $active_competition_id = (int) bpm_get_active_competition_id();
                if ($active_competition_id > 0) {
                    return $cached = $active_competition_id;
                }
            }

            $active_id = (int) $wpdb->get_var("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            if ($active_id > 0) {
                return $cached = $active_id;
            }
        }

        // Preview routes (e.g. /photo/?bpm_preview_photo=1) are often not mapped
        // in competition page columns, so fall back to active/current competition.
        if (isset($_GET['bpm_preview_photo']) || isset($_GET['competition_id'])) {
            $from_query = isset($_GET['competition_id']) ? (int) $_GET['competition_id'] : 0;
            if ($from_query > 0) {
                return $cached = $from_query;
            }

            if (function_exists('bpm_get_active_competition_id')) {
                $active_competition_id = (int) bpm_get_active_competition_id();
                if ($active_competition_id > 0) {
                    return $cached = $active_competition_id;
                }
            }

            $active_id = (int) $wpdb->get_var("SELECT id FROM {$table} WHERE status = 'active' ORDER BY id DESC LIMIT 1");
            if ($active_id > 0) {
                return $cached = $active_id;
            }
        }

        return $cached = 0;
    }

    // -------------------------------------------------------------------------
    // Config getters / setters
    // -------------------------------------------------------------------------

    /**
     * Get the full brand config for a competition.
     *
     * @param int $competition_id
     * @return array{
     *   mechanic: string,
     *   flow: list<string>,
     *   gf_ids: array<string,int>,
     *   colors: array<string,string>,
     *   fonts: array<string,string>,
     *   assets: array<string,int>,
     *   asset_sizes: array<string,string>,
     *   asset_alignments: array<string,string>,
     *   page_assets: array<string,array<string,int>>,
     *   page_asset_sizes: array<string,array<string,string>>,
     *   page_asset_alignments: array<string,array<string,string>>,
     *   bg_deco: array<string,array<string,string>>
     * }
     */
    public static function get_config(int $competition_id): array {
        $stored = get_option(self::OPTION_PREFIX . $competition_id, []);

        $mechanic = $stored['mechanic'] ?? 'instant_win';

        return [
            'mechanic'    => $mechanic,
            'flow'        => $stored['flow']        ?? self::DEFAULT_FLOWS[$mechanic] ?? [],
            'gf_ids'      => $stored['gf_ids']      ?? [],
            'colors'      => $stored['colors']      ?? [],
            'fonts'       => array_map(
                static fn($font): string => is_string($font) ? wp_unslash($font) : '',
                (array) ($stored['fonts'] ?? [])
            ),
            'assets'      => $stored['assets']      ?? [],
            'asset_sizes' => $stored['asset_sizes'] ?? [],
            'asset_alignments' => $stored['asset_alignments'] ?? [],
            'page_assets' => $stored['page_assets'] ?? [],
            'page_asset_sizes' => $stored['page_asset_sizes'] ?? [],
            'page_asset_alignments' => $stored['page_asset_alignments'] ?? [],
            'bg_deco'     => $stored['bg_deco']     ?? [],
        ];
    }

    /**
     * Save the full brand config for a competition.
     *
     * @param int   $competition_id
     * @param array $config
     * @return bool
     */
    public static function save_config(int $competition_id, array $config): bool {
        $existing = self::get_config($competition_id);
        $merged   = array_merge($existing, $config);

        $result = update_option(self::OPTION_PREFIX . $competition_id, $merged, false);

        if (class_exists('BPM_Audit_Logger')) {
            BPM_Audit_Logger::log(
                $competition_id,
                'brand_config_updated',
                "Brand config updated for competition {$competition_id}",
                'info',
                ['keys_updated' => array_keys($config)]
            );
        }

        // update_option() returns false when the value is unchanged.
        // Treat that as a successful save to avoid false-negative UI errors.
        return $result !== false || get_option(self::OPTION_PREFIX . $competition_id, null) !== null;
    }

    // -------------------------------------------------------------------------
    // Colour helpers
    // -------------------------------------------------------------------------

    /**
     * Get a colour token value for the current competition.
     *
     * @param string $token  e.g. 'primary', 'cta', 'bg'
     * @param int    $competition_id  0 = auto-detect
     * @return string Hex colour or empty string
     */
    public static function get_color(string $token, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        return sanitize_hex_color($config['colors'][$token] ?? '') ?? '';
    }

    /**
     * Get all colour tokens for a competition, with defaults filled in.
     *
     * @param int $competition_id
     * @return array<string,string>
     */
    public static function get_all_colors(int $competition_id): array {
        $config   = self::get_config($competition_id);
        $defaults = [
            'primary'    => '#e61f48',
            'secondary'  => '#000000',
            'accent'     => '#ffffff',
            'bg'         => '#000000',
            'bg_alt'     => '#1a1a1a',
            'text'       => '#ffffff',
            'text_muted' => 'rgba(255,255,255,0.7)',
            'cta'        => '#e61f48',
            'cta_text'   => '#ffffff',
            'cta_hover'  => '#c01838',
            'error'      => '#ff4444',
        ];

        $stored = $config['colors'] ?? [];
        return array_merge($defaults, array_filter($stored));
    }

    // -------------------------------------------------------------------------
    // Font helpers
    // -------------------------------------------------------------------------

    /**
     * Get a font token value.
     *
     * @param string $token  'primary' | 'secondary' | 'body'
     * @param int    $competition_id
     * @return string font-family string or empty string
     */
    public static function get_font(string $token, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config   = self::get_config($competition_id);
        $defaults = [
            'primary'   => "'Obviously', 'Arial Black', sans-serif",
            'secondary' => "'FatFrank', 'Arial Black', sans-serif",
            'body'      => 'system-ui, sans-serif',
        ];
        return $config['fonts'][$token] ?? $defaults[$token] ?? '';
    }

    // -------------------------------------------------------------------------
    // Asset / image helpers
    // -------------------------------------------------------------------------

    /**
     * Get a global asset attachment ID.
     *
     * @param string $slot  e.g. 'brand_logo', 'partner_logo', 'bg_base_image'
     * @param int    $competition_id
     * @return int Attachment ID or 0
     */
    public static function get_asset_id(string $slot, int $competition_id = 0): int {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        return (int) ($config['assets'][$slot] ?? 0);
    }

    /**
     * Get a per-page asset attachment ID.
     *
     * @param string $slot  e.g. 'hero_image', 'bg_deco_image'
     * @param string $page  e.g. 'age-gate', 'entry', 'game'
     * @param int    $competition_id
     * @return int Attachment ID or 0
     */
    public static function get_page_asset_id(string $slot, string $page, int $competition_id = 0): int {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $page   = self::normalize_page_key($page);
        return (int) ($config['page_assets'][$page][$slot] ?? 0);
    }

    /**
     * Get optional CSS width value for a per-page asset slot.
     *
     * @param string $slot
     * @param string $page
     * @param int    $competition_id
     * @return string
     */
    public static function get_page_asset_size(string $slot, string $page, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $page   = self::normalize_page_key($page);
        $raw    = (string) ($config['page_asset_sizes'][$page][$slot] ?? '');
        return self::sanitize_css_size($raw);
    }

    /**
     * Get optional CSS width value for a global asset slot.
     *
     * @param string $slot
     * @param int    $competition_id
     * @return string
     */
    public static function get_asset_size(string $slot, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $raw    = (string) ($config['asset_sizes'][$slot] ?? '');
        return self::sanitize_css_size($raw);
    }

    /**
     * Get horizontal alignment for a global asset slot.
     *
     * @return string start|center|end
     */
    public static function get_asset_alignment(string $slot, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $raw    = (string) ($config['asset_alignments'][$slot] ?? 'center');
        return self::sanitize_asset_alignment($raw);
    }

    /**
     * Get horizontal alignment for a per-page asset slot.
     *
     * @return string start|center|end
     */
    public static function get_page_asset_alignment(string $slot, string $page, int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $page   = self::normalize_page_key($page);
        $raw    = (string) ($config['page_asset_alignments'][$page][$slot] ?? 'center');
        return self::sanitize_asset_alignment($raw);
    }

    /**
     * Get the URL for an asset slot (global or per-page).
     *
     * @param string      $slot
     * @param string|null $page  null for global slots
     * @param int         $competition_id
     * @return string URL or empty string
     */
    public static function get_asset_url(string $slot, ?string $page = null, int $competition_id = 0): string {
        $id = $page !== null
            ? self::get_page_asset_id($slot, $page, $competition_id)
            : self::get_asset_id($slot, $competition_id);

        if ($id === 0) {
            return '';
        }

        $url = wp_get_attachment_image_url($id, 'full');
        return $url ?: '';
    }

    /**
     * Get the <img> HTML for an asset slot.
     *
     * @param string      $slot
     * @param string|null $page
     * @param string      $class  Additional CSS class
     * @param string      $alt    Alt text
     * @param int         $competition_id
     * @return string HTML string or empty string
     */
    public static function get_asset_img(
        string $slot,
        ?string $page = null,
        string $class = '',
        string $alt = '',
        int $competition_id = 0
    ): string {
        $id = $page !== null
            ? self::get_page_asset_id($slot, $page, $competition_id)
            : self::get_asset_id($slot, $competition_id);

        if ($id === 0) {
            return '';
        }

        $img_attrs = [
            'class' => $class ?: "bpm-asset bpm-asset--{$slot}",
            'alt'   => $alt,
        ];

        if ($page !== null && $slot !== 'bg_deco_image') {
            $size = self::get_page_asset_size($slot, $page, $competition_id);
            $alignment = self::get_page_asset_alignment($slot, $page, $competition_id);
            $style_bits = [];

            if ($size !== '') {
                // Override theme defaults when a slot-specific width is configured.
                $style_bits[] = 'width:' . esc_attr($size);
                $style_bits[] = 'max-width:none';
            }
            $style_bits[] = 'height:auto';
            $style_bits[] = 'display:block';
            if ($alignment === 'start') {
                $style_bits[] = 'margin-left:0';
                $style_bits[] = 'margin-right:auto';
            } elseif ($alignment === 'end') {
                $style_bits[] = 'margin-left:auto';
                $style_bits[] = 'margin-right:0';
            } else {
                $style_bits[] = 'margin-left:auto';
                $style_bits[] = 'margin-right:auto';
            }

            $img_attrs['style'] = implode(';', $style_bits) . ';';
        } elseif ($page === null && $slot !== 'bg_base_image') {
            $size = self::get_asset_size($slot, $competition_id);
            $alignment = self::get_asset_alignment($slot, $competition_id);
            $style_bits = [];
            if ($size !== '') {
                $style_bits[] = 'width:' . esc_attr($size);
                $style_bits[] = 'max-width:none';
            }
            $style_bits[] = 'height:auto';
            $style_bits[] = 'display:block';
            if ($alignment === 'start') {
                $style_bits[] = 'margin-left:0';
                $style_bits[] = 'margin-right:auto';
            } elseif ($alignment === 'end') {
                $style_bits[] = 'margin-left:auto';
                $style_bits[] = 'margin-right:0';
            } else {
                $style_bits[] = 'margin-left:auto';
                $style_bits[] = 'margin-right:auto';
            }
            $img_attrs['style'] = implode(';', $style_bits) . ';';
        }

        return wp_get_attachment_image($id, 'full', false, $img_attrs);
    }

    // -------------------------------------------------------------------------
    // Background deco helpers
    // -------------------------------------------------------------------------

    /**
     * Get background deco CSS values for a specific page.
     *
     * @param string $page
     * @param int    $competition_id
     * @return array{position: string, size: string, url: string}
     */
    public static function get_bg_deco(string $page, int $competition_id = 0): array {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $page   = self::normalize_page_key($page);
        $config = self::get_config($competition_id);
        $deco   = $config['bg_deco'][$page] ?? [];

        $url = self::get_asset_url('bg_deco_image', $page, $competition_id);

        return [
            'url'      => $url,
            'position' => $deco['position'] ?? '50% 100%',
            'size'     => $deco['size']     ?? '100%',
        ];
    }

    // -------------------------------------------------------------------------
    // Page flow helpers
    // -------------------------------------------------------------------------

    /**
     * Get the mechanic type for the current competition.
     *
     * @param int $competition_id  0 = auto-detect
     * @return string  'instant_win' | 'spin_wheel' | 'prize_draw'
     */
    public static function get_mechanic_type(int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        $type   = $config['mechanic'] ?? 'instant_win';
        return in_array($type, self::MECHANIC_TYPES, true) ? $type : 'instant_win';
    }

    /**
     * Get the ordered list of active steps for the current competition.
     *
     * @param int $competition_id
     * @return list<string>
     */
    public static function get_flow_steps(int $competition_id = 0): array {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        return $config['flow'] ?? self::DEFAULT_FLOWS[self::get_mechanic_type($competition_id)] ?? [];
    }

    /**
     * Check whether a page step is active for the current competition.
     *
     * @param string $step  e.g. 'photo', 'partner', 'age-gate'
     * @param int    $competition_id
     * @return bool
     */
    public static function is_step_active(string $step, int $competition_id = 0): bool {
        return in_array($step, self::get_flow_steps($competition_id), true);
    }

    /**
     * Get the URL of the next step in the flow from the current page template.
     *
     * Uses the current page slug to determine position in the flow.
     *
     * @param int $competition_id
     * @return string URL of the next step, or home URL if undetermined
     */
    public static function get_next_step_url(int $competition_id = 0): string {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }

        global $post;
        if (!$post) {
            return home_url('/');
        }

        $slug  = $post->post_name;
        $steps = self::get_flow_steps($competition_id);
        $index = array_search($slug, $steps, true);

        if ($index === false || $index >= count($steps) - 1) {
            return home_url('/');
        }

        $next_step = $steps[$index + 1];

        // Try to find a WordPress page with this slug
        $next_page = get_page_by_path($next_step);
        if ($next_page) {
            return get_permalink($next_page->ID) ?: home_url('/');
        }

        // Fallback: assume slug is the URL path
        return home_url('/' . $next_step . '/');
    }

    // -------------------------------------------------------------------------
    // Gravity Forms ID helpers
    // -------------------------------------------------------------------------

    /**
     * Get the Gravity Forms ID mapped to a form key.
     *
     * Form keys: 'age_gate', 'entry', 'photo', 'draw_entry',
     *            'winner_claim', 'draw_winner'
     *
     * @param string $form_key
     * @param int    $competition_id
     * @return int Gravity Forms form ID or 0
     */
    public static function get_gf_id(string $form_key, int $competition_id = 0): int {
        if ($competition_id === 0) {
            $competition_id = self::get_current_competition_id();
        }
        $config = self::get_config($competition_id);
        return (int) ($config['gf_ids'][$form_key] ?? 0);
    }

    // -------------------------------------------------------------------------
    // CSS injection
    // -------------------------------------------------------------------------

    /**
     * Output CSS custom property overrides to wp_head for the current competition.
     * Hooked to 'wp_head' at priority 20 (after stylesheets).
     */
    public static function output_css_vars(): void {
        $competition_id = self::get_current_competition_id();

        if ($competition_id === 0) {
            return;
        }

        $colors  = self::get_all_colors($competition_id);
        $config  = self::get_config($competition_id);
        $fonts   = $config['fonts'] ?? [];
        $bg_base = self::get_asset_url('bg_base_image', null, $competition_id);

        // Determine current page for deco layer
        global $post;
        $current_page = $post ? $post->post_name : '';
        $deco         = self::get_bg_deco($current_page, $competition_id);

        $css  = ':root{';

        // Colours
        $css .= self::css_var('color-primary',    $colors['primary']);
        $css .= self::css_var('color-secondary',  $colors['secondary']);
        $css .= self::css_var('color-accent',      $colors['accent']);
        $css .= self::css_var('color-bg',          $colors['bg']);
        $css .= self::css_var('color-bg-alt',      $colors['bg_alt']);
        $css .= self::css_var('color-text',        $colors['text']);
        $css .= self::css_var('color-text-muted',  $colors['text_muted']);
        $css .= self::css_var('color-cta',         $colors['cta']);
        $css .= self::css_var('color-cta-text',    $colors['cta_text']);
        $css .= self::css_var('color-cta-hover',   $colors['cta_hover']);
        $css .= self::css_var('color-error',       $colors['error']);

        // Fonts
        if (!empty($fonts['primary'])) {
            $css .= self::css_var('font-primary', sanitize_text_field($fonts['primary']));
        }
        if (!empty($fonts['secondary'])) {
            $css .= self::css_var('font-secondary', sanitize_text_field($fonts['secondary']));
        }

        // Background base image
        if ($bg_base) {
            $css .= self::css_var('asset-bg-base', "url('" . esc_url($bg_base) . "')");
        }

        // Deco layer for current page
        if ($deco['url']) {
            $css .= self::css_var('asset-bg-deco', "url('" . esc_url($deco['url']) . "')");
            $css .= self::css_var('asset-bg-deco-position', $deco['position']);
            $css .= self::css_var('asset-bg-deco-size', $deco['size']);
        }

        $css .= '}';

        echo '<style id="bpm-brand-css">' . $css . '</style>' . "\n";
    }

    /**
     * Format a single CSS custom property declaration.
     */
    private static function css_var(string $name, string $value): string {
        if ($value === '') {
            return '';
        }
        return '--' . $name . ':' . $value . ';';
    }

    // -------------------------------------------------------------------------
    // Init / hooks
    // -------------------------------------------------------------------------

    /**
     * Register WordPress hooks. Called from the main plugin class.
     */
    public static function init(): void {
        add_action('wp_head', [self::class, 'output_css_vars'], 20);
        add_action('wp_ajax_bpm_save_brand_config', [self::class, 'ajax_save']);
    }

    /**
     * AJAX handler for saving brand config from admin UI.
     */
    public static function ajax_save(): void {
        check_ajax_referer('bpm_brand_config', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $competition_id = (int) ($_POST['competition_id'] ?? 0);
        if ($competition_id === 0) {
            wp_send_json_error(['message' => 'Missing competition_id'], 400);
        }

        $raw_flow = [];
        if (!empty($_POST['flow_order'])) {
            $raw_flow = explode(',', (string) $_POST['flow_order']);
        } elseif (!empty($_POST['flow_steps'])) {
            $raw_flow = (array) $_POST['flow_steps'];
        } elseif (!empty($_POST['flow'])) {
            $raw_flow = (array) $_POST['flow'];
        }

        $config = [
            'mechanic' => sanitize_text_field($_POST['mechanic'] ?? 'instant_win'),
            'flow'     => array_values(array_filter(array_map('sanitize_text_field', $raw_flow))),
            'gf_ids'   => array_map('intval', (array) ($_POST['gf_ids'] ?? [])),
            'colors'   => [],
            'fonts'    => [],
            'assets'   => [],
            'asset_sizes' => [],
            'asset_alignments' => [],
            'page_assets' => [],
            'page_asset_sizes' => [],
            'page_asset_alignments' => [],
            'bg_deco'  => [],
        ];

        // Colours
        foreach (self::COLOR_TOKENS as $token) {
            $val = sanitize_hex_color($_POST['colors'][$token] ?? '');
            if ($val) {
                $config['colors'][$token] = $val;
            }
        }

        // Fonts
        foreach (['primary', 'secondary', 'body'] as $token) {
            $raw = wp_unslash($_POST['fonts'][$token] ?? '');
            $val = sanitize_text_field($raw);
            if ($val) {
                $config['fonts'][$token] = $val;
            }
        }

        // Global assets
        foreach (self::GLOBAL_ASSET_SLOTS as $slot) {
            $id = (int) ($_POST['assets'][$slot] ?? 0);
            if ($id > 0) {
                $config['assets'][$slot] = $id;
            }

            $size = self::sanitize_css_size((string) ($_POST['asset_sizes'][$slot] ?? ''));
            if ($size !== '') {
                $config['asset_sizes'][$slot] = $size;
            }

            $alignment = self::sanitize_asset_alignment((string) ($_POST['asset_alignments'][$slot] ?? 'center'));
            if ($alignment !== 'center') {
                $config['asset_alignments'][$slot] = $alignment;
            }
        }

        // Per-page assets (dynamic page list from admin definitions)
        foreach (self::get_page_asset_slots() as $page => $slots) {
            $page = self::normalize_page_key($page);
            foreach ($slots as $slot) {
                $id = (int) ($_POST['page_assets'][$page][$slot] ?? 0);
                if ($id > 0) {
                    $config['page_assets'][$page][$slot] = $id;
                }

                $size = self::sanitize_css_size((string) ($_POST['page_asset_sizes'][$page][$slot] ?? ''));
                if ($size !== '') {
                    $config['page_asset_sizes'][$page][$slot] = $size;
                }

                $alignment = self::sanitize_asset_alignment((string) ($_POST['page_asset_alignments'][$page][$slot] ?? 'center'));
                if ($alignment !== 'center') {
                    $config['page_asset_alignments'][$page][$slot] = $alignment;
                }
            }

            // Deco position/size
            $pos  = sanitize_text_field($_POST['bg_deco'][$page]['position'] ?? '50% 100%');
            $size = sanitize_text_field($_POST['bg_deco'][$page]['size']     ?? '100%');
            $config['bg_deco'][$page] = ['position' => $pos, 'size' => $size];
        }

        $saved = self::save_config($competition_id, $config);

        if ($saved) {
            wp_send_json_success(['message' => 'Brand config saved.']);
        } else {
            wp_send_json_error(['message' => 'Failed to save config.'], 500);
        }
    }

    /**
     * Sanitize a CSS size token used for image width fields.
     *
     * Allows numeric units and a small set of safe keywords/functions.
     */
    private static function sanitize_css_size(string $value): string {
        $value = trim(sanitize_text_field($value));
        if ($value === '') {
            return '';
        }

        // Allow unitless numbers and default them to px.
        if (preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return $value . 'px';
        }

        // Allow whitespace between number and unit, normalize it.
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(px|%|rem|em|vw|vh|vmin|vmax)$/i', $value, $m)) {
            return $m[1] . strtolower($m[2]);
        }

        if (preg_match('/^\d+(?:\.\d+)?(?:px|%|rem|em|vw|vh|vmin|vmax)$/i', $value)) {
            return $value;
        }

        if (preg_match('/^(?:auto|inherit|initial|unset|max-content|min-content|fit-content)$/', $value)) {
            return $value;
        }

        return '';
    }

    /**
     * Normalize a page key to the canonical slug used in config arrays.
     */
    private static function normalize_page_key(string $page): string {
        $page = sanitize_title($page);
        return self::PAGE_KEY_ALIASES[$page] ?? $page;
    }

    /**
     * Sanitize per-slot horizontal alignment value.
     */
    private static function sanitize_asset_alignment(string $value): string {
        $value = strtolower(trim(sanitize_text_field($value)));
        return in_array($value, ['start', 'center', 'end'], true) ? $value : 'center';
    }

    // -------------------------------------------------------------------------
    // Schema reference
    // -------------------------------------------------------------------------

    /**
     * Return the page asset slot definitions for use in admin UI.
     *
     * @return array<string, list<string>>
     */
    public static function get_page_asset_slots(): array {
        $slots = [];

        foreach (self::get_page_steps() as $slug => $_label) {
            $base = self::PAGE_ASSET_SLOTS[$slug] ?? self::DEFAULT_PAGE_ASSET_SLOTS;
            $slots[$slug] = self::expand_with_route_variants($base);
        }

        if (empty($slots)) {
            $expanded = [];
            foreach (self::PAGE_ASSET_SLOTS as $page => $base) {
                $expanded[$page] = self::expand_with_route_variants($base);
            }
            return $expanded;
        }

        return $slots;
    }

    /**
     * Insert {slot}_alt right after each ROUTE_VARIANT_SLOTS entry so the
     * admin UI shows the alt-route companion adjacent to its base asset.
     *
     * @param list<string> $base
     * @return list<string>
     */
    private static function expand_with_route_variants(array $base): array {
        $expanded = [];
        foreach ($base as $slot) {
            $expanded[] = $slot;
            if (in_array($slot, self::ROUTE_VARIANT_SLOTS, true) && substr($slot, -4) !== '_alt') {
                $alt = $slot . '_alt';
                if (!in_array($alt, $expanded, true)) {
                    $expanded[] = $alt;
                }
            }
        }
        return $expanded;
    }

    /**
     * Return all available page steps with labels.
     *
     * @return array<string, string>
     */
    public static function get_page_steps(): array {
        $steps = [];

        // 1) Start with known campaign steps.
        foreach (self::PAGE_STEPS as $slug => $label) {
            $steps[$slug] = $label;
        }

        // 2) Add any active-theme page templates like `page-how-to-play.php`.
        $template_map = function_exists('wp_get_theme') ? wp_get_theme()->get_page_templates() : [];
        $template_files = array_values((array) $template_map);
        foreach ($template_files as $template_file) {
            if (preg_match('/^page-(.+)\.php$/', (string) $template_file, $m)) {
                $slug = sanitize_title((string) $m[1]);
                if ($slug !== '' && !isset($steps[$slug])) {
                    $steps[$slug] = ucwords(str_replace('-', ' ', $slug));
                }
            }
        }

        // 3) If a WP page is assigned to one of these templates, prefer page title as label.
        $pages = get_pages([
            'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'sort_column' => 'menu_order,post_title',
            'sort_order'  => 'ASC',
            'meta_key'    => '_wp_page_template',
        ]);

        foreach ($pages as $page) {
            $template_file = (string) get_post_meta((int) $page->ID, '_wp_page_template', true);
            if (!preg_match('/^page-(.+)\.php$/', $template_file, $m)) {
                continue;
            }

            $slug = sanitize_title((string) $m[1]);
            if ($slug === '') {
                continue;
            }

            $title = trim((string) ($page->post_title ?? ''));
            if ($title !== '') {
                $steps[$slug] = $title;
            } elseif (!isset($steps[$slug])) {
                $steps[$slug] = ucwords(str_replace('-', ' ', $slug));
            }
        }

        /**
         * Filter the page-steps list shown in the Brand Config → Page Flow
         * admin builder. Themes can hide irrelevant or outcome-only templates
         * (those gated by application logic rather than sequential flow
         * position) so the builder UI stays focused on the real journey.
         *
         * @param array<string, string> $steps  slug => human label
         */
        return apply_filters('bpm_brand_config_page_steps', $steps);
    }
}
