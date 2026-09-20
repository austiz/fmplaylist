import { router } from '@inertiajs/react';
import { Inbox, Trash2 } from 'lucide-react';

import { QueueStatusBadge } from '@/components/admin/queue-status-badge';
import { BarSparkline, LineSparkline } from '@/components/admin/sparkline';
import { useConfirm } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatTile } from '@/components/stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { AdminLayout } from '@/layouts/admin-layout';
import { formatRuntime, shortDay } from '@/lib/format';
import type { NowPlayingData, QueueItem } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    queueDepth: number;
    queueRuntimeSeconds: number;
    recentRequests: QueueItem[];
    stats: { requestsToday: number; songsPlayedToday: number };
    requestsPerHour: number[];
    playsLast7Days: { date: string; count: number }[];
}

export default function Dashboard({
    nowPlaying,
    queueDepth,
    queueRuntimeSeconds,
    recentRequests,
    stats,
    requestsPerHour,
    playsLast7Days,
}: Props) {
    const confirm = useConfirm();

    const deleteRequest = async (id: number) => {
        const ok = await confirm({
            title: 'Remove this request from the queue?',
            description: 'The listener is not notified.',
            confirmLabel: 'Remove',
            variant: 'destructive',
        });

        if (!ok) {
            return;
        }

        router.delete(`/admin/queue/${id}`, { preserveScroll: true });
    };

    return (
        <AdminLayout
            title="Dashboard"
            description="How the station is doing right now"
        >
            <div className="space-y-4">
                <div className="grid gap-4 sm:grid-cols-4">
                    <StatTile
                        label="Queue"
                        value={queueDepth}
                        hint="songs waiting"
                    />
                    <StatTile
                        label="Wait time"
                        value={formatRuntime(queueRuntimeSeconds)}
                        hint="until queue clears"
                        delay={40}
                    />
                    <StatTile
                        label="Requests today"
                        value={stats.requestsToday}
                        delay={80}
                    />
                    <StatTile
                        label="Played today"
                        value={stats.songsPlayedToday}
                        delay={120}
                    />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Card
                        className="animate-card-in"
                        style={{ animationDelay: '160ms' }}
                    >
                        <CardContent className="space-y-3">
                            <CardTitle>Requests — today by hour</CardTitle>
                            <BarSparkline
                                values={requestsPerHour}
                                labels={['12am', '11pm']}
                            />
                        </CardContent>
                    </Card>
                    <Card
                        className="animate-card-in"
                        style={{ animationDelay: '200ms' }}
                    >
                        <CardContent className="space-y-3">
                            <CardTitle>Plays — last 7 days</CardTitle>
                            <LineSparkline
                                points={playsLast7Days.map((d) => ({
                                    label: shortDay(d.date),
                                    value: d.count,
                                }))}
                            />
                        </CardContent>
                    </Card>
                </div>

                {nowPlaying && (
                    <Card className="animate-card-in border-playing/30 bg-playing-soft">
                        <CardContent className="space-y-1">
                            <Badge variant="playing">Now broadcasting</Badge>
                            <p className="pt-1 font-display text-lg font-bold text-foreground">
                                {nowPlaying.song?.title ?? '(deleted)'}
                            </p>
                            {nowPlaying.song?.artist && (
                                <p className="text-sm text-muted-foreground">
                                    {nowPlaying.song.artist}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}

                <section className="space-y-2">
                    <h2 className="font-display text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        Recent requests
                    </h2>

                    {recentRequests.length === 0 ? (
                        <EmptyState
                            icon={<Inbox />}
                            title="No requests yet"
                            description="Anything a listener asks for shows up here, newest first."
                        />
                    ) : (
                        <Card className="gap-0 divide-y divide-border overflow-hidden py-0">
                            {recentRequests.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex items-center gap-4 px-4 py-2.5"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-foreground">
                                            {item.song.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.requested_by_name && (
                                                <span className="mr-1 text-foreground/60">
                                                    {item.requested_by_name} ·
                                                </span>
                                            )}
                                            {item.created_at}
                                        </p>
                                    </div>

                                    <QueueStatusBadge status={item.status} />

                                    {item.status === 'pending' && (
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-7 shrink-0 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                deleteRequest(item.id)
                                            }
                                            aria-label={`Remove ${item.song.title} from the queue`}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </Card>
                    )}
                </section>
            </div>
        </AdminLayout>
    );
}
