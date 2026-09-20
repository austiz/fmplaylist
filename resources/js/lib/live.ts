import { withStation } from '@/lib/station';
import type { NowPlayingData, PiStatus } from '@/types/fm';

export interface ChatMsg {
    id: number;
    name: string;
    message: string;
    created_at: string;
}

/**
 * One poll of `/api/live`. Everything past `v` and `listeners` is present only when
 * the cursor moved — an unchanged station answers with the first three fields alone.
 */
export interface LiveFrame {
    v: string;
    listeners: number;
    poll_seconds: number;
    now_playing?: NowPlayingData | null;
    pi_status?: PiStatus | null;
    queue_version?: string;
    chat?: ChatMsg[];
}

export interface LiveOptions {
    stationSlug?: string | null;
    /**
     * Identifies this tab in the listener count. Pass null for a page that watches
     * the station without being an audience for it, like the admin status bar.
     */
    clientId?: string | null;
    /** Highest chat id already held, so the server sends only what is new. */
    since?: () => number | null;
    onFrame: (frame: LiveFrame) => void;
    onConnectedChange?: (connected: boolean) => void;
}

/**
 * Watch a station's live state.
 *
 * This replaced an EventSource. The stream held a PHP worker open per tab for 55
 * seconds while its own server loop slept two seconds between checks, so it bought no
 * latency over polling and shared a worker pool with the transmitter's API.
 *
 * Polling stops while the tab is hidden and resumes with an immediate poll when it
 * comes back, so a wall of forgotten tabs costs nothing. Each response carries the
 * interval the server wants, which is what actually paces the loop.
 *
 * @returns an unsubscribe function
 */
export function subscribeLive(options: LiveOptions): () => void {
    const { stationSlug, clientId, since, onFrame, onConnectedChange } =
        options;

    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;
    let cursor: string | null = null;
    let intervalMs = 3000;

    const schedule = () => {
        clearTimeout(timer);

        if (!stopped && !document.hidden) {
            timer = setTimeout(poll, intervalMs);
        }
    };

    const poll = async () => {
        if (stopped || document.hidden) {
            return;
        }

        controller?.abort();
        controller = new AbortController();

        const params = new URLSearchParams();

        if (cursor) {
            params.set('v', cursor);
        }

        if (clientId) {
            params.set('c', clientId);
        }

        const sinceId = since?.();

        if (sinceId) {
            params.set('since', String(sinceId));
        }

        const url = withStation(`/api/live?${params.toString()}`, stationSlug);

        try {
            const res = await fetch(url, { signal: controller.signal });

            if (!res.ok) {
                throw new Error(`live poll failed: ${res.status}`);
            }

            const frame: LiveFrame = await res.json();

            cursor = frame.v;
            intervalMs = Math.max(1000, frame.poll_seconds * 1000);
            onConnectedChange?.(true);
            onFrame(frame);
        } catch (e) {
            // An abort is us tearing down, not the server being unreachable.
            if (!(e instanceof DOMException && e.name === 'AbortError')) {
                onConnectedChange?.(false);
            }
        } finally {
            schedule();
        }
    };

    const onVisibility = () => {
        if (document.hidden) {
            clearTimeout(timer);
        } else {
            poll();
        }
    };

    document.addEventListener('visibilitychange', onVisibility);
    poll();

    return () => {
        stopped = true;
        clearTimeout(timer);
        controller?.abort();
        document.removeEventListener('visibilitychange', onVisibility);
    };
}

/**
 * A stable id for this tab, so the listener count is a count of tabs rather than of
 * requests. Kept in sessionStorage so a reload is the same listener, and regenerated
 * silently where storage is blocked — a private window still counts, just as a new
 * listener each time it reloads.
 */
export function listenerId(): string {
    const key = 'fm.listener_id';

    try {
        const existing = sessionStorage.getItem(key);

        if (existing) {
            return existing;
        }

        const id = crypto.randomUUID();
        sessionStorage.setItem(key, id);

        return id;
    } catch {
        return crypto.randomUUID();
    }
}
