import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Badge, BadgeDot } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { useAdminLive } from '@/hooks/use-admin-live';

/**
 * What the transmitter is doing, in the sidebar footer.
 *
 * This used to be a full-width strip pinned under the admin header, which spent
 * a row of vertical space on every page to say "Connected" three times. It reads
 * the shell's shared `/api/live` frame, so every admin page watching live state
 * costs the operator one poll between them -- see `useAdminLive`.
 *
 * Collapsed to the icon rail it degrades to a single dot, which is enough to
 * notice that something went offline and expand.
 */
export function PiStatusBlock() {
    const { piStatus: pi } = useAdminLive();
    const [updating, setUpdating] = useState(false);
    const { state } = useSidebar();

    if (!pi) {
        return null;
    }

    const live = pi.status === 'live';
    const playing = pi.status === 'playing';

    const variant = !pi.online
        ? 'offline'
        : live
          ? 'live'
          : playing
            ? 'playing'
            : 'neutral';
    const label = !pi.online
        ? 'Offline'
        : live
          ? 'Live'
          : playing
            ? 'On Air'
            : 'Idle';

    const pushUpdate = () => {
        setUpdating(true);
        router.post(
            '/admin/pi/update',
            {},
            { preserveScroll: true, onFinish: () => setUpdating(false) },
        );
    };

    if (state === 'collapsed') {
        return (
            <div
                className="flex justify-center py-1"
                title={`Transmitter: ${label}`}
            >
                <span
                    className={`inline-block size-2 rounded-full ${
                        !pi.online
                            ? 'bg-offline'
                            : live
                              ? 'bg-live'
                              : playing
                                ? 'bg-playing'
                                : 'bg-muted-foreground/40'
                    }`}
                />
            </div>
        );
    }

    return (
        <div className="space-y-2 rounded-lg border border-border bg-surface-2 p-2.5 shadow-card">
            <div className="flex items-center justify-between gap-2">
                <span className="font-display text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                    Transmitter
                </span>
                <Badge variant={variant} dot pulse={live || playing}>
                    {label}
                </Badge>
            </div>

            {pi.ip && (
                <p className="truncate font-mono text-[11px] text-muted-foreground">
                    {live ? 'stream → ' : ''}
                    {pi.ip}
                </p>
            )}

            {pi.update_available && (
                <div className="space-y-1.5 border-t border-border pt-2">
                    <p className="flex items-center gap-1.5 text-[11px] font-medium text-warning">
                        <BadgeDot pulse />
                        Update available
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={pushUpdate}
                        disabled={updating}
                        className="h-7 w-full border-warning/40 text-warning hover:bg-warning-soft hover:text-warning"
                    >
                        {updating ? 'Queuing…' : 'Install on Pi'}
                    </Button>
                </div>
            )}
        </div>
    );
}
