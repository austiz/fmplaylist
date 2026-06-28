import { Link, usePage } from '@inertiajs/react';
import { NowPlayingBar } from '@/components/now-playing-bar';
import { PublicLayout } from '@/components/public-layout';
import { Button } from '@/components/ui/button';
import type { NowPlayingData, QueueItem } from '@/types/fm';

interface Props {
    nowPlaying: NowPlayingData | null;
    queue: QueueItem[];
    queueCount: number;
}

export default function Home({ nowPlaying, queue, queueCount }: Props) {
    const { props } = usePage<{ flash: { success?: string }; frequency: string }>();
    const flash = props.flash;
    const freq = props.frequency ?? '96.9';

    return (
        <PublicLayout>
            <div className="space-y-6">
                {/* Hero */}
                <div className="relative overflow-hidden border-b border-border pb-6 space-y-2">
                    {/* Breathing red glow — GPU-animated via @property */}
                    <div
                        className="pointer-events-none absolute -top-20 left-1/2 h-64 w-96 -translate-x-1/2 rounded-full blur-3xl"
                        style={{
                            background: `radial-gradient(ellipse, oklch(0.55 0.24 27 / var(--glow-opacity, 0.1)), transparent)`,
                            animation: 'hero-breathe 4s ease-in-out infinite',
                        }}
                    />
                    <p className="relative font-display text-xs font-bold uppercase tracking-[0.25em] text-red-500">
                        On Air · {freq} FM
                    </p>
                    <h1 className="relative font-display text-4xl font-bold leading-tight text-foreground sm:text-5xl">
                        Your station.<br />Your songs.
                    </h1>
                    <p className="relative max-w-sm text-base text-muted-foreground">
                        No algorithms. No ads. No bullshit. Request a track, hear it live on {freq}.
                    </p>
                </div>

                <NowPlayingBar initial={nowPlaying} />

                {flash?.success && (
                    <div className="border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                        {flash.success}
                    </div>
                )}

                {/* Request CTA */}
                <div className="border border-border bg-card p-4">
                    <h2 className="font-display text-lg font-bold text-foreground">Request a Song</h2>
                    <p className="mb-4 mt-1 text-sm text-muted-foreground">
                        Browse the{' '}
                        <Link href="/songs" className="text-red-500 hover:underline">
                            full library
                        </Link>{' '}
                        and add anything to the queue.
                    </p>
                    <Link href="/songs">
                        <Button className="bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700">
                            Browse Songs →
                        </Button>
                    </Link>
                </div>

                {/* Queue preview */}
                {queue.length > 0 && (
                    <div>
                        <div className="mb-4 flex items-center justify-between">
                            <h2 className="font-display text-sm font-bold uppercase tracking-widest text-muted-foreground">
                                Up Next ({queueCount})
                            </h2>
                            <Link href="/queue" className="text-xs font-medium text-red-500 hover:underline">
                                Full queue →
                            </Link>
                        </div>
                        <div className="divide-y divide-border border border-border">
                            {queue.map((item) => (
                                <div key={item.id} className="flex items-center gap-4 px-4 py-3">
                                    <span className="font-display w-6 shrink-0 text-center text-sm font-bold tabular-nums text-red-500/60">
                                        {item.position}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-foreground">
                                            {item.song.title}
                                        </p>
                                        {item.song.artist && (
                                            <p className="truncate text-xs text-muted-foreground">{item.song.artist}</p>
                                        )}
                                    </div>
                                    {item.requested_by_name && (
                                        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                            {item.requested_by_name}
                                        </span>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </PublicLayout>
    );
}
