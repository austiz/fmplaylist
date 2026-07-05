import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { Celebration } from '@/components/celebration';
import { FrequencyField } from '@/components/visualizer/frequency-field';
import { FmLiveProvider, useFmLive } from '@/hooks/use-fm-live';

// Full-bleed visualizer on the cockpit + drive views; subtle behind the reading-heavy lists.
const WEIGHT: Record<string, number> = {
    home: 1,
    drive: 1,
    songs: 0.4,
    queue: 0.45,
};

/**
 * Persistent layout for all listener pages. Because Inertia keeps this same instance mounted
 * across home/songs/queue/drive navigations, the SSE connection (FmLiveProvider) and the
 * WebGL context (FrequencyField) live once for the whole session — no reconnect/flicker.
 */
function FmLiveShell({ children }: { children: ReactNode }) {
    const { component } = usePage();
    const weight = WEIGHT[component] ?? 0.5;
    const { palette } = useFmLive();

    // Paint the commute palette onto CSS custom properties (drives the hero glow + accents).
    // `palette` comes from FmLiveProvider's shared 60s timer, so this stays in lockstep with
    // every other phase-aware consumer instead of rolling over on its own clock.
    useEffect(() => {
        const root = document.documentElement;
        root.style.setProperty('--phase-accent', palette.accent);
        root.style.setProperty('--phase-glow-hue', String(palette.glowHue));
    }, [palette]);

    return (
        <div className="relative min-h-screen">
            <FrequencyField
                weight={weight}
                className="fixed inset-0 z-0 h-full w-full"
            />
            <div className="relative z-10">{children}</div>
            <Celebration />
        </div>
    );
}

export default function FmLiveLayout({ children }: { children: ReactNode }) {
    return (
        <FmLiveProvider>
            <FmLiveShell>{children}</FmLiveShell>
        </FmLiveProvider>
    );
}
