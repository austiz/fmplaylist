export type CommutePhase = 'dawn' | 'morning' | 'midday' | 'evening' | 'night';

export interface CommutePalette {
    phase: CommutePhase;
    /** Short broadcast-style label, e.g. "MORNING DRIVE". */
    label: string;
    /** Secondary accent (red stays the brand anchor); used for phase glimmer. Hex. */
    accent: string;
    /** Hue (OKLCH) fed to the existing radial hero glow. */
    glowHue: number;
    /** Three colour stops for the WebGL field, linear-ish RGB in 0‥1. */
    viz: {
        low: [number, number, number];
        mid: [number, number, number];
        high: [number, number, number];
    };
}

/**
 * Which part of the San Diego commute we're in, by local hour. Tuned to the I-5 rhythm:
 * dawn glow, the morning push, flat midday, the evening crawl, then night.
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

const PALETTES: Record<CommutePhase, CommutePalette> = {
    dawn: {
        phase: 'dawn',
        label: 'Dawn Patrol',
        accent: '#ff9e64',
        glowHue: 40,
        viz: {
            low: [0.14, 0.1, 0.3],
            mid: [0.62, 0.22, 0.42],
            high: [1.0, 0.62, 0.34],
        },
    },
    morning: {
        phase: 'morning',
        label: 'Morning Drive',
        accent: '#ffb340',
        glowHue: 55,
        viz: {
            low: [0.2, 0.12, 0.15],
            mid: [0.9, 0.35, 0.2],
            high: [1.0, 0.78, 0.36],
        },
    },
    midday: {
        phase: 'midday',
        label: 'Midday Cruise',
        accent: '#ff5a4d',
        glowHue: 27,
        viz: {
            low: [0.15, 0.05, 0.08],
            mid: [0.82, 0.16, 0.16],
            high: [1.0, 0.52, 0.42],
        },
    },
    evening: {
        phase: 'evening',
        label: 'Evening Crawl',
        accent: '#ff6b9d',
        glowHue: 12,
        viz: {
            low: [0.18, 0.06, 0.22],
            mid: [0.86, 0.2, 0.32],
            high: [1.0, 0.46, 0.26],
        },
    },
    night: {
        phase: 'night',
        label: 'After Hours',
        accent: '#c774f0',
        glowHue: 330,
        viz: {
            low: [0.1, 0.03, 0.14],
            mid: [0.56, 0.1, 0.36],
            high: [0.92, 0.26, 0.52],
        },
    },
};

export function getCommutePalette(date: Date = new Date()): CommutePalette {
    return PALETTES[getCommutePhase(date)];
}
