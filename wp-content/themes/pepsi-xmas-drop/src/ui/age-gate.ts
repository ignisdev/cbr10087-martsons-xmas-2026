export function initAgeGatePage(): void {
  const root = document.querySelector<HTMLElement>('[data-js="age-gate-root"]');
  if (!root) return;

  const formId = Number(root.dataset.formId || '0');
  if (!formId) return;

  const dobTitle = root.querySelector<HTMLElement>('[data-js="age-gate-title-dob"]');
  const contactTitle = root.querySelector<HTMLElement>('[data-js="age-gate-title-contact"]');
  const contactLogo = root.querySelector<HTMLElement>('[data-js="age-gate-contact-logo"]');
  const headlineWrap = root.querySelector<HTMLElement>('[data-js="age-gate-headline"]');
  const formWrap = root.querySelector<HTMLElement>('[data-js="age-gate-form-wrap"]');
  const termsLink = root.querySelector<HTMLElement>('[data-js="age-gate-terms-link"]');

  const detectCurrentPage = (targetFormId: number): number => {
    const pages = document.querySelectorAll<HTMLElement>(`#gform_wrapper_${targetFormId} .gform_page`);
    for (const page of pages) {
      if (window.getComputedStyle(page).display !== 'none' && page.id) {
        const parts = page.id.split('_');
        const pageNum = Number(parts[parts.length - 1]);
        if (!Number.isNaN(pageNum) && pageNum > 0) return pageNum;
      }
    }

    const sourceInput = document.getElementById(`gform_source_page_number_${targetFormId}`) as HTMLInputElement | null;
    const targetInput = document.getElementById(`gform_target_page_number_${targetFormId}`) as HTMLInputElement | null;

    const targetValue = Number(targetInput?.value || '0');
    if (targetValue > 0) return targetValue;

    const sourceValue = Number(sourceInput?.value || '0');
    if (sourceValue > 0) return sourceValue;

    return 1;
  };

  const updateStepUi = (currentPage: number): void => {
    if (!dobTitle || !contactTitle || !headlineWrap || !formWrap) return;

    const pageNum = Number.isNaN(currentPage) || currentPage < 1 ? 1 : currentPage;
    const isDobStep = pageNum === 1;

    dobTitle.classList.toggle('u-hidden', !isDobStep);
    contactTitle.classList.toggle('u-hidden', isDobStep);
    contactLogo?.classList.toggle('u-hidden', isDobStep);
    headlineWrap.classList.toggle('u-hidden', !isDobStep);
    termsLink?.classList.toggle('u-hidden', isDobStep);

    formWrap.classList.toggle('is-step-dob', isDobStep);
    formWrap.classList.toggle('is-step-contact', !isDobStep);
  };

  const stripGfHash = (): void => {
    if (window.location.hash && /^#gf_\d+/.test(window.location.hash)) {
      history.replaceState(null, '', window.location.pathname + window.location.search);
    }
  };

  // Date-of-birth field is rendered as a plain text input by the
  // gform_field_input filter (see includes/lib/methods.php) and the
  // DD/MM/YYYY slashes are kept in place by ui/date-mask.ts. No native date
  // picker is opened on any browser — Safari iOS / desktop included.

  updateStepUi(detectCurrentPage(formId));
  stripGfHash();

  document.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null;
    if (!target) return;

    const selector = `#gform_wrapper_${formId} .gform_next_button, #gform_wrapper_${formId} .gform_previous_button`;
    if (!target.closest(selector)) return;

    setTimeout(() => {
      updateStepUi(detectCurrentPage(formId));
      stripGfHash();
    }, 50);

    setTimeout(() => {
      updateStepUi(detectCurrentPage(formId));
      stripGfHash();
    }, 250);
  });

  const jq = (window as Window & { jQuery?: any }).jQuery;
  if (jq) {
    jq(document).on('gform_page_loaded', (_event: unknown, loadedFormId: number, currentPage: number) => {
      if (Number(loadedFormId) !== formId) return;
      updateStepUi(Number(currentPage));
      stripGfHash();
    });
  }

  window.addEventListener('hashchange', stripGfHash);

  // Inline submit spinner — GF's default `<img class="gform_ajax_spinner">`
  // only ever appears on AJAX-mode forms (which the age-gate isn't, by
  // design), so we render it manually next to the submit / next button on
  // any submit event. Mounts once per click, vanishes naturally when the
  // page reloads to the next step.
  const form = document.getElementById(`gform_${formId}`) as HTMLFormElement | null;
  if (form) {
    form.addEventListener('submit', () => {
      const btn = form.querySelector<HTMLElement>(
        '.gform_button, .gform_next_button, button[type="submit"], input[type="submit"]'
      );
      if (!btn) return;
      // Avoid stacking duplicates if the submit handler fires more than once.
      if (btn.parentElement?.querySelector('.gform_ajax_spinner')) return;
      const spinner = document.createElement('img');
      spinner.className = 'gform_ajax_spinner';
      spinner.src = '/wp-content/plugins/gravityforms/images/spinner.svg';
      spinner.alt = '';
      btn.insertAdjacentElement('afterend', spinner);
    });
  }
}
