import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FieldError } from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { WifiInfo } from '@/types/fm';

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

/** The Pi's last WiFi scan, and the form that queues a switch onto it. */
export function WifiPanel({ wifi }: { wifi: WifiInfo }) {
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

    return (
        <div className="mt-8 max-w-2xl space-y-4 border border-border bg-card p-5">
            <div className="flex items-center justify-between">
                <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                    Pi WiFi
                </h2>
                {wifi.current_ssid && (
                    <span className="flex items-center gap-1.5 text-xs text-online">
                        <span className="inline-block h-1.5 w-1.5 rounded-full bg-online" />
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
                <p className="border-l-2 border-online bg-online-soft px-3 py-2 text-xs text-online">
                    Connected to &ldquo;{wifi.last_ssid}&rdquo; successfully.
                </p>
            )}
            {wifi.last_status === 'failed' && (
                <p className="border-l-2 border-destructive bg-destructive/12 px-3 py-2 text-xs text-destructive">
                    Failed to connect to &ldquo;{wifi.last_ssid}&rdquo; — rolled
                    back to previous network.
                </p>
            )}
            {wifi.pending_ssid && wifi.last_status === '' && (
                <p className="border-l-2 border-warning bg-warning-soft px-3 py-2 text-xs text-warning">
                    Switching to &ldquo;{wifi.pending_ssid}&rdquo;&hellip; Pi
                    will confirm within 30&ndash;60 seconds.
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
                                selectedSsid === net.ssid ? 'bg-muted/40' : '',
                                net.active
                                    ? 'text-foreground'
                                    : 'text-muted-foreground',
                            ].join(' ')}
                        >
                            <span
                                className={
                                    net.active
                                        ? 'text-online'
                                        : 'text-muted-foreground'
                                }
                            >
                                <SignalBars signal={net.signal} />
                            </span>
                            <span className="flex-1 font-medium">
                                {net.ssid}
                            </span>
                            {net.active && (
                                <span className="text-xs font-bold tracking-wider text-online uppercase">
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
                    No scan data yet — Pi sends networks on next heartbeat (~30
                    s).
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
                                <Label htmlFor="wifi-password">Password</Label>
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
                                <FieldError
                                    message={wifiForm.errors.password}
                                />
                            </div>
                        );
                    })()}
                    <div className="flex gap-2">
                        <Button type="submit" disabled={wifiForm.processing}>
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
    );
}
