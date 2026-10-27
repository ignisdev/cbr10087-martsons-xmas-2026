<?php
/**
 * Brand Configuration Admin Page
 *
 * Four-tab interface: Brand Identity, Page Assets, Page Flow, Form IDs.
 *
 * @package BritvicPromotionManager
 * @since 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load current competition
global $wpdb;
$competitions_table = $wpdb->prefix . 'bpm_competitions';
$competitions = $wpdb->get_results(
    "SELECT id, name, type, status FROM {$competitions_table} ORDER BY created_at DESC"
);

$competition_id = (int) ($_GET['competition_id'] ?? ($competitions[0]->id ?? 0));
$config = $competition_id > 0 ? BPM_Brand_Config::get_config($competition_id) : [];
$page_steps = BPM_Brand_Config::get_page_steps();
$page_asset_slots = BPM_Brand_Config::get_page_asset_slots();
$current_flow = $config['flow'] ?? [];
$mechanic = $config['mechanic'] ?? 'instant_win';
$colors = $config['colors'] ?? [];
$fonts  = $config['fonts']  ?? [];
$assets = $config['assets'] ?? [];
$page_assets = $config['page_assets'] ?? [];
$asset_sizes = $config['asset_sizes'] ?? [];
$asset_alignments = $config['asset_alignments'] ?? [];
$page_asset_sizes = $config['page_asset_sizes'] ?? [];
$page_asset_alignments = $config['page_asset_alignments'] ?? [];
$bg_deco = $config['bg_deco'] ?? [];

// Font options (auto-discovered from pepsi-football theme fonts directory)
$font_options = [];
$font_dir = WP_CONTENT_DIR . '/themes/pepsi-football/fonts';

if (is_dir($font_dir) && is_readable($font_dir)) {
    $font_files = glob($font_dir . '/*.{woff2,woff,ttf,otf}', GLOB_BRACE) ?: [];

    foreach ($font_files as $font_file) {
        $filename = pathinfo($font_file, PATHINFO_FILENAME);

        // Convert file name to readable family label, keep alnum/space/hyphen only.
        $family_label = preg_replace('/[-_]+/', ' ', $filename);
        $family_label = preg_replace('/\s+/', ' ', (string) $family_label);
        $family_label = trim((string) $family_label);
        $family_label = preg_replace('/[^A-Za-z0-9 \-]/', '', (string) $family_label);

        if ($family_label === '') {
            continue;
        }

        $font_stack = "'" . $family_label . "', sans-serif";
        $font_options[$font_stack] = $family_label;
    }
}

asort($font_options, SORT_NATURAL | SORT_FLAG_CASE);
$font_options['system-ui, sans-serif'] = 'System (no custom font)';

// Preserve previously saved values even if a file is missing/renamed.
foreach (['primary', 'secondary', 'body'] as $font_key) {
    $saved_font = wp_unslash((string) ($fonts[$font_key] ?? ''));
    if ($saved_font !== '' && !isset($font_options[$saved_font])) {
        $font_options[$saved_font] = $saved_font;
    }
}

$color_labels = [
    'primary'    => 'Primary Colour',
    'secondary'  => 'Secondary Colour',
    'accent'     => 'Accent Colour',
    'bg'         => 'Background Colour (main)',
    'bg_alt'     => 'Background Colour (alt — e.g. Thanks page)',
    'text'       => 'Text Colour',
    'cta'        => 'CTA Button Colour',
    'cta_text'   => 'CTA Button Text Colour',
    'cta_hover'  => 'CTA Hover Colour',
];

$color_defaults = [
    'primary'    => '#e61f48',
    'secondary'  => '#000000',
    'accent'     => '#ffffff',
    'bg'         => '#000000',
    'bg_alt'     => '#1a1a1a',
    'text'       => '#ffffff',
    'cta'        => '#e61f48',
    'cta_text'   => '#ffffff',
    'cta_hover'  => '#c01838',
];

$asset_labels = [
    'brand_logo'        => 'Brand Logo',
    'brand_logo_alt'    => 'Brand Logo — Alternative Route (optional)',
    'partner_logo'      => 'Partner Logo (optional)',
    'partner_logo_alt'  => 'Partner Logo — Alternative Route (optional)',
    'bg_base_image'     => 'Background Base Image',
    'bg_deco_image_alt' => 'Decorative Layer Image — Alternative Route (global, optional)',
];

$page_slot_labels = [
    'header_graphic'      => 'Header Graphic',
    'header_graphic_alt'  => 'Header Graphic — Alternative Route (optional)',
    'hero_image'          => 'Hero / Product Image',
    'hero_image_alt'      => 'Hero / Product Image — Alternative Route (optional)',
    'headline_graphic'    => 'Headline Graphic (image, optional)',
    'bg_deco_image'       => 'Decorative Layer Image',
    'bg_deco_image_alt'   => 'Decorative Layer Image — Alternative Route (optional)',
    'product_image'       => 'Product Image',
    'camera_image'        => 'Camera Overlay Icon',
    'wheel_image'         => 'Spin Wheel Graphic',
    'win_graphic'         => 'Win Badge / Graphic',
    'thanks_graphic'      => 'Thank You Graphic',
];

$tab_labels = [
    'identity' => 'Brand Identity',
    'assets'   => 'Page Assets',
    'flow'     => 'Page Flow',
    'forms'    => 'Form IDs',
];
?>

<div class="wrap bpm-brand-config">
            <h1>Brand Configuration</h1>
    <?php if (empty($competitions)): ?>
        <div class="notice notice-warning"><p>No competitions found. <a href="<?php echo admin_url('admin.php?page=bpm-competitions'); ?>">Create a competition first.</a></p></div>
    <?php else: ?>

    <!-- Competition selector -->
    <div class="bpm-competition-select">
        <label for="bpm-comp-select"><strong>Competition:</strong></label>
        <select id="bpm-comp-select" onchange="window.location = '?page=bpm-brand-config&competition_id=' + this.value">
            <?php foreach ($competitions as $comp): ?>
                <option value="<?php echo esc_attr($comp->id); ?>" <?php selected($competition_id, $comp->id); ?>>
                    <?php echo esc_html($comp->name); ?> (<?php echo esc_html($comp->type); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($competition_id > 0): ?>
    <form id="bpm-brand-config-form">
        <?php wp_nonce_field('bpm_brand_config', 'nonce'); ?>
        <input type="hidden" name="competition_id" value="<?php echo esc_attr($competition_id); ?>">

        <!-- Tabs -->
        <nav class="nav-tab-wrapper bpm-tabs" aria-label="Brand configuration sections">
            <a href="#tab-identity" class="nav-tab nav-tab-active"><?php echo esc_html($tab_labels['identity']); ?></a>
            <a href="#tab-assets"   class="nav-tab"><?php echo esc_html($tab_labels['assets']); ?></a>
            <a href="#tab-flow"     class="nav-tab"><?php echo esc_html($tab_labels['flow']); ?></a>
            <a href="#tab-forms"    class="nav-tab"><?php echo esc_html($tab_labels['forms']); ?></a>
        </nav>

        <!-- ── TAB 1: Brand Identity ───────────────────────────────────────── -->
        <div id="tab-identity" class="bpm-tab-content">
            <p class="description bpm-section-intro">Set the global brand tokens used across all templates for this competition.</p>

            <div class="bpm-section">
                <h2>Colour Tokens</h2>
                <p class="description">These map directly to CSS custom properties injected on every frontend page. They also correspond to Figma Variables — use the same hex values from the Figma handoff.</p>
                <table class="form-table">
                    <?php foreach ($color_labels as $token => $label): ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td>
                            <input type="color"
                                   name="colors[<?php echo esc_attr($token); ?>]"
                                   value="<?php echo esc_attr($colors[$token] ?? $color_defaults[$token] ?? '#000000'); ?>"
                                   class="bpm-color-picker" />
                            <input type="text"
                                   name="colors[<?php echo esc_attr($token); ?>]_hex"
                                   value="<?php echo esc_attr($colors[$token] ?? $color_defaults[$token] ?? '#000000'); ?>"
                                   class="bpm-hex-input regular-text"
                                   pattern="^#[0-9a-fA-F]{6}$"
                                   placeholder="#000000" />
                            <span class="description">CSS: <code>--color-<?php echo esc_html(str_replace('_', '-', $token)); ?></code></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="bpm-section">
                <h2>Typography</h2>
                <p class="description">Select from available brand fonts. Custom font files should be placed in the theme's <code>/fonts/</code> directory.</p>
                <table class="form-table">
                    <?php foreach (['primary' => 'Primary Font', 'secondary' => 'Secondary Font', 'body' => 'Body Font'] as $key => $label): ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td>
                            <select name="fonts[<?php echo esc_attr($key); ?>]">
                                <?php foreach ($font_options as $value => $display): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($fonts[$key] ?? '', $value); ?>>
                                        <?php echo esc_html($display); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="description">CSS: <code>--font-<?php echo esc_html($key); ?></code></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <div class="bpm-section">
                <h2>Global Brand Assets</h2>
                <p class="description">These assets are reused across multiple pages, so set them once here.</p>
                <table class="form-table">
                    <?php foreach ($asset_labels as $slot => $label): ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td>
                            <div class="bpm-asset-slot-group">
                                <div class="bpm-asset-slot-row">
                                    <label class="bpm-field-label">Asset</label>
                                    <?php self_bpm_media_picker("assets[{$slot}]", (int)($assets[$slot] ?? 0)); ?>
                                </div>
                                <?php if ($slot !== 'bg_base_image'): ?>
                                <div class="bpm-asset-slot-row">
                                    <label class="bpm-field-label" for="global-asset-size-<?php echo esc_attr($slot); ?>">Display Width</label>
                                    <div class="bpm-field-control">
                                        <input type="text"
                                               id="global-asset-size-<?php echo esc_attr($slot); ?>"
                                               name="asset_sizes[<?php echo esc_attr($slot); ?>]"
                                               value="<?php echo esc_attr($asset_sizes[$slot] ?? ''); ?>"
                                               class="regular-text"
                                               placeholder="e.g. 180px or 22vw" />
                                    </div>
                                </div>
                                <div class="bpm-asset-slot-row">
                                    <label class="bpm-field-label" for="global-asset-align-<?php echo esc_attr($slot); ?>">Alignment</label>
                                    <div class="bpm-field-control">
                                        <?php $global_alignment = $asset_alignments[$slot] ?? 'center'; ?>
                                        <select id="global-asset-align-<?php echo esc_attr($slot); ?>"
                                                name="asset_alignments[<?php echo esc_attr($slot); ?>]">
                                            <option value="start" <?php selected($global_alignment, 'start'); ?>>Start</option>
                                            <option value="center" <?php selected($global_alignment, 'center'); ?>>Center</option>
                                            <option value="end" <?php selected($global_alignment, 'end'); ?>>End</option>
                                        </select>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div><!-- /tab-identity -->

        <!-- ── TAB 2: Page Assets ──────────────────────────────────────────── -->
        <div id="tab-assets" class="bpm-tab-content" style="display:none">
            <p class="description">
                Configure each page by slot. Each slot keeps the image and display size together so content and sizing stay in sync.
                <strong>Tip:</strong> Name files using page + slot (e.g. <code>age-gate--hero-image.png</code>).
            </p>

            <?php foreach ($page_asset_slots as $page => $slots): ?>
            <div class="bpm-page-assets-section postbox">
                <div class="postbox-header">
                    <h2 class="hndle"><?php echo esc_html($page_steps[$page] ?? ucfirst($page)); ?> <code style="font-weight:400;font-size:12px"><?php echo esc_html($page); ?></code></h2>
                    <div class="handle-actions"></div>
                </div>
                <div class="inside">
                    <table class="form-table">
                        <?php foreach ($slots as $slot): ?>
                        <tr>
                            <th>
                                <span class="bpm-asset-slot-label"><?php echo esc_html($page_slot_labels[$slot] ?? ucwords(str_replace('_', ' ', $slot))); ?></span>
                                <code class="bpm-asset-slot-key"><?php echo esc_html($slot); ?></code>
                            </th>
                            <td>
                                <div class="bpm-asset-slot-group">
                                    <div class="bpm-asset-slot-row">
                                        <label class="bpm-field-label">Asset</label>
                                        <?php self_bpm_media_picker(
                                            "page_assets[{$page}][{$slot}]",
                                            (int)($page_assets[$page][$slot] ?? 0)
                                        ); ?>
                                    </div>
                                    <?php if ($slot !== 'bg_deco_image'): ?>
                                    <div class="bpm-asset-slot-row">
                                        <label class="bpm-field-label" for="page-asset-size-<?php echo esc_attr($page . '-' . $slot); ?>">Display Width</label>
                                        <div class="bpm-field-control">
                                            <input type="text"
                                                   id="page-asset-size-<?php echo esc_attr($page . '-' . $slot); ?>"
                                                   name="page_asset_sizes[<?php echo esc_attr($page); ?>][<?php echo esc_attr($slot); ?>]"
                                                   value="<?php echo esc_attr($page_asset_sizes[$page][$slot] ?? ''); ?>"
                                                   class="regular-text"
                                                   placeholder="e.g. 120px or 35%" />
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($slot !== 'bg_deco_image'): ?>
                                    <div class="bpm-asset-slot-row">
                                        <label class="bpm-field-label" for="page-asset-align-<?php echo esc_attr($page . '-' . $slot); ?>">Alignment</label>
                                        <div class="bpm-field-control">
                                            <?php $alignment = $page_asset_alignments[$page][$slot] ?? 'center'; ?>
                                            <select id="page-asset-align-<?php echo esc_attr($page . '-' . $slot); ?>"
                                                    name="page_asset_alignments[<?php echo esc_attr($page); ?>][<?php echo esc_attr($slot); ?>]">
                                                <option value="start" <?php selected($alignment, 'start'); ?>>Start</option>
                                                <option value="center" <?php selected($alignment, 'center'); ?>>Center</option>
                                                <option value="end" <?php selected($alignment, 'end'); ?>>End</option>
                                            </select>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($slot === 'bg_deco_image'): ?>
                                    <div class="bpm-asset-slot-row">
                                        <label class="bpm-field-label" for="bg-deco-position-<?php echo esc_attr($page); ?>">Background Position</label>
                                        <div class="bpm-field-control">
                                            <input type="text"
                                                   id="bg-deco-position-<?php echo esc_attr($page); ?>"
                                                   name="bg_deco[<?php echo esc_attr($page); ?>][position]"
                                                   value="<?php echo esc_attr($bg_deco[$page]['position'] ?? '50% 100%'); ?>"
                                                   class="regular-text"
                                                   placeholder="50% 100%" />
                                        </div>
                                    </div>
                                    <div class="bpm-asset-slot-row">
                                        <label class="bpm-field-label" for="bg-deco-size-<?php echo esc_attr($page); ?>">Background Size</label>
                                        <div class="bpm-field-control">
                                            <input type="text"
                                                   id="bg-deco-size-<?php echo esc_attr($page); ?>"
                                                   name="bg_deco[<?php echo esc_attr($page); ?>][size]"
                                                   value="<?php echo esc_attr($bg_deco[$page]['size'] ?? '100%'); ?>"
                                                   class="regular-text"
                                                   placeholder="100%" />
                                        </div>
                                    </div>
                                    <span class="description">For decorative backgrounds only. Maps to CSS <code>background-position</code> and <code>background-size</code>.</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div><!-- /tab-assets -->

        <!-- ── TAB 3: Page Flow ────────────────────────────────────────────── -->
        <div id="tab-flow" class="bpm-tab-content" style="display:none">
            <p class="description bpm-section-intro">Choose the promotion mechanic, then order and enable the pages participants move through.</p>

            <div class="bpm-section">
                <h2>Promotion Mechanic</h2>
                <table class="form-table">
                    <tr>
                        <th>Mechanic</th>
                        <td>
                            <select name="mechanic" id="bpm-mechanic-select">
                                <?php foreach (BPM_Brand_Config::MECHANIC_TYPES as $type): ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($mechanic, $type); ?>>
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $type))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Changing the mechanic auto-populates the default flow below. You can then customise it.</p>
                            <button type="button" class="button" id="bpm-reset-flow">Reset to default flow for this mechanic</button>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="bpm-section">
                <h2>Flow Builder</h2>
                <p class="description">Drag to reorder steps. Toggle the checkbox to include or exclude a step from this promotion's flow. Steps marked <strong>Required</strong> cannot be removed.</p>

                <div id="bpm-flow-builder">
                    <?php
                    $all_steps  = array_keys($page_steps);
                    // Show flow steps first (in order), then non-flow steps
                    $in_flow     = $current_flow;
                    $not_in_flow = array_diff($all_steps, $in_flow);
                    $sorted      = array_merge($in_flow, $not_in_flow);
                    foreach ($sorted as $step):
                        $active  = in_array($step, $current_flow, true);
                        $label   = $page_steps[$step] ?? ucfirst($step);
                        $default = BPM_Brand_Config::DEFAULT_FLOWS[$mechanic] ?? [];
                    ?>
                    <div class="bpm-flow-item <?php echo $active ? 'is-active' : 'is-inactive'; ?>" data-step="<?php echo esc_attr($step); ?>">
                        <span class="dashicons dashicons-menu bpm-drag-handle"></span>
                        <input type="checkbox"
                               name="flow_steps[]"
                               value="<?php echo esc_attr($step); ?>"
                               <?php checked($active); ?>
                               class="bpm-flow-checkbox" />
                        <span class="bpm-flow-label"><?php echo esc_html($label); ?></span>
                        <code><?php echo esc_html($step); ?></code>
                        <?php if (in_array($step, ['thanks', 'closed'], true)): ?>
                            <span class="bpm-badge">System</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <!-- Hidden inputs for ordered flow (populated by JS on save) -->
                    <input type="hidden" name="flow_order" id="bpm-flow-order" value="<?php echo esc_attr(implode(',', $current_flow)); ?>" />
                </div>
            </div>
        </div><!-- /tab-flow -->

        <!-- ── TAB 4: Form IDs ────────────────────────────────────────────── -->
        <div id="tab-forms" class="bpm-tab-content" style="display:none">
            <p class="description">Map Gravity Forms IDs to campaign steps. These mappings are read by <code>bpm_get_gf_id()</code> in templates.</p>

            <?php
            $gf_ids = $config['gf_ids'] ?? [];
            $form_keys = [
                'age_gate'     => 'Age Gate Form',
                'initial_entry'=> 'Initial Entry Form',
                'entry'        => 'Entry Form',
                'photo'        => 'Photo Upload Form',
                'draw_entry'   => 'Prize Draw Entry Form',
                'code_entry'   => 'Code Entry Form',
                'winner_claim' => 'Winner Claim Form',
                'draw_winner'  => 'Draw Winner Form',
            ];
            ?>
            <table class="form-table">
                <?php foreach ($form_keys as $key => $label): ?>
                <tr>
                    <th><?php echo esc_html($label); ?></th>
                    <td>
                        <?php $gf_value = (int) ($gf_ids[$key] ?? 0); ?>
                        <input type="number"
                               name="gf_ids[<?php echo esc_attr($key); ?>]"
                               value="<?php echo esc_attr($gf_value > 0 ? (string) $gf_value : ''); ?>"
                               class="small-text"
                               min="1"
                               placeholder="Form ID" />
                        <code>bpm_get_gf_id('<?php echo esc_html($key); ?>')</code>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div><!-- /tab-forms -->

        <div class="bpm-save-bar">
            <button type="submit" class="button button-primary button-large" id="bpm-save-config">
                Save Brand Configuration
            </button>
            <span class="bpm-save-status"></span>
        </div>

    </form><!-- /bpm-brand-config-form -->
    <?php endif; // competition_id > 0 ?>
    <?php endif; // competitions not empty ?>
</div>

<!-- ── Media picker helper ─────────────────────────────────────────────────── -->
<?php
/**
 * Render a WordPress media library picker field.
 */
function self_bpm_media_picker(string $name, int $current_id = 0): void {
    $url = $current_id > 0 ? wp_get_attachment_image_url($current_id, 'thumbnail') : '';
    ?>
    <div class="bpm-media-picker" data-name="<?php echo esc_attr($name); ?>">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current_id ?: ''); ?>" class="bpm-media-id" />
        <div class="bpm-media-preview" style="<?php echo $url ? '' : 'display:none'; ?>">
            <?php if ($url): ?>
                <img src="<?php echo esc_url($url); ?>" style="max-height:80px;max-width:160px;display:block;margin-bottom:6px" />
            <?php endif; ?>
        </div>
        <button type="button" class="button bpm-media-select"><?php echo $current_id ? 'Change Image' : 'Select Image'; ?></button>
        <?php if ($current_id): ?>
            <button type="button" class="button bpm-media-remove" style="margin-left:4px">Remove</button>
        <?php endif; ?>
    </div>
    <?php
}
?>

<style>
.bpm-brand-config .bpm-competition-select { margin: 16px 0; }
.bpm-brand-config .bpm-section { margin-bottom: 32px; }
.bpm-brand-config .bpm-section-intro { margin-bottom: 20px; }
.bpm-brand-config .bpm-color-picker { width: 48px; height: 36px; padding: 2px; vertical-align: middle; cursor: pointer; }
.bpm-brand-config .bpm-hex-input { vertical-align: middle; margin-left: 8px; font-family: monospace; }
.bpm-brand-config .bpm-save-bar { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #ddd; padding: 16px 0; display: flex; align-items: center; gap: 12px; z-index: 100; }
.bpm-brand-config .bpm-save-status { font-style: italic; color: #555; }
.bpm-brand-config .bpm-flow-item { display: flex; align-items: center; gap: 10px; padding: 8px 12px; background: #fff; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 4px; cursor: grab; }
.bpm-brand-config .bpm-flow-item.is-inactive { opacity: .5; }
.bpm-brand-config .bpm-drag-handle { color: #999; cursor: grab; }
.bpm-brand-config .bpm-flow-label { flex: 1; font-weight: 500; }
.bpm-brand-config .bpm-flow-item code { font-size: 11px; color: #666; }
.bpm-brand-config .bpm-badge { font-size: 10px; background: #e0e0e0; padding: 2px 6px; border-radius: 3px; }
.bpm-brand-config .bpm-media-picker { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.bpm-brand-config .bpm-page-assets-section { margin-bottom: 16px; }
.bpm-brand-config .bpm-page-assets-section .postbox-header {
    padding: 0 12px;
    min-height: 48px;
    display: flex;
    align-items: center;
}
.bpm-brand-config .bpm-page-assets-section .postbox-header .hndle {
    margin: 0;
    padding: 10px 0;
    line-height: 1.3;
}
.bpm-brand-config .bpm-tabs { margin-bottom: 0; }
.bpm-brand-config .bpm-tab-content { border: 1px solid #c3c4c7; border-top: none; padding: 20px; background: #fff; }
.bpm-brand-config .bpm-asset-slot-label { display: block; font-weight: 600; }
.bpm-brand-config .bpm-asset-slot-key { font-size: 11px; color: #666; }
.bpm-brand-config .bpm-asset-slot-group {
    border: 1px solid #e0e4e9;
    border-radius: 6px;
    background: #fbfcfe;
    padding: 12px;
}
.bpm-brand-config .bpm-asset-slot-row {
    display: grid;
    grid-template-columns: 140px minmax(0, 1fr);
    align-items: start;
    gap: 12px;
    margin-bottom: 10px;
}
.bpm-brand-config .bpm-asset-slot-row:last-child { margin-bottom: 0; }
.bpm-brand-config .bpm-field-label {
    font-weight: 600;
    color: #1d2327;
    padding-top: 7px;
}
.bpm-brand-config .bpm-field-control {
    min-width: 0;
}
.bpm-brand-config .bpm-field-control .regular-text {
    width: 100%;
    max-width: 280px;
}
@media (max-width: 782px) {
    .bpm-brand-config .bpm-asset-slot-row {
        display: block;
    }
    .bpm-brand-config .bpm-field-label {
        display: block;
        margin-bottom: 6px;
    }
}
</style>

<script>
(function($) {
    // Tab switching
    $('.bpm-tabs .nav-tab').on('click', function(e) {
        e.preventDefault();
        const target = $(this).attr('href');
        $('.bpm-tab-content').hide();
        $('.bpm-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $(target).show();
    });

    // Sync color picker ↔ hex input
    $(document).on('input change', '.bpm-color-picker', function() {
        $(this).next('.bpm-hex-input').val($(this).val());
    });
    $(document).on('input', '.bpm-hex-input', function() {
        if (/^#[0-9a-fA-F]{6}$/.test($(this).val())) {
            $(this).prev('.bpm-color-picker').val($(this).val());
        }
    });

    // Drag-to-reorder flow builder (native HTML5 drag)
    const flowContainer = document.getElementById('bpm-flow-builder');
    if (flowContainer) {
        let dragging = null;
        flowContainer.querySelectorAll('.bpm-flow-item').forEach(item => {
            item.setAttribute('draggable', 'true');
            item.addEventListener('dragstart', () => { dragging = item; });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                const after = getDragAfterElement(flowContainer, e.clientY);
                if (after == null) {
                    flowContainer.appendChild(dragging);
                } else {
                    flowContainer.insertBefore(dragging, after);
                }
            });
        });
        function getDragAfterElement(container, y) {
            const els = [...container.querySelectorAll('.bpm-flow-item:not(.dragging)')];
            return els.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element;
        }
        // Toggle active class on checkbox
        $(flowContainer).on('change', '.bpm-flow-checkbox', function() {
            $(this).closest('.bpm-flow-item').toggleClass('is-active', this.checked).toggleClass('is-inactive', !this.checked);
        });
    }

    // Reset flow to defaults
    $('#bpm-reset-flow').on('click', function() {
        const mechanic = $('#bpm-mechanic-select').val();
        const defaults = <?php echo wp_json_encode(BPM_Brand_Config::DEFAULT_FLOWS); ?>;
        const flow = defaults[mechanic] || [];
        const container = $('#bpm-flow-builder');
        container.find('.bpm-flow-checkbox').each(function() {
            const step = $(this).val();
            const active = flow.includes(step);
            this.checked = active;
            $(this).closest('.bpm-flow-item').toggleClass('is-active', active).toggleClass('is-inactive', !active);
        });
    });

    // Media picker
    $(document).on('click', '.bpm-media-select', function() {
        const picker = $(this).closest('.bpm-media-picker');
        const frame = wp.media({ title: 'Select Image', multiple: false });
        frame.on('select', function() {
            const attachment = frame.state().get('selection').first().toJSON();
            const previewUrl = attachment?.sizes?.thumbnail?.url || attachment?.sizes?.medium?.url || attachment?.url || '';
            picker.find('.bpm-media-id').val(attachment.id);
            if (previewUrl) {
                picker.find('.bpm-media-preview').html('<img src="' + previewUrl + '" style="max-height:80px;max-width:160px;display:block;margin-bottom:6px">').show();
            }
            picker.find('.bpm-media-select').text('Change Image');
            if (!picker.find('.bpm-media-remove').length) {
                picker.find('.bpm-media-select').after('<button type="button" class="button bpm-media-remove" style="margin-left:4px">Remove</button>');
            }
        });
        frame.open();
    });

    $(document).on('click', '.bpm-media-remove', function() {
        const picker = $(this).closest('.bpm-media-picker');
        picker.find('.bpm-media-id').val('');
        picker.find('.bpm-media-preview').hide().empty();
        picker.find('.bpm-media-select').text('Select Image');
        $(this).remove();
    });

    // AJAX save
    $('#bpm-brand-config-form').on('submit', function(e) {
        e.preventDefault();
        const statusEl = $('.bpm-save-status');
        const btn = $('#bpm-save-config');

        // Build ordered flow from current DOM order
        const flowOrder = [];
        $('#bpm-flow-builder .bpm-flow-item').each(function() {
            if ($(this).find('.bpm-flow-checkbox').is(':checked')) {
                flowOrder.push($(this).data('step'));
            }
        });
        $('#bpm-flow-order').val(flowOrder.join(','));

        btn.prop('disabled', true).text('Saving…');
        statusEl.text('');

        const formData = new FormData(this);
        formData.append('action', 'bpm_save_brand_config');

        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    statusEl.text('Saved successfully.').css('color', 'green');
                } else {
                    statusEl.text('Save failed: ' + (res.data?.message || 'Unknown error')).css('color', 'red');
                }
            },
            error: function() {
                statusEl.text('Network error. Please try again.').css('color', 'red');
            },
            complete: function() {
                btn.prop('disabled', false).text('Save Brand Configuration');
            }
        });
    });

})(jQuery);
</script>
