import { Link, router, usePage } from '@inertiajs/react';
import { Gauge, RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';
import { NowPlayingBar } from '@/components/now-playing-bar';
import { PublicLayout } from '@/components/public-layout';
import { Button } from '@/components/ui/button';
import { useFmLive } from '@/hooks/use-fm-live';
import { getQuip, getTagline } from '@/lib/quips';
import { getRecents, pushRecent } from '@/lib/recents';
import type { Recent } from '@/lib/recents';
import type { NowPlayingData, QueueItem, Station } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    queue: QueueItem[];
    queueCount: number;
    station: Station;
}

export default function Home({
    nowPlaying,
    queue,
    queueCount,
    station,
}: Props) {
    const { props } = usePage<{
        flash: { success?: string };
        frequency: string;
    }>();
    const flash = props.flash;
    const freq = props.frequency ?? '96.9';
    const { queueVersion, palette, listenerCount } = useFmLive();

    const [recents, setRecents] = useState<Recent[]>(() => getRecents());
    const [busyId, setBusyId] = useState<number | null>(null);

    useEffect(() => {
        if (!queueVersion) {
            return;
        }

        router.reload({ only: ['queue', 'queueCount'] });
    }, [queueVersion]);

    const reRequest = (r: Recent) => {
        setBusyId(r.songId);
        router.post(
            `/songs/${r.songId}/request?station=${encodeURIComponent(station.slug)}`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    try {
                        localStorage.setItem(
                            'fm.my_request',
                            JSON.stringify({
                                songId: r.songId,
                                title: r.title,
                            }),
                        );
                    } catch {
                        /* ignore */
                    }

                    setRecents(
                        pushRecent({
                            songId: r.songId,
                            title: r.title,
                            artist: r.artist,
                        }),
                    );

                    try {
                        navigator.vibrate?.(30);
                    } catch {
                        /* unsupported */
                    }
                },
                onFinish: () => setBusyId(null),
            },
        );
    };

    return (
        <PublicLayout>
            <div className="space-y-6">
                {/* Hero cockpit */}
                <div className="relative space-y-2 overflow-hidden border-b border-border pb-6">
                    <div
                        className="pointer-events-none absolute -top-20 left-1/2 h-64 w-96 -translate-x-1/2 rounded-full blur-3xl"
                        style={{
                            background: `radial-gradient(ellipse, oklch(0.55 0.24 var(--phase-glow-hue, 27) / var(--glow-opacity, 0.1)), transparent)`,
                            animation: 'hero-breathe 4s ease-in-out infinite',
                        }}
                    />
                    <div className="relative flex items-center gap-2">
                        <p className="font-display text-xs font-bold tracking-[0.25em] text-primary uppercase">
                            On Air · {freq} FM
                        </p>
                        <span
                            className="font-display text-[10px] font-bold tracking-[0.2em] uppercase"
                            style={{ color: palette.accent }}
                        >
                            · {palette.label}
                        </span>
                        {!!listenerCount && listenerCount > 0 && (
                            <span className="font-display text-[10px] font-bold tracking-[0.2em] text-muted-foreground uppercase">
                                · {listenerCount} listening now
                            </span>
                        )}
                    </div>
                    <h1 className="relative font-display text-4xl leading-tight font-bold text-foreground sm:text-5xl">
                        Your station.
                        <br />
                        Your songs.
                    </h1>
                    <p className="relative max-w-md text-base text-muted-foreground">
                        {getTagline(palette.phase)}
                    </p>
                    <p className="relative max-w-md text-sm text-muted-foreground/60 italic">
                        {getQuip(palette.phase)}
                    </p>
                </div>

                <NowPlayingBar initial={nowPlaying} />

                {flash?.success && (
                    <div className="border-l-2 border-online bg-online-soft px-4 py-3 text-sm text-online backdrop-blur-sm">
                        {flash.success}
                    </div>
                )}

                {/* Primary actions */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="border border-border bg-card/70 p-4 backdrop-blur-sm">
                        <h2 className="font-display text-lg font-bold text-foreground">
                            Request a Song
                        </h2>
                        <p className="mt-1 mb-4 text-sm text-muted-foreground">
                            Browse the full library and put anything on the air.
                        </p>
                        <Link href="/songs">
                            <Button className="h-12 w-full font-display font-bold tracking-wide uppercase active:scale-[0.98]">
                                Browse Songs →
                            </Button>
                        </Link>
                    </div>
                    <div className="border border-border bg-card/70 p-4 backdrop-blur-sm">
                        <h2 className="font-display text-lg font-bold text-foreground">
                            Driving Mode
                        </h2>
                        <p className="mt-1 mb-4 text-sm text-muted-foreground">
                            Big, glanceable, thumb-friendly. Eyes on the 5.
                        </p>
                        <Link href="/drive">
                            <Button
                                variant="outline"
                                className="h-12 w-full gap-2 font-display font-bold tracking-wide uppercase active:scale-[0.98]"
                            >
                                <Gauge size={18} /> Enter Drive
                            </Button>
                        </Link>
                    </div>
                </div>

                {/* Recent requests — one-tap play again */}
                {recents.length > 0 && (
                    <div>
                        <h2 className="mb-3 font-display text-sm font-bold tracking-widest text-muted-foreground uppercase">
                            Your Recent Requests
                        </h2>
                        <div className="flex scrollbar-none gap-3 overflow-x-auto pb-2">
                            {recents.map((r) => (
                                <button
                                    key={r.songId}
                                    onClick={() => reRequest(r)}
                                    disabled={busyId === r.songId}
                                    className="group flex w-40 shrink-0 flex-col justify-between border border-border bg-card/70 p-3 text-left backdrop-blur-sm transition-colors hover:border-primary/50 active:scale-[0.98] disabled:opacity-50"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium text-foreground">
                                            {r.title}
                                        </p>
                                        {r.artist && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {r.artist}
                                            </p>
                                        )}
                                    </div>
                                    <span className="mt-3 flex items-center gap-1.5 font-display text-[10px] font-bold tracking-wider text-primary uppercase">
                                        <RotateCw
                                            size={12}
                                            className={
                                                busyId === r.songId
                                                    ? 'animate-spin'
                                                    : ''
                                            }
                                        />
                                        {busyId === r.songId
                                            ? 'Adding'
                                            : 'Again'}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                )}

                {/* Queue preview */}
                {queue.length > 0 && (
                    <div>
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="font-display text-sm font-bold tracking-widest text-muted-foreground uppercase">
                                Up Next ({queueCount})
                            </h2>
                            <Link
                                href="/queue"
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                Full queue →
                            </Link>
                        </div>
                        <div className="divide-y divide-border border border-border bg-card/40 backdrop-blur-sm">
                            {queue.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center gap-4 px-4 py-3"
                                >
                                    <span className="w-6 shrink-0 text-center font-display text-sm font-bold text-primary/60 tabular-nums">
                                        {item.position}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-foreground">
                                            {item.song.title}
                                        </p>
                                        {item.song.artist && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.song.artist}
                                            </p>
                                        )}
                                    </div>
                                    {item.requested_by_name && (
                                        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                            {item.requested_by_name}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
