export interface Recent {
    songId: number;
    title: string;
    artist: string;
    ts: number;
}

const KEY = 'fm.recents';
const CAP = 8;

/** Recently-requested tracks, most-recent first. Safe in private mode (returns []). */
export function getRecents(): Recent[] {
    try {
        const raw = localStorage.getItem(KEY);

        if (!raw) {
            return [];
        }

        const parsed = JSON.parse(raw) as Recent[];

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

/** Record a request; dedupes by song, moves it to the front, caps the list. */
export function pushRecent(r: Omit<Recent, 'ts'>): Recent[] {
    const next = [
        { ...r, ts: Date.now() },
        ...getRecents().filter((x) => x.songId !== r.songId),
    ].slice(0, CAP);

    try {
        localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        /* ignore */
    }

    return next;
}
