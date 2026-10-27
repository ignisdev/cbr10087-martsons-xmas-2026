/**
 * Entry.js
 * Entrance animation for the main entry / prize-draw entry page.
 * The Gravity Form itself handles all submission logic.
 */

import { gsap } from 'gsap';

export default class Entry {
  constructor() {
    this._animate();
  }

  _animate() {
    gsap.from('.scene--entry .scene__content > *', {
      y: 24,
      autoAlpha: 0,
      stagger: 0.1,
      duration: 0.55,
      ease: 'power3.out',
    });
  }
}
