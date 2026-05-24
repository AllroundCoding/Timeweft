import { Deferred, Head } from '@inertiajs/react';

const PX_PER_YEAR = 84;
const ROW_H = 16;
const M = { top: 30, right: 28, bottom: 10, left: 140 };

// A few beats read better with an intuitive colour; the rest get a stable colour hashed from the type.
const SEMANTIC = {
    birth: '#34d399',
    death: '#fb7185',
    pairing: '#f472b6',
    migration: '#22d3ee',
};
const PALETTE = ['#a78bfa', '#60a5fa', '#f59e0b', '#4ade80', '#f87171', '#2dd4bf', '#c084fc', '#fb923c', '#38bdf8', '#facc15'];

function colorFor(type) {
    if (SEMANTIC[type]) {
        return SEMANTIC[type];
    }
    let h = 0;
    for (let i = 0; i < type.length; i += 1) {
        h = (h * 31 + type.charCodeAt(i)) >>> 0;
    }
    return PALETTE[h % PALETTE.length];
}

export default function Timeline({ run, axis, lives, world, milestones, counts, narrative }) {
    const { ticksPerYear, startTick, endTick, startYear } = axis;
    const spanYears = Math.max(1, Math.round((endTick - startTick) / ticksPerYear));
    const plotW = spanYears * PX_PER_YEAR;
    const plotH = Math.max(lives.length, 1) * ROW_H;
    const width = M.left + plotW + M.right;
    const height = M.top + plotH + M.bottom;

    // tick → x (px), clamped to the simulated window so pre-sim founder births sit at the left edge.
    const x = (tick) => {
        const t = Math.min(Math.max(tick, startTick), endTick);
        return M.left + ((t - startTick) / ticksPerYear) * PX_PER_YEAR;
    };

    // Milestones render as their own pins, so keep their chronicle echoes out of the world lane.
    const worldEvents = world.filter((e) => !e.type.startsWith('milestone'));
    const types = [...new Set([...worldEvents.map((e) => e.type), ...lives.flatMap((l) => l.events.map((e) => e.type))])].sort();
    const years = Array.from({ length: spanYears + 1 }, (_, i) => i);

    return (
        <>
            <Head title="Timeline" />
            <div className="min-h-full p-6">
                <header className="mb-4">
                    <h1 className="text-lg font-semibold text-stone-100">
                        Timeweft <span className="text-stone-500">— chronicle of seed “{run.seed}”</span>
                    </h1>
                    <p className="mt-1 text-sm text-stone-400">
                        {run.years} years · {counts.total} souls ({run.population} founders · {counts.born} born · {counts.died} died ·{' '}
                        {counts.living} living)
                    </p>
                    <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-400">
                        {types.map((t) => (
                            <li key={t} className="flex items-center gap-1.5">
                                <span className="inline-block h-2.5 w-2.5 rounded-sm" style={{ backgroundColor: colorFor(t) }} />
                                {t}
                            </li>
                        ))}
                    </ul>
                </header>

                <section className="mb-5 rounded-lg border border-stone-800 bg-stone-900/40 p-4">
                    <h2 className="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">The chronicle, retold</h2>
                    <Deferred data="narrative" fallback={<p className="text-sm italic text-stone-500">Narrating the saga…</p>}>
                        <div className="max-w-3xl space-y-3 text-sm leading-relaxed text-stone-300">
                            {(narrative ?? '').split('\n\n').map((para, i) => (
                                <p key={i}>{para}</p>
                            ))}
                        </div>
                    </Deferred>
                </section>

                <div className="overflow-x-auto rounded-lg border border-stone-800 bg-stone-900/40">
                    <svg width={width} height={height} className="block font-sans">
                        {years.map((y) => {
                            const gx = M.left + y * PX_PER_YEAR;
                            return (
                                <g key={`yr-${y}`}>
                                    <line x1={gx} y1={M.top} x2={gx} y2={M.top + plotH} stroke="#292524" strokeWidth="1" />
                                    <text x={gx} y={M.top - 10} fontSize="10" fill="#78716c" textAnchor="middle">
                                        {startYear + y}
                                    </text>
                                </g>
                            );
                        })}

                        {worldEvents.map((e, i) => {
                            const ex = x(e.tick);
                            const c = colorFor(e.type);
                            return (
                                <g key={`w-${i}`}>
                                    <line x1={ex} y1={M.top} x2={ex} y2={M.top + plotH} stroke={c} strokeOpacity="0.12" strokeWidth="1" />
                                    <rect x={ex - 2} y={M.top - 6} width="4" height="4" fill={c}>
                                        <title>{e.text}</title>
                                    </rect>
                                </g>
                            );
                        })}

                        {milestones.map((m, i) => {
                            const at = m.achievedTick ?? m.deadlineTick;
                            const mx = x(at);
                            const c = m.achieved ? '#fbbf24' : m.lapsed ? '#57534e' : '#a8a29e';
                            const status = m.achieved ? (m.forced ? 'forced' : 'achieved') : m.lapsed ? 'lapsed' : 'unmet';
                            return (
                                <polygon key={`m-${i}`} points={`${mx},${M.top - 2} ${mx - 4},${M.top - 9} ${mx + 4},${M.top - 9}`} fill={c} stroke="#1c1917" strokeWidth="0.5">
                                    <title>{`${m.name} — ${status}`}</title>
                                </polygon>
                            );
                        })}

                        {lives.map((life, i) => {
                            const y = M.top + i * ROW_H;
                            const cy = y + ROW_H / 2;
                            const x1 = x(life.birthTick);
                            const x2 = x(life.deathTick ?? endTick);
                            return (
                                <g key={life.id}>
                                    <text x={M.left - 8} y={cy + 3} fontSize="10" textAnchor="end" fill={life.alive ? '#d6d3d1' : '#78716c'}>
                                        {life.name}
                                    </text>
                                    <rect
                                        x={x1}
                                        y={y + 3}
                                        width={Math.max(x2 - x1, 1)}
                                        height={ROW_H - 6}
                                        rx="2"
                                        fill={life.alive ? '#1f7a5a' : '#3f3f46'}
                                        fillOpacity={life.alive ? 0.85 : 0.55}
                                    >
                                        <title>{`${life.name} · ${life.sex}${life.profession ? ` · ${life.profession}` : ''}${life.alive ? '' : ' · died'}`}</title>
                                    </rect>
                                    {life.events.map((e, j) => (
                                        <circle key={j} cx={x(e.tick)} cy={cy} r="2.5" fill={colorFor(e.type)} stroke="#0c0a09" strokeWidth="0.5">
                                            <title>{e.text}</title>
                                        </circle>
                                    ))}
                                </g>
                            );
                        })}
                    </svg>
                </div>
            </div>
        </>
    );
}
