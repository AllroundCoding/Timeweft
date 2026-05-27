import { Head } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';

const SIZE = 7; // hex radius in px
const SQRT3 = Math.sqrt(3);

const BIOME = {
    ocean: '#1c3d5a',
    ice: '#dbeafe',
    tundra: '#94a3b8',
    desert: '#e6d29a',
    shrubland: '#b7a66b',
    grassland: '#74a942',
    forest: '#2f7d4f',
    rainforest: '#1c5b3a',
};
const LAKE = '#2a6f97';
const RIVER = '#4aa3cf';
const TIER_R = { hamlet: 0.45, village: 0.7, town: 1.0, city: 1.4 };

function hexColor(h) {
    if (!h.land) {
        return BIOME.ocean;
    }
    if (h.lake) {
        return LAKE;
    }
    if (h.river) {
        return RIVER;
    }
    return BIOME[h.biome] ?? '#777';
}

// Pointy-top axial (q, r) → pixel centre.
function center(q, r) {
    return { cx: SIZE * SQRT3 * (q + r / 2), cy: SIZE * 1.5 * r };
}

function hexPoints(cx, cy) {
    const pts = [];
    for (let i = 0; i < 6; i += 1) {
        const a = (Math.PI / 180) * (60 * i - 30);
        pts.push(`${(cx + SIZE * Math.cos(a)).toFixed(2)},${(cy + SIZE * Math.sin(a)).toFixed(2)}`);
    }
    return pts.join(' ');
}

export default function Map({ run, hexes, settlements }) {
    const [view, setView] = useState({ scale: 1, tx: 60, ty: 60 });
    const drag = useRef(null);

    // The hex polygons never change with pan/zoom, so build them once — only the <g> transform moves.
    const hexEls = useMemo(
        () =>
            hexes.map((h) => {
                const { cx, cy } = center(h.q, h.r);
                return (
                    <polygon key={`${h.q},${h.r}`} points={hexPoints(cx, cy)} fill={hexColor(h)} stroke="#0c0a09" strokeWidth="0.25">
                        <title>{`${h.biome}${h.river ? ' · river' : ''}${h.lake ? ' · lake' : ''} (${h.q},${h.r})`}</title>
                    </polygon>
                );
            }),
        [hexes],
    );

    const settleEls = useMemo(
        () =>
            settlements.map((s, i) => {
                const { cx, cy } = center(s.q, s.r);
                return (
                    <circle key={`s-${i}`} cx={cx} cy={cy} r={SIZE * (TIER_R[s.tier] ?? 0.6)} fill="#fde68a" stroke="#78350f" strokeWidth="0.8">
                        <title>{s.tier}</title>
                    </circle>
                );
            }),
        [settlements],
    );

    const onWheel = (e) => {
        const factor = e.deltaY < 0 ? 1.15 : 1 / 1.15;
        setView((v) => ({ ...v, scale: Math.max(0.25, Math.min(8, v.scale * factor)) }));
    };
    const onDown = (e) => {
        drag.current = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty };
    };
    const onMove = (e) => {
        if (!drag.current) {
            return;
        }
        const d = drag.current;
        setView((v) => ({ ...v, tx: d.tx + (e.clientX - d.x), ty: d.ty + (e.clientY - d.y) }));
    };
    const onUp = () => {
        drag.current = null;
    };

    return (
        <>
            <Head title="World map" />
            <div className="min-h-full p-6">
                <header className="mb-4">
                    <h1 className="text-lg font-semibold text-stone-100">
                        Timeweft <span className="text-stone-500">— world of seed “{run.seed}”</span>
                    </h1>
                    <p className="mt-1 text-sm text-stone-400">
                        {run.cols}×{run.rows} hexes · {settlements.length} settlements · scroll to zoom, drag to pan
                    </p>
                    <ul className="mt-3 flex flex-wrap gap-x-3 gap-y-1 text-xs text-stone-400">
                        {Object.entries(BIOME).map(([name, c]) => (
                            <li key={name} className="flex items-center gap-1.5">
                                <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: c }} />
                                {name}
                            </li>
                        ))}
                        <li className="flex items-center gap-1.5">
                            <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: RIVER }} />
                            river
                        </li>
                        <li className="flex items-center gap-1.5">
                            <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: '#fde68a' }} />
                            settlement
                        </li>
                    </ul>
                </header>

                <div className="overflow-hidden rounded-lg border border-stone-800 bg-stone-950" style={{ height: '72vh' }}>
                    <svg
                        className="block h-full w-full cursor-grab active:cursor-grabbing"
                        onWheel={onWheel}
                        onMouseDown={onDown}
                        onMouseMove={onMove}
                        onMouseUp={onUp}
                        onMouseLeave={onUp}
                    >
                        <g transform={`translate(${view.tx} ${view.ty}) scale(${view.scale})`}>
                            {hexEls}
                            {settleEls}
                        </g>
                    </svg>
                </div>
            </div>
        </>
    );
}
