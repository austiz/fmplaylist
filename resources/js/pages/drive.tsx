import { Head, Link, router } from '@inertiajs/react';
import { Music, RotateCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useElapsed } from '@/hooks/use-elapsed';
import { useFmLive } from '@/hooks/use-fm-live';
import { getCommutePalette } from '@/lib/commute';
import { getRecents } from '@/lib/recents';
import type { Recent } from '@/lib/recents';
import type { NowPlayingData } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
}

// Minimal Wake Lock typing (not in the DOM lib everywhere yet).
interface WakeLockSentinelLike {
    release: () => Promise<void>;
}
interface WakeLockNavigator {
    wakeLock?: { request: (type: 'screen') => Promise<WakeLockSentinelLike> };
}

function fmtTime(sec: number): string {
    const s = Math.max(0, Math.floor(sec));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

export default function Drive({ nowPlaying }: Props) {
    const { nowPlaying: live, piStatus } = useFmLive();
    const data = live === undefined ? nowPlaying : live;
    const isLive = piStatus?.status === 'live';
    const palette = getCommutePalette();

    const { elapsed, duration, progress } = useElapsed(
        data?.started_at,
        data?.song?.duration_seconds,
    );
    const [last, setLast] = useState<Recent | null>(
        () => getRecents()[0] ?? null,
    );
    const [busy, setBusy] = useState(false);

    // Keep the screen awake while driving.
    useEffect(() => {
        let lock: WakeLockSentinelLike | null = null;
        const nav = navigator as Navigator & WakeLockNavigator;
        const acquire = async () => {
            try {
                lock = (await nav.wakeLock?.request('screen')) ?? null;
            } catch {
                /* denied/unsupported */
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
        router.post(
            `/songs/${last.songId}/request`,
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

    // Big circular progress ring.
    const R = 130;
    const C = 2 * Math.PI * R;

    return (
        <div
            className="flex min-h-screen flex-col px-5 py-5"
            style={{ paddingBottom: 'env(safe-area-inset-bottom, 1.25rem)' }}
        >
            <Head title="Driving Mode" />

            {/* Top bar */}
            <div className="flex items-center justify-between">
                <span
                    className="font-display text-sm font-bold tracking-[0.25em] uppercase"
                    style={{ color: palette.accent }}
                >
                    {palette.label}
                </span>
                <Link
                    href="/"
                    className="flex h-14 w-14 items-center justify-center border border-border bg-card/70 text-muted-foreground backdrop-blur-sm active:scale-95"
                    aria-label="Exit Driving Mode"
                >
                    <X size={26} />
                </Link>
            </div>

            {/* Center — the glance */}
            <div className="flex flex-1 flex-col items-center justify-center gap-8 text-center">
                <div className="relative flex items-center justify-center">
                    <svg
                        width={2 * (R + 12)}
                        height={2 * (R + 12)}
                        className="-rotate-90"
                        aria-hidden
                    >
                        <circle
                            cx={R + 12}
                            cy={R + 12}
                            r={R}
                            fill="none"
                            strokeWidth={6}
                            className="stroke-border"
                        />
                        <circle
                            cx={R + 12}
                            cy={R + 12}
                            r={R}
                            fill="none"
                            strokeWidth={6}
                            strokeLinecap="round"
                            className={
                                isLive ? 'stroke-violet-400' : 'stroke-red-500'
                            }
                            strokeDasharray={C}
                            strokeDashoffset={
                                C * (1 - (duration > 0 ? progress : 0))
                            }
                            style={{
                                transition: 'stroke-dashoffset 0.25s linear',
                            }}
                        />
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center px-8">
                        <span
                            className={`font-display text-xs font-bold tracking-[0.3em] uppercase ${isLive ? 'text-violet-400' : 'text-red-500'}`}
                        >
                            {isLive
                                ? 'Live'
                                : data?.song
                                  ? 'On Air'
                                  : 'Dead Air'}
                        </span>
                    </div>
                </div>

                <div className="max-w-full min-w-0">
                    <h1 className="truncate font-display text-4xl leading-tight font-bold text-foreground sm:text-6xl">
                        {data?.song?.title ?? 'Nothing playing'}
                    </h1>
                    {data?.song?.artist && (
                        <p className="mt-2 truncate text-xl text-muted-foreground sm:text-2xl">
                            {data.song.artist}
                        </p>
                    )}
                    {duration > 0 && (
                        <p className="mt-3 font-display text-sm text-muted-foreground/60 tabular-nums">
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
                        className="flex h-20 items-center justify-center gap-3 border border-red-500/50 bg-red-600/90 font-display text-lg font-bold tracking-wide text-white uppercase backdrop-blur-sm active:scale-[0.98] disabled:opacity-60"
                    >
                        <RotateCw
                            size={24}
                            className={busy ? 'animate-spin' : ''}
                        />
                        {busy ? 'Adding…' : 'Request Again'}
                    </button>
                )}
                <Link
                    href="/songs"
                    className={`flex h-20 items-center justify-center gap-3 border border-border bg-card/70 font-display text-lg font-bold tracking-wide text-foreground uppercase backdrop-blur-sm active:scale-[0.98] ${last ? '' : 'sm:col-span-2'}`}
                >
                    <Music size={24} /> Browse Songs
                </Link>
            </div>
        </div>
    );
}
