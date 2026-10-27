// DD/MM/YYYY input mask for the GF date fields rendered as text via
// gform_field_input. Strips non-digits, caps at 8 digits, and re-inserts the
// slashes at positions 3 and 6 so the format hint is always present in the
// value as the user types.

function formatDateValue( raw: string ): string {
	const digits = raw.replace( /\D/g, '' ).slice( 0, 8 );
	if ( digits.length <= 2 ) return digits;
	if ( digits.length <= 4 ) return `${ digits.slice( 0, 2 ) }/${ digits.slice( 2 ) }`;
	return `${ digits.slice( 0, 2 ) }/${ digits.slice( 2, 4 ) }/${ digits.slice( 4 ) }`;
}

function attachMask( input: HTMLInputElement ): void {
	if ( input.dataset.pfDateMaskBound ) return;
	input.dataset.pfDateMaskBound = '1';

	// Initial render — useful if the field comes back pre-filled from a server
	// validation round-trip with raw digits or partial slashes.
	input.value = formatDateValue( input.value );

	input.addEventListener( 'input', ( e ) => {
		const target = e.target as HTMLInputElement;
		const before = target.value;
		const caretEnd = target.selectionEnd ?? before.length;
		const digitsBeforeCaret = before.slice( 0, caretEnd ).replace( /\D/g, '' ).length;

		const formatted = formatDateValue( before );
		target.value = formatted;

		// Restore caret position after re-formatting: count digits up to where
		// the caret was and place the caret after the same digit count in the
		// new value (skipping inserted slashes).
		let pos = 0;
		let digitsSeen = 0;
		while ( pos < formatted.length && digitsSeen < digitsBeforeCaret ) {
			if ( /\d/.test( formatted[ pos ] ) ) digitsSeen++;
			pos++;
		}
		target.setSelectionRange( pos, pos );
	} );

	// Paste: let `input` event handle reformatting after the paste lands.
	// No special handling needed beyond the input listener above.
}

function attachToAll(): void {
	document.querySelectorAll<HTMLInputElement>( 'input[data-pf-date-mask]' ).forEach( attachMask );
}

export function initDateMask(): void {
	attachToAll();

	const jq = ( window as Window & { jQuery?: any } ).jQuery;
	if ( jq ) {
		// Re-bind after GF AJAX re-renders.
		jq( document ).on( 'gform_post_render', attachToAll );
	}
}
