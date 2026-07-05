import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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

    return gb >= 1 ? `${gb.toFixed(1)} GB` : `${(bytes / 1024 ** 2).toFixed(0)} MB`;
}

export default function Tokens({ tokens, stations, newToken, appUrl }: Props) {
    const [copiedToken, setCopiedToken] = useState(false);
    const [copiedCmd, setCopiedCmd] = useState(false);

    const installCmd = newToken
        ? `curl -fsSL ${appUrl}/pi/setup.sh | sudo bash -s -- ${newToken}`
        : null;

    const addForm = useForm({ label: '', station_id: stations[0] ? String(stations[0].id) : '' });

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
        if (confirm(`This will invalidate "${token.label}"'s current credential. It will stop working until updated. Continue?`)) {
            router.post(`/admin/tokens/${token.id}/regenerate`);
        }
    };

    const revoke = (token: PiDevice) => {
        if (confirm(`Revoke "${token.label}"? It will stop broadcasting for the connected station.`)) {
            router.delete(`/admin/tokens/${token.id}`);
        }
    };

    const reassign = (token: PiDevice, stationId: string) => {
        router.patch(`/admin/tokens/${token.id}`, { station_id: stationId }, { preserveScroll: true });
    };

    const addDevice = (e: React.FormEvent) => {
        e.preventDefault();
        addForm.post('/admin/tokens', { preserveScroll: true, onSuccess: () => addForm.reset('label') });
    };

    return (
        <AdminLayout title="Devices">
            <div className="max-w-3xl space-y-6">
                {newToken && (
                    <div className="rounded-xl border-2 border-red-300 bg-red-50 p-5 dark:border-red-800 dark:bg-red-950/30 space-y-4">
                        <p className="text-sm font-bold text-red-900 dark:text-red-200">
                            New token generated — shown once only
                        </p>

                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">
                                Run this on the Pi (sets everything up automatically)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 rounded bg-white px-3 py-2 text-xs font-mono text-zinc-900 dark:bg-zinc-900 dark:text-white break-all">
                                    {installCmd}
                                </code>
                                <Button size="sm" onClick={copyCmd} className="shrink-0">
                                    {copiedCmd ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>

                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">
                                Raw token (for manual config.json edits)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 rounded bg-white px-3 py-2 text-xs font-mono text-zinc-900 dark:bg-zinc-900 dark:text-white break-all">
                                    {newToken}
                                </code>
                                <Button size="sm" variant="outline" onClick={copyToken} className="shrink-0">
                                    {copiedToken ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                <div className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 className="mb-4 font-semibold text-zinc-900 dark:text-white">Devices</h2>
                    {tokens.length === 0 ? (
                        <p className="text-sm text-zinc-400">No devices yet — add one below.</p>
                    ) : (
                        <div className="space-y-2">
                            {tokens.map((t) => (
                                <div key={t.id} className="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800 space-y-2">
                                    <div className="flex items-center justify-between gap-4">
                                        <div className="min-w-0">
                                            <p className="flex items-center gap-2 text-sm font-medium text-zinc-900 dark:text-white">
                                                <span className={`inline-block h-1.5 w-1.5 shrink-0 rounded-full ${t.online ? 'bg-green-500' : 'bg-zinc-400'}`} />
                                                {t.label}
                                            </p>
                                            <p className="text-xs text-zinc-500">
                                                Last seen: {t.last_seen_at ?? 'never'} · Created {t.created_at} ·{' '}
                                                {t.downloads_done}/{t.downloads_total} downloaded · {formatBytes(t.disk_free_bytes)} free of {formatBytes(t.disk_total_bytes)}
                                            </p>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2">
                                            <Select value={t.station ? String(t.station.id) : ''} onValueChange={(v) => reassign(t, v)}>
                                                <SelectTrigger className="h-8 w-40"><SelectValue placeholder="Unassigned" /></SelectTrigger>
                                                <SelectContent>
                                                    {stations.map((s) => (
                                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <Button size="sm" variant="outline" onClick={() => regenerate(t)}>Regenerate</Button>
                                            <Button size="sm" variant="outline" className="text-red-600" onClick={() => revoke(t)}>Revoke</Button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <form onSubmit={addDevice} className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                    <h2 className="font-semibold text-zinc-900 dark:text-white">Add a Device</h2>
                    <div className="grid gap-3 sm:grid-cols-[1fr_180px_auto]">
                        <div className="space-y-1">
                            <Label>Label</Label>
                            <Input
                                value={addForm.data.label}
                                onChange={(e) => addForm.setData('label', e.target.value)}
                                placeholder="e.g. Pi Zero — Car 1"
                                required
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Station</Label>
                            <Select value={addForm.data.station_id} onValueChange={(v) => addForm.setData('station_id', v)}>
                                <SelectTrigger><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {stations.map((s) => (
                                        <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="submit" disabled={addForm.processing || !addForm.data.label} className="self-end bg-red-600 hover:bg-red-700 text-white">
                            {tokens.length > 0 ? 'Add Device' : 'Generate Token'}
                        </Button>
                    </div>
                </form>
            </div>
        </AdminLayout>
    );
}
