import { router } from '@inertiajs/react';
import { AdminLayout } from '@/components/admin-layout';

interface Props {
    nowPlaying: { type: string; song: { title: string; artist: string }; started_at: string } | null;
    queueDepth: number;
    queueRuntimeSeconds: number;
    recentRequests: {
        id: number; status: string; requested_by_name: string | null;
        created_at: string; played_at: string | null;
        song: { title: string; artist: string };
    }[];
    stats: { requestsToday: number; songsPlayedToday: number };
}

function formatRuntime(seconds: number): string {
    if (seconds <= 0) return '—';
    const h = Math.floor(seconds / 3600);
    const m = Math.ceil((seconds % 3600) / 60);
    return h > 0 ? `${h}h ${m}m` : `${m} min`;
}

export default function Dashboard({ nowPlaying, queueDepth, queueRuntimeSeconds, recentRequests, stats }: Props) {
    const deleteRequest = (id: number) => {
        if (!confirm('Remove this request from the queue?')) return;
        router.delete(`/admin/queue/${id}`);
    };

    return (
        <AdminLayout title="Dashboard">
            <div className="grid gap-4 sm:grid-cols-4">
                <div className="border border-border bg-card p-4">
                    <p className="font-display text-xs font-bold uppercase tracking-wider text-muted-foreground">Queue</p>
                    <p className="mt-1 font-display text-3xl font-bold text-foreground">{queueDepth}</p>
                    <p className="text-sm text-muted-foreground">songs waiting</p>
                </div>
                <div className="border border-border bg-card p-4">
                    <p className="font-display text-xs font-bold uppercase tracking-wider text-muted-foreground">Wait time</p>
                    <p className="mt-1 font-display text-3xl font-bold text-foreground">{formatRuntime(queueRuntimeSeconds)}</p>
                    <p className="text-sm text-muted-foreground">until queue clears</p>
                </div>
                <div className="border border-border bg-card p-4">
                    <p className="font-display text-xs font-bold uppercase tracking-wider text-muted-foreground">Requests today</p>
                    <p className="mt-1 font-display text-3xl font-bold text-foreground">{stats.requestsToday}</p>
                </div>
                <div className="border border-border bg-card p-4">
                    <p className="font-display text-xs font-bold uppercase tracking-wider text-muted-foreground">Played today</p>
                    <p className="mt-1 font-display text-3xl font-bold text-foreground">{stats.songsPlayedToday}</p>
                </div>
            </div>

            {nowPlaying && (
                <div className="mt-4 border border-red-900/40 bg-red-950/20 p-4">
                    <p className="font-display text-xs font-bold uppercase tracking-wider text-red-500">Now Broadcasting</p>
                    <p className="mt-1 text-lg font-bold text-foreground">{nowPlaying.song.title}</p>
                    {nowPlaying.song.artist && <p className="text-sm text-muted-foreground">{nowPlaying.song.artist}</p>}
                </div>
            )}

            <div className="mt-4">
                <h2 className="mb-2 font-display text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Recent Requests
                </h2>
                <div className="divide-y divide-border border border-border bg-card">
                    {recentRequests.map((item) => (
                        <div key={item.id} className="flex items-center gap-4 px-4 py-2.5">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-foreground">{item.song.title}</p>
                                <p className="text-xs text-muted-foreground">
                                    {item.requested_by_name && <span className="mr-1 text-foreground/60">{item.requested_by_name} ·</span>}
                                    {item.created_at}
                                </p>
                            </div>
                            <span className={`shrink-0 px-2 py-0.5 text-xs font-bold uppercase tracking-wide ${
                                item.status === 'played'  ? 'bg-green-500/15 text-green-400' :
                                item.status === 'playing' ? 'bg-red-500/15 text-red-400' :
                                item.status === 'pending' ? 'bg-secondary text-muted-foreground' :
                                'bg-secondary text-muted-foreground/50'
                            }`}>{item.status}</span>
                            {item.status === 'pending' && (
                                <button
                                    onClick={() => deleteRequest(item.id)}
                                    className="shrink-0 px-2 py-0.5 text-xs text-muted-foreground/40 hover:text-red-400 transition-colors"
                                    title="Remove from queue"
                                >
                                    ✕
                                </button>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </AdminLayout>
    );
}
