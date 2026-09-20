import { Link, router } from '@inertiajs/react';
import { LineSparkline } from '@/components/admin/sparkline';
import { AdminLayout } from '@/components/admin-layout';
import { shortDay } from '@/lib/format';
import type { PaginatedResponse } from '@/types/fm';

interface HistoryItem {
    id: number;
    status: string;
    requested_by_name: string | null;
    created_at: string;
    played_at: string | null;
    song: { title: string; artist: string };
}

interface Props {
    items: PaginatedResponse<HistoryItem>;
    filter: string;
    playsLast7Days: { date: string; count: number }[];
}

const filters = ['all', 'pending', 'playing', 'played', 'skipped'];

export default function History({ items, filter, playsLast7Days }: Props) {
    return (
        <AdminLayout title="Request History">
            <div className="mb-4 border border-border bg-card p-4">
                <p className="mb-2 font-display text-xs font-bold tracking-wider text-muted-foreground uppercase">
                    Plays — last 7 days
                </p>
                <LineSparkline
                    points={playsLast7Days.map((d) => ({
                        label: shortDay(d.date),
                        value: d.count,
                    }))}
                />
            </div>

            <div className="mb-4 flex gap-2">
                {filters.map((f) => (
                    <button
                        key={f}
                        onClick={() =>
                            router.get(
                                '/admin/history',
                                { filter: f },
                                { preserveState: true },
                            )
                        }
                        className={`px-3 py-1 text-sm font-medium capitalize transition-colors ${
                            filter === f
                                ? 'bg-red-600 text-white'
                                : 'border border-border text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {f}
                    </button>
                ))}
            </div>

            <div className="divide-y divide-border border border-border bg-card">
                {items.data.length === 0 && (
                    <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                        No items.
                    </p>
                )}
                {items.data.map((item) => (
                    <div
                        key={item.id}
                        className="flex items-center gap-4 px-4 py-3"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-foreground">
                                {item.song.title}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Requested {item.created_at}
                                {item.requested_by_name
                                    ? ` by ${item.requested_by_name}`
                                    : ''}
                            </p>
                        </div>
                        {item.played_at && (
                            <span className="shrink-0 text-xs text-muted-foreground/50">
                                Played {item.played_at}
                            </span>
                        )}
                        <span
                            className={`shrink-0 px-2 py-0.5 text-xs font-bold tracking-wide uppercase ${
                                item.status === 'played'
                                    ? 'bg-green-500/15 text-green-400'
                                    : item.status === 'playing'
                                      ? 'bg-red-500/15 text-red-400'
                                      : item.status === 'pending'
                                        ? 'bg-secondary text-muted-foreground'
                                        : 'bg-secondary text-muted-foreground/50'
                            }`}
                        >
                            {item.status}
                        </span>
                    </div>
                ))}
            </div>

            {items.last_page > 1 && (
                <div className="mt-4 flex justify-center gap-1">
                    {items.links.map((link, i) =>
                        link.url ? (
                            <Link
                                key={i}
                                href={link.url}
                                className={`px-3 py-1.5 text-xs font-medium ${link.active ? 'bg-red-600 text-white' : 'border border-border text-muted-foreground hover:text-foreground'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ) : (
                            <span
                                key={i}
                                className="px-3 py-1.5 text-xs text-muted-foreground/30"
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ),
                    )}
                </div>
            )}
        </AdminLayout>
    );
}
