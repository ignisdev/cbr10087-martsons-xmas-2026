/**
 * Pepsi Xmas Drop — game sound effects.
 *
 * Web Audio API wrapper (rather than HTMLAudioElement) so each one-shot
 * decodes once at boot and plays with ~zero scheduling latency on mobile —
 * important for the drop / landing sounds that need to feel like a
 * collision, not an animated SFX.
 *
 * One AudioContext, created lazily on the first user gesture (browser
 * autoplay policy). Up to four pre-decoded buffers (ambience, cheer, groan,
 * drop) — any key without a URL is simply skipped. Each call to play*
 * schedules a fresh AudioBufferSourceNode
 * — buffers are one-shot, sources are throwaway.
 *
 * Each sound's region within its source file is configurable via SOUND_CONFIG
 * (offset / duration / gain / loop) so the raw audio files don't need editing
 * — the live tuner panel (sound-tuner.ts, debug-only) lets you dial these in
 * by ear and copy the final values back into this file.
 *
 * Mute state persists in localStorage so it survives the GF redirects
 * through the how-to-play / game funnel.
 */

// ── Per-sound config (edit these to retime within the source files) ──────────

interface SoundConfig {
	/** Filename inside the sound URL map. */
	src:      string;
	/** Seconds into the file where the audible region begins. */
	offset:   number;
	/** Seconds to play before auto-stopping; null = play to end of buffer. */
	duration: number | null;
	/** Per-sound volume 0–1 (mixed under the master + ambience-duck gains). */
	gain:     number;
	/** Loop the region (only used for ambience). */
	loop:     boolean;
}

export type SoundKey = 'ambience' | 'cheer' | 'groan' | 'drop';

// `offset` skips the leading silence on files that were trimmed in QuickTime
// (etc) but still carry a fractional delay before the audible content. Edit
// these numbers; no audio editing needed.
const SOUND_CONFIG: Record<SoundKey, SoundConfig> = {
	ambience: { src: 'ambience', offset: 0,   duration: null, gain: 0.28, loop: true  },
	cheer:    { src: 'cheer',    offset: 0,   duration: null, gain: 0.72, loop: false },
	groan:    { src: 'groan',    offset: 0,   duration: null, gain: 0.64, loop: false },
	drop:     { src: 'drop',     offset: 0,   duration: null, gain: 1.00, loop: false },
};


// ── Ambience loop envelope ───────────────────────────────────────────────────
// Ambience is played as a chain of overlapping iterations, each with its own
// fade-in + hold + fade-out envelope. The next iteration starts when the
// previous one begins fading out, so the fades crossfade rather than producing
// a hard loop seam. Per-iteration shape:
//
//   gain  ▁▄█████████████████████▄▁
//         ┃◀──┃                ┃────▶┃
//         ┃ in │     hold       │ out┃
//         ┃ 0.5│                │ 3.0┃
//
// "Fade out 3 seconds earlier" = fade-out begins this many seconds before the
// iteration's natural end (so the new iteration's fade-in fills the gap).
const AMBIENCE_FADE_IN_S  = 0.5;
const AMBIENCE_FADE_OUT_S = 3.0;

const MUTE_STORAGE_KEY = 'pf_sound_muted';

// ── Internal types / state ───────────────────────────────────────────────────

interface SoundRuntime {
	ctx:             AudioContext | null;
	masterGain:      GainNode     | null;
	ambienceGain:    GainNode     | null;   // ambience routes through here (mute / overall vol)
	buffers:         Partial<Record<SoundKey, AudioBuffer>>;
	// Multiple ambience sources can be alive simultaneously during a crossfade
	// — track all of them so stopAmbience() can clean the entire chain.
	ambienceSources: AudioBufferSourceNode[];
	// setInterval ID that keeps scheduling the next iteration. null when no
	// ambience chain is running.
	ambienceTimer:   number | null;
	muted:           boolean;
	unlocked:        boolean;
	urls:            Partial<Record<SoundKey, string>>;
	loaded:          boolean;
	loadingPromise:  Promise<void> | null;
}

const state: SoundRuntime = {
	ctx:             null,
	masterGain:      null,
	ambienceGain:    null,
	buffers:         {},
	ambienceSources: [],
	ambienceTimer:   null,
	muted:           readMutedFromStorage(),
	unlocked:        false,
	urls:            {},
	loaded:          false,
	loadingPromise:  null,
};

// ── Public API ───────────────────────────────────────────────────────────────

export const sounds = {
	/**
	 * Wire up audio file URLs (called once at boot from main.ts using the
	 * URLs the server injected via PepsiXmasConfig.sounds).
	 */
	setUrls( urls: Partial<Record<SoundKey, string>> ): void {
		state.urls = { ...state.urls, ...urls };
	},

	/**
	 * Unlock the AudioContext + start loading buffers. MUST be called from
	 * a user gesture (pointerdown / touchstart / click) on first invocation.
	 * Subsequent calls just resume the context if browser suspended it.
	 */
	async unlock(): Promise<void> {
		// iOS Safari routes pure Web Audio to the *ringer* channel, so the
		// hardware silent switch mutes it and the page never gets promoted to
		// the "playback" audio session. Playing a silent <audio> element on
		// this same gesture flips the session to playback, after which the
		// Web Audio graph below is audible regardless of the silent switch.
		// Must run inside the user gesture → first thing in unlock().
		unlockHtmlAudioSession();

		if ( ! state.ctx ) {
			try {
				const Ctx = window.AudioContext || ( window as unknown as { webkitAudioContext: typeof AudioContext } ).webkitAudioContext;
				if ( ! Ctx ) return;
				state.ctx        = new Ctx();
				state.masterGain = state.ctx.createGain();
				state.masterGain.gain.value = state.muted ? 0 : 1;
				state.masterGain.connect( state.ctx.destination );

				state.ambienceGain = state.ctx.createGain();
				state.ambienceGain.gain.value = SOUND_CONFIG.ambience.gain;
				state.ambienceGain.connect( state.masterGain );
			} catch {
				return;
			}
		}

		// Safari can suspend the context when the tab backgrounds — resume on
		// every unlock call (cheap no-op if already running).
		if ( state.ctx.state === 'suspended' ) {
			try { await state.ctx.resume(); } catch { /* ignore */ }
		}

		state.unlocked = true;

		// Kick off (idempotent) buffer loading.
		void this.loadAll();
	},

	/**
	 * Fetch + decode every configured sound. Idempotent — the in-flight
	 * Promise is cached so concurrent calls share one decode pass.
	 */
	loadAll(): Promise<void> {
		if ( state.loaded )         return Promise.resolve();
		if ( state.loadingPromise ) return state.loadingPromise;
		if ( ! state.ctx )          return Promise.resolve();

		const ctx = state.ctx;
		const keys = Object.keys( SOUND_CONFIG ) as SoundKey[];

		state.loadingPromise = Promise.all( keys.map( async ( key ) => {
			const url = state.urls[ key ];
			if ( ! url ) return;
			try {
				const res = await fetch( url );
				if ( ! res.ok ) return;
				const arr = await res.arrayBuffer();
				const buf = await ctx.decodeAudioData( arr );
				state.buffers[ key ] = buf;
			} catch { /* decode failure — skip silently */ }
		} ) ).then( () => {
			state.loaded = true;
		} );

		return state.loadingPromise;
	},

	/**
	 * Start (or restart) the ambience bed as a chain of crossfading
	 * iterations. Each iteration has its own envelope: a quick fade-in, a
	 * hold at full gain, then a 3 s fade-out that overlaps the next
	 * iteration's fade-in. No-op when muted (the unlock path skips this
	 * call entirely; if invoked directly while muted, the chain still runs
	 * but masterGain is at 0 — inaudible until unmute).
	 */
	async playAmbience(): Promise<void> {
		if ( ! state.ctx || ! state.ambienceGain ) return;
		if ( state.loadingPromise ) await state.loadingPromise;
		const buf = state.buffers.ambience;
		if ( ! buf ) return;

		// Replay = clean restart. Tear down any in-flight iterations + the
		// scheduling interval so we never stack two chains.
		this.stopAmbience();

		const cfg = SOUND_CONFIG.ambience;
		const ctx = state.ctx;
		const D   = cfg.duration ?? Math.max( 0, buf.duration - cfg.offset );

		// If the buffer is too short for the envelope shape (fade-in + fade-out
		// would consume the whole iteration), fall back to a single non-looping
		// play. Avoids inverted envelopes / click artefacts; the audio file
		// should ideally be much longer than fade-in + fade-out combined.
		if ( D <= AMBIENCE_FADE_IN_S + AMBIENCE_FADE_OUT_S ) {
			const src = ctx.createBufferSource();
			src.buffer = buf;
			src.connect( state.ambienceGain );
			src.start( 0, cfg.offset, D );
			state.ambienceSources.push( src );
			return;
		}

		// Schedule one iteration with envelope (fade-in → hold → fade-out)
		// starting at AudioContext time `t`.
		const scheduleIteration = ( t: number ): void => {
			if ( ! state.ctx || ! state.ambienceGain ) return;
			const src = state.ctx.createBufferSource();
			const env = state.ctx.createGain();
			src.buffer = buf;
			src.connect( env );
			env.connect( state.ambienceGain );

			// Envelope shaped on the iteration's own gain node — peaks at 1.0
			// because the SHARED `ambienceGain` (downstream) already applies
			// cfg.gain as the overall ambience-bed volume. Ramping this to
			// cfg.gain too would double-apply it (cfg.gain × cfg.gain).
			//   t                    : gain 0  (start of fade-in)
			//   t + FADE_IN          : gain 1  (full per-iteration level)
			//   t + D - FADE_OUT     : gain 1  (start of fade-out)
			//   t + D                : gain 0  (silence, source ends)
			env.gain.setValueAtTime( 0, t );
			env.gain.linearRampToValueAtTime( 1, t + AMBIENCE_FADE_IN_S );
			env.gain.setValueAtTime( 1, t + D - AMBIENCE_FADE_OUT_S );
			env.gain.linearRampToValueAtTime( 0, t + D );

			src.start( t, cfg.offset, D );
			src.onended = () => {
				try { src.disconnect(); } catch { /* */ }
				try { env.disconnect(); } catch { /* */ }
				const i = state.ambienceSources.indexOf( src );
				if ( i >= 0 ) state.ambienceSources.splice( i, 1 );
			};
			state.ambienceSources.push( src );
		};

		// First iteration fires immediately. Each subsequent iteration starts
		// at `prev.start + (D - FADE_OUT)` — i.e. when the previous one's
		// fade-out begins — so the new fade-in overlaps the old fade-out for
		// a seamless crossfade.
		const stride = D - AMBIENCE_FADE_OUT_S;
		const t0     = ctx.currentTime;
		scheduleIteration( t0 );
		let nextT = t0 + stride;
		state.ambienceTimer = window.setInterval( () => {
			if ( ! state.ctx ) return;
			scheduleIteration( nextT );
			nextT += stride;
		}, stride * 1000 );
	},

	/** Stop the ambience chain — cleans both the JS scheduler and any live sources. */
	stopAmbience(): void {
		if ( state.ambienceTimer != null ) {
			clearInterval( state.ambienceTimer );
			state.ambienceTimer = null;
		}
		for ( const src of state.ambienceSources ) {
			try { src.stop(); } catch { /* already stopped */ }
			try { src.disconnect(); } catch { /* */ }
		}
		state.ambienceSources = [];
	},

	/** One-shot crowd cheer (win). */
	playWin():     void { void this.playOneShot( 'cheer' ); },
	/** One-shot disappointed groan (miss — short, overshoot, or near-miss). */
	playMiss():    void { void this.playOneShot( 'groan' ); },
	/** One-shot thud when a piece touches down on the tree. */
	playDrop():    void { void this.playOneShot( 'drop' ); },

	/**
	 * Generic one-shot trigger — used internally by play{Win,Miss,Drop} and by the live tuner. Ducks the ambience bed while the
	 * one-shot plays.
	 *
	 * Async so a press that arrives before buffers finish loading (very common
	 * on first interaction — the first drop on the very first
	 * pointerdown) waits for decode rather than silently
	 * no-op'ing. Once buffers are loaded this is effectively synchronous —
	 * the await on an already-resolved Promise costs ~microtask time, well
	 * under one animation frame.
	 */
	async playOneShot( key: SoundKey ): Promise<void> {
		if ( ! state.ctx || ! state.masterGain ) return;
		// Wait out any in-flight decode so an early press still produces sound.
		if ( state.loadingPromise ) await state.loadingPromise;
		const buf = state.buffers[ key ];
		if ( ! buf ) return;

		const cfg = SOUND_CONFIG[ key ];

		const src  = state.ctx.createBufferSource();
		const gain = state.ctx.createGain();
		src.buffer    = buf;
		gain.gain.value = cfg.gain;
		src.connect( gain );
		gain.connect( state.masterGain );

		const dur = cfg.duration ?? Math.max( 0, buf.duration - cfg.offset );
		try {
			src.start( 0, cfg.offset, dur );
		} catch {
			return;
		}

		// Auto-cleanup so we're not leaking nodes after each shot.
		// One-shots sit ON TOP of the ambience — both buffers route through
		// masterGain and sum at the destination, so the cheer/groan/drop
		// overlay the crowd bed rather than ducking it.
		src.onended = () => {
			try { src.disconnect(); } catch { /* */ }
			try { gain.disconnect(); } catch { /* */ }
		};
	},

	/** Mute / unmute everything by riding the master gain. Persists to localStorage. */
	setMuted( muted: boolean ): void {
		state.muted = muted;
		try { localStorage.setItem( MUTE_STORAGE_KEY, muted ? '1' : '0' ); } catch { /* */ }
		if ( ! state.ctx || ! state.masterGain ) return;
		const now = state.ctx.currentTime;
		const g   = state.masterGain.gain;
		g.cancelScheduledValues( now );
		g.setValueAtTime( g.value, now );
		g.linearRampToValueAtTime( muted ? 0 : 1, now + 0.15 );
	},

	isMuted(): boolean { return state.muted; },

	/** True once the AudioContext exists and is actively running (not suspended). */
	isRunning(): boolean { return state.ctx?.state === 'running'; },
};

// ── Helpers ──────────────────────────────────────────────────────────────────

function readMutedFromStorage(): boolean {
	try {
		return localStorage.getItem( MUTE_STORAGE_KEY ) === '1';
	} catch {
		return false;
	}
}

// ── iOS silent-switch workaround ─────────────────────────────────────────────
// Pure Web Audio on iOS Safari plays through the ringer channel and is muted by
// the hardware silent switch. Playing a (near-)silent looping HTMLMediaElement
// on a user gesture promotes the page's audio session to "playback", which lets
// the Web Audio output sound even when the phone is on silent. The element is
// created once and kept playing for the lifetime of the page. No-op on
// platforms where it isn't needed — a silent loop is harmless.
let htmlAudioSessionEl: HTMLAudioElement | null = null;

function unlockHtmlAudioSession(): void {
	if ( typeof document === 'undefined' ) return;

	// Already created — just make sure it's still playing (Safari can pause it
	// on backgrounding); cheap no-op when already running.
	if ( htmlAudioSessionEl ) {
		void htmlAudioSessionEl.play().catch( () => { /* ignore */ } );
		return;
	}

	try {
		const el = document.createElement( 'audio' );
		el.setAttribute( 'playsinline', '' );
		el.setAttribute( 'webkit-playsinline', '' );
		el.loop     = true;
		el.preload  = 'auto';
		// Inaudible but non-zero — a literal 0 can make iOS skip the session
		// promotion. The clip itself is digital silence regardless.
		el.volume   = 0.001;
		el.src      = makeSilentWavDataUri();
		htmlAudioSessionEl = el;
		void el.play().catch( () => { /* gesture/policy may still reject — harmless */ } );
	} catch {
		htmlAudioSessionEl = null;
	}
}

// Build a tiny silent 16-bit PCM WAV as a data URI (no network fetch needed).
function makeSilentWavDataUri(): string {
	const sampleRate   = 8000;
	const numSamples   = Math.floor( sampleRate * 0.25 ); // 0.25 s, looped
	const bytesPerSamp = 2;                                // 16-bit
	const dataSize     = numSamples * bytesPerSamp;
	const buffer       = new ArrayBuffer( 44 + dataSize );
	const view         = new DataView( buffer );

	const writeStr = ( off: number, s: string ): void => {
		for ( let i = 0; i < s.length; i++ ) view.setUint8( off + i, s.charCodeAt( i ) );
	};

	writeStr( 0, 'RIFF' );
	view.setUint32( 4, 36 + dataSize, true );
	writeStr( 8, 'WAVE' );
	writeStr( 12, 'fmt ' );
	view.setUint32( 16, 16, true );                       // fmt chunk size
	view.setUint16( 20, 1, true );                        // PCM
	view.setUint16( 22, 1, true );                        // mono
	view.setUint32( 24, sampleRate, true );
	view.setUint32( 28, sampleRate * bytesPerSamp, true ); // byte rate
	view.setUint16( 32, bytesPerSamp, true );             // block align
	view.setUint16( 34, 16, true );                       // bits per sample
	writeStr( 36, 'data' );
	view.setUint32( 40, dataSize, true );
	// Sample region is already all-zero → digital silence.

	let binary = '';
	const bytes = new Uint8Array( buffer );
	for ( let i = 0; i < bytes.length; i++ ) binary += String.fromCharCode( bytes[ i ] );
	return 'data:audio/wav;base64,' + btoa( binary );
}
