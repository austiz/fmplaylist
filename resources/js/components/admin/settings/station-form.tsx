import { Link, useForm } from '@inertiajs/react';
import { FieldError } from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { cardSkin } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Frequency, callsign, rotation intervals and fade -- everything the Pi reads as config. */
export function StationForm({
    settings,
}: {
    settings: Record<string, string>;
}) {
    const { data, setData, post, processing, errors } = useForm({
        frequency: settings.frequency,
        callsign: settings.callsign,
        fallback_song: settings.fallback_song,
        commercial_interval: settings.commercial_interval,
        sound_byte_interval: settings.sound_byte_interval,
        fade_in_duration: settings.fade_in_duration,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/admin/settings');
    };

    return (
        <>
            <form
                onSubmit={submit}
                className={cn(cardSkin, 'block max-w-2xl space-y-6 p-5')}
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
                        <FieldError message={errors.frequency} />
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
                        <FieldError message={errors.callsign} />
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
                        <FieldError message={errors.fallback_song} />
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
                            <FieldError message={errors.commercial_interval} />
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
                            <FieldError message={errors.sound_byte_interval} />
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
                        <FieldError message={errors.fade_in_duration} />
                    </div>
                </section>

                <Button type="submit" disabled={processing}>
                    Save Settings
                </Button>
            </form>

            <p className="mt-4 text-xs text-muted-foreground">
                RDS messages and live broadcast control are in{' '}
                <Link
                    href="/admin/broadcast"
                    className="text-destructive hover:underline"
                >
                    Admin &gt; Broadcast
                </Link>
                .
            </p>
        </>
    );
}
