import { router } from '@inertiajs/react';
import {
    ArrowUpToLine,
    ChevronDown,
    ChevronUp,
    GripVertical,
    ListMusic,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { useConfirm } from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import { StatTile } from '@/components/stat-tile';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAdminLive } from '@/hooks/use-admin-live';
import { AdminLayout } from '@/layouts/admin-layout';
import { fmtTime, formatRuntime } from '@/lib/format';
import type { NowPlayingData, QueueItem } from '@/types/fm';

interface Props {
    items: QueueItem[];
    nowPlaying: NowPlayingData | null;
}

/** `from` moved to `to`, in a copy of the list. */
function move<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [item] = next.splice(from, 1);
    next.splice(to, 0, item);

    return next;
}

export default function Queue({ items, nowPlaying }: Props) {
    const confirm = useConfirm();
    const { queueVersion } = useAdminLive();

    const playing = items.find((item) => item.status === 'playing') ?? null;
    const fromServer = items.filter((item) => item.status === 'pending');

    // Local order, so a drag previews immediately rather than waiting for the
    // round trip. It resets whenever the server sends a list that differs from
    // the one this copy was made from, which covers our own save landing, a
    // second operator reordering, and the Pi taking the head of the queue.
    const signature = items.map((item) => `${item.id}:${item.status}`).join();
    const [order, setOrder] = useState(fromServer);
    const [seen, setSeen] = useState(signature);

    if (seen !== signature) {
        setSeen(signature);
        setOrder(fromServer);
    }

    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [dragId, setDragId] = useState<number | null>(null);

    // Mirrored into a ref so the live-reload effect below can read it without
    // taking it as a dependency: that effect must fire on a new queue version
    // and on nothing else, and depending on `dragId` would make dropping a row
    // trigger the very reload the flag exists to suppress.
    const dragging = useRef(false);

    useEffect(() => {
        dragging.current = dragId !== null;
    }, [dragId]);

    useEffect(() => {
        // Pulling the rug out from under a half-finished drag would be worse
        // than showing a queue that is three seconds stale.
        if (!queueVersion || dragging.current) {
            return;
        }

        router.reload({ only: ['items', 'nowPlaying'] });
    }, [queueVersion]);

    const save = (next: QueueItem[]) => {
        setOrder(next);
        router.post(
            '/admin/queue/reorder',
            { ids: next.map((item) => item.id) },
            { preserveScroll: true, preserveState: true },
        );
    };

    const nudge = (index: number, direction: -1 | 1) => {
        const to = index + direction;

        if (to < 0 || to >= order.length) {
            return;
        }

        save(move(order, index, to));
    };

    const playNext = (item: QueueItem) =>
        router.post(
            `/admin/queue/${item.id}/play-next`,
            {},
            { preserveScroll: true },
        );

    const remove = async (item: QueueItem) => {
        const ok = await confirm({
            title: `Remove "${item.song.title}"?`,
            description: item.requested_by_name
                ? `${item.requested_by_name} requested this. It will not go on air.`
                : 'It will not go on air.',
            confirmLabel: 'Remove',
            variant: 'destructive',
        });

        if (ok) {
            router.delete(`/admin/queue/${item.id}`, { preserveScroll: true });
        }
    };

    const removeSelected = async () => {
        const ok = await confirm({
            title: `Remove ${selected.size} requests?`,
            description: 'None of them will go on air.',
            confirmLabel: `Remove ${selected.size}`,
            variant: 'destructive',
        });

        if (!ok) {
            return;
        }

        router.post(
            '/admin/queue/bulk-destroy',
            { ids: [...selected] },
            { preserveScroll: true, onSuccess: () => setSelected(new Set()) },
        );
    };

    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);

            if (!next.delete(id)) {
                next.add(id);
            }

            return next;
        });

    const allSelected = order.length > 0 && selected.size === order.length;

    const runtime = order.reduce(
        (total, item) => total + (item.song.duration_seconds ?? 0),
        0,
    );

    return (
        <AdminLayout
            title="Queue"
            description="What goes out next, in the order it goes out"
        >
            <div className="space-y-5">
                <div className="grid gap-4 sm:grid-cols-3">
                    <StatTile label="Waiting" value={order.length} />
                    <StatTile
                        label="Runtime"
                        value={formatRuntime(runtime)}
                        hint="until the queue clears"
                        delay={60}
                    />
                    <StatTile
                        label="On air"
                        value={
                            <span className="line-clamp-1 text-xl">
                                {nowPlaying?.song?.title ??
                                    playing?.song.title ??
                                    'Dead air'}
                            </span>
                        }
                        delay={120}
                    />
                </div>

                {/* Sits above the table rather than replacing its header, because
                    the header carries the select-all that got you here. */}
                {selected.size > 0 && (
                    <div className="flex items-center gap-3 rounded-lg border border-border bg-surface-2 px-4 py-2.5 shadow-card">
                        <span className="text-sm text-foreground tabular-nums">
                            {selected.size} selected
                        </span>
                        <Button
                            size="sm"
                            variant="destructive"
                            onClick={removeSelected}
                            className="ml-auto gap-1.5"
                        >
                            <Trash2 className="size-3.5" />
                            Remove
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setSelected(new Set())}
                        >
                            Clear
                        </Button>
                    </div>
                )}

                {order.length === 0 ? (
                    <EmptyState
                        icon={<ListMusic />}
                        title="Nothing waiting"
                        description="Listener requests land here, and autofill tops the queue up from the library whenever the transmitter asks for its next song."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    <Checkbox
                                        checked={allSelected}
                                        onCheckedChange={() =>
                                            setSelected(
                                                allSelected
                                                    ? new Set()
                                                    : new Set(
                                                          order.map(
                                                              (item) => item.id,
                                                          ),
                                                      ),
                                            )
                                        }
                                        aria-label={
                                            allSelected
                                                ? 'Clear selection'
                                                : 'Select every request'
                                        }
                                    />
                                </TableHead>
                                <TableHead className="w-0">#</TableHead>
                                <TableHead>Song</TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Requested by
                                </TableHead>
                                <TableHead className="hidden md:table-cell">
                                    Requested
                                </TableHead>
                                <TableHead className="w-0 text-right">
                                    Length
                                </TableHead>
                                <TableHead className="w-0">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>

                        <TableBody>
                            {order.map((item, index) => (
                                <TableRow
                                    key={item.id}
                                    draggable
                                    onDragStart={() => setDragId(item.id)}
                                    onDragEnd={() => {
                                        setDragId(null);
                                        save(order);
                                    }}
                                    onDragOver={(e) => {
                                        e.preventDefault();

                                        if (
                                            dragId === null ||
                                            dragId === item.id
                                        ) {
                                            return;
                                        }

                                        const from = order.findIndex(
                                            (row) => row.id === dragId,
                                        );

                                        if (from !== -1) {
                                            setOrder(move(order, from, index));
                                        }
                                    }}
                                    data-state={
                                        selected.has(item.id)
                                            ? 'selected'
                                            : undefined
                                    }
                                    className={
                                        dragId === item.id
                                            ? 'opacity-40'
                                            : undefined
                                    }
                                >
                                    <TableCell>
                                        <Checkbox
                                            checked={selected.has(item.id)}
                                            onCheckedChange={() =>
                                                toggle(item.id)
                                            }
                                            aria-label={`Select ${item.song.title}`}
                                        />
                                    </TableCell>

                                    <TableCell>
                                        <div className="flex items-center gap-1 text-muted-foreground">
                                            <GripVertical
                                                aria-hidden
                                                className="size-3.5 cursor-grab opacity-40"
                                            />
                                            <span className="font-display text-sm font-semibold tabular-nums">
                                                {index + 1}
                                            </span>
                                        </div>
                                    </TableCell>

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

                                    <TableCell className="hidden max-w-0 truncate text-sm text-muted-foreground sm:table-cell">
                                        {item.requested_by_name ?? (
                                            <Badge variant="neutral">
                                                Autofill
                                            </Badge>
                                        )}
                                    </TableCell>

                                    <TableCell className="hidden text-xs whitespace-nowrap text-muted-foreground md:table-cell">
                                        {item.created_at}
                                    </TableCell>

                                    <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                        {item.song.duration_seconds
                                            ? fmtTime(
                                                  item.song.duration_seconds,
                                              )
                                            : '—'}
                                    </TableCell>

                                    <TableCell>
                                        {/* Buttons as well as the drag handle:
                                            HTML5 drag is pointer-only, and the
                                            queue has to be reorderable from a
                                            keyboard. */}
                                        <div className="flex items-center justify-end gap-0.5">
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                disabled={index === 0}
                                                onClick={() => nudge(index, -1)}
                                                aria-label={`Move ${item.song.title} up`}
                                            >
                                                <ChevronUp className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                disabled={
                                                    index === order.length - 1
                                                }
                                                onClick={() => nudge(index, 1)}
                                                aria-label={`Move ${item.song.title} down`}
                                            >
                                                <ChevronDown className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7"
                                                disabled={index === 0}
                                                onClick={() => playNext(item)}
                                                aria-label={`Play ${item.song.title} next`}
                                            >
                                                <ArrowUpToLine className="size-4" />
                                            </Button>
                                            <Button
                                                size="icon"
                                                variant="ghost"
                                                className="size-7 text-muted-foreground hover:text-destructive"
                                                onClick={() => remove(item)}
                                                aria-label={`Remove ${item.song.title}`}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </div>
        </AdminLayout>
    );
}
