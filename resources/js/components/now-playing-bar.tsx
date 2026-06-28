import { useEffect, useRef, useState } from 'react';
import type { NowPlayingData, PiStatus } from '@/types/fm';

const BARS = ['animate-bar-a', 'animate-bar-b', 'animate-bar-c', 'animate-bar-d', 'animate-bar-e'] as const;
const IDLE_HEIGHTS = [40, 65, 30, 80, 50];

function PiDot({ status }: { status: PiStatus }) {
    if (!status.online) {
        return (
            <span className="flex items-center gap-1 font-display text-[10px] font-bold uppercase tracking-widest text-muted-foreground/40">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground/30" />
                OFFLINE
            </span>
        );
    }

    if (status.status === 'live') {
        return (
            <span className="flex items-center gap-1 font-display text-[10px] font-bold uppercase tracking-widest text-violet-400">
                <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-violet-400" />
                LIVE
            </span>
        );
    }

    return (
        <span className="flex items-center gap-1 font-display text-[10px] font-bold uppercase tracking-widest text-green-500/70">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-green-500" />
            ON AIR
        </span>
    );
}

export function NowPlayingBar({ initial }: { initial: NowPlayingData | null }) {
    const [data, setData] = useState<NowPlayingData | null>(initial);
    const [pi, setPi] = useState<PiStatus | null>(null);
    const [myRequestTitle, setMyRequestTitle] = useState<string | null>(null);
    const toastTimer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        let es: EventSource;

        const connect = () => {
            es = new EventSource('/api/events');

            es.addEventListener('now-playing', (e) => {
                const newData: NowPlayingData = JSON.parse(e.data);
                setData(newData);

                // Check if this is the song the user requested
                try {
                    const stored = localStorage.getItem('fm.my_request');
                    if (stored && newData?.song?.id) {
                        const { songId, title } = JSON.parse(stored) as { songId: number; title: string };
                        if (newData.song.id === songId) {
                            localStorage.removeItem('fm.my_request');
                            setMyRequestTitle(title);
                            clearTimeout(toastTimer.current);
                            toastTimer.current = setTimeout(() => setMyRequestTitle(null), 8000);
                        }
                    }
                } catch { /* ignore */ }
            });

            es.addEventListener('pi-status', (e) => {
                setPi(JSON.parse(e.data));
            });

            // EventSource auto-reconnects on error; no manual retry needed
        };

        connect();
        return () => { es?.close(); clearTimeout(toastTimer.current); };
    }, []);

    const isLive = pi?.status === 'live';

    return (
        <>
            {myRequestTitle && (
                <div className="fixed bottom-4 left-4 right-4 z-50 border border-green-500/30 bg-background px-4 py-3 shadow-xl sm:left-auto sm:right-6 sm:w-80">
                    <div className="flex items-start gap-3">
                        <div className="flex h-5 shrink-0 items-end gap-0.75 pt-0.5">
                            {BARS.map((cls, i) => (
                                <span key={i} className={`inline-block h-full w-0.75 origin-bottom rounded-full bg-green-400 ${cls}`} />
                            ))}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="font-display text-xs font-bold uppercase tracking-wider text-green-400">Your song is on air!</p>
                            <p className="mt-0.5 truncate text-sm font-medium text-foreground">{myRequestTitle}</p>
                        </div>
                        <button
                            onClick={() => { clearTimeout(toastTimer.current); setMyRequestTitle(null); }}
                            className="shrink-0 text-muted-foreground/40 hover:text-foreground transition-colors"
                        >
                            ✕
                        </button>
                    </div>
                </div>
            )}

            <div className="space-y-2">
                {pi && <PiDot status={pi} />}

                {!data?.song ? (
                    <div className="flex items-center gap-4 border border-dashed border-border bg-card px-4 py-3 text-sm text-muted-foreground">
                        <div className="flex h-5 shrink-0 items-end gap-0.75">
                            {IDLE_HEIGHTS.map((h, i) => (
                                <span key={i} className="inline-block w-0.75 rounded-full bg-muted-foreground/20" style={{ height: `${h}%` }} />
                            ))}
                        </div>
                        {isLive ? 'Live broadcast in progress' : 'Nothing playing right now'}
                    </div>
                ) : (
                    <div className="flex items-center gap-4 border border-border bg-card px-4 py-3">
                        <div className="flex h-5 shrink-0 items-end gap-0.75">
                            {BARS.map((cls, i) => (
                                <span key={i} className={`inline-block h-full w-0.75 origin-bottom rounded-full ${isLive ? 'bg-violet-400' : 'bg-red-500'} ${cls}`} />
                            ))}
                        </div>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-foreground">{data.song.title}</p>
                            {data.song.artist && <p className="truncate text-xs text-muted-foreground">{data.song.artist}</p>}
                        </div>
                        <span className={`ml-auto shrink-0 font-display text-xs font-bold uppercase tracking-widest ${isLive ? 'text-violet-400' : 'text-red-500'}`}>
                            {isLive ? 'Live' : 'On Air'}
                        </span>
                    </div>
                )}
            </div>
        </>
    );
}
