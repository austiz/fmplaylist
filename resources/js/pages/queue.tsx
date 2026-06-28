import { Link, router } from '@inertiajs/react';
import { useEffect } from 'react';
import { NowPlayingBar } from '@/components/now-playing-bar';
import { PublicLayout } from '@/components/public-layout';
import type { NowPlayingData, QueueItem } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    queue: QueueItem[];
    waitMinutes: number | null;
}

export default function Queue({ nowPlaying, queue, waitMinutes }: Props) {
    useEffect(() => {
        const es = new EventSource('/api/events');

        es.addEventListener('queue-changed', () => {
            router.reload({ only: ['queue', 'waitMinutes'] });
        });
        es.addEventListener('now-playing', () => {
            router.reload({ only: ['nowPlaying', 'queue', 'waitMinutes'] });
        });

        // Fallback poll in case SSE is disrupted for an extended period
        const fallback = setInterval(() => {
            router.reload({ only: ['queue', 'nowPlaying', 'waitMinutes'] });
        }, 60_000);

        return () => { es.close(); clearInterval(fallback); };
    }, []);

    return (
        <PublicLayout>
            <div className="space-y-5">
                <div className="border-b border-border pb-5">
                    <p className="font-display text-xs font-bold uppercase tracking-[0.2em] text-muted-foreground">
                        Live Broadcast Queue
                    </p>
                    <div className="mt-1 flex items-baseline gap-4">
                        <h1 className="font-display text-3xl font-bold text-foreground">Queue</h1>
                        {waitMinutes !== null && queue.length > 0 && (
                            <span className="text-sm text-muted-foreground">
                                ~{waitMinutes} min wait
                            </span>
                        )}
                    </div>
                </div>

                <NowPlayingBar initial={nowPlaying} />

                {queue.length === 0 ? (
                    <div className="py-20 text-center">
                        <p className="font-display text-6xl font-bold text-border">—</p>
                        <p className="mt-4 text-sm text-muted-foreground">
                            Queue is empty.{' '}
                            <Link href="/songs" className="text-red-500 hover:underline">
                                Request a song.
                            </Link>
                        </p>
                    </div>
                ) : (
                    <div className="divide-y divide-border border border-border">
                        {queue.map((item) => (
                            <div key={item.id} className="flex items-center gap-4 px-4 py-3">
                                <span className="font-display w-8 shrink-0 text-center text-xl font-bold tabular-nums text-red-600">
                                    {item.position}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-medium text-foreground">{item.song.title}</p>
                                    {item.song.artist && (
                                        <p className="truncate text-xs text-muted-foreground">{item.song.artist}</p>
                                    )}
                                </div>
                                {item.requested_by_name && (
                                    <span className="shrink-0 text-xs text-muted-foreground">
                                        — {item.requested_by_name}
                                    </span>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
