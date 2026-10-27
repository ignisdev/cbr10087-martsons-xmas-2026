// Page-transition overlay (#pf-page-overlay, header.php). The fade-OUT on load
// is pure CSS (_transitions.scss) so pages show without waiting for JS; this
// module only fades it IN before an outbound navigation. No GSAP — it ships in
// the site bundle on every page.

const FADE_OUT_MS = 150;

const overlay = (): HTMLElement | null => document.getElementById( 'pf-page-overlay' );

export function initPageTransitions(): void {
	if ( ! overlay() ) return;

	// bfcache restore (Back): the page comes back as it was left — faded to
	// black mid-navigation. Clear that so it's visible again.
	window.addEventListener( 'pageshow', ( e: PageTransitionEvent ) => {
		const el = overlay();
		if ( ! e.persisted || ! el ) return;
		el.style.transition    = 'none';
		el.style.opacity       = '0';
		el.style.pointerEvents = 'none';
	} );

	// Intercept same-origin <a> clicks and fade out before navigating.
	document.addEventListener( 'click', ( e ) => {
		const link = ( e.target as HTMLElement ).closest<HTMLAnchorElement>( 'a[href]' );
		if ( ! link ) return;

		const href = link.getAttribute( 'href' ) ?? '';
		if ( ! href || href.startsWith( '#' ) || href.startsWith( 'javascript' ) || href.startsWith( 'mailto' ) || link.target === '_blank' ) return;
		if ( e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) return;

		try {
			const url = new URL( href, window.location.origin );
			if ( url.origin !== window.location.origin ) return;
		} catch {
			return;
		}

		e.preventDefault();
		navigateFade( href );
	} );
}

/** Fade to black then navigate — use instead of window.location.href for animated exits. */
export function navigateFade( url: string ): void {
	const el = overlay();
	if ( ! el ) {
		window.location.href = url;
		return;
	}
	// The CSS load animation pins opacity while it applies; drop it so the
	// inline fade-in takes over.
	el.style.animation     = 'none';
	el.style.transition    = 'none';
	el.style.opacity       = '0';
	el.style.pointerEvents = 'auto';
	void el.offsetWidth;   // commit opacity 0 before transitioning
	el.style.transition    = `opacity ${ FADE_OUT_MS }ms ease-in`;
	el.style.opacity       = '1';
	// Navigate on a timer rather than transitionend — it always fires, and the
	// browser starts the request while the last frames of the fade paint.
	setTimeout( () => { window.location.href = url; }, FADE_OUT_MS );
}
