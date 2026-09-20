import { Link } from '@inertiajs/react';
import { Flame, History, ListMusic } from 'lucide-react';
import { useEffect, useState } from 'react';

import { ChatPanel } from '@/components/chat-panel';
import { EmptyState } from '@/components/empty-state';
import { NowPlayingBar } from '@/components/now-playing-bar';
import { PageHeader } from '@/components/page-header';
import { PublicLayout } from '@/components/public-layout';
import { Card } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useFmLive } from '@/hooks/use-fm-live';
import { useViewTransitionReload } from '@/hooks/use-view-transition-reload';
import { bumpReaction, getReaction } from '@/lib/reactions';
import type { NowPlayingData, QueueItem, Station } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    queue: QueueItem[];
    waitMinutes: number | null;
    history: QueueItem[];
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
            aria-label="React to this song"
            className={`flex shrink-0 items-center gap-1 rounded-md px-1.5 py-1 text-xs font-bold transition-colors active:scale-90 ${
                count > 0
                    ? 'text-warning'
                    : 'text-muted-foreground/40 hover:text-warning'
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
                <PageHeader
                    title="Queue"
                    description={
                        waitMinutes !== null && queue.length > 0
                            ? `About ${waitMinutes} min until the end of the queue`
                            : 'Everything lined up to go on air'
                    }
                />

                <NowPlayingBar initial={nowPlaying} />

                {/* Radix Tabs rather than the two plain buttons that were here:
                    those had no roving tabindex, so arrow keys did nothing. */}
                <Tabs defaultValue="queue" className="gap-5">
                    <TabsList>
                        <TabsTrigger value="queue">
                            Up next ({queue.length})
                        </TabsTrigger>
                        <TabsTrigger value="history">History</TabsTrigger>
                    </TabsList>

                    <TabsContent value="queue">
                        {queue.length === 0 ? (
                            <EmptyState
                                icon={<ListMusic size={22} />}
                                title="The queue is empty"
                                description={
                                    <>
                                        Whatever you request plays next.{' '}
                                        <Link
                                            href={`/songs?station=${encodeURIComponent(station.slug)}`}
                                            className="text-primary hover:underline"
                                        >
                                            Browse songs
                                        </Link>
                                        .
                                    </>
                                }
                            />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <ul className="divide-y divide-border">
                                    {queue.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex items-center gap-4 px-5 py-3"
                                            style={{
                                                viewTransitionName: `queue-item-${item.id}`,
                                            }}
                                        >
                                            <span className="w-8 shrink-0 text-center font-display text-lg font-bold text-primary tabular-nums">
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
                                            <ReactionButton
                                                queueItemId={item.id}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </TabsContent>

                    <TabsContent value="history">
                        {history.length === 0 ? (
                            <EmptyState
                                icon={<History size={22} />}
                                title="Nothing played yet"
                                description="Songs land here once they have been on air."
                            />
                        ) : (
                            <Card className="gap-0 overflow-hidden py-0">
                                <ul className="divide-y divide-border">
                                    {history.map((item) => (
                                        <li
                                            key={item.id}
                                            className="flex items-center gap-4 px-5 py-3"
                                        >
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
                                            <div className="shrink-0 space-y-0.5 text-right">
                                                {item.requested_by_name && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {item.requested_by_name}
                                                    </p>
                                                )}
                                                {item.played_at && (
                                                    <p className="text-xs text-muted-foreground/60 tabular-nums">
                                                        {item.played_at}
                                                    </p>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            </Card>
                        )}
                    </TabsContent>
                </Tabs>

                <ChatPanel />
            </div>
        </PublicLayout>
    );
}
