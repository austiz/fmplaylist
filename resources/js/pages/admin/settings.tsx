import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface WifiNetwork {
    ssid: string;
    signal: number; // 0–100
    security: string; // 'WPA2' | 'Open' | etc.
    active: boolean;
}

interface SavedNetwork {
    id: number;
    ssid: string;
    priority: number;
    has_password: boolean;
}

interface WifiInfo {
    current_ssid: string;
    networks: WifiNetwork[];
    pending_ssid: string;
    last_status: string; // 'connected' | 'failed' | ''
    last_ssid: string;
    saved: SavedNetwork[];
    saved_rev: string; // hash of the saved list on the server
    pi_rev: string; // what the Pi last reported having
}

interface Props {
    settings: Record<string, string>;
    wifi: WifiInfo;
}

function SignalBars({ signal }: { signal: number }) {
    const bars = [25, 50, 75, 100];

    return (
        <span
            className="inline-flex h-3.5 items-end gap-px"
            aria-label={`Signal ${signal}%`}
        >
            {bars.map((threshold, i) => (
                <span
                    key={i}
                    className="inline-block w-1 rounded-sm"
                    style={{
                        height: `${(i + 1) * 25}%`,
                        backgroundColor:
                            signal >= threshold
                                ? 'currentColor'
                                : 'color-mix(in srgb, currentColor 20%, transparent)',
                    }}
                />
            ))}
        </span>
    );
}

export default function Settings({ settings, wifi }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        frequency: settings.frequency,
        callsign: settings.callsign,
        fallback_song: settings.fallback_song,
        commercial_interval: settings.commercial_interval,
        sound_byte_interval: settings.sound_byte_interval,
        fade_in_duration: settings.fade_in_duration,
    });

    const [selectedSsid, setSelectedSsid] = useState('');
    const wifiForm = useForm({ ssid: '', password: '' });

    const selectNetwork = (ssid: string) => {
        setSelectedSsid(ssid);
        wifiForm.setData('ssid', ssid);
        wifiForm.setData('password', '');
    };

    const submitWifi = (e: React.FormEvent) => {
        e.preventDefault();
        wifiForm.post('/admin/settings/wifi', {
            onSuccess: () => setSelectedSsid(''),
        });
    };

    const savedForm = useForm({ ssid: '', password: '' });

    const submitSaved = (e: React.FormEvent) => {
        e.preventDefault();
        savedForm.post('/admin/settings/wifi/networks', {
            preserveScroll: true,
            onSuccess: () => savedForm.reset(),
        });
    };

    const reorder = (id: number, direction: -1 | 1) => {
        const ids = wifi.saved.map((n) => n.id);
        const from = ids.indexOf(id);
        const to = from + direction;

        if (from < 0 || to < 0 || to >= ids.length) {
            return;
        }

        [ids[from], ids[to]] = [ids[to], ids[from]];

        router.post(
            '/admin/settings/wifi/networks/reorder',
            { ids },
            { preserveScroll: true },
        );
    };

    const removeSaved = (net: SavedNetwork) => {
        if (!confirm(`Remove "${net.ssid}" from the fallback list?`)) {
            return;
        }

        router.delete(`/admin/settings/wifi/networks/${net.id}`, {
            preserveScroll: true,
        });
    };

    // The Pi only reports a rev once it has actually written the list to disk,
    // so a mismatch means the change hasn't reached it yet.
    const pendingSync =
        wifi.saved.length > 0 &&
        wifi.pi_rev !== '' &&
        wifi.pi_rev !== wifi.saved_rev;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/settings');
    };

    return (
        <AdminLayout title="Settings">
            <form
                onSubmit={submit}
                className="max-w-2xl space-y-6 border border-border bg-card p-5"
            >
                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Station
                    </h2>

                    <div className="max-w-xs space-y-1">
                        <Label>Broadcast frequency (MHz)</Label>
                        <Input
                            type="number"
                            min={87.5}
                            max={108.0}
                            step={0.1}
                            value={data.frequency}
                            onChange={(e) =>
                                setData('frequency', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            FM band: 87.5–108.0 MHz. The Pi switches to the new
                            frequency within one poll cycle (~30 s).
                        </p>
                        {errors.frequency && (
                            <p className="text-xs text-red-400">
                                {errors.frequency}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label>Callsign / Station name</Label>
                        <Input
                            value={data.callsign}
                            onChange={(e) =>
                                setData('callsign', e.target.value)
                            }
                            maxLength={64}
                        />
                        <p className="text-xs text-muted-foreground">
                            Used for default RDS station text and Pi display
                            naming.
                        </p>
                        {errors.callsign && (
                            <p className="text-xs text-red-400">
                                {errors.callsign}
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label>Fallback song filename</Label>
                        <Input
                            value={data.fallback_song}
                            onChange={(e) =>
                                setData('fallback_song', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Played when the request queue is empty. This file
                            must exist in the Pi song directory.
                        </p>
                        {errors.fallback_song && (
                            <p className="text-xs text-red-400">
                                {errors.fallback_song}
                            </p>
                        )}
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Rotation
                    </h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label>Songs between commercials</Label>
                            <Input
                                type="number"
                                min={0}
                                max={50}
                                value={data.commercial_interval}
                                onChange={(e) =>
                                    setData(
                                        'commercial_interval',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Use 0 to disable automatic commercials.
                            </p>
                            {errors.commercial_interval && (
                                <p className="text-xs text-red-400">
                                    {errors.commercial_interval}
                                </p>
                            )}
                        </div>

                        <div className="space-y-1">
                            <Label>Songs between sound bytes</Label>
                            <Input
                                type="number"
                                min={0}
                                max={20}
                                value={data.sound_byte_interval}
                                onChange={(e) =>
                                    setData(
                                        'sound_byte_interval',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Use 0 to disable automatic drops.
                            </p>
                            {errors.sound_byte_interval && (
                                <p className="text-xs text-red-400">
                                    {errors.sound_byte_interval}
                                </p>
                            )}
                        </div>
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Transitions
                    </h2>

                    <div className="max-w-xs space-y-1">
                        <Label>Song fade-in duration</Label>
                        <Input
                            type="number"
                            min={0}
                            max={3}
                            step={0.1}
                            value={data.fade_in_duration}
                            onChange={(e) =>
                                setData('fade_in_duration', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Seconds of fade-in applied by the Pi to songs and
                            fallback playback.
                        </p>
                        {errors.fade_in_duration && (
                            <p className="text-xs text-red-400">
                                {errors.fade_in_duration}
                            </p>
                        )}
                    </div>
                </section>

                <Button
                    type="submit"
                    disabled={processing}
                    className="bg-red-600 text-white hover:bg-red-700"
                >
                    Save Settings
                </Button>
            </form>

            <p className="mt-4 text-xs text-muted-foreground">
                RDS messages and live broadcast control are in{' '}
                <Link
                    href="/admin/broadcast"
                    className="text-red-500 hover:underline"
                >
                    Admin &gt; Broadcast
                </Link>
                .
            </p>

            {/* ── WiFi ─────────────────────────────────────────────────────── */}
            <div className="mt-8 max-w-2xl space-y-4 border border-border bg-card p-5">
                <div className="flex items-center justify-between">
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Pi WiFi
                    </h2>
                    {wifi.current_ssid && (
                        <span className="flex items-center gap-1.5 text-xs text-green-400">
                            <span className="inline-block h-1.5 w-1.5 rounded-full bg-green-400" />
                            {wifi.current_ssid}
                        </span>
                    )}
                    {!wifi.current_ssid && (
                        <span className="text-xs text-muted-foreground">
                            Pi offline or not connected
                        </span>
                    )}
                </div>

                {/* Last WiFi switch result */}
                {wifi.last_status === 'connected' && (
                    <p className="border-l-2 border-green-500 bg-green-500/10 px-3 py-2 text-xs text-green-400">
                        Connected to &ldquo;{wifi.last_ssid}&rdquo;
                        successfully.
                    </p>
                )}
                {wifi.last_status === 'failed' && (
                    <p className="border-l-2 border-red-500 bg-red-500/10 px-3 py-2 text-xs text-red-400">
                        Failed to connect to &ldquo;{wifi.last_ssid}&rdquo; —
                        rolled back to previous network.
                    </p>
                )}
                {wifi.pending_ssid && wifi.last_status === '' && (
                    <p className="border-l-2 border-yellow-500 bg-yellow-500/10 px-3 py-2 text-xs text-yellow-400">
                        Switching to &ldquo;{wifi.pending_ssid}&rdquo;&hellip;
                        Pi will confirm within 30&ndash;60 seconds.
                    </p>
                )}

                {/* Network list */}
                {wifi.networks.length > 0 ? (
                    <div className="divide-y divide-border border border-border">
                        {wifi.networks.map((net) => (
                            <button
                                key={net.ssid}
                                type="button"
                                onClick={() => selectNetwork(net.ssid)}
                                className={[
                                    'flex w-full items-center gap-3 px-4 py-3 text-left text-sm transition-colors hover:bg-muted/30',
                                    selectedSsid === net.ssid
                                        ? 'bg-muted/40'
                                        : '',
                                    net.active
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                ].join(' ')}
                            >
                                <span
                                    className={
                                        net.active
                                            ? 'text-green-400'
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <SignalBars signal={net.signal} />
                                </span>
                                <span className="flex-1 font-medium">
                                    {net.ssid}
                                </span>
                                {net.active && (
                                    <span className="text-xs font-bold tracking-wider text-green-400 uppercase">
                                        Connected
                                    </span>
                                )}
                                {net.security !== 'Open' && !net.active && (
                                    <span className="text-xs text-muted-foreground/60">
                                        {net.security}
                                    </span>
                                )}
                                {net.security === 'Open' && !net.active && (
                                    <span className="text-xs text-muted-foreground/60">
                                        Open
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                ) : (
                    <p className="py-4 text-center text-xs text-muted-foreground">
                        No scan data yet — Pi sends networks on next heartbeat
                        (~30 s).
                    </p>
                )}

                {/* Password + connect — shown when a network is selected */}
                {selectedSsid && (
                    <form
                        onSubmit={submitWifi}
                        className="space-y-3 border-t border-border pt-4"
                    >
                        <p className="text-sm font-medium">
                            Connect to{' '}
                            <span className="text-foreground">
                                &ldquo;{selectedSsid}&rdquo;
                            </span>
                        </p>
                        {(() => {
                            const net = wifi.networks.find(
                                (n) => n.ssid === selectedSsid,
                            );

                            return net?.security === 'Open' ? (
                                <p className="text-xs text-muted-foreground">
                                    Open network — no password needed.
                                </p>
                            ) : (
                                <div className="space-y-1">
                                    <Label htmlFor="wifi-password">
                                        Password
                                    </Label>
                                    <Input
                                        id="wifi-password"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder="Network password"
                                        value={wifiForm.data.password}
                                        onChange={(e) =>
                                            wifiForm.setData(
                                                'password',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {wifiForm.errors.password && (
                                        <p className="text-xs text-red-400">
                                            {wifiForm.errors.password}
                                        </p>
                                    )}
                                </div>
                            );
                        })()}
                        <div className="flex gap-2">
                            <Button
                                type="submit"
                                disabled={wifiForm.processing}
                                className="bg-red-600 text-white hover:bg-red-700"
                            >
                                {wifiForm.processing ? 'Queuing…' : 'Connect'}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setSelectedSsid('')}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}
            </div>

            {/* ── Saved networks / fallback chain ──────────────────────────── */}
            <div className="mt-6 max-w-2xl space-y-4 border border-border bg-card p-5">
                <div className="flex items-center justify-between">
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Saved Networks
                    </h2>
                    {pendingSync && (
                        <span className="text-xs text-yellow-400">
                            Pi hasn&rsquo;t synced yet
                        </span>
                    )}
                </div>

                <p className="text-xs text-muted-foreground">
                    Tried in order, top first. The Pi stores these locally, so
                    it reconnects on its own after a reboot even with no
                    internet. Broadcasting never waits on WiFi.
                </p>

                {wifi.saved.length > 0 ? (
                    <div className="divide-y divide-border border border-border">
                        {wifi.saved.map((net, i) => (
                            <div
                                key={net.id}
                                className="flex items-center gap-3 px-4 py-2.5 text-sm"
                            >
                                <span className="w-5 text-xs text-muted-foreground/60 tabular-nums">
                                    {i + 1}
                                </span>
                                <span className="flex-1 font-medium">
                                    {net.ssid}
                                    {net.ssid === wifi.current_ssid && (
                                        <span className="ml-2 text-xs font-bold tracking-wider text-green-400 uppercase">
                                            Connected
                                        </span>
                                    )}
                                </span>
                                <span className="text-xs text-muted-foreground/60">
                                    {net.has_password ? 'WPA' : 'Open'}
                                </span>
                                <div className="flex items-center gap-1">
                                    <button
                                        type="button"
                                        onClick={() => reorder(net.id, -1)}
                                        disabled={i === 0}
                                        aria-label={`Move ${net.ssid} up`}
                                        className="px-1.5 py-0.5 text-muted-foreground hover:text-foreground disabled:opacity-25"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => reorder(net.id, 1)}
                                        disabled={i === wifi.saved.length - 1}
                                        aria-label={`Move ${net.ssid} down`}
                                        className="px-1.5 py-0.5 text-muted-foreground hover:text-foreground disabled:opacity-25"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => removeSaved(net)}
                                        aria-label={`Remove ${net.ssid}`}
                                        className="px-1.5 py-0.5 text-muted-foreground hover:text-red-400"
                                    >
                                        ✕
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="py-4 text-center text-xs text-muted-foreground">
                        No fallback networks saved yet.
                    </p>
                )}

                <form
                    onSubmit={submitSaved}
                    className="space-y-3 border-t border-border pt-4"
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="saved-ssid">Network name</Label>
                            <Input
                                id="saved-ssid"
                                placeholder="Exact SSID"
                                value={savedForm.data.ssid}
                                onChange={(e) =>
                                    savedForm.setData('ssid', e.target.value)
                                }
                            />
                            {savedForm.errors.ssid && (
                                <p className="text-xs text-red-400">
                                    {savedForm.errors.ssid}
                                </p>
                            )}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="saved-password">Password</Label>
                            <Input
                                id="saved-password"
                                type="password"
                                autoComplete="new-password"
                                placeholder="Blank if open"
                                value={savedForm.data.password}
                                onChange={(e) =>
                                    savedForm.setData(
                                        'password',
                                        e.target.value,
                                    )
                                }
                            />
                            {savedForm.errors.password && (
                                <p className="text-xs text-red-400">
                                    {savedForm.errors.password}
                                </p>
                            )}
                        </div>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Type the name by hand — a backup network usually
                        isn&rsquo;t in range when you set it up, so it
                        won&rsquo;t show in the scan above.
                    </p>
                    <Button
                        type="submit"
                        disabled={savedForm.processing || !savedForm.data.ssid}
                        className="bg-red-600 text-white hover:bg-red-700"
                    >
                        {savedForm.processing ? 'Saving…' : 'Add network'}
                    </Button>
                </form>
            </div>
        </AdminLayout>
    );
}
