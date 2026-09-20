import { useEffect } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';

import { FmLiveProvider, useFmLive } from '@/hooks/use-fm-live';

/**
 * "Your song is on air" used to be 153 lines: a full-viewport canvas, ninety
 * gravity-simulated confetti particles and a hand-built fixed banner with its own
 * dismiss button and z-index. The moment is worth keeping; a bespoke notification
 * system to deliver it is not, when a <Toaster> is already mounted app-wide.
 *
 * The haptic buzz survives -- on a phone in a car it is the part that actually
 * reaches you.
 */
function useOnAirToast() {
    const { onAirTitle, dismissOnAir } = useFmLive();

    useEffect(() => {
        if (!onAirTitle) {
            return;
        }

        try {
            navigator.vibrate?.([40, 30, 40, 30, 140]);
        } catch {
            /* unsupported */
        }

        toast.success('Your song is on air', {
            description: onAirTitle,
            duration: 8000,
            onDismiss: dismissOnAir,
            onAutoClose: dismissOnAir,
        });
    }, [onAirTitle, dismissOnAir]);
}

/**
 * Persistent layout for all listener pages. Inertia keeps this same instance
 * mounted across home/songs/queue/drive, so the `/api/live` poll in
 * FmLiveProvider lives once for the whole session rather than reconnecting on
 * every navigation -- which is why this file has to keep a stable module-scope
 * identity even now that it has almost nothing left to render.
 */
function FmLiveShell({ children }: { children: ReactNode }) {
    useOnAirToast();

    return <>{children}</>;
}

export default function FmLiveLayout({ children }: { children: ReactNode }) {
    return (
        <FmLiveProvider>
            <FmLiveShell>{children}</FmLiveShell>
        </FmLiveProvider>
    );
}
