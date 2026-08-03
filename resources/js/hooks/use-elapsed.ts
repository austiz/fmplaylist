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
    if (!startedAt) {
        return 0;
    }

    const ms = Date.now() - Date.parse(startedAt);

    return ms > 0 ? ms / 1000 : 0;
}

/**
 * Simulates playback progress client-side from `started_at` + `duration_seconds` — the
 * browser has no audio (the Pi broadcasts over FM), so this is our source of "playhead".
 *
 * `elapsed` is pure `Date.now() - startedAt`, so it's derived directly in the render body
 * rather than stored in state — the effect only holds a 250ms heartbeat tick to force a
 * re-render. That avoids the "setState synchronously in an effect" anti-pattern and the
 * up-to-250ms stale window that a stored-state version would show right after a track change.
 */
export function useElapsed(
    startedAt: string | null | undefined,
    durationSeconds: number | null | undefined,
): Elapsed {
    const [, setTick] = useState(0);

    useEffect(() => {
        const id = setInterval(() => setTick((t) => (t + 1) % 1_000_000), 250);

        return () => clearInterval(id);
    }, []);

    const elapsed = secondsSince(startedAt);
    const duration = durationSeconds ?? 0;
    const progress = duration > 0 ? Math.min(1, elapsed / duration) : 0;

    return { elapsed, duration, progress };
}
