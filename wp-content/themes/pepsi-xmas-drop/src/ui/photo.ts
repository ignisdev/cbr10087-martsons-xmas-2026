import { createPfLoader } from './loader.js';

// Upload sizing — phone photos are 3–10 MB; the reviewer only needs to see the
// Pepsi Max. Anything bigger than this is re-encoded before upload.
const MAX_EDGE_PX     = 2048;
const JPEG_QUALITY    = 0.85;
const KEEP_UNDER_BYTES = 1.5 * 1024 * 1024;

// Loader pacing: PROCESSING runs while the upload is in flight (never shorter
// than PROCESSING_MIN_MS, so it doesn't flash), then IMAGE RECEIVED shows
// briefly once the server has answered.
const PROCESSING_MIN_MS = 800;
const RECEIVED_MS       = 600;

const wait = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));

/**
 * Shrink a photo to MAX_EDGE_PX on its longest side as a JPEG, with the
 * camera's EXIF rotation applied. Returns the original file untouched when it
 * is already small, is a GIF, or the browser can't decode it (e.g. HEIC on
 * Android Chrome) — so a failure here never blocks the upload.
 */
async function shrinkPhoto(file: File): Promise<File> {
  if (file.type === 'image/gif') return file;
  try {
    const bmp   = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX_EDGE_PX / Math.max(bmp.width, bmp.height));
    if (scale === 1 && file.size <= KEEP_UNDER_BYTES) {
      bmp.close();
      return file;
    }
    const canvas  = document.createElement('canvas');
    canvas.width  = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    const ctx = canvas.getContext('2d');
    if (!ctx) { bmp.close(); return file; }
    ctx.drawImage(bmp, 0, 0, canvas.width, canvas.height);
    bmp.close();
    const blob = await new Promise<Blob | null>((r) => canvas.toBlob(r, 'image/jpeg', JPEG_QUALITY));
    if (!blob || blob.size === 0 || blob.size >= file.size) return file;
    const name = (file.name || 'photo').replace(/\.[^.]+$/, '') + '.jpg';
    return new File([ blob ], name, { type: 'image/jpeg' });
  } catch {
    return file;
  }
}

export function initPhotoPage(): void {
  const root = document.querySelector<HTMLElement>('[data-js="photo-root"]');
  if (!root) return;

  const cameraTrigger = root.querySelector<HTMLElement>('[data-js="photo-trigger"]');
  const proxyInput = root.querySelector<HTMLInputElement>('[data-js="photo-proxy-input"]');
  const overlay = root.querySelector<HTMLElement>('[data-js="photo-preview"]');
  const uploadBtn = root.querySelector<HTMLButtonElement>('[data-js="photo-upload-btn"]');
  const formWrap = root.querySelector<HTMLElement>('[data-js="photo-gf-wrap"]');

  // formWrap is best-effort — if the GF photo form isn't rendering for any
  // reason, we still want the camera/upload buttons to open the file picker.
  if (!proxyInput || !cameraTrigger || !uploadBtn) return;

  const gfFileInput = formWrap?.querySelector<HTMLInputElement>('input[type="file"]') ?? null;
  const gfSubmitBtn = formWrap?.querySelector<HTMLElement>('input[type="submit"], button[type="submit"], .gform_button') ?? null;

  // Prefer the GF file input as the picker target so the chosen File lives
  // directly on the input that the form posts. iOS Safari silently blocks
  // `input.files = new DataTransfer().files`, which previously left GF's
  // input empty and caused the form's required-file validation to refuse
  // the submit (no POST, user stuck on /photo/).
  //
  // We can only click a file input that isn't display:none — Safari and
  // Chrome both refuse to open a file picker on a fully-hidden input. The
  // GF wrapper renders the input, so we visually hide it via CSS (off-screen
  // / opacity:0) but keep it clickable. As a last resort if GF's input
  // isn't reachable we fall back to the proxy.
  const ensureGfInputClickable = (): void => {
    if (!gfFileInput) return;
    gfFileInput.style.position = 'absolute';
    gfFileInput.style.opacity  = '0';
    gfFileInput.style.width    = '1px';
    gfFileInput.style.height   = '1px';
    gfFileInput.style.pointerEvents = 'none';
    gfFileInput.style.display  = '';   // override any inline display:none from GF
    gfFileInput.style.visibility = 'visible';
  };
  ensureGfInputClickable();

  const pickerTarget: HTMLInputElement = gfFileInput ?? proxyInput;

  // The PROCESSING loader's bauble image is only drawn on Submit — fetch it now
  // so it's cached and appears with the loader rather than popping in.
  const loaderImage = window.PepsiXmasConfig?.loader_image ?? '';
  if (loaderImage) new Image().src = loaderImage;

  let photoChosen = false;
  // Started as soon as a photo is chosen so it's usually ready by Submit.
  let prepared: Promise<File> | null = null;

  const openPicker = (): void => {
    pickerTarget.click();
  };

  const onFileChosen = (file: File | null | undefined): void => {
    if (!file) return;
    photoChosen = true;

    // Once a photo's been chosen the button stops being a "pick a file"
    // trigger and becomes the "send the form" CTA.
    uploadBtn.textContent = 'Submit';
    prepared = shrinkPhoto(file);

    const reader = new FileReader();
    reader.onload = (ev) => {
      if (overlay && ev.target?.result) {
        overlay.style.backgroundImage = `url("${String(ev.target.result)}")`;
      }
    };
    reader.readAsDataURL(file);

    // If we ended up picking via the proxy (GF input not present) try the
    // DataTransfer copy as a best-effort. Won't work on iOS but doesn't hurt
    // on desktop.
    if (gfFileInput && pickerTarget !== gfFileInput) {
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        gfFileInput.files = dt.files;
      } catch {
        // no-op
      }
    }
  };

  cameraTrigger.addEventListener('click', openPicker);
  cameraTrigger.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openPicker();
    }
  });

  pickerTarget.addEventListener('change', (event) => {
    const target = event.target as HTMLInputElement;
    onFileChosen(target.files?.[0]);
  });

  uploadBtn.addEventListener('click', () => {
    // Before a photo is chosen, the Upload button doubles as a capture trigger.
    if (!photoChosen) {
      openPicker();
      return;
    }

    if (uploadBtn.disabled) return;
    uploadBtn.disabled = true;

    // Upload straight away; the loader messages run alongside it rather than
    // as a fixed pause in front of it (pf_flow_next_url decides where the
    // successful submit redirects).
    //
    // iOS Safari has a known bug picking from the Photo Library: the File
    // reference carries `name` + `type` but its bytes aren't materialised when
    // the browser synchronously serializes the multipart body — the file part
    // is sent with `filename` set but the body empty, so GF rejects "file
    // required" and re-renders the page (looks like a refresh). To work around
    // that we read the file into an ArrayBuffer explicitly first, rebuild a
    // FormData from the GF form, replace the file field with a Blob built from
    // those bytes so the body is guaranteed non-empty, and POST via fetch.
    const loader    = createPfLoader({ label: 'PROCESSING' });
    const startedAt = performance.now();
    const received  = async (): Promise<void> => {
      await wait(Math.max(0, PROCESSING_MIN_MS - (performance.now() - startedAt)));
      loader.setLabel('IMAGE RECEIVED', 'PLEASE WAIT');
      await wait(RECEIVED_MS);
    };

    void (async () => {
      const form   = formWrap?.querySelector<HTMLFormElement>('form') ?? null;
      const picked = (gfFileInput?.files?.[0]) ?? (proxyInput?.files?.[0]) ?? null;
      if (!form || !picked) {
        gfSubmitBtn?.click();
        return;
      }

      try {
        const file = await (prepared ?? shrinkPhoto(picked));
        // Materialise the bytes now (FileReader / arrayBuffer fires async and
        // is what the iOS picker actually populates, unlike sync serialization).
        const buffer = await file.arrayBuffer();
        if (buffer.byteLength === 0) {
          throw new Error('file.arrayBuffer() returned 0 bytes');
        }
        const blob = new Blob([ buffer ], { type: file.type || 'image/jpeg' });

        const fd = new FormData(form);
        const fileFieldName = gfFileInput?.getAttribute('name') ?? 'input_1';
        fd.set(fileFieldName, blob, file.name || 'photo.jpg');

        const action = form.action || location.pathname;
        const res = await fetch(action, {
          method:      'POST',
          body:        fd,
          credentials: 'same-origin',
          redirect:    'follow',
        });

        // Successful GF submit responds with a redirect to the gform
        // confirmation target (the next flow step). fetch follows it, so
        // res.url is the destination — point the browser at it.
        if (res.redirected && res.url) {
          await received();
          location.href = res.url;
          return;
        }
        // Same-page response → GF returned the form with validation errors
        // (or the redirect failed). Render whatever the server returned so
        // the player can see the message instead of an apparent refresh.
        const html = await res.text();
        document.open();
        document.write(html);
        document.close();
      } catch {
        gfSubmitBtn?.click();
      }
    })();
  });
}
