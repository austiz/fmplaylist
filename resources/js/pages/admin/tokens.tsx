import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { PiDevice, Station } from '@/types/fm';

interface Props {
    tokens: PiDevice[];
    stations: Pick<Station, 'id' | 'name'>[];
    newToken: string | null;
    appUrl: string;
}

function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    const gb = bytes / 1024 ** 3;

    return gb >= 1
        ? `${gb.toFixed(1)} GB`
        : `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

/** Actions that interrupt the broadcast get a confirm; the rest fire directly. */
const DISRUPTIVE = new Set([
    'update',
    'rollback',
    'rebuild',
    'restart_daemon',
    'reboot',
    'fm_stop',
]);

const ACTIONS: { command: string; label: string; danger?: boolean }[] = [
    { command: 'update', label: 'Update' },
    { command: 'rollback', label: 'Roll back' },
    { command: 'rebuild', label: 'Rebuild' },
    { command: 'restart_daemon', label: 'Restart' },
    { command: 'reboot', label: 'Reboot', danger: true },
    { command: 'fetch_logs', label: 'Logs' },
];

function DeviceControls({ device }: { device: PiDevice }) {
    const [showLogs, setShowLogs] = useState<string | null>(null);

    const send = (command: string, label: string) => {
        if (
            DISRUPTIVE.has(command) &&
            !confirm(
                `${label} "${device.label}"? This interrupts the broadcast.`,
            )
        ) {
            return;
        }

        router.post(
            `/admin/tokens/${device.id}/command`,
            { command },
            { preserveScroll: true },
        );
    };

    const pending = (device.commands ?? []).find(
        (c) => c.status === 'queued' || c.status === 'sent',
    );
    const lastLogs = (device.commands ?? []).find(
        (c) => c.command === 'fetch_logs' && c.status === 'acked' && c.result,
    );

    return (
        <div className="space-y-2 border-t border-border pt-2">
            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span>
                    Transmitter:{' '}
                    {device.fm_running === null ? (
                        '—'
                    ) : device.fm_running ? (
                        <span className="text-green-400">on air</span>
                    ) : (
                        <span className="text-red-400">off air</span>
                    )}
                </span>
                {device.ip && <span>IP {device.ip}</span>}
                {device.queue_depth !== null && (
                    <span>queue {device.queue_depth}</span>
                )}
                <span>
                    Version:{' '}
                    {device.daemon_hash ? (
                        <span
                            className={
                                device.up_to_date
                                    ? 'text-green-400'
                                    : 'text-amber-400'
                            }
                        >
                            {device.daemon_hash.slice(0, 8)}
                            {device.up_to_date ? ' (current)' : ' (outdated)'}
                        </span>
                    ) : (
                        'unknown'
                    )}
                </span>
                {device.last_update_status && (
                    <span
                        className={
                            device.last_update_status === 'ok'
                                ? ''
                                : 'text-amber-400'
                        }
                    >
                        Last update: {device.last_update_status}
                        {device.last_update_at
                            ? ` ${device.last_update_at}`
                            : ''}
                    </span>
                )}
            </div>

            {device.last_error && (
                <p className="border-l-2 border-red-500 bg-red-500/10 px-3 py-1.5 text-xs text-red-400">
                    {device.last_error}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                {ACTIONS.map((a) => (
                    <Button
                        key={a.command}
                        size="sm"
                        variant="outline"
                        className={a.danger ? 'text-red-500' : ''}
                        disabled={!!pending}
                        onClick={() => send(a.command, a.label)}
                    >
                        {a.label}
                    </Button>
                ))}
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!!pending}
                    onClick={() =>
                        send(
                            device.fm_running === false
                                ? 'fm_start'
                                : 'fm_stop',
                            device.fm_running === false
                                ? 'Go on air'
                                : 'Go off air',
                        )
                    }
                >
                    {device.fm_running === false ? 'Go on air' : 'Go off air'}
                </Button>

                {pending && (
                    <span className="text-xs text-amber-400">
                        {pending.command} {pending.status}…
                    </span>
                )}

                {lastLogs && (
                    <button
                        type="button"
                        className="text-xs text-muted-foreground underline"
                        onClick={() =>
                            setShowLogs(showLogs ? null : lastLogs.result)
                        }
                    >
                        {showLogs ? 'Hide logs' : 'View logs'}
                    </button>
                )}
            </div>

            {showLogs && (
                <pre className="max-h-72 overflow-auto border border-border bg-background p-3 text-[11px] leading-relaxed whitespace-pre-wrap">
                    {showLogs}
                </pre>
            )}

            {(device.commands ?? []).some((c) => c.status === 'failed') && (
                <p className="text-xs text-red-400">
                    Last failure:{' '}
                    {
                        (device.commands ?? []).find(
                            (c) => c.status === 'failed',
                        )?.result
                    }
                </p>
            )}
        </div>
    );
}

export default function Tokens({ tokens, stations, newToken, appUrl }: Props) {
    const [copiedToken, setCopiedToken] = useState(false);
    const [copiedCmd, setCopiedCmd] = useState(false);

    const installCmd = newToken
        ? `curl -fsSL ${appUrl}/pi/setup.sh | sudo bash -s -- ${newToken}`
        : null;

    const addForm = useForm({
        label: '',
        station_id: stations[0] ? String(stations[0].id) : '',
    });

    const copyToken = () => {
        navigator.clipboard.writeText(newToken!);
        setCopiedToken(true);
        setTimeout(() => setCopiedToken(false), 2000);
    };

    const copyCmd = () => {
        navigator.clipboard.writeText(installCmd!);
        setCopiedCmd(true);
        setTimeout(() => setCopiedCmd(false), 2000);
    };

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

    const addDevice = (e: React.FormEvent) => {
        e.preventDefault();
        addForm.post('/admin/tokens', {
            preserveScroll: true,
            onSuccess: () => addForm.reset('label'),
        });
    };

    return (
        <AdminLayout title="Devices">
            <div className="max-w-3xl space-y-6">
                {newToken && (
                    <div className="space-y-4 border border-red-500/40 bg-red-500/5 p-5">
                        <p className="text-sm font-bold text-red-400">
                            New token generated — shown once only
                        </p>

                        <div>
                            <p className="mb-1.5 font-display text-xs font-semibold tracking-wide text-red-400/80 uppercase">
                                Run this on the Pi (sets everything up
                                automatically)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
                                    {installCmd}
                                </code>
                                <Button
                                    size="sm"
                                    onClick={copyCmd}
                                    className="shrink-0"
                                >
                                    {copiedCmd ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>

                        <div>
                            <p className="mb-1.5 font-display text-xs font-semibold tracking-wide text-red-400/80 uppercase">
                                Raw token (for manual config.json edits)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
                                    {newToken}
                                </code>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={copyToken}
                                    className="shrink-0"
                                >
                                    {copiedToken ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                <div className="border border-border bg-card p-5">
                    <h2 className="mb-4 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Devices
                    </h2>
                    {tokens.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No devices yet — add one below.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {tokens.map((t) => (
                                <div
                                    key={t.id}
                                    className="space-y-2 border border-border bg-secondary/50 px-4 py-3"
                                >
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-2 text-sm font-medium text-foreground">
                                                <span
                                                    className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${t.online ? 'bg-green-500' : 'bg-muted-foreground/30'}`}
                                                />
                                                {t.label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Last seen:{' '}
                                                {t.last_seen_at ?? 'never'} ·
                                                Created {t.created_at} ·{' '}
                                                {t.downloads_done}/
                                                {t.downloads_total} downloaded ·{' '}
                                                {formatBytes(t.disk_free_bytes)}{' '}
                                                free of{' '}
                                                {formatBytes(
                                                    t.disk_total_bytes,
                                                )}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Select
                                                value={
                                                    t.station
                                                        ? String(t.station.id)
                                                        : ''
                                                }
                                                onValueChange={(v) =>
                                                    reassign(t, v)
                                                }
                                            >
                                                <SelectTrigger className="h-8 w-40">
                                                    <SelectValue placeholder="Unassigned" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {stations.map((s) => (
                                                        <SelectItem
                                                            key={s.id}
                                                            value={String(s.id)}
                                                        >
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
                            ))}
                        </div>
                    )}
                </div>

                <form
                    onSubmit={addDevice}
                    className="space-y-3 border border-border bg-card p-5"
                >
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Add a Device
                    </h2>
                    <div className="grid gap-3 sm:grid-cols-[1fr_180px_auto]">
                        <div className="space-y-1">
                            <Label>Label</Label>
                            <Input
                                value={addForm.data.label}
                                onChange={(e) =>
                                    addForm.setData('label', e.target.value)
                                }
                                placeholder="e.g. Pi Zero — Car 1"
                                required
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Station</Label>
                            <Select
                                value={addForm.data.station_id}
                                onValueChange={(v) =>
                                    addForm.setData('station_id', v)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {stations.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="submit"
                            disabled={addForm.processing || !addForm.data.label}
                            className="self-end bg-red-600 text-white hover:bg-red-700"
                        >
                            {tokens.length > 0
                                ? 'Add Device'
                                : 'Generate Token'}
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
