/**
 * Display formatters shared across the listener and admin pages.
 *
 * Each of these existed in two or three places with the same body; the copies
 * had already started to drift on their empty-value spelling.
 */

/** A duration as `m:ss`. Negative and fractional inputs are clamped, not NaN. */
export function fmtTime(sec: number): string {
    const s = Math.max(0, Math.floor(sec));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

/** A `YYYY-MM-DD` day as a short weekday name, read in the viewer's locale. */
export function shortDay(iso: string): string {
    // The explicit midnight keeps a bare date from being parsed as UTC and
    // landing on the previous day west of Greenwich.
    return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'short',
    });
}

/** A span of airtime, coarse enough to read at a glance. */
export function formatRuntime(seconds: number): string {
    if (seconds <= 0) {
        return '—';
    }

    const h = Math.floor(seconds / 3600);
    const m = Math.ceil((seconds % 3600) / 60);

    return h > 0 ? `${h}h ${m}m` : `${m} min`;
}

/** Disk space as the Pi reports it: GB once there is a gigabyte to show. */
export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    const gb = bytes / 1024 ** 3;

    return gb >= 1
        ? `${gb.toFixed(1)} GB`
        : `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}
