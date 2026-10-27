/**
 * Game.js
 * Spin-wheel instant-win mechanic.
 *
 * Expected markup (from page-game.php):
 *   .game-wheel__spinner__wrapper   — the rotating element
 *   .spin-button                    — trigger button
 *   .scene--game-winner             — overlay shown on win
 *   .scene--game-loser              — overlay shown on loss
 *
 * window.prizeData is injected by the PHP template:
 *   { outcome: 'win' | 'lose', prize: string }
 *
 * The spin animation always ends at a visually-random position;
 * the actual outcome is determined server-side before the spin starts.
 */

import { gsap } from 'gsap';

export default class Game {
  constructor() {
    this.$spinner  = document.querySelector('.game-wheel__spinner__wrapper');
    this.$spinBtn  = document.querySelector('.spin-button');
    this.$winScene = document.querySelector('.scene--game-winner');
    this.$loseScene = document.querySelector('.scene--game-loser');
    this.prizeData = window.prizeData || null;
    this.hasSpun   = false;

    if (!this.$spinner || !this.$spinBtn) return;

    this._animate();
    this._bind();
  }

  _animate() {
    gsap.from('.scene--game .scene__content > *', {
      y: 24,
      autoAlpha: 0,
      stagger: 0.1,
      duration: 0.55,
      ease: 'power3.out',
    });
  }

  _bind() {
    this.$spinBtn.addEventListener('click', () => this._spin());
  }

  /**
   * Determine the final rotation angle.
   * Always adds multiple full rotations for visual effect.
   * The "landing" offset within the final rotation is randomised so the
   * wheel doesn't always stop at the same visual segment.
   */
  _getFinalAngle() {
    const fullRotations = (5 + Math.floor(Math.random() * 3)) * 360; // 5–7 full turns
    const randomOffset  = Math.random() * 360;
    return fullRotations + randomOffset;
  }

  _spin() {
    if (this.hasSpun) return;
    this.hasSpun = true;

    // Disable spin button immediately
    this.$spinBtn.disabled = true;
    gsap.to(this.$spinBtn, { autoAlpha: 0.4, duration: 0.2 });

    const angle    = this._getFinalAngle();
    const duration = 4 + Math.random() * 1.5; // 4–5.5 seconds

    gsap.to(this.$spinner, {
      rotation:  angle,
      duration,
      ease: 'power4.out',
      onComplete: () => this._revealOutcome(),
    });
  }

  _revealOutcome() {
    const outcome = this.prizeData?.outcome;
    const $scene  = outcome === 'win' ? this.$winScene : this.$loseScene;

    if (!$scene) {
      // Fallback: redirect to next page if overlay markup is missing
      this._redirectNext();
      return;
    }

    // Slide the overlay in
    gsap.set($scene, { autoAlpha: 0, display: 'flex' });
    gsap.to($scene, {
      autoAlpha: 1,
      duration:  0.5,
      ease: 'power2.out',
    });

    // Animate overlay children
    gsap.from($scene.querySelectorAll('.scene__content > *'), {
      y: 30,
      autoAlpha: 0,
      stagger: 0.12,
      duration: 0.5,
      delay: 0.3,
      ease: 'power3.out',
    });

    // The CTA inside the overlay handles the redirect via a normal link
  }

  _redirectNext() {
    if (window.bpm_config?.next_url) {
      window.location.href = window.bpm_config.next_url;
    }
  }
}
