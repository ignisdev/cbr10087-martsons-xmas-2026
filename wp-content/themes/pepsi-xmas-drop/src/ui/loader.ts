// Single canonical loader used site-wide (game asset boot, photo processing).
// Full-screen black overlay with the swinging bauble artwork (config
// loader_image — the game's bauble sprite, warmed in advance) + optional label
// below. Falls back to a pure-CSS bauble if no image URL is configured. On
// /game/ the markup is server-rendered (page-game.php, same markup) and adopted.

const MIN_DISPLAY_MS     = 500;  // once visible, keep the loader up at least this long
const DEFERRED_REVEAL_MS = 400;  // .pf-loader--deferred fades in after this (_loader.scss)

export interface PfLoader {
	el:           HTMLElement;
	setLabel:     ( text: string, sublabel?: string ) => void;
	setSublabel:  ( text: string ) => void;
	stopSpin:     () => void;
	hide:         ( delayMs?: number, onHidden?: () => void ) => void;
}

function markup(): string {
	const img  = window.PepsiXmasConfig?.loader_image ?? '';
	const ball = img
		? `<img class="pf-loader__image" src="${ encodeURI( img ) }" alt="" decoding="async">`
		: '<span class="pf-loader__bauble"></span>';
	return `
		<div class="pf-loader__inner">
			<div class="pf-loader__ball" aria-hidden="true">${ ball }</div>
			<span class="pf-loader__label"></span>
			<span class="pf-loader__sublabel"></span>
		</div>
	`;
}

export function createPfLoader( opts: { label?: string; parent?: HTMLElement; el?: HTMLElement } = {} ): PfLoader {
	// Adopted (server-rendered) loaders have been on screen since first paint,
	// so their minimum display time counts from navigation start.
	const adopted = opts.el ?? null;
	const root    = adopted ?? document.createElement( 'div' );
	if ( ! adopted ) {
		root.className = 'pf-loader';
		root.setAttribute( 'aria-hidden', 'true' );
		root.innerHTML = markup();
		( opts.parent ?? document.body ).appendChild( root );
	}

	const labelEl    = root.querySelector<HTMLElement>( '.pf-loader__label' )!;
	const sublabelEl = root.querySelector<HTMLElement>( '.pf-loader__sublabel' )!;
	const ballEl     = root.querySelector<HTMLElement>( '.pf-loader__ball' )!;
	if ( opts.label !== undefined ) labelEl.textContent = opts.label;

	const mountedAt = adopted ? 0 : performance.now();
	// Deferred loaders only become visible at DEFERRED_REVEAL_MS; their minimum
	// display time counts from then.
	const shownAt   = root.classList.contains( 'pf-loader--deferred' ) ? mountedAt + DEFERRED_REVEAL_MS : mountedAt;

	return {
		el: root,
		setLabel( text: string, sublabel?: string ): void {
			labelEl.textContent = text;
			if ( sublabel !== undefined ) {
				sublabelEl.textContent = sublabel;
			}
		},
		setSublabel( text: string ): void {
			sublabelEl.textContent = text;
		},
		stopSpin(): void {
			ballEl.classList.add( 'is-still' );
		},
		hide( delayMs: number = 0, onHidden?: () => void ): void {
			// Never shown (fast boot) — just drop it, no fade, no minimum.
			if ( delayMs === 0 && performance.now() < shownAt ) {
				root.remove();
				onHidden?.();
				return;
			}
			const minRemain = Math.max( shownAt + MIN_DISPLAY_MS - performance.now(), 0 );
			setTimeout( () => {
				root.classList.add( 'is-hidden' );
				setTimeout( () => {
					root.remove();
					onHidden?.();
				}, 400 );
			}, delayMs + minRemain );
		},
	};
}
