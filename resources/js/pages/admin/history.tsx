import { router } from '@inertiajs/react';
import { History as HistoryIcon } from 'lucide-react';

import { QueueStatusBadge } from '@/components/admin/queue-status-badge';
import { LineSparkline } from '@/components/admin/sparkline';
import { EmptyState } from '@/components/empty-state';
import { Pagination } from '@/components/pagination';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AdminLayout } from '@/layouts/admin-layout';
import { shortDay } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PaginatedResponse, QueueItem } from '@/types/fm';

interface Props {
    items: PaginatedResponse<QueueItem>;
    filter: string;
    playsLast7Days: { date: string; count: number }[];
}

const FILTERS = ['all', 'pending', 'playing', 'played', 'skipped'];

export default function History({ items, filter, playsLast7Days }: Props) {
    return (
        <AdminLayout
            title="Request History"
            description="Everything that has been asked for, and what became of it"
        >
            <div className="space-y-4">
                <Card className="animate-card-in">
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

                {/* A segmented filter rather than tabs: there is one list, and
                    these choose which rows the server sends for it. */}
                <div
                    role="group"
                    aria-label="Filter by status"
                    className="flex w-fit gap-1 rounded-lg border border-border bg-surface-2 p-1"
                >
                    {FILTERS.map((option) => {
                        const active = filter === option;

                        return (
                            <button
                                key={option}
                                type="button"
                                aria-pressed={active}
                                onClick={() =>
                                    router.get(
                                        '/admin/history',
                                        { filter: option },
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                            replace: true,
                                        },
                                    )
                                }
                                className={cn(
                                    'rounded-md px-3 py-1 text-sm font-medium capitalize transition-colors',
                                    'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                    active
                                        ? 'bg-primary text-primary-foreground shadow-card'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {option}
                            </button>
                        );
                    })}
                </div>

                {items.data.length === 0 ? (
                    <EmptyState
                        icon={<HistoryIcon />}
                        title="Nothing here"
                        description={
                            filter === 'all'
                                ? 'No song has been requested on this station yet.'
                                : `No requests are ${filter}.`
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Song</TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Requested
                                </TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Played
                                </TableHead>
                                <TableHead className="w-0 text-right">
                                    Status
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.data.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="max-w-0">
                                        <p className="truncate font-medium text-foreground">
                                            {item.song.title}
                                        </p>
                                        {item.song.artist && (
                                            <p className="truncate text-xs text-muted-foreground">
                                                {item.song.artist}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-xs whitespace-nowrap text-muted-foreground sm:table-cell">
                                        {item.created_at}
                                        {item.requested_by_name && (
                                            <span className="block text-foreground/60">
                                                by {item.requested_by_name}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="hidden text-xs whitespace-nowrap text-muted-foreground md:table-cell">
                                        {item.played_at ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <QueueStatusBadge
                                            status={item.status}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                <Pagination page={items} />
            </div>
        </AdminLayout>
    );
}
