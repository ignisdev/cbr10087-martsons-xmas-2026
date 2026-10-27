/**
 * PromoApp.js
 * Bootstrap for the Britvic Promo Base theme.
 *
 * Detects the current page by inspecting <body> classes, loads the
 * appropriate page module, initialises shared utilities, then hides
 * the loading screen.
 *
 * Body class → module mapping:
 *   page-age-gate        → AgeGate
 *   page-qualification   → AgeGate (shares the same DOB logic)
 *   page-entry           → Entry
 *   page-draw-entry      → Entry
 *   page-photo           → Photo
 *   page-game            → Game
 *   page-winner          → Winner
 *   page-non-winner      → (no animation module needed — static)
 *   page-thanks          → Thanks
 *   page-draw-confirm    → Thanks (reuses the same animation)
 *   page-closed          → (static)
 *   page-partner         → Entry (generic entrance)
 */

import { gsap }       from 'gsap';
import LoadingScreen  from './utils/LoadingScreen.js';
import TermsModal     from './utils/TermsModal.js';
import AgeGate        from './pages/AgeGate.js';
import Entry          from './pages/Entry.js';
import Photo          from './pages/Photo.js';
import Game           from './pages/Game.js';
import Winner         from './pages/Winner.js';
import Thanks         from './pages/Thanks.js';

class PromoApp {
  constructor() {
    this.bodyClasses  = document.body.classList;
    this.loader       = new LoadingScreen(200);
    this.termsModal   = new TermsModal();

    this._initPage();
    this.loader.hide();
  }

  _initPage() {
    const c = this.bodyClasses;

    if (c.contains('page-age-gate') || c.contains('page-qualification')) {
      new AgeGate();
    } else if (c.contains('page-entry') || c.contains('page-draw-entry') || c.contains('page-partner')) {
      new Entry();
    } else if (c.contains('page-photo')) {
      new Photo();
    } else if (c.contains('page-game')) {
      new Game();
    } else if (c.contains('page-winner')) {
      new Winner();
    } else if (c.contains('page-thanks') || c.contains('page-draw-confirm')) {
      new Thanks();
    }
    // page-non-winner, page-closed → no JS module required
  }
}

// Initialise once DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  new PromoApp();
});
