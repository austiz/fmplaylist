import { Link, useForm, usePage } from '@inertiajs/react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface Props {
    settings: Record<string, string>;
}

export default function Settings({ settings }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        frequency: settings.frequency ?? '96.9',
        callsign: settings.callsign ?? '96.9 FM',
        fallback_song: settings.fallback_song ?? 'FTPA.wav',
        commercial_interval: settings.commercial_interval ?? '0',
        sound_byte_interval: settings.sound_byte_interval ?? '0',
        fade_in_duration: settings.fade_in_duration ?? '0.5',
    });
    const { props } = usePage<{ flash: { success?: string } }>();

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/settings');
    };

    return (
        <AdminLayout title="Settings">
            {props.flash?.success && (
                <div className="mb-4 border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                    {props.flash.success}
                </div>
            )}

            <form onSubmit={submit} className="max-w-2xl space-y-6 border border-border bg-card p-5">
                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Station</h2>

                    <div className="max-w-xs space-y-1">
                        <Label>Broadcast frequency (MHz)</Label>
                        <Input
                            type="number"
                            min={87.5}
                            max={108.0}
                            step={0.1}
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            FM band: 87.5–108.0 MHz. The Pi switches to the new frequency within one poll cycle (~30 s).
                        </p>
                        {errors.frequency && <p className="text-xs text-red-400">{errors.frequency}</p>}
                    </div>

                    <div className="space-y-1">
                        <Label>Callsign / Station name</Label>
                        <Input
                            value={data.callsign}
                            onChange={(e) => setData('callsign', e.target.value)}
                            maxLength={64}
                        />
                        <p className="text-xs text-muted-foreground">
                            Used for default RDS station text and Pi display naming.
                        </p>
                        {errors.callsign && <p className="text-xs text-red-400">{errors.callsign}</p>}
                    </div>

                    <div className="space-y-1">
                        <Label>Fallback song filename</Label>
                        <Input
                            value={data.fallback_song}
                            onChange={(e) => setData('fallback_song', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Played when the request queue is empty. This file must exist in the Pi song directory.
                        </p>
                        {errors.fallback_song && <p className="text-xs text-red-400">{errors.fallback_song}</p>}
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Rotation</h2>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label>Songs between commercials</Label>
                            <Input
                                type="number"
                                min={0}
                                max={50}
                                value={data.commercial_interval}
                                onChange={(e) => setData('commercial_interval', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">Use 0 to disable automatic commercials.</p>
                            {errors.commercial_interval && <p className="text-xs text-red-400">{errors.commercial_interval}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label>Songs between sound bytes</Label>
                            <Input
                                type="number"
                                min={0}
                                max={20}
                                value={data.sound_byte_interval}
                                onChange={(e) => setData('sound_byte_interval', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">Use 0 to disable automatic drops.</p>
                            {errors.sound_byte_interval && <p className="text-xs text-red-400">{errors.sound_byte_interval}</p>}
                        </div>
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Transitions</h2>

                    <div className="max-w-xs space-y-1">
                        <Label>Song fade-in duration</Label>
                        <Input
                            type="number"
                            min={0}
                            max={3}
                            step={0.1}
                            value={data.fade_in_duration}
                            onChange={(e) => setData('fade_in_duration', e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Seconds of fade-in applied by the Pi to songs and fallback playback.
                        </p>
                        {errors.fade_in_duration && <p className="text-xs text-red-400">{errors.fade_in_duration}</p>}
                    </div>
                </section>

                <Button type="submit" disabled={processing} className="bg-red-600 text-white hover:bg-red-700">
                    Save Settings
                </Button>
            </form>

            <p className="mt-4 text-xs text-muted-foreground">
                RDS messages and live broadcast control are in{' '}
                <Link href="/admin/broadcast" className="text-red-500 hover:underline">Admin &gt; Broadcast</Link>.
            </p>
        </AdminLayout>
    );
}
