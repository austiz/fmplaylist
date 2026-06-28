import { Link, router } from '@inertiajs/react';
import { AdminLayout } from '@/components/admin-layout';
import type { PaginatedResponse } from '@/types/fm';

interface HistoryItem {
    id: number; status: string; requested_by_name: string | null;
    created_at: string; played_at: string | null;
    song: { title: string; artist: string };
}

interface Props {
    items: PaginatedResponse<HistoryItem>;
    filter: string;
}

const filters = ['all', 'pending', 'playing', 'played', 'skipped'];

export default function History({ items, filter }: Props) {
    return (
        <AdminLayout title="Request History">
            <div className="mb-4 flex gap-2">
                {filters.map((f) => (
                    <button
                        key={f}
                        onClick={() => router.get('/admin/history', { filter: f }, { preserveState: true })}
                        className={`rounded-full px-3 py-1 text-sm font-medium capitalize ${
                            filter === f
                                ? 'bg-red-600 text-white'
                                : 'border border-zinc-300 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400'
                        }`}
                    >
                        {f}
                    </button>
                ))}
            </div>

            <div className="divide-y divide-zinc-100 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                {items.data.length === 0 && (
                    <p className="px-4 py-8 text-center text-sm text-zinc-400">No items.</p>
                )}
                {items.data.map((item) => (
                    <div key={item.id} className="flex items-center gap-4 px-4 py-3">
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-zinc-900 dark:text-white">{item.song.title}</p>
                            <p className="text-xs text-zinc-500">
                                Requested {item.created_at}
                                {item.requested_by_name ? ` by ${item.requested_by_name}` : ''}
                            </p>
                        </div>
                        {item.played_at && <span className="shrink-0 text-xs text-zinc-400">Played {item.played_at}</span>}
                        <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                            item.status === 'played' ? 'bg-green-100 text-green-700' :
                            item.status === 'playing' ? 'bg-red-100 text-red-700' :
                            item.status === 'pending' ? 'bg-zinc-100 text-zinc-600' :
                            'bg-zinc-100 text-zinc-400'
                        }`}>{item.status}</span>
                    </div>
                ))}
            </div>

            {items.last_page > 1 && (
                <div className="mt-4 flex justify-center gap-1">
                    {items.links.map((link, i) => (
                        link.url ? (
                            <Link key={i} href={link.url} className={`px-3 py-1 text-sm rounded ${link.active ? 'bg-red-600 text-white' : 'border text-zinc-600 hover:bg-zinc-50'}`}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                        ) : (
                            <span key={i} className="px-3 py-1 text-sm text-zinc-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                        )
                    ))}
                </div>
            )}
        </AdminLayout>
    );
}
