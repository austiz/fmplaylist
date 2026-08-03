interface ReactionStore {
    [queueItemId: string]: number;
}

const KEY = 'fm.reactions';
const CAP = 50;

function readStore(): ReactionStore {
    try {
        const raw = localStorage.getItem(KEY);

        if (!raw) {
            return {};
        }

        const parsed = JSON.parse(raw) as ReactionStore;

        return typeof parsed === 'object' && parsed !== null ? parsed : {};
    } catch {
        return {};
    }
}

function writeStore(store: ReactionStore): void {
    try {
        localStorage.setItem(KEY, JSON.stringify(store));
    } catch {
        /* ignore */
    }
}

/**
 * Your own reaction taps on a queue item — local-only, per-browser. This is a fast-to-ship
 * flourish, not shared social proof: two listeners looking at the same item will see
 * different counts, since nothing here talks to the server.
 */
export function getReaction(queueItemId: number): number {
    return readStore()[String(queueItemId)] ?? 0;
}

/** Bump your own reaction count for a queue item; caps total tracked items so storage doesn't grow unbounded. */
export function bumpReaction(queueItemId: number): number {
    const store = readStore();
    const key = String(queueItemId);
    const next = (store[key] ?? 0) + 1;
    const entries = Object.entries(store).filter(([k]) => k !== key);

    entries.push([key, next]);
    writeStore(Object.fromEntries(entries.slice(-CAP)));

    return next;
}
