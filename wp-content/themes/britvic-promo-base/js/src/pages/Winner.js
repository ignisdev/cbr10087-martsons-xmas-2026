/**
 * Winner.js
 * Entrance animation for the winner / prize-claim page.
 * The Gravity Form handles prize claim submission.
 */

import { gsap } from 'gsap';

export default class Winner {
  constructor() {
    this._animate();
  }

  _animate() {
    const tl = gsap.timeline();

    // Graphic pops in with a bounce
    tl.from('.scene--winner .scene__win-graphic', {
      scale:     0.6,
      autoAlpha: 0,
      duration:  0.6,
      ease: 'back.out(1.7)',
    });

    // Then the rest of the content fades up
    tl.from(
      '.scene--winner .scene__content > *:not(.scene__win-graphic)',
      {
        y:         20,
        autoAlpha: 0,
        stagger:   0.1,
        duration:  0.45,
        ease: 'power3.out',
      },
      '-=0.2'
    );
  }
}
