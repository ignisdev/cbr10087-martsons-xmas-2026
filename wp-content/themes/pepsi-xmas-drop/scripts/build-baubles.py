#!/usr/bin/env python3
"""
Build the web bauble assets from the source PNGs.

The sources are crops from one master composition, so their pixel sizes are
true to each other: every sprite is scaled by the same factor and the game
sizes each piece from its source width.

For each tree piece in src/images/baubles/ this writes:
  - src/images/baubles/web/<type>.webp   the sprite the game draws
  - an entry in src/renderer/bauble-outlines.ts: the piece's silhouette traced
    from its alpha channel, used for the dashed placeholder slot.

Re-run whenever a source PNG changes:  python3 scripts/build-baubles.py
Requires Pillow only.
"""

from collections import deque
from pathlib import Path

from PIL import Image, ImageFilter

THEME   = Path(__file__).resolve().parent.parent
SRC     = THEME / 'src/images/baubles'
WEB     = SRC / 'web'
OUT_TS  = THEME / 'src/renderer/bauble-outlines.ts'

# type → source file: the 9 drop pieces (types served by drop-engine.php)
# plus the 4 decorative light strands (DECOR in src/renderer/baubles.ts)
PIECES = {
    'cracker':   '1. Cracker.png',
    'present':   '4. Present.png',
    'bauble':    '2. Bauble.png',
    'mouth':     '7. Mouth.png',
    'heart':     '8. Heart.png',
    'snowman':   '6. Snowman.png',
    'snowglobe': '10. Snowglobe.png',
    'gift':      '11. Present.png',
    'star':      '13. StarTopper.png',
    'lights1':   '3. Lights.png',
    'lights2':   '5. Lights2.png',
    'lights3':   '12 Lights3.png',
    'lights4':   '9. LightsTop.png',
}

SPRITE_SCALE = 0.3   # shipped sprite size ÷ source size (same for every piece)
MASK_MAX    = 400    # longest side of the tracing mask, px
ALPHA_MIN   = 230    # alpha above this counts as "inside" — the solid body, not the baked soft glow round it
SIMPLIFY_PX = 1.1    # Douglas-Peucker tolerance on the tracing mask, px

# Clockwise on screen (y down), starting west.
DIRS = [(-1, 0), (-1, -1), (0, -1), (1, -1), (1, 0), (1, 1), (0, 1), (-1, 1)]
DIR_INDEX = {d: i for i, d in enumerate(DIRS)}


def build_mask(alpha):
    """Binary silhouette with thin hanging wires removed, true to the sprite's edge."""
    scale = MASK_MAX / max(alpha.size)
    size  = (round(alpha.width * scale), round(alpha.height * scale))
    mask  = alpha.resize(size, Image.LANCZOS).point(lambda a: 255 if a > ALPHA_MIN else 0)
    # Pad so the margin and the tracer never touch the image edge.
    pad    = 6
    padded = Image.new('L', (size[0] + 2 * pad, size[1] + 2 * pad), 0)
    padded.paste(mask, (pad, pad))
    # Opening (erode then dilate by the same amount) drops hair-thin wires
    # without growing the silhouette, so the slot outline matches the sprite.
    padded = padded.filter(ImageFilter.MinFilter(5)).filter(ImageFilter.MaxFilter(5))
    return padded, pad, scale


def largest_component(mask):
    w, h = mask.size
    px   = mask.load()
    seen = [[False] * w for _ in range(h)]
    best = []
    for y0 in range(h):
        for x0 in range(w):
            if px[x0, y0] == 0 or seen[y0][x0]:
                continue
            comp  = []
            queue = deque([(x0, y0)])
            seen[y0][x0] = True
            while queue:
                x, y = queue.popleft()
                comp.append((x, y))
                for dx, dy in DIRS:
                    nx, ny = x + dx, y + dy
                    if 0 <= nx < w and 0 <= ny < h and px[nx, ny] and not seen[ny][nx]:
                        seen[ny][nx] = True
                        queue.append((nx, ny))
            if len(comp) > len(best):
                best = comp
    return set(best)


def trace_boundary(inside):
    """Moore-neighbour trace of the outer boundary, clockwise on screen."""
    start = min(inside, key=lambda p: (p[1], p[0]))   # topmost, then leftmost
    cur, back = start, 0                               # west of start is empty
    path = [start]
    for _ in range(200000):
        for i in range(1, 9):
            d = (back + i) % 8
            nxt = (cur[0] + DIRS[d][0], cur[1] + DIRS[d][1])
            if nxt in inside:
                prev = (cur[0] + DIRS[(d - 1) % 8][0], cur[1] + DIRS[(d - 1) % 8][1])
                back = DIR_INDEX[(prev[0] - nxt[0], prev[1] - nxt[1])]
                cur  = nxt
                break
        else:
            return path   # isolated pixel
        if cur == start and back == 0:
            return path
        path.append(cur)
    raise RuntimeError('boundary trace did not close')


def _dp(points, eps):
    if len(points) < 3:
        return points
    (ax, ay), (bx, by) = points[0], points[-1]
    dx, dy = bx - ax, by - ay
    norm   = (dx * dx + dy * dy) ** 0.5 or 1.0
    worst, idx = 0.0, 0
    for i in range(1, len(points) - 1):
        px, py = points[i]
        dist = abs(dy * (px - ax) - dx * (py - ay)) / norm
        if dist > worst:
            worst, idx = dist, i
    if worst <= eps:
        return [points[0], points[-1]]
    return _dp(points[: idx + 1], eps)[:-1] + _dp(points[idx:], eps)


def simplify_closed(path, eps):
    far = max(range(len(path)), key=lambda i: (path[i][0] - path[0][0]) ** 2 + (path[i][1] - path[0][1]) ** 2)
    a = _dp(path[: far + 1], eps)
    b = _dp(path[far:] + [path[0]], eps)
    return a[:-1] + b[:-1]


def main():
    import sys
    sys.setrecursionlimit(10000)
    WEB.mkdir(exist_ok=True)
    entries = []
    for kind, filename in PIECES.items():
        img = Image.open(SRC / filename).convert('RGBA')

        sprite = img.resize((round(img.width * SPRITE_SCALE), round(img.height * SPRITE_SCALE)), Image.LANCZOS)
        sprite.save(WEB / f'{kind}.webp', 'WEBP', quality=88, method=6)

        mask, pad, scale = build_mask(img.getchannel('A'))
        path   = trace_boundary(largest_component(mask))
        points = simplify_closed(path, SIMPLIFY_PX)

        # Normalise to units of image width, origin at the image centre, y up.
        flat = []
        for x, y in points:
            sx = (x - pad + 0.5) / scale
            sy = (y - pad + 0.5) / scale
            flat.append(round(sx / img.width - 0.5, 4))
            flat.append(round((img.height / 2 - sy) / img.width, 4))
        entries.append((kind, img.width, round(img.height / img.width, 4), flat))
        size_kb = (WEB / f'{kind}.webp').stat().st_size // 1024
        print(f'{kind:10s} {sprite.width}x{sprite.height}  {size_kb:4d} KB  {len(points):3d} outline points')

    lines = [
        '// GENERATED by scripts/build-baubles.py — do not edit by hand.',
        '// Each outline is the piece\'s silhouette traced from its PNG alpha channel:',
        '// flat [x, y, x, y, …] in units of the image width, origin at the image',
        '// centre, y up. `aspect` is image height ÷ width; `width` is the source',
        '// image width in px (the sources share one scale, so it sizes the piece).',
        '',
        'export interface BaubleOutline {',
        '\twidth:  number;',
        '\taspect: number;',
        '\tpoints: number[];',
        '}',
        '',
        'export const BAUBLE_OUTLINES: Record<string, BaubleOutline> = {',
    ]
    for kind, width, aspect, flat in entries:
        lines.append(f'\t{kind}: {{')
        lines.append(f'\t\twidth:  {width},')
        lines.append(f'\t\taspect: {aspect},')
        lines.append('\t\tpoints: [ ' + ', '.join(str(v) for v in flat) + ' ],')
        lines.append('\t},')
    lines.append('};')
    OUT_TS.write_text('\n'.join(lines) + '\n')
    print(f'wrote {OUT_TS.relative_to(THEME)}')


if __name__ == '__main__':
    main()
