import { useEffect, useState } from 'react';

interface Elapsed {
    /** Seconds since the track started (clamped ≥ 0). */
    elapsed: number;
    /** Track length in seconds (0 if unknown). */
    duration: number;
    /** 0‥1 fraction of the way through the track (0 when duration is unknown). */
    progress: number;
}

function secondsSince(startedAt: string | null | undefined): number {
    if (!startedAt) return 0;
    const ms = Date.now() - Date.parse(startedAt);
    return ms > 0 ? ms / 1000 : 0;
}

/**
 * Simulates playback progress client-side from `started_at` + `duration_seconds` — the
 * browser has no audio (the Pi broadcasts over FM), so this is our source of "playhead".
 * Ticks at 4Hz (enough for a smooth ring, cheap for React) and re-syncs whenever the
 * track changes.
 */
export function useElapsed(startedAt: string | null | undefined, durationSeconds: number | null | undefined): Elapsed {
    const [elapsed, setElapsed] = useState(() => secondsSince(startedAt));

    useEffect(() => {
        setElapsed(secondsSince(startedAt));
        const id = setInterval(() => setElapsed(secondsSince(startedAt)), 250);
        return () => clearInterval(id);
    }, [startedAt]);

    const duration = durationSeconds ?? 0;
    const progress = duration > 0 ? Math.min(1, elapsed / duration) : 0;
    return { elapsed, duration, progress };
}
