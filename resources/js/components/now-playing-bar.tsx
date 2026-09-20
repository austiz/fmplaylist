import { Radio } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { useElapsed } from '@/hooks/use-elapsed';
import { useFmLive } from '@/hooks/use-fm-live';
import { fmtTime } from '@/lib/format';
import type { NowPlayingData } from '@/types/fm';

/**
 * The now-playing showpiece. Reads the shared live state (no poll of its own) and
 * simulates the playhead with useElapsed so the progress bar advances in real time
 * even though the browser has no audio.
 *
 * The title used to animate in on a hand-built split-flap display and the state was
 * shown as a five-bar CSS equalizer. Both are gone in favour of the same
 * `Badge variant="playing" dot pulse` the operator's sidebar uses, so "on air" now
 * looks identical whether you are listening or running the station.
 */
export function NowPlayingBar({ initial }: { initial: NowPlayingData | null }) {
    const { nowPlaying, piStatus } = useFmLive();
    const data = nowPlaying === undefined ? initial : nowPlaying;
    const isLive = piStatus?.status === 'live';
    const offline = piStatus ? !piStatus.online : false;

    const { elapsed, duration, progress } = useElapsed(
        data?.started_at,
        data?.song?.duration_seconds,
    );
    const showProgress = data?.type === 'song' && duration > 0;

    if (!data?.song) {
        return (
            <Card className="gap-0 border-dashed py-0">
                <div className="flex items-center gap-3 px-5 py-4 text-sm text-muted-foreground">
                    <Radio size={18} className="shrink-0 opacity-40" />
                    {offline
                        ? 'The transmitter is offline.'
                        : isLive
                          ? 'Live broadcast in progress.'
                          : 'Dead air. Request something.'}
                </div>
            </Card>
        );
    }

    return (
        <Card className="gap-0 overflow-hidden py-0">
            <div className="flex items-center gap-4 px-5 py-4">
                <div className="min-w-0 flex-1 space-y-1">
                    <p className="font-display text-[10px] font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                        Now playing
                    </p>
                    <p className="truncate font-display text-lg font-bold text-foreground">
                        {data.song.title}
                    </p>
                    {data.song.artist && (
                        <p className="truncate text-sm text-muted-foreground">
                            {data.song.artist}
                        </p>
                    )}
                </div>

                <Badge
                    variant={offline ? 'offline' : isLive ? 'live' : 'playing'}
                    dot
                    pulse={!offline}
                    className="shrink-0"
                >
                    {offline ? 'Offline' : isLive ? 'Live' : 'On Air'}
                </Badge>
            </div>

            {showProgress && (
                <div className="px-5 pb-4">
                    <div className="h-1 w-full overflow-hidden rounded-full bg-surface-3">
                        <div
                            className={`h-full rounded-full transition-[width] duration-200 ease-linear ${
                                isLive ? 'bg-live' : 'bg-playing'
                            }`}
                            style={{ width: `${progress * 100}%` }}
                        />
                    </div>
                    <div className="mt-1.5 flex justify-between font-display text-[11px] text-muted-foreground tabular-nums">
                        <span>{fmtTime(elapsed)}</span>
                        <span>{fmtTime(duration)}</span>
                    </div>
                </div>
            )}
        </Card>
    );
}
