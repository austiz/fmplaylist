import { createContext, useContext } from 'react';

/** Pi devices that have checked in at least once; 0 means we know of no real device. */
export const DeviceCountContext = createContext(0);

const BADGE = 'shrink-0 px-2 py-0.5 text-xs font-bold tracking-wide uppercase';

/**
 * Sync state is derived from `devices_have` (per-device `device_downloads` rows), never
 * from `needs_pi_download` — that flag is global and defaults to false, so it reported
 * "On Pi" for items no device had ever downloaded.
 */
export function PiBadge({
    devicesHave = 0,
    hasFile = true,
    deleteRequested,
    active,
}: {
    devicesHave?: number;
    hasFile?: boolean;
    deleteRequested?: boolean;
    active?: boolean;
}) {
    const deviceCount = useContext(DeviceCountContext);

    if (deleteRequested) {
        return (
            <span className={`${BADGE} bg-destructive/12 text-destructive`}>
                Deleting
            </span>
        );
    }

    // No file on the web side and no device holding it — nothing can ever play this.
    if (devicesHave === 0 && !hasFile) {
        return (
            <span className={`${BADGE} bg-destructive/12 text-destructive`}>
                No File
            </span>
        );
    }

    if (devicesHave === 0) {
        return (
            <span className={`${BADGE} bg-warning-soft text-warning`}>
                Pending ↓
            </span>
        );
    }

    if (devicesHave < deviceCount) {
        return (
            <span
                className={`${BADGE} bg-warning-soft text-warning`}
                title={`${devicesHave} of ${deviceCount} Pis have this file`}
            >
                {devicesHave}/{deviceCount} Pis
            </span>
        );
    }

    if (active === false) {
        return (
            <span className={`${BADGE} bg-secondary text-muted-foreground/50`}>
                Off
            </span>
        );
    }

    return <span className={`${BADGE} bg-online-soft text-online`}>On Pi</span>;
}
