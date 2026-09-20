import type { BroadcastPiStatus } from '@/types/fm';

export function PiStatusCard({
    pi,
    nowPlaying,
}: {
    pi: BroadcastPiStatus;
    nowPlaying: { title: string; artist: string; type: string } | null;
}) {
    const piLive = pi.status === 'live';

    return (
        <div
            className={`mb-5 border p-4 ${pi.online ? 'border-green-500/30 bg-green-500/5' : 'border-border bg-card'}`}
        >
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Pi Status
                    </p>
                    <div className="mt-2 flex flex-wrap items-center gap-4">
                        <span
                            className={`flex items-center gap-1.5 text-sm font-bold ${pi.online ? 'text-green-400' : 'text-muted-foreground/40'}`}
                        >
                            <span
                                className={`h-2 w-2 rounded-full ${pi.online ? 'bg-green-400' : 'bg-muted-foreground/30'}`}
                            />
                            {pi.online ? 'Connected' : 'Offline'}
                        </span>
                        <span
                            className={`flex items-center gap-1.5 text-sm font-bold ${pi.status === 'playing' ? 'text-red-400' : 'text-muted-foreground/30'}`}
                        >
                            <span
                                className={`h-2 w-2 rounded-full ${pi.status === 'playing' ? 'animate-pulse bg-red-400' : 'bg-muted-foreground/20'}`}
                            />
                            Playing
                        </span>
                        <span
                            className={`flex items-center gap-1.5 text-sm font-bold ${piLive ? 'text-violet-400' : 'text-muted-foreground/30'}`}
                        >
                            <span
                                className={`h-2 w-2 rounded-full ${piLive ? 'animate-pulse bg-violet-400' : 'bg-muted-foreground/20'}`}
                            />
                            Live
                        </span>
                    </div>
                    {pi.online && pi.ip && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            Pi IP:{' '}
                            <span className="font-mono text-foreground">
                                {pi.ip}
                            </span>
                        </p>
                    )}
                    {pi.last_seen && (
                        <p className="mt-0.5 text-xs text-muted-foreground/50">
                            Last seen {pi.last_seen}
                        </p>
                    )}
                </div>
                {nowPlaying && (
                    <div className="text-right">
                        <p className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                            Now Playing
                        </p>
                        <p className="mt-1 text-sm font-semibold text-foreground">
                            {nowPlaying.title}
                        </p>
                        {nowPlaying.artist && (
                            <p className="text-xs text-muted-foreground">
                                {nowPlaying.artist}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
