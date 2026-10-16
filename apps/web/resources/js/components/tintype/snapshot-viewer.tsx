import { useMemo, useRef, useState } from 'react';
import { cn } from '@/lib/utils';
import type { Rect, SnapshotProps } from '@/types';

export type ViewMode = 'diff' | 'side' | 'swipe' | 'baseline' | 'new';

export const VIEW_MODES: { key: ViewMode; label: string; hotkey: string }[] = [
    { key: 'diff', label: 'Diff', hotkey: '1' },
    { key: 'side', label: 'Side by side', hotkey: '2' },
    { key: 'swipe', label: 'Swipe', hotkey: '3' },
    { key: 'baseline', label: 'Baseline', hotkey: '4' },
    { key: 'new', label: 'New', hotkey: '5' },
];

interface Props {
    snapshot: SnapshotProps;
    mode: ViewMode;
    zoom: number | 'fit';
    spotlight: boolean;
    showDiff: boolean;
}

function Checkerboard({ children, className }: { children: React.ReactNode; className?: string }) {
    return (
        <div
            className={cn('relative inline-block bg-[length:16px_16px] shadow-sm ring-1 ring-border', className)}
            style={{
                backgroundImage:
                    'linear-gradient(45deg,var(--muted) 25%,transparent 25%),linear-gradient(-45deg,var(--muted) 25%,transparent 25%),linear-gradient(45deg,transparent 75%,var(--muted) 75%),linear-gradient(-45deg,transparent 75%,var(--muted) 75%)',
                backgroundPosition: '0 0,0 8px,8px -8px,-8px 0',
            }}
        >
            {children}
        </div>
    );
}

/** Dims everything except the changed regions. Coordinates are image pixels. */
function Spotlight({ regions, width, height }: { regions: Rect[]; width: number; height: number }) {
    const id = useMemo(() => `spot-${Math.random().toString(36).slice(2)}`, []);
    if (!regions.length) return null;

    return (
        <svg className="pointer-events-none absolute inset-0 size-full" viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none">
            <defs>
                <mask id={id}>
                    <rect width={width} height={height} fill="white" />
                    {regions.map((r, i) => (
                        <rect key={i} x={r.x - 4} y={r.y - 4} width={r.width + 8} height={r.height + 8} rx={4} fill="black" />
                    ))}
                </mask>
            </defs>
            <rect width={width} height={height} fill="rgba(0,0,0,0.55)" mask={`url(#${id})`} />
            {regions.map((r, i) => (
                <rect key={i} x={r.x - 4} y={r.y - 4} width={r.width + 8} height={r.height + 8} rx={4} fill="none" stroke="#f59e0b" strokeWidth={2} vectorEffect="non-scaling-stroke" />
            ))}
        </svg>
    );
}

function Img({ src, width, label, zoom, testId }: { src: string | null; width: number | null; label: string; zoom: number | 'fit'; testId?: string }) {
    if (!src) {
        return <div className="flex h-40 w-64 items-center justify-center rounded border border-dashed text-sm text-muted-foreground">No {label.toLowerCase()} image</div>;
    }

    return (
        <img
            src={src}
            alt={label}
            data-testid={testId}
            draggable={false}
            className={cn('block select-none', zoom === 'fit' && 'h-auto max-w-full')}
            style={zoom === 'fit' ? undefined : { width: (width ?? 0) * zoom, maxWidth: 'none' }}
        />
    );
}

export function SnapshotViewer({ snapshot: s, mode, zoom, spotlight, showDiff }: Props) {
    const [swipe, setSwipe] = useState(50);
    const swipeRef = useRef<HTMLDivElement>(null);
    const baseline = s.baseline;
    const hasBaseline = Boolean(baseline?.image);
    const effective: ViewMode = !hasBaseline && mode !== 'new' ? 'new' : mode;
    const w = s.width ?? 0;
    const h = s.height ?? 0;

    if (s.status === 'error' && !s.image) {
        return <pre className="max-w-full overflow-auto rounded-lg bg-red-500/10 p-4 text-sm whitespace-pre-wrap text-red-700 dark:text-red-300">{s.error}</pre>;
    }

    const highlight = showDiff && Boolean(s.diff) && (effective === 'diff' || effective === 'side');
    const current = (
        <Checkerboard>
            <div className={cn(highlight && 'opacity-30')}>
                <Img src={s.image} width={s.width} label="New" zoom={zoom} testId="snapshot-new" />
            </div>
            {highlight && s.diff && (
                <img src={s.diff} alt="Changed pixels" data-testid="snapshot-diff" className="pointer-events-none absolute top-0 left-0 size-full" />
            )}
            {spotlight && <Spotlight regions={s.diffRegions} width={w} height={h} />}
        </Checkerboard>
    );

    switch (effective) {
        case 'new':
            return current;
        case 'baseline':
            return (
                <Checkerboard>
                    <Img src={baseline?.image ?? null} width={baseline?.width ?? null} label="Baseline" zoom={zoom} testId="snapshot-baseline" />
                </Checkerboard>
            );
        case 'side':
            return (
                <div className="flex flex-wrap items-start gap-4">
                    <figure className="space-y-1">
                        <figcaption className="text-xs text-muted-foreground">Baseline</figcaption>
                        <Checkerboard>
                            <Img src={baseline?.image ?? null} width={baseline?.width ?? null} label="Baseline" zoom={zoom} />
                        </Checkerboard>
                    </figure>
                    <figure className="space-y-1">
                        <figcaption className="text-xs text-muted-foreground">New</figcaption>
                        {current}
                    </figure>
                </div>
            );
        case 'swipe':
            return (
                <div
                    ref={swipeRef}
                    className="relative inline-block cursor-ew-resize"
                    onPointerMove={(e) => {
                        if (e.buttons !== 1 || !swipeRef.current) return;
                        const r = swipeRef.current.getBoundingClientRect();
                        setSwipe(Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100)));
                    }}
                    onPointerDown={(e) => (e.target as HTMLElement).setPointerCapture?.(e.pointerId)}
                >
                    <Checkerboard>
                        <Img src={s.image} width={s.width} label="New" zoom={zoom} />
                        <div className="absolute inset-0 overflow-hidden" style={{ clipPath: `inset(0 ${100 - swipe}% 0 0)` }}>
                            <Img src={baseline?.image ?? null} width={baseline?.width ?? null} label="Baseline" zoom={zoom} />
                        </div>
                    </Checkerboard>
                    <div className="pointer-events-none absolute inset-y-0 w-0.5 bg-amber-500" style={{ left: `${swipe}%` }} />
                    <input
                        type="range"
                        min={0}
                        max={100}
                        value={swipe}
                        onChange={(e) => setSwipe(Number(e.target.value))}
                        aria-label="Swipe between baseline and new"
                        className="mt-2 w-full"
                    />
                </div>
            );
        default:
            return current;
    }
}
