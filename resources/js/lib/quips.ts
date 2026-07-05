import type { CommutePhase } from '@/lib/commute';

/**
 * San Diego / I-5 commute one-liners, pirate-radio voice. Phase-aware so the copy matches
 * the drive you're actually on. Rotated deterministically by the hour so it never flickers
 * on re-render but still feels alive across the day.
 */
const QUIPS: Record<CommutePhase, string[]> = {
    dawn: [
        'Sun’s not up. Neither is Caltrans. Beat the merge.',
        'Empty freeway, full queue. This is the good hour.',
        'Coffee in the cupholder, banger in the queue. Roll out.',
    ],
    morning: [
        'Merge onto the 5. Merge into the queue.',
        'Brake lights past Balboa? At least the tunes don’t stop.',
        'No ads, no morning-show yelling. Just your songs. Drive.',
        'Carmel Valley’s crawling. Cue something loud.',
    ],
    midday: [
        'Off-peak and off the grid. Request whatever you want.',
        'Lunch run down the 5 hits different with the right track.',
        'The suits are stuck in meetings. You’re stuck in traffic. We win.',
    ],
    evening: [
        'The 805 split is a parking lot — at least the tracks don’t brake.',
        'Five o’clock crawl. Soundtrack it, don’t suffer it.',
        'Bumper to bumper to the beach. Load up the queue.',
        'Sitting on the 5 south? Make the gridlock groove.',
    ],
    night: [
        'Empty lanes, neon dash. Send it.',
        'Late drive, loud speakers, zero corporate playlist. Perfect.',
        'The freeway’s yours after dark. So is the aux.',
    ],
};

const TAGLINES: Record<CommutePhase, string> = {
    dawn: 'Request a track. Hear it before the sun’s all the way up.',
    morning:
        'No algorithms. No ads. No bullshit. Request a track, hear it live.',
    midday: 'Your station, your songs — straight to the freeway.',
    evening: 'Turn the crawl into a concert. Request a track, hear it live.',
    night: 'After-hours radio. You pick, we broadcast.',
};

/** Stable-per-hour pick so copy doesn't jitter on re-render. */
function hourlyIndex(len: number): number {
    return Math.floor(Date.now() / 3_600_000) % len;
}

export function getQuip(phase: CommutePhase): string {
    const list = QUIPS[phase];

    return list[hourlyIndex(list.length)];
}

export function getTagline(phase: CommutePhase): string {
    return TAGLINES[phase];
}
