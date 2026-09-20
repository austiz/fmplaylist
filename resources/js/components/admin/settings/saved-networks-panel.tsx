import { router, useForm } from '@inertiajs/react';
import { FieldError } from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { SavedNetwork, WifiInfo } from '@/types/fm';

/** The ordered fallback chain the Pi keeps on disk so it reconnects without us. */
export function SavedNetworksPanel({ wifi }: { wifi: WifiInfo }) {
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

    return (
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
                Tried in order, top first. The Pi stores these locally, so it
                reconnects on its own after a reboot even with no internet.
                Broadcasting never waits on WiFi.
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
                        <FieldError message={savedForm.errors.ssid} />
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
                                savedForm.setData('password', e.target.value)
                            }
                        />
                        <FieldError message={savedForm.errors.password} />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    Type the name by hand — a backup network usually isn&rsquo;t
                    in range when you set it up, so it won&rsquo;t show in the
                    scan above.
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
    );
}
