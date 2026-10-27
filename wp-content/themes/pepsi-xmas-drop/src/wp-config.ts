import type { ModelType } from './game-engine/config.js';
import type { SoundKey } from './audio/sounds.js';

// ── Config from WordPress (wp_localize_script in includes/lib/assets.php) ─────
export interface GameConfig {
	congratulations_url?:  string;  // Winner page
	non_winner_url?:       string;  // Non-winner / thank-you page
	daily_limit_url?:      string;  // Shown when /session/start returns 429 daily_limit
	rest_url?:             string;
	rest_nonce?:           string;
	pf_nonce?:             string;  // anonymous per-session nonce, replayed via X-PF-Nonce
	competition_id?:       number;
	piece_images?:         Partial<Record<ModelType, string>>;  // sprite per piece / light-strand type
	track_image?:          string;                              // bauble_track.png
	loader_image?:         string;                              // loader bauble artwork
	sounds?:               Partial<Record<SoundKey, string>>;   // server-injected SFX URLs
	warm_urls?:            string[];                            // how-to-play only: /game/ assets to pre-cache
}
declare global {
	interface Window {
		PepsiXmasConfig?: GameConfig;
	}
}
