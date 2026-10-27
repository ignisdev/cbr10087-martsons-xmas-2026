/**
 * Thanks.js
 * Entrance animation for the thank-you / confirmation page.
 */

import { gsap } from 'gsap';

export default class Thanks {
  constructor() {
    this._animate();
  }

  _animate() {
    const tl = gsap.timeline();

    // Graphic bounces in
    tl.from('.scene--thanks .scene__thanks-graphic', {
      scale:     0.7,
      autoAlpha: 0,
      duration:  0.55,
      ease: 'back.out(1.5)',
    });

    // Rest of content fades up
    tl.from(
      '.scene--thanks .scene__content > *:not(.scene__thanks-graphic)',
      {
        y:         18,
        autoAlpha: 0,
        stagger:   0.1,
        duration:  0.45,
        ease: 'power3.out',
      },
      '-=0.15'
    );
  }
}
