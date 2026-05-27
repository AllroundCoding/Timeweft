import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';

const CELL = 1; // px per terrain cell at scale 1
const MIN_SCALE = 0.1;
const MAX_SCALE = 40;

// Hex tiles crossfade in as the camera zooms, measured in on-screen pixels per terrain cell — so the
// threshold means the same "how far am I zoomed in" regardless of world size.
const HEX_START = 14; // tiles begin to appear
const HEX_FULL = 26; // fully tiled
const HEX_GAP = 0.9; // tile inset, so terrain shows between tiles as a subtle border

// Continuous-terrain palette, keyed by the one-char raster the controller emits (shared by both layers).
const COLOR = {
    O: '#1c3d5a', // ocean
    I: '#dbeafe', // ice
    T: '#94a3b8', // tundra
    D: '#e6d29a', // desert
    S: '#b7a66b', // shrubland
    G: '#74a942', // grassland
    F: '#2f7d4f', // forest
    J: '#1c5b3a', // rainforest
    '~': '#4aa3cf', // river
    L: '#2a6f97', // lake
};

const LEGEND = [
    ['G', 'grassland'],
    ['F', 'forest'],
    ['J', 'rainforest'],
    ['S', 'shrubland'],
    ['D', 'desert'],
    ['T', 'tundra'],
    ['I', 'ice'],
    ['~', 'river'],
    ['L', 'lake'],
    ['O', 'ocean'],
];

const TIER_PX = { hamlet: 5, village: 7, town: 10, city: 14 };
const FALLBACK = [119, 119, 119];

function clamp(n, lo, hi) {
    return Math.max(lo, Math.min(hi, n));
}

function toRgb(hex) {
    return [parseInt(hex.slice(1, 3), 16), parseInt(hex.slice(3, 5), 16), parseInt(hex.slice(5, 7), 16)];
}
const RGB = Object.fromEntries(Object.entries(COLOR).map(([k, v]) => [k, toRgb(v)]));

// Pointy-top hexagon path in display-pixel space, centred at (cx, cy) with the given half-extents.
function hexPath(cx, cy, hw, hh) {
    return (
        `M${cx},${cy - hh}` +
        `L${cx + hw},${cy - hh / 2}` +
        `L${cx + hw},${cy + hh / 2}` +
        `L${cx},${cy + hh}` +
        `L${cx - hw},${cy + hh / 2}` +
        `L${cx - hw},${cy - hh / 2}Z`
    );
}

export default function Map({ run, width, height, rows, hex, settlements }) {
    const viewportRef = useRef(null);
    const canvasRef = useRef(null);
    const drag = useRef(null);
    const [view, setView] = useState({ scale: 1, tx: 0, ty: 0 });

    const dispW = width * CELL;
    const dispH = height * CELL;

    // Paint the raster at one device pixel per cell; CSS upscales it crisp (pixelated), so the heavy
    // per-cell loop runs once per world, not per frame.
    useEffect(() => {
        const ctx = canvasRef.current?.getContext('2d');
        if (!ctx) {
            return;
        }
        const img = ctx.createImageData(width, height);
        for (let y = 0; y < height; y += 1) {
            const line = rows[y] ?? '';
            for (let x = 0; x < width; x += 1) {
                const [r, g, b] = RGB[line[x]] ?? FALLBACK;
                const o = (y * width + x) * 4;
                img.data[o] = r;
                img.data[o + 1] = g;
                img.data[o + 2] = b;
                img.data[o + 3] = 255;
            }
        }
        ctx.putImageData(img, 0, 0);
    }, [rows, width, height]);

    // Fit the whole world into the viewport and centre it on first paint (and if the world changes size).
    useLayoutEffect(() => {
        const vp = viewportRef.current;
        if (!vp) {
            return;
        }
        const scale = Math.min(vp.clientWidth / dispW, vp.clientHeight / dispH);
        setView({ scale, tx: (vp.clientWidth - dispW * scale) / 2, ty: (vp.clientHeight - dispH * scale) / 2 });
    }, [dispW, dispH]);

    // Zoom anchored on the cursor — the world point under the pointer stays put. A non-passive listener so
    // we can stop the page from scrolling while zooming.
    useEffect(() => {
        const vp = viewportRef.current;
        if (!vp) {
            return;
        }
        const onWheel = (e) => {
            e.preventDefault();
            const rect = vp.getBoundingClientRect();
            const mx = e.clientX - rect.left;
            const my = e.clientY - rect.top;
            setView((v) => {
                const scale = clamp(v.scale * (e.deltaY < 0 ? 1.15 : 1 / 1.15), MIN_SCALE, MAX_SCALE);
                const wx = (mx - v.tx) / v.scale;
                const wy = (my - v.ty) / v.scale;
                return { scale, tx: mx - wx * scale, ty: my - wy * scale };
            });
        };
        vp.addEventListener('wheel', onWheel, { passive: false });
        return () => vp.removeEventListener('wheel', onWheel);
    }, []);

    const onDown = useCallback(
        (e) => {
            drag.current = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty };
        },
        [view.tx, view.ty],
    );
    const onMove = useCallback((e) => {
        const d = drag.current;
        if (!d) {
            return;
        }
        setView((v) => ({ ...v, tx: d.tx + (e.clientX - d.x), ty: d.ty + (e.clientY - d.y) }));
    }, []);
    const onUp = useCallback(() => {
        drag.current = null;
    }, []);

    // The hex tiles are laid out once in display-pixel space (an offset, odd-row-shifted grid filling the
    // terrain rect); the wrapper's transform handles pan/zoom, so this never recomputes on camera moves.
    const hexEls = useMemo(() => {
        const { cols, rows: hRows, cells } = hex;
        const stepX = dispW / (cols + 0.5);
        const stepY = dispH / (hRows + 1 / 3);
        const hh = stepY / 1.5;
        const hw = stepX / 2;
        const els = [];
        for (let r = 0; r < hRows; r += 1) {
            const line = cells[r] ?? '';
            const cy = hh + r * stepY;
            for (let q = 0; q < cols; q += 1) {
                const ch = line[q];
                const cx = stepX * (0.5 + q + 0.5 * (r & 1));
                els.push(<path key={`${q},${r}`} d={hexPath(cx, cy, hw * HEX_GAP, hh * HEX_GAP)} fill={COLOR[ch] ?? '#777'} />);
            }
        }
        return els;
    }, [hex, dispW, dispH]);

    // Crossfade the two layers by how far we are zoomed in (pixels per terrain cell).
    const hexT = clamp((view.scale * CELL - HEX_START) / (HEX_FULL - HEX_START), 0, 1);
    const terrainOpacity = 1 - 0.7 * hexT;

    // Settlements ride in an un-scaled overlay so their markers stay a constant screen size at any zoom.
    const settleEls = useMemo(
        () =>
            settlements.map((s, i) => {
                const size = TIER_PX[s.tier] ?? 6;
                return (
                    <div
                        key={`s-${i}`}
                        title={s.tier}
                        className="absolute rounded-full border border-amber-900 bg-amber-300 shadow"
                        style={{
                            left: `${view.tx + s.nx * dispW * view.scale}px`,
                            top: `${view.ty + s.ny * dispH * view.scale}px`,
                            width: `${size}px`,
                            height: `${size}px`,
                            transform: 'translate(-50%, -50%)',
                        }}
                    />
                );
            }),
        [settlements, view, dispW, dispH],
    );

    return (
        <>
            <Head title="World map" />
            <div className="min-h-full p-6">
                <header className="mb-4">
                    <h1 className="text-lg font-semibold text-stone-100">
                        Timeweft <span className="text-stone-500">— world of seed “{run.seed}”</span>
                    </h1>
                    <p className="mt-1 text-sm text-stone-400">
                        {width}×{height} terrain · {settlements.length} settlements · scroll to zoom (tiles appear as you zoom in), drag to
                        pan
                    </p>
                    <ul className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-stone-400">
                        {LEGEND.map(([ch, name]) => (
                            <li key={name} className="flex items-center gap-1.5">
                                <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: COLOR[ch] }} />
                                {name}
                            </li>
                        ))}
                        <li className="flex items-center gap-1.5">
                            <span className="inline-block h-2.5 w-2.5 rounded-full border border-amber-900 bg-amber-300" />
                            settlement
                        </li>
                    </ul>
                </header>

                <div
                    ref={viewportRef}
                    className="relative cursor-grab overflow-hidden rounded-lg border border-stone-800 bg-stone-950 active:cursor-grabbing"
                    style={{ height: '72vh' }}
                    onMouseDown={onDown}
                    onMouseMove={onMove}
                    onMouseUp={onUp}
                    onMouseLeave={onUp}
                >
                    <div
                        className="absolute left-0 top-0 origin-top-left"
                        style={{ transform: `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})` }}
                    >
                        <canvas
                            ref={canvasRef}
                            width={width}
                            height={height}
                            style={{ width: `${dispW}px`, height: `${dispH}px`, imageRendering: 'pixelated', opacity: terrainOpacity }}
                        />
                        {hexT > 0 && (
                            <svg
                                className="pointer-events-none absolute left-0 top-0"
                                width={dispW}
                                height={dispH}
                                viewBox={`0 0 ${dispW} ${dispH}`}
                                preserveAspectRatio="none"
                                style={{ opacity: hexT }}
                            >
                                {hexEls}
                            </svg>
                        )}
                    </div>
                    <div className="pointer-events-none absolute inset-0">{settleEls}</div>
                </div>
            </div>
        </>
    );
}
