import { Head, Link, router } from '@inertiajs/react';
import { Music, RotateCw, X } from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { useElapsed } from '@/hooks/use-elapsed';
import { useFmLive } from '@/hooks/use-fm-live';
import { fmtTime } from '@/lib/format';
import { getRecents } from '@/lib/recents';
import type { Recent } from '@/lib/recents';
import type { NowPlayingData, Station } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    station: Station;
}

// Minimal Wake Lock typing (not in the DOM lib everywhere yet).
interface WakeLockSentinelLike {
    release: () => Promise<void>;
    addEventListener?: (type: 'release', listener: () => void) => void;
}
interface WakeLockNavigator {
    wakeLock?: { request: (type: 'screen') => Promise<WakeLockSentinelLike> };
}

const RING_STROKE = 6;
const RING_MAX_R = 130;

/**
 * Driving Mode keeps its own rules: near-black canvas, h-20 thumb targets, no
 * grid. It is a glanceable surface for a phone clamped to a dashboard, not a
 * dashboard in the UI sense, so the card language deliberately stops here.
 */
export default function Drive({ nowPlaying, station }: Props) {
    const { nowPlaying: live, piStatus, commuteLabel } = useFmLive();
    const data = live === undefined ? nowPlaying : live;
    const isLive = piStatus?.status === 'live';

    const { elapsed, duration, progress } = useElapsed(
        data?.started_at,
        data?.song?.duration_seconds,
    );
    const [last, setLast] = useState<Recent | null>(
        () => getRecents()[0] ?? null,
    );
    const [busy, setBusy] = useState(false);
    // null = still trying, true = screen held awake, false = denied/unsupported/released —
    // Driving Mode's whole promise is "screen stays on", so a silent failure here would
    // undercut it right when the driver trusts it least. Unsupported browsers are known
    // synchronously (no effect needed) via this lazy initializer.
    const [wakeLockOk, setWakeLockOk] = useState<boolean | null>(() =>
        (navigator as Navigator & WakeLockNavigator).wakeLock ? null : false,
    );

    // The ring was a hardcoded r=130, so its 284px box overflowed the viewport
    // on anything narrower than ~330px — which is exactly the old phone most
    // likely to be the one living in a car mount. Measure instead.
    const ringBox = useRef<HTMLDivElement>(null);
    const [radius, setRadius] = useState(RING_MAX_R);

    useLayoutEffect(() => {
        const el = ringBox.current;

        if (!el) {
            return;
        }

        const measure = () => {
            const available = el.clientWidth - RING_STROKE * 2;
            setRadius(Math.max(64, Math.min(RING_MAX_R, available / 2)));
        };

        measure();

        const observer = new ResizeObserver(measure);
        observer.observe(el);

        return () => observer.disconnect();
    }, []);

    // Keep the screen awake while driving.
    useEffect(() => {
        const nav = navigator as Navigator & WakeLockNavigator;

        if (!nav.wakeLock) {
            return;
        }

        let lock: WakeLockSentinelLike | null = null;
        const acquire = async () => {
            try {
                lock = await nav.wakeLock!.request('screen');
                setWakeLockOk(true);
                lock.addEventListener?.('release', () => setWakeLockOk(false));
            } catch {
                setWakeLockOk(false);
            }
        };
        acquire();
        const onVis = () => {
            if (document.visibilityState === 'visible') {
                acquire();
            }
        };
        document.addEventListener('visibilitychange', onVis);

        return () => {
            document.removeEventListener('visibilitychange', onVis);
            lock?.release().catch(() => {});
        };
    }, []);

    const again = () => {
        if (!last) {
            return;
        }

        setBusy(true);
        // The station has to travel with the request: without it the server falls
        // back to the default station, so re-requesting from Driving Mode on any
        // other station silently queued the song on the wrong one.
        router.post(
            `/songs/${last.songId}/request?station=${encodeURIComponent(station.slug)}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    try {
                        localStorage.setItem(
                            'fm.my_request',
                            JSON.stringify({
                                songId: last.songId,
                                title: last.title,
                            }),
                        );
                    } catch {
                        /* ignore */
                    }

                    try {
                        navigator.vibrate?.(40);
                    } catch {
                        /* unsupported */
                    }

                    setLast(getRecents()[0] ?? last);
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const size = 2 * (radius + RING_STROKE);
    const circumference = 2 * Math.PI * radius;
    const accent = isLive ? 'text-live' : 'text-playing';

    return (
        <div
            className="flex min-h-screen flex-col px-5 py-5"
            style={{ paddingBottom: 'env(safe-area-inset-bottom, 1.25rem)' }}
        >
            <Head title="Driving Mode" />

            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0">
                    <span className="font-display text-sm font-bold tracking-[0.25em] text-primary uppercase">
                        {commuteLabel}
                    </span>
                    {wakeLockOk === false && (
                        <p className="mt-0.5 font-display text-[10px] font-bold tracking-wider text-warning uppercase">
                            Screen may lock — tap occasionally
                        </p>
                    )}
                </div>
                <Link
                    href="/"
                    className="flex size-14 shrink-0 items-center justify-center rounded-xl border border-border bg-surface-2 text-muted-foreground shadow-card active:scale-95"
                    aria-label="Exit Driving Mode"
                >
                    <X size={26} />
                </Link>
            </div>

            {/* Center — the glance */}
            <div className="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                <div
                    ref={ringBox}
                    className="flex w-full max-w-[17rem] items-center justify-center"
                >
                    <div className="relative flex items-center justify-center">
                        <svg
                            width={size}
                            height={size}
                            className="-rotate-90"
                            aria-hidden
                        >
                            <circle
                                cx={size / 2}
                                cy={size / 2}
                                r={radius}
                                fill="none"
                                strokeWidth={RING_STROKE}
                                className="stroke-border"
                            />
                            <circle
                                cx={size / 2}
                                cy={size / 2}
                                r={radius}
                                fill="none"
                                strokeWidth={RING_STROKE}
                                strokeLinecap="round"
                                className={
                                    isLive ? 'stroke-live' : 'stroke-playing'
                                }
                                strokeDasharray={circumference}
                                strokeDashoffset={
                                    circumference *
                                    (1 - (duration > 0 ? progress : 0))
                                }
                                style={{
                                    transition:
                                        'stroke-dashoffset 0.25s linear',
                                }}
                            />
                        </svg>
                        <span
                            className={`absolute font-display text-xs font-bold tracking-[0.3em] uppercase ${accent}`}
                        >
                            {isLive
                                ? 'Live'
                                : data?.song
                                  ? 'On Air'
                                  : 'Dead Air'}
                        </span>
                    </div>
                </div>

                <div className="w-full min-w-0">
                    <h1 className="truncate font-display text-4xl leading-tight font-bold text-foreground sm:text-6xl">
                        {data?.song?.title ?? 'Nothing playing'}
                    </h1>
                    {data?.song?.artist && (
                        <p className="mt-2 truncate text-xl text-muted-foreground sm:text-2xl">
                            {data.song.artist}
                        </p>
                    )}
                    {duration > 0 && (
                        <p className="mt-3 font-display text-sm text-muted-foreground tabular-nums">
                            {fmtTime(elapsed)} / {fmtTime(duration)}
                        </p>
                    )}
                </div>
            </div>

            {/* Bottom — thumb targets */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {last && (
                    <button
                        onClick={again}
                        disabled={busy}
                        className="flex h-20 items-center justify-center gap-3 rounded-xl bg-primary font-display text-lg font-bold tracking-wide text-primary-foreground uppercase shadow-raised active:scale-[0.98] disabled:opacity-60"
                    >
                        <RotateCw
                            size={24}
                            className={busy ? 'animate-spin' : ''}
                        />
                        {busy ? 'Adding…' : 'Request again'}
                    </button>
                )}
                <Link
                    href="/songs"
                    className={`flex h-20 items-center justify-center gap-3 rounded-xl border border-border bg-surface-2 font-display text-lg font-bold tracking-wide text-foreground uppercase shadow-card active:scale-[0.98] ${last ? '' : 'sm:col-span-2'}`}
                >
                    <Music size={24} /> Browse songs
                </Link>
            </div>
        </div>
    );
}
