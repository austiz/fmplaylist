import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import type { PiDevice } from '@/types/fm';

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

/** The command buttons and last-result strip under one device. */
export function DeviceControls({ device }: { device: PiDevice }) {
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
