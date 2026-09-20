export type CommutePhase = 'dawn' | 'morning' | 'midday' | 'evening' | 'night';

/**
 * Which part of the San Diego commute we're in, by local hour. Tuned to the I-5
 * rhythm: dawn glow, the morning push, flat midday, the evening crawl, then night.
 *
 * This used to also carry a hex accent, a glow hue and three linear-RGB stops for
 * the WebGL field -- an art-direction table for a shader that no longer exists.
 * What survives is the part a listener actually read: the name of the hour.
 */
export function getCommutePhase(date: Date = new Date()): CommutePhase {
    const h = date.getHours();

    if (h >= 5 && h < 7) {
        return 'dawn';
    }

    if (h >= 7 && h < 10) {
        return 'morning';
    }

    if (h >= 10 && h < 15) {
        return 'midday';
    }

    if (h >= 15 && h < 19) {
        return 'evening';
    }

    return 'night';
}

const LABELS: Record<CommutePhase, string> = {
    dawn: 'Dawn Patrol',
    morning: 'Morning Drive',
    midday: 'Midday Cruise',
    evening: 'Evening Crawl',
    night: 'After Hours',
};

/** Short broadcast-style label for the current hour, e.g. "MORNING DRIVE". */
export function getCommuteLabel(date: Date = new Date()): string {
    return LABELS[getCommutePhase(date)];
}
