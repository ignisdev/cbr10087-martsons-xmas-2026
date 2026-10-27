import gsap from 'gsap';

// ── DOM element accessors ─────────────────────────────────────────────────────

const $  = ( id: string ) => document.getElementById( id ) as HTMLElement | null;
const js = ( key: string ) => document.querySelector<HTMLElement>( `[data-js="${ key }"]` );

const els = {
	instruction:    () => $( 'overlay-instruction' ),
	tapHint:        () => $( 'overlay-swipe-hint' ),
	commentary:     () => $( 'overlay-commentary' ),
	commentaryText: () => $( 'commentary-text' ),
	levelBanner:    () => js( 'level-banner' ),
	levelText:      () => js( 'level-banner-text' ),
	hudLevel:       () => js( 'hud-level' ),
};

// ── Public overlay API ────────────────────────────────────────────────────────

export const overlay = {
	/** Fade out the pre-game instruction + tap hint (first drop). */
	hideInstruction(): void {
		els.tapHint()?.classList.add( 'is-hidden' );
		const el = els.instruction();
		if ( el ) {
			gsap.to( el, { opacity: 0, y: -8, duration: 0.35, ease: 'power2.in' } );
		}
	},

	/** Pop in a commentary line; it stays until the page navigates away. */
	showCommentary( text: string ): void {
		const box = els.commentary();
		const txt = els.commentaryText();
		if ( ! box || ! txt ) return;
		txt.textContent = text;
		box.removeAttribute( 'aria-hidden' );
		gsap.fromTo( box, { opacity: 0, scale: 0.85 }, { opacity: 1, scale: 1, duration: 0.4, ease: 'back.out(2)' } );
	},

	/** Flash a short banner between levels. Resolves when it has faded out. */
	showLevelBanner( text: string ): Promise<void> {
		const box = els.levelBanner();
		const txt = els.levelText();
		if ( ! box || ! txt ) return Promise.resolve();
		txt.textContent = text;
		gsap.killTweensOf( box );   // a skipped practice can cut a banner short
		return new Promise( ( resolve ) => {
			gsap.timeline( { onComplete: () => resolve() } )
				.fromTo( box, { opacity: 0, scale: 0.8 }, { opacity: 1, scale: 1, duration: 0.3, ease: 'back.out(2)' } )
				.to( box, { opacity: 0, duration: 0.3, delay: 0.7, ease: 'power2.in' } );
		} );
	},

	/** Clear the commentary line (end of the practice go). */
	hideCommentary(): void {
		const box = els.commentary();
		if ( ! box ) return;
		box.setAttribute( 'aria-hidden', 'true' );
		gsap.to( box, { opacity: 0, duration: 0.25, ease: 'power2.in' } );
	},

	/** Current level in the header pill — or PRACTICE during the practice go. */
	setLevel( level: number | 'practice' ): void {
		const el = els.hudLevel();
		if ( el ) el.textContent = level === 'practice' ? 'PRACTICE' : `LEVEL ${ level }`;
	},
};
