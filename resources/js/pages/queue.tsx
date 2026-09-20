import { Link } from '@inertiajs/react';
import { Flame } from 'lucide-react';
import { useEffect, useState } from 'react';
import { ChatPanel } from '@/components/chat-panel';
import { NowPlayingBar } from '@/components/now-playing-bar';
import { PublicLayout } from '@/components/public-layout';
import { useFmLive } from '@/hooks/use-fm-live';
import { useViewTransitionReload } from '@/hooks/use-view-transition-reload';
import { bumpReaction, getReaction } from '@/lib/reactions';
import type { NowPlayingData, QueueItem, Station } from '@/types/fm';

interface HistoryItem {
    id: number;
    played_at: string | null;
    requested_by_name: string | null;
    song: { title: string; artist: string };
}

interface Props {
    nowPlaying: NowPlayingData | null;
    queue: QueueItem[];
    waitMinutes: number | null;
    history: HistoryItem[];
    station: Station;
}

/** Local-only reaction tap — your own count, not a shared count across listeners (see reactions.ts). */
function ReactionButton({ queueItemId }: { queueItemId: number }) {
    const [count, setCount] = useState(() => getReaction(queueItemId));

    const react = () => {
        setCount(bumpReaction(queueItemId));

        try {
            navigator.vibrate?.(15);
        } catch {
            /* unsupported */
        }
    };

    return (
        <button
            onClick={react}
            title="React — just for you, not a shared count"
            className={`flex shrink-0 items-center gap-1 px-1.5 py-1 text-xs font-bold transition-colors active:scale-90 ${
                count > 0
                    ? 'text-orange-400'
                    : 'text-muted-foreground/30 hover:text-orange-400'
            }`}
        >
            <Flame size={14} fill={count > 0 ? 'currentColor' : 'none'} />
            {count > 0 && <span className="tabular-nums">{count}</span>}
        </button>
    );
}

export default function Queue({
    nowPlaying,
    queue,
    waitMinutes,
    history,
    station,
}: Props) {
    const [tab, setTab] = useState<'queue' | 'history'>('queue');
    const { queueVersion } = useFmLive();
    const viewTransitionReload = useViewTransitionReload();

    useEffect(() => {
        if (!queueVersion) {
            return;
        }

        viewTransitionReload({ only: ['queue', 'waitMinutes', 'history'] });
    }, [queueVersion, viewTransitionReload]);

    return (
        <PublicLayout>
            <div className="space-y-5">
                <div className="border-b border-border pb-5">
                    <p className="font-display text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                        Live Broadcast
                    </p>
                    <div className="mt-1 flex items-baseline gap-4">
                        <h1 className="font-display text-3xl font-bold text-foreground">
                            Queue
                        </h1>
                        {tab === 'queue' &&
                            waitMinutes !== null &&
                            queue.length > 0 && (
                                <span className="text-sm text-muted-foreground">
                                    ~{waitMinutes} min wait
                                </span>
                            )}
                    </div>
                </div>

                <NowPlayingBar initial={nowPlaying} />

                {/* Tab strip */}
                <div className="flex border-b border-border">
                    {(['queue', 'history'] as const).map((t) => (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            className={`px-4 py-2 text-xs font-bold tracking-wider uppercase transition-colors ${
                                tab === t
                                    ? 'border-b-2 border-red-500 text-red-500'
                                    : 'text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {t === 'queue'
                                ? `Queue (${queue.length})`
                                : 'History'}
                        </button>
                    ))}
                </div>

                {tab === 'queue' &&
                    (queue.length === 0 ? (
                        <div className="py-20 text-center">
                            <p className="font-display text-6xl font-bold text-border">
                                —
                            </p>
                            <p className="mt-4 text-sm text-muted-foreground">
                                Queue is empty.{' '}
                                <Link
                                    href={`/songs?station=${encodeURIComponent(station.slug)}`}
                                    className="text-red-500 hover:underline"
                                >
                                    Request a song.
                                </Link>
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-border border border-border">
                            {queue.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center gap-4 px-4 py-3"
                                    style={{
                                        viewTransitionName: `queue-item-${item.id}`,
                                    }}
                                >
                                    <span className="w-8 shrink-0 text-center font-display text-xl font-bold text-red-600 tabular-nums">
                                        {item.position}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium text-foreground">
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
                                            — {item.requested_by_name}
                                        </span>
                                    )}
                                    <ReactionButton queueItemId={item.id} />
                                </div>
                            ))}
                        </div>
                    ))}

                {tab === 'history' &&
                    (history.length === 0 ? (
                        <div className="py-20 text-center">
                            <p className="font-display text-6xl font-bold text-border">
                                —
                            </p>
                            <p className="mt-4 text-sm text-muted-foreground">
                                Nothing played yet.
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-border border border-border">
                            {history.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center gap-4 px-4 py-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium text-foreground">
                                            {item.song.title}
                                        </p>
                                        {item.song.artist && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.song.artist}
                                            </p>
                                        )}
                                    </div>
                                    <div className="shrink-0 text-right">
                                        {item.requested_by_name && (
                                            <p className="text-xs text-muted-foreground">
                                                {item.requested_by_name}
                                            </p>
                                        )}
                                        {item.played_at && (
                                            <p className="text-xs text-muted-foreground/50">
                                                {item.played_at}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    ))}

                <ChatPanel />
            </div>
        </PublicLayout>
    );
}
