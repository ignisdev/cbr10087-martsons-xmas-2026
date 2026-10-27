/**
 * AgeGate.js
 * Handles the age-gate / qualification page.
 *
 * Expects either:
 *   - A single <input type="date" id="dob"> field, or
 *   - Three separate inputs: #dob-day, #dob-month, #dob-year
 *
 * On valid DOB the form POSTs to WordPress (AJAX handler in BPM plugin)
 * which sets the session flag and returns the next step URL.
 */

import { gsap } from 'gsap';
import AgeValidator from '../utils/AgeValidator.js';

export default class AgeGate {
  constructor() {
    this.$form   = document.getElementById('dobCheck');
    this.$submit = this.$form?.querySelector('[type="submit"]');
    this.$error  = document.getElementById('dob-error');
    this.validator = new AgeValidator(18);

    if (!this.$form) return;
    this._animate();
    this._bind();
  }

  _animate() {
    // Entrance: stagger the scene children in
    gsap.from('.scene--age-gate .scene__content > *', {
      y: 30,
      autoAlpha: 0,
      stagger: 0.12,
      duration: 0.6,
      ease: 'power3.out',
    });
  }

  _bind() {
    this.$form.addEventListener('submit', (e) => {
      e.preventDefault();
      this._submit();
    });
  }

  _getDob() {
    // Single date input
    const single = document.getElementById('dob');
    if (single) return single.value; // 'YYYY-MM-DD'

    // Split inputs
    const day   = document.getElementById('dob-day')?.value;
    const month = document.getElementById('dob-month')?.value;
    const year  = document.getElementById('dob-year')?.value;
    if (day && month && year) {
      return `${day.padStart(2,'0')}/${month.padStart(2,'0')}/${year}`;
    }
    return null;
  }

  _setError(msg) {
    if (this.$error) {
      this.$error.textContent = msg;
      this.$error.hidden = !msg;
    }
  }

  _submit() {
    const dob = this._getDob();

    if (!dob) {
      this._setError('Please enter your date of birth.');
      return;
    }

    if (!this.validator.isOldEnough(dob)) {
      this._setError("Sorry, you must be 18 or over to enter this promotion.");
      return;
    }

    this._setError('');
    if (this.$submit) this.$submit.disabled = true;

    jQuery.post(
      window.bpm_ajax.url,
      {
        action: 'bpm_verify_age',
        nonce:  window.bpm_ajax.nonce,
        dob,
      },
      (response) => {
        if (response.success && response.data?.redirect) {
          window.location.href = response.data.redirect;
        } else {
          this._setError(response.data?.message || 'Something went wrong. Please try again.');
          if (this.$submit) this.$submit.disabled = false;
        }
      }
    );
  }
}
