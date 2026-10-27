/**
 * Photo.js
 * Camera / photo-upload page.
 *
 * Flow:
 *   1. User taps the camera trigger → opens the hidden <input type="file">
 *   2. On file selection → shows a preview and enables the upload button
 *   3. Upload button submits the hidden Gravity Form (which handles the actual upload)
 *
 * Expected markup (from page-photo.php):
 *   #cameraButton     — visible trigger button
 *   #photoInput       — hidden <input type="file" accept="image/*" capture="environment">
 *   #photoPreview     — <img> for the preview
 *   #uploadButton     — submit button (disabled until photo chosen)
 *   #gf-photo-form    — hidden Gravity Form wrapper
 *   #gfPhotoField     — the GF file field input
 */

import { gsap } from 'gsap';

export default class Photo {
  constructor() {
    this.$cameraBtn  = document.getElementById('cameraButton');
    this.$photoInput = document.getElementById('photoInput');
    this.$preview    = document.getElementById('photoPreview');
    this.$uploadBtn  = document.getElementById('uploadButton');
    this.$gfInput    = document.getElementById('gfPhotoField');

    if (!this.$cameraBtn || !this.$photoInput) return;

    this._animate();
    this._bind();
  }

  _animate() {
    gsap.from('.scene--photo .scene__content > *', {
      y: 24,
      autoAlpha: 0,
      stagger: 0.1,
      duration: 0.55,
      ease: 'power3.out',
    });
  }

  _bind() {
    // Camera trigger opens file picker
    this.$cameraBtn.addEventListener('click', () => this.$photoInput.click());

    this.$photoInput.addEventListener('change', (e) => this._onFileChosen(e));
  }

  _onFileChosen(e) {
    const file = e.target.files?.[0];
    if (!file) return;

    // Show preview
    const reader = new FileReader();
    reader.onload = (ev) => {
      if (this.$preview) {
        this.$preview.src = ev.target.result;
        this.$preview.hidden = false;
        gsap.from(this.$preview, { autoAlpha: 0, duration: 0.4 });
      }
    };
    reader.readAsDataURL(file);

    // Copy file to the hidden GF field via DataTransfer
    if (this.$gfInput) {
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        this.$gfInput.files = dt.files;
      } catch (_) {
        // DataTransfer not supported in all browsers — GF will handle fallback
      }
    }

    // Enable upload button
    if (this.$uploadBtn) {
      this.$uploadBtn.disabled = false;
      gsap.to(this.$uploadBtn, { autoAlpha: 1, duration: 0.3 });
    }
  }
}
