/**
 * LoadingScreen.js
 * Fades out and removes the full-screen loading overlay once the page is ready.
 * Markup: <div id="loading-screen" class="loading-screen"> … </div>
 */

import { gsap } from 'gsap';

export default class LoadingScreen {
  /**
   * @param {number} delay  Extra delay (ms) before starting the fade — useful for
   *                        letting hero images settle. Default 200ms.
   */
  constructor(delay = 200) {
    this.$el = document.getElementById('loading-screen');
    this.delay = delay;
  }

  /**
   * Hide the loading screen.
   * Called automatically by PromoApp after the page module has initialised.
   */
  hide() {
    if (!this.$el) return;

    gsap.to(this.$el, {
      autoAlpha: 0,
      delay: this.delay / 1000,
      duration: 0.4,
      ease: 'power2.inOut',
      onComplete: () => {
        this.$el.remove();
      },
    });
  }

  /**
   * Show the loading screen immediately (useful before a page redirect).
   */
  show() {
    if (!this.$el) return;
    gsap.set(this.$el, { autoAlpha: 1 });
  }
}
