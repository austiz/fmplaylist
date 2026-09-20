import { usePage } from '@inertiajs/react';
import { createContext, use, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

import { subscribeLive } from '@/lib/live';
import type { NowPlayingData, PiStatus, Station } from '@/types/fm';

const OFFLINE: PiStatus = {
    online: false,
    status: 'offline',
    mode: 'normal',
    ip: null,
    update_available: false,
};

interface AdminLive {
    /** null until the first frame lands, so a page can tell "unknown" from "offline". */
    piStatus: PiStatus | null;
    nowPlaying: NowPlayingData | null | undefined;
    /** Changes whenever the queue moves. Pages reload their own props off it. */
    queueVersion: string | undefined;
}

const AdminLiveContext = createContext<AdminLive>({
    piStatus: null,
    nowPlaying: undefined,
    queueVersion: undefined,
});

/**
 * One `/api/live` poll for the whole operator shell.
 *
 * The sidebar's transmitter block used to own its own subscription, which was
 * fine while it was the only thing watching. The queue page watches the same
 * frame for `queue_version`, and a second `subscribeLive` call is a second
 * independent poll loop -- so an operator with the queue open would have been
 * making two requests every three seconds for state that arrives together.
 *
 * `clientId` stays null: an operator is not an audience member, and counting the
 * admin shell would inflate the listener number on every page it renders.
 */
export function AdminLiveProvider({ children }: { children: ReactNode }) {
    const { props } = usePage<{ activeStation: Station | null }>();
    const stationSlug = props.activeStation?.slug;

    const [piStatus, setPiStatus] = useState<PiStatus | null>(null);
    const [nowPlaying, setNowPlaying] = useState<
        NowPlayingData | null | undefined
    >(undefined);
    const [queueVersion, setQueueVersion] = useState<string | undefined>(
        undefined,
    );

    useEffect(
        () =>
            subscribeLive({
                stationSlug,
                clientId: null,
                onFrame: (frame) => {
                    // Absent means "unchanged since your cursor", which is not the
                    // same as "null" -- overwriting on every frame would blank the
                    // sidebar every three seconds on a quiet station.
                    if (frame.pi_status !== undefined) {
                        setPiStatus(frame.pi_status ?? OFFLINE);
                    }

                    if (frame.now_playing !== undefined) {
                        setNowPlaying(frame.now_playing);
                    }

                    if (frame.queue_version !== undefined) {
                        setQueueVersion(frame.queue_version);
                    }
                },
            }),
        [stationSlug],
    );

    const value = useMemo(
        () => ({ piStatus, nowPlaying, queueVersion }),
        [piStatus, nowPlaying, queueVersion],
    );

    return <AdminLiveContext value={value}>{children}</AdminLiveContext>;
}

export function useAdminLive(): AdminLive {
    return use(AdminLiveContext);
}
