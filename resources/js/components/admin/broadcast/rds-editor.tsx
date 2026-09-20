import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export function RdsEditor({ settings }: { settings: Record<string, string> }) {
    const rdsForm = useForm({
        rds_rt_mode: settings.rds_rt_mode,
        rds_rt: settings.rds_rt,
        rds_ps: settings.rds_ps,
    });

    return (
        <div>
            <h2 className="mb-2 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                RDS Messages
            </h2>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    rdsForm.post('/admin/broadcast/rds');
                }}
                className="space-y-4 border border-border bg-card p-4"
            >
                {/* PS override */}
                <div className="space-y-1">
                    <Label>
                        Program Service (PS) — station name on radio display
                    </Label>
                    <Input
                        value={rdsForm.data.rds_ps}
                        onChange={(e) =>
                            rdsForm.setData(
                                'rds_ps',
                                e.target.value.slice(0, 8),
                            )
                        }
                        placeholder="96.9 FM  (leave blank to use Callsign)"
                        maxLength={8}
                        className="font-mono"
                    />
                    <p className="text-xs text-muted-foreground">
                        Max 8 chars. Shown as the station name on every car
                        radio. Leave blank to use the callsign from Settings.
                    </p>
                    {rdsForm.data.rds_ps && (
                        <p className="font-mono text-xs text-muted-foreground">
                            Preview:{' '}
                            <span className="text-foreground">
                                &ldquo;{rdsForm.data.rds_ps.padEnd(8)}&rdquo;
                            </span>
                        </p>
                    )}
                </div>

                {/* RT mode toggle */}
                <div className="space-y-2">
                    <Label>
                        RadioText (RT) — scrolling message on radio display
                    </Label>
                    <div className="flex gap-2">
                        {(['auto', 'custom'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() =>
                                    rdsForm.setData('rds_rt_mode', m)
                                }
                                className={`flex-1 border py-2 text-xs font-bold tracking-widest uppercase transition-colors ${
                                    rdsForm.data.rds_rt_mode === m
                                        ? 'border-red-500 bg-red-500/10 text-red-400'
                                        : 'border-border text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {m === 'auto'
                                    ? 'Auto (song title)'
                                    : 'Custom message'}
                            </button>
                        ))}
                    </div>
                    {rdsForm.data.rds_rt_mode === 'custom' && (
                        <div className="space-y-1">
                            <Input
                                value={rdsForm.data.rds_rt}
                                onChange={(e) =>
                                    rdsForm.setData(
                                        'rds_rt',
                                        e.target.value.slice(0, 64),
                                    )
                                }
                                placeholder="e.g. REQUEST SONGS AT FMPLAYLIST.COM"
                                maxLength={64}
                            />
                            <p className="text-xs text-muted-foreground">
                                {rdsForm.data.rds_rt.length}/64 chars. Scrolls
                                across the radio display on all RDS radios.
                            </p>
                        </div>
                    )}
                    {rdsForm.data.rds_rt_mode === 'auto' && (
                        <p className="text-xs text-muted-foreground">
                            RadioText shows the current song title and artist
                            automatically.
                        </p>
                    )}
                </div>

                <Button
                    type="submit"
                    disabled={rdsForm.processing}
                    className="h-12 w-full bg-red-600 font-display font-bold tracking-wide text-white uppercase hover:bg-red-700"
                >
                    Save RDS Settings
                </Button>

                <p className="text-xs text-muted-foreground/50">
                    Changes apply to Pi within 30 seconds (next heartbeat).
                </p>
            </form>

            {/* Reference */}
            <div className="mt-4 border border-border bg-card p-4 text-xs text-muted-foreground">
                <p className="mb-2 font-display font-bold tracking-widest text-foreground uppercase">
                    Radio Display Preview
                </p>
                <div className="font-mono leading-relaxed">
                    <p className="text-foreground">┌──────────────────────┐</p>
                    <p>
                        <span className="text-foreground">│ </span>
                        <span className="text-yellow-400">
                            {(
                                rdsForm.data.rds_ps ||
                                settings.rds_ps ||
                                '96.9 FM '
                            )
                                .padEnd(8)
                                .slice(0, 8)}
                        </span>
                        <span className="text-foreground"> │</span>
                    </p>
                    <p>
                        <span className="text-foreground">│ </span>
                        <span className="truncate text-green-400">
                            {rdsForm.data.rds_rt_mode === 'custom'
                                ? (
                                      rdsForm.data.rds_rt ||
                                      'Custom message here...'
                                  ).slice(0, 20)
                                : 'Song Title - Artist'}
                        </span>
                        <span className="text-foreground"> │</span>
                    </p>
                    <p className="text-foreground">└──────────────────────┘</p>
                </div>
            </div>
        </div>
    );
}
