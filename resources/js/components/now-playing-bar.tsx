import { SplitFlapText } from '@/components/split-flap-text';
import { useElapsed } from '@/hooks/use-elapsed';
import { useFmLive } from '@/hooks/use-fm-live';
import { fmtTime } from '@/lib/format';
import type { NowPlayingData, PiStatus } from '@/types/fm';

const BARS = [
    'animate-bar-a',
    'animate-bar-b',
    'animate-bar-c',
    'animate-bar-d',
    'animate-bar-e',
] as const;
const IDLE_HEIGHTS = [40, 65, 30, 80, 50];

function PiDot({ status }: { status: PiStatus }) {
    if (!status.online) {
        return (
            <span className="flex items-center gap-1 font-display text-[10px] font-bold tracking-widest text-muted-foreground/40 uppercase">
                <span className="inline-block h-1.5 w-1.5 rounded-full bg-muted-foreground/30" />
                OFFLINE
            </span>
        );
    }

    if (status.status === 'live') {
        return (
            <span className="flex items-center gap-1 font-display text-[10px] font-bold tracking-widest text-live uppercase">
                <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-live" />
                LIVE
            </span>
        );
    }

    return (
        <span className="flex items-center gap-1 font-display text-[10px] font-bold tracking-widest text-online/70 uppercase">
            <span className="inline-block h-1.5 w-1.5 rounded-full bg-online" />
            ON AIR
        </span>
    );
}

/**
 * The now-playing showpiece. Reads the shared live state (no SSE of its own) and simulates
 * the playhead with useElapsed so the progress bar advances in real time even though the
 * browser has no audio. The "your song is on air" celebration lives in <Celebration/>.
 */
export function NowPlayingBar({ initial }: { initial: NowPlayingData | null }) {
    const { nowPlaying, piStatus } = useFmLive();
    const data = nowPlaying === undefined ? initial : nowPlaying;
    const pi = piStatus;
    const isLive = pi?.status === 'live';

    const { elapsed, duration, progress } = useElapsed(
        data?.started_at,
        data?.song?.duration_seconds,
    );
    const showProgress = data?.type === 'song' && duration > 0;
    const accent = isLive ? 'text-live' : 'text-playing';
    const barColor = isLive ? 'bg-live' : 'bg-playing';

    return (
        <div className="space-y-2">
            {pi && <PiDot status={pi} />}

            {!data?.song ? (
                <div className="flex items-center gap-4 border border-dashed border-border bg-card/70 px-4 py-3 text-sm text-muted-foreground backdrop-blur-sm">
                    <div className="flex h-5 shrink-0 items-end gap-0.75">
                        {IDLE_HEIGHTS.map((h, i) => (
                            <span
                                key={i}
                                className="inline-block w-0.75 rounded-full bg-muted-foreground/20"
                                style={{ height: `${h}%` }}
                            />
                        ))}
                    </div>
                    {isLive
                        ? 'Live broadcast in progress'
                        : 'Dead air. Request something.'}
                </div>
            ) : (
                <div className="overflow-hidden border border-border bg-card/70 backdrop-blur-md">
                    <div className="flex items-center gap-4 px-4 py-3">
                        <div className="flex h-5 shrink-0 items-end gap-0.75">
                            {BARS.map((cls, i) => (
                                <span
                                    key={i}
                                    className={`inline-block h-full w-0.75 origin-bottom rounded-full ${barColor} ${cls}`}
                                />
                            ))}
                        </div>
                        <div className="min-w-0 flex-1">
                            <SplitFlapText
                                text={data.song.title}
                                className="block truncate text-sm font-semibold text-foreground"
                            />
                            {data.song.artist && (
                                <p className="truncate text-xs text-muted-foreground">
                                    {data.song.artist}
                                </p>
                            )}
                        </div>
                        <span
                            className={`ml-auto shrink-0 font-display text-xs font-bold tracking-widest uppercase ${accent}`}
                        >
                            {isLive ? 'Live' : 'On Air'}
                        </span>
                    </div>

                    {showProgress && (
                        <div className="px-4 pb-2.5">
                            <div className="h-0.5 w-full overflow-hidden rounded-full bg-border">
                                <div
                                    className={`h-full ${barColor} transition-[width] duration-200 ease-linear`}
                                    style={{ width: `${progress * 100}%` }}
                                />
                            </div>
                            <div className="mt-1 flex justify-between font-display text-[10px] text-muted-foreground/60 tabular-nums">
                                <span>{fmtTime(elapsed)}</span>
                                <span>{fmtTime(duration)}</span>
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
