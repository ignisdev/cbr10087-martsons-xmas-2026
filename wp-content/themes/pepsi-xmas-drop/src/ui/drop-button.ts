import gsap from 'gsap';

// Press animation for the drop-button image (pepsi_logo_button.png).
//
// The artwork is a flat PNG, so on press the whole logo dips and springs back.

export interface DropRipple {
	play: () => void;
}

export function initDropRipple( img: HTMLElement | null ): DropRipple {
	if ( ! img ) return { play: () => undefined };

	let tl: gsap.core.Timeline | null = null;

	return {
		play() {
			tl?.progress( 1 ).kill();
			tl = gsap.timeline()
				.to( img, { scale: 0.88, duration: 0.08, ease: 'power2.out' }, 0 )
				.to( img, { scale: 1, duration: 0.35, ease: 'back.out(3)', clearProps: 'transform' }, 0.08 );
		},
	};
}
