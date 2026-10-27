/**
 * TermsModal.js
 * Opens / closes the T&C overlay with a simple GSAP fade.
 * Expects the markup written by header.php:
 *   .terms-wrapper  (the overlay)
 *   .terms-wrapper__close  (close button inside the overlay)
 *   [data-terms-open]  (any trigger — e.g. the footer T&C link)
 */

import { gsap } from 'gsap';

export default class TermsModal {
  constructor() {
    this.$wrapper = document.querySelector('.terms-wrapper');
    if (!this.$wrapper) return;

    this._bind();
  }

  _bind() {
    // Open triggers
    document.querySelectorAll('[data-terms-open]').forEach((el) => {
      el.addEventListener('click', (e) => {
        e.preventDefault();
        this.open();
      });
    });

    // Close button inside the modal
    const closeBtn = this.$wrapper.querySelector('.terms-wrapper__close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.close());
    }

    // Click outside the inner content closes the modal
    this.$wrapper.addEventListener('click', (e) => {
      if (e.target === this.$wrapper) this.close();
    });

    // Escape key
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.$wrapper.classList.contains('terms-wrapper--is-active')) {
        this.close();
      }
    });
  }

  open() {
    this.$wrapper.classList.add('terms-wrapper--is-active');
    gsap.fromTo(
      this.$wrapper,
      { autoAlpha: 0 },
      { autoAlpha: 1, duration: 0.3, ease: 'power2.out' }
    );
    document.body.style.overflow = 'hidden';
  }

  close() {
    gsap.to(this.$wrapper, {
      autoAlpha: 0,
      duration: 0.25,
      ease: 'power2.in',
      onComplete: () => {
        this.$wrapper.classList.remove('terms-wrapper--is-active');
        document.body.style.overflow = '';
      },
    });
  }
}
