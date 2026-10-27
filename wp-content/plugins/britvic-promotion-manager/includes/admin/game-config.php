<?php
/**
 * Game Configuration Admin Page
 *
 * @package BritvicPromotionManager
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Get all competitions for dropdown
global $wpdb;
$competitions_table = $wpdb->prefix . 'bpm_competitions';
$competitions = $wpdb->get_results("SELECT id, name, status FROM $competitions_table ORDER BY created_at DESC");

// Handle form submission
if (isset($_POST['save_game_config']) && wp_verify_nonce($_POST['_wpnonce'], 'bpm_save_game_config')) {
    $competition_id = (int) $_POST['competition_id'];
    
    if ($competition_id > 0) {
        // Process wheel sections
        $wheel_sections = [];
        if (isset($_POST['wheel_sections'])) {
            foreach ($_POST['wheel_sections'] as $section_id => $section_data) {
                $wheel_sections[(int)$section_id] = [
                    'type' => sanitize_text_field($section_data['type']),
                    'prize_types' => array_map('sanitize_text_field', explode(',', $section_data['prize_types'])),
                    'angle' => (float) $section_data['angle'],
                    'speed' => (float) $section_data['speed'],
                    'label' => sanitize_text_field($section_data['label'])
                ];
            }
        }
        
        // Process game settings
        $game_settings = [
            'spin_duration' => (float) ($_POST['spin_duration'] ?? 1.5),
            'final_spin_duration_min' => (float) ($_POST['final_spin_duration_min'] ?? 1.0),
            'final_spin_duration_max' => (float) ($_POST['final_spin_duration_max'] ?? 1.8),
            'celebration_duration' => (int) ($_POST['celebration_duration'] ?? 6000),
            'enable_sound' => !empty($_POST['enable_sound']),
            'enable_animations' => !empty($_POST['enable_animations'])
        ];
        
        // Process redirect URLs
        $redirect_urls = [
            'winner' => sanitize_text_field($_POST['winner_url'] ?? '/winner'),
            'loser' => sanitize_text_field($_POST['loser_url'] ?? '/non-winner'),
            'error' => sanitize_text_field($_POST['error_url'] ?? '/error')
        ];
        
        $config = [
            'wheel_sections' => $wheel_sections,
            'game_settings' => $game_settings,
            'redirect_urls' => $redirect_urls
        ];
        
        if (BPM_Game_Config::save_game_config($competition_id, $config)) {
            echo '<div class="notice notice-success"><p>Game configuration saved successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to save game configuration.</p></div>';
        }
    }
}

// Get current configuration if competition is selected
$current_config = [];
$selected_competition = 0;
if (isset($_GET['competition_id']) || isset($_POST['competition_id'])) {
    $selected_competition = (int) ($_GET['competition_id'] ?? $_POST['competition_id']);
    if ($selected_competition > 0) {
        $current_config = BPM_Game_Config::get_game_config($selected_competition);
    }
}

// Auto-select the only competition when one exists and none chosen.
if ( ! $selected_competition && is_array( $competitions ) && count( $competitions ) === 1 ) {
    $only = reset( $competitions );
    $selected_competition = is_array( $only ) ? (int) $only['id'] : (int) $only->id;
    if ( $selected_competition > 0 ) {
        $current_config = BPM_Game_Config::get_game_config( $selected_competition );
    }
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <div class="bpm-admin-container">
        
        <!-- Competition Selection -->
        <div class="postbox">
            <h2 class="hndle">Select Competition</h2>
            <div class="inside">
                <form method="get" action="">
                    <input type="hidden" name="page" value="britvic-game-config" />
                    <table class="form-table">
                        <tr>
                            <th scope="row">Competition</th>
                            <td>
                                <select name="competition_id" onchange="this.form.submit()">
                                    <option value="">Select a competition...</option>
                                    <?php foreach ($competitions as $competition): ?>
                                        <option value="<?php echo esc_attr($competition->id); ?>" 
                                                <?php selected($selected_competition, $competition->id); ?>>
                                            <?php echo esc_html($competition->name . ' (' . ucfirst($competition->status) . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>

        <?php if ($selected_competition > 0): ?>
        
        <!-- Game Configuration Form -->
        <form method="post" action="">
            <?php wp_nonce_field('bpm_save_game_config'); ?>
            <input type="hidden" name="competition_id" value="<?php echo esc_attr($selected_competition); ?>" />
            
            <!-- Wheel Sections Configuration -->
            <div class="postbox">
                <h2 class="hndle">Wheel Sections Configuration</h2>
                <div class="inside">
                    <p>Configure each section of the prize wheel. Each section should have a unique angle and can be set as winner or loser.</p>
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Section</th>
                                <th>Type</th>
                                <th>Prize Types (comma-separated)</th>
                                <th>Angle</th>
                                <th>Speed</th>
                                <th>Label</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sections = $current_config['wheel_sections'] ?? [];
                            for ($i = 1; $i <= 12; $i++): 
                                $section = $sections[$i] ?? [
                                    'type' => 'loser',
                                    'prize_types' => ['loser'],
                                    'angle' => ($i - 1) * 30,
                                    'speed' => 1.2,
                                    'label' => 'Section ' . $i
                                ];
                            ?>
                            <tr>
                                <td><strong><?php echo $i; ?></strong></td>
                                <td>
                                    <select name="wheel_sections[<?php echo $i; ?>][type]">
                                        <option value="winner" <?php selected($section['type'], 'winner'); ?>>Winner</option>
                                        <option value="loser" <?php selected($section['type'], 'loser'); ?>>Loser</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" 
                                           name="wheel_sections[<?php echo $i; ?>][prize_types]" 
                                           value="<?php echo esc_attr(implode(',', $section['prize_types'])); ?>"
                                           placeholder="drink,voucher" 
                                           class="regular-text" />
                                </td>
                                <td>
                                    <input type="number" 
                                           name="wheel_sections[<?php echo $i; ?>][angle]" 
                                           value="<?php echo esc_attr($section['angle']); ?>"
                                           step="0.1" 
                                           min="0" 
                                           max="360" 
                                           class="small-text" />°
                                </td>
                                <td>
                                    <input type="number" 
                                           name="wheel_sections[<?php echo $i; ?>][speed]" 
                                           value="<?php echo esc_attr($section['speed']); ?>"
                                           step="0.1" 
                                           min="0.1" 
                                           max="5" 
                                           class="small-text" />
                                </td>
                                <td>
                                    <input type="text" 
                                           name="wheel_sections[<?php echo $i; ?>][label]" 
                                           value="<?php echo esc_attr($section['label']); ?>"
                                           class="regular-text" />
                                </td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Game Settings -->
            <div class="postbox">
                <h2 class="hndle">Game Settings</h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Spin Duration (seconds)</th>
                            <td>
                                <input type="number" 
                                       name="spin_duration" 
                                       value="<?php echo esc_attr($current_config['game_settings']['spin_duration'] ?? 1.5); ?>"
                                       step="0.1" 
                                       min="0.5" 
                                       max="5" />
                                <p class="description">Duration of the initial spin animation</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Final Spin Duration Min (seconds)</th>
                            <td>
                                <input type="number" 
                                       name="final_spin_duration_min" 
                                       value="<?php echo esc_attr($current_config['game_settings']['final_spin_duration_min'] ?? 1.0); ?>"
                                       step="0.1" 
                                       min="0.5" 
                                       max="3" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Final Spin Duration Max (seconds)</th>
                            <td>
                                <input type="number" 
                                       name="final_spin_duration_max" 
                                       value="<?php echo esc_attr($current_config['game_settings']['final_spin_duration_max'] ?? 1.8); ?>"
                                       step="0.1" 
                                       min="0.5" 
                                       max="5" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Celebration Duration (milliseconds)</th>
                            <td>
                                <input type="number" 
                                       name="celebration_duration" 
                                       value="<?php echo esc_attr($current_config['game_settings']['celebration_duration'] ?? 6000); ?>"
                                       step="500" 
                                       min="1000" 
                                       max="15000" />
                                <p class="description">How long to wait before redirecting after the game ends</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Enable Sound</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="enable_sound" 
                                           value="1" 
                                           <?php checked(!empty($current_config['game_settings']['enable_sound'])); ?> />
                                    Enable sound effects during game
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Enable Animations</th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           name="enable_animations" 
                                           value="1" 
                                           <?php checked($current_config['game_settings']['enable_animations'] ?? true); ?> />
                                    Enable game animations
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Redirect URLs -->
            <div class="postbox">
                <h2 class="hndle">Redirect URLs</h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Winner Page URL</th>
                            <td>
                                <input type="text" 
                                       name="winner_url" 
                                       value="<?php echo esc_attr($current_config['redirect_urls']['winner'] ?? '/winner'); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Loser Page URL</th>
                            <td>
                                <input type="text" 
                                       name="loser_url" 
                                       value="<?php echo esc_attr($current_config['redirect_urls']['loser'] ?? '/non-winner'); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Error Page URL</th>
                            <td>
                                <input type="text" 
                                       name="error_url" 
                                       value="<?php echo esc_attr($current_config['redirect_urls']['error'] ?? '/error'); ?>"
                                       class="regular-text" />
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <p class="submit">
                <input type="submit" name="save_game_config" class="button-primary" value="Save Game Configuration" />
            </p>
        </form>
        
        <?php endif; ?>
        
    </div>
</div>

<style>
.bmp-admin-container .postbox {
    margin-bottom: 20px;
}
.bpm-admin-container .form-table th {
    width: 200px;
}
</style>