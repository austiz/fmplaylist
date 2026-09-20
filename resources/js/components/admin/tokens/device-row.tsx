import { router } from '@inertiajs/react';
import { DeviceControls } from '@/components/admin/tokens/device-controls';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatBytes } from '@/lib/format';
import type { PiDevice, Station } from '@/types/fm';

/** One Pi in the device list: identity, disk, station assignment and its commands. */
export function DeviceRow({
    t,
    stations,
}: {
    t: PiDevice;
    stations: Pick<Station, 'id' | 'name'>[];
}) {
    const regenerate = (token: PiDevice) => {
        if (
            confirm(
                `This will invalidate "${token.label}"'s current credential. It will stop working until updated. Continue?`,
            )
        ) {
            router.post(`/admin/tokens/${token.id}/regenerate`);
        }
    };

    const revoke = (token: PiDevice) => {
        if (
            confirm(
                `Revoke "${token.label}"? It will stop broadcasting for the connected station.`,
            )
        ) {
            router.delete(`/admin/tokens/${token.id}`);
        }
    };

    const reassign = (token: PiDevice, stationId: string) => {
        router.patch(
            `/admin/tokens/${token.id}`,
            { station_id: stationId },
            { preserveScroll: true },
        );
    };

    return (
        <div className="space-y-2 border border-border bg-secondary/50 px-4 py-3">
            <div className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                    <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                        <span
                            className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${t.online ? 'bg-green-500' : 'bg-muted-foreground/30'}`}
                        />
                        {t.label}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Last seen: {t.last_seen_at ?? 'never'} · Created{' '}
                        {t.created_at} · {t.downloads_done}/{t.downloads_total}{' '}
                        downloaded · {formatBytes(t.disk_free_bytes)} free of{' '}
                        {formatBytes(t.disk_total_bytes)}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <Select
                        value={t.station ? String(t.station.id) : ''}
                        onValueChange={(v) => reassign(t, v)}
                    >
                        <SelectTrigger className="h-8 w-40">
                            <SelectValue placeholder="Unassigned" />
                        </SelectTrigger>
                        <SelectContent>
                            {stations.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => regenerate(t)}
                    >
                        Regenerate
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        className="text-red-500"
                        onClick={() => revoke(t)}
                    >
                        Revoke
                    </Button>
                </div>
            </div>

            <DeviceControls device={t} />
        </div>
    );
}
