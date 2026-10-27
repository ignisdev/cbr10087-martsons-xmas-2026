// Replaces GF's jQuery datepicker with the native OS date input on any GF form.
// Runs site-wide so any form with a `.ginput_container_date` field (age gate,
// congratulations, etc.) gets the same DD/MM/YYYY native picker UX.
//
// Strategy mirrors the original age-gate implementation: hide GF's input, lay a
// native <input type="date"> on top, sync the value back to GF as DD/MM/YYYY so
// GF's built-in validator accepts it.

function applyOverlayToContainer( container: HTMLElement ): void {
	if ( container.dataset.nativeDateOverlay ) return;

	const gfInput = container.querySelector<HTMLInputElement>( 'input' );
	if ( !gfInput ) return;

	container.dataset.nativeDateOverlay = '1';
	// Always use the format hint as the placeholder — GF's per-field
	// placeholder (e.g. "Date of Birth") is treated as a label and shouldn't
	// leak into the input itself. The CSS pseudo-element reads this via
	// attr(data-placeholder).
	const gfPlaceholder = 'DD/MM/YYYY';
	container.dataset.placeholder = gfPlaceholder;

	// Strip GF's auto-generated "DD slash MM slash YYYY" screen-reader hint —
	// the placeholder + aria-label on the overlay already convey the format.
	const srSpan = container.parentElement?.querySelector<HTMLElement>(
		'span.screen-reader-text[id$="_date_format"]',
	) ?? container.querySelector<HTMLElement>(
		'span.screen-reader-text[id$="_date_format"]',
	);
	srSpan?.remove();

	gfInput.style.display = 'none';
	gfInput.tabIndex      = -1;
	gfInput.setAttribute( 'aria-hidden', 'true' );

	const today = new Date().toISOString().split( 'T' )[ 0 ];

	const native = document.createElement( 'input' );
	native.type  = 'date';
	native.max   = today;
	native.setAttribute( 'autocomplete', 'bday' );
	native.className = gfInput.className
		.replace( /\b(datepicker|hasDatepicker|dateinput)\b/g, '' )
		.trim();
	native.setAttribute( 'aria-label', gfPlaceholder );
	// type="date" ignores `placeholder` in most browsers, but setting it keeps
	// the attribute available for any UA that does honour it (and as a fallback
	// for the brief moment the overlay's input is type="text" mid-init).
	native.placeholder = gfPlaceholder;

	// iOS Safari doesn't render a placeholder for type="date". Mark the container
	// as empty so a CSS ::before pseudo-element can show the GF placeholder until
	// the user picks a date.
	const setEmptyState = (): void => {
		if ( native.value === '' ) {
			container.dataset.empty = '1';
		} else {
			delete container.dataset.empty;
		}
	};
	setEmptyState();

	native.addEventListener( 'change', () => {
		const parts = native.value.split( '-' ); // YYYY-MM-DD
		if ( parts.length === 3 ) {
			gfInput.value = `${ parts[ 2 ] }/${ parts[ 1 ] }/${ parts[ 0 ] }`; // DD/MM/YYYY
		}
		setEmptyState();
	} );

	native.addEventListener( 'input', setEmptyState );

	// iOS Safari: change/input from the date wheel can race with the focus
	// release, leaving data-empty set even though the user picked a value.
	// Drop the placeholder eagerly on focus and re-evaluate on blur (and on a
	// trailing rAF after blur, since native.value sometimes settles a tick
	// after the blur event).
	native.addEventListener( 'focus', () => {
		delete container.dataset.empty;
	} );
	native.addEventListener( 'blur', () => {
		setEmptyState();
		requestAnimationFrame( setEmptyState );
	} );

	container.insertBefore( native, gfInput );
}

function attachToAllDateContainers(): void {
	const containers = document.querySelectorAll<HTMLElement>( '.ginput_container_date' );
	containers.forEach( applyOverlayToContainer );
}

export function initNativeDateOverlay(): void {
	// Run immediately (sync, before any $(document).ready handlers fire)
	// so we win the race with GF's GFDatepicker init.
	attachToAllDateContainers();

	// Re-attach after GF re-renders (AJAX page advance, validation refresh).
	const jq = ( window as Window & { jQuery?: any } ).jQuery;
	if ( jq ) {
		jq( document ).on( 'gform_post_render', () => {
			attachToAllDateContainers();
		} );
	}
}
