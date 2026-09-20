import { Link, router } from '@inertiajs/react';
import { Gauge, ListMusic, Music, RotateCw } from 'lucide-react';
import { useEffect, useState } from 'react';

import { NowPlayingBar } from '@/components/now-playing-bar';
import { SectionHeader } from '@/components/page-header';
import { PublicLayout } from '@/components/public-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFmLive } from '@/hooks/use-fm-live';
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
    const { queueVersion, commuteLabel, listenerCount } = useFmLive();

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
                {/* The hour and the room, in one line. This was three stacked
                    paragraphs of rotating copy over an animated glow, and a
                    listener who came to request a song read past all of it. */}
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 font-display text-[11px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                    <span className="text-primary">{commuteLabel}</span>
                    {!!listenerCount && listenerCount > 0 && (
                        <>
                            <span aria-hidden>·</span>
                            <span>{listenerCount} listening now</span>
                        </>
                    )}
                </div>

                <NowPlayingBar initial={nowPlaying} />

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card className="animate-card-in">
                        <CardContent className="space-y-4">
                            <SectionHeader
                                title="Request a song"
                                description="Browse the full library and put anything on the air."
                            />
                            <Button asChild className="h-12 w-full gap-2">
                                <Link href="/songs">
                                    <Music size={18} />
                                    Browse songs
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>

                    <Card
                        className="animate-card-in"
                        style={{ animationDelay: '60ms' }}
                    >
                        <CardContent className="space-y-4">
                            <SectionHeader
                                title="Driving mode"
                                description="Big, glanceable, thumb-friendly. Eyes on the 5."
                            />
                            <Button
                                asChild
                                variant="outline"
                                className="h-12 w-full gap-2"
                            >
                                <Link href="/drive">
                                    <Gauge size={18} />
                                    Enter drive
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                {recents.length > 0 && (
                    <section className="space-y-3">
                        <SectionHeader title="Your recent requests" />
                        <div className="flex scrollbar-none gap-3 overflow-x-auto pb-1">
                            {recents.map((r) => (
                                <button
                                    key={r.songId}
                                    onClick={() => reRequest(r)}
                                    disabled={busyId === r.songId}
                                    className="flex w-44 shrink-0 flex-col justify-between gap-4 rounded-xl border border-border bg-card p-4 text-left shadow-card transition-colors hover:border-primary/50 hover:bg-surface-2 disabled:opacity-50"
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
                                    <span className="flex items-center gap-1.5 font-display text-[10px] font-semibold tracking-wider text-primary uppercase">
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
                    </section>
                )}

                {queue.length > 0 ? (
                    <section className="space-y-3">
                        <SectionHeader
                            title={`Up next (${queueCount})`}
                            actions={
                                <Link
                                    href="/queue"
                                    className="text-xs font-medium text-primary hover:underline"
                                >
                                    Full queue →
                                </Link>
                            }
                        />
                        <Card className="gap-0 overflow-hidden py-0">
                            <ul className="divide-y divide-border">
                                {queue.map((item) => (
                                    <li
                                        key={item.id}
                                        className="flex items-center gap-4 px-5 py-3"
                                    >
                                        <span className="w-6 shrink-0 text-center font-display text-sm font-bold text-muted-foreground tabular-nums">
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
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {item.requested_by_name}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </section>
                ) : (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center gap-3 py-6 text-center">
                            <ListMusic
                                size={22}
                                className="text-muted-foreground/50"
                            />
                            <p className="text-sm text-muted-foreground">
                                Nothing queued. Whatever you request plays next.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </PublicLayout>
    );
}
