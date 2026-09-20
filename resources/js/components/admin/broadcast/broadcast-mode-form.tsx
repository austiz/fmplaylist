import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BroadcastPiStatus } from '@/types/fm';

const MODES = [
    {
        id: 'normal',
        label: 'Normal',
        sub: 'Queue & fallback playback',
        color: 'border-border',
        activeColor: 'border-online bg-online-soft',
    },
    {
        id: 'phone_stream',
        label: 'Phone Stream',
        sub: 'Go live from Larix Broadcaster',
        color: 'border-border',
        activeColor: 'border-live bg-live-soft',
    },
    {
        id: 'usb_input',
        label: 'USB Input',
        sub: 'Broadcast from USB mic / mixer',
        color: 'border-border',
        activeColor: 'border-live bg-live-soft',
    },
    {
        id: 'custom_stream',
        label: 'Custom Stream',
        sub: 'Any RTMP, HLS, or HTTP audio URL',
        color: 'border-border',
        activeColor: 'border-info bg-info-soft',
    },
] as const;

export function BroadcastModeForm({
    settings,
    pi,
}: {
    settings: Record<string, string>;
    pi: BroadcastPiStatus;
}) {
    const modeForm = useForm({
        broadcast_mode: settings.broadcast_mode,
        live_stream_url: settings.live_stream_url,
        live_alsa_device: settings.live_alsa_device,
    });

    const currentMode = modeForm.data.broadcast_mode;
    const rtmpUrl = pi.ip
        ? `rtmp://${pi.ip}:1935/live`
        : 'rtmp://PI_IP:1935/live';

    return (
        <section>
            <h2 className="mb-2 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Broadcast Mode
            </h2>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    modeForm.post('/admin/broadcast/mode');
                }}
                className="space-y-2"
            >
                {MODES.map((m) => (
                    <button
                        key={m.id}
                        type="button"
                        onClick={() => modeForm.setData('broadcast_mode', m.id)}
                        className={`w-full border p-3 text-left transition-colors ${
                            currentMode === m.id
                                ? m.activeColor
                                : 'border-border bg-card hover:bg-secondary'
                        }`}
                    >
                        <p className="text-sm font-semibold text-foreground">
                            {m.label}
                        </p>
                        <p className="text-xs text-muted-foreground">{m.sub}</p>
                    </button>
                ))}

                {/* Extra fields per mode */}
                {currentMode === 'phone_stream' && (
                    <div className="border border-live/30 bg-live-soft p-4 text-sm">
                        <p className="font-semibold text-live">
                            Stream URL for Larix Broadcaster (iOS/Android):
                        </p>
                        <p className="mt-1 font-mono text-live">{rtmpUrl}</p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Larix → Settings → Connections → Add → RTMP → paste
                            URL above → Stream Name: <code>live</code>
                        </p>
                        {!pi.ip && (
                            <p className="mt-2 text-xs text-warning">
                                ⚠ Pi IP unknown — Pi must connect first
                            </p>
                        )}
                    </div>
                )}

                {currentMode === 'usb_input' && (
                    <div className="space-y-1 p-1">
                        <Label>ALSA Device</Label>
                        <Input
                            value={modeForm.data.live_alsa_device}
                            onChange={(e) =>
                                modeForm.setData(
                                    'live_alsa_device',
                                    e.target.value,
                                )
                            }
                            placeholder="hw:1,0"
                            className="font-mono"
                        />
                        <p className="text-xs text-muted-foreground">
                            USB audio interface is usually <code>hw:1,0</code>.
                            Run <code>arecord -l</code> on Pi to list devices.
                        </p>
                    </div>
                )}

                {currentMode === 'custom_stream' && (
                    <div className="space-y-1 p-1">
                        <Label>Stream URL</Label>
                        <Input
                            value={modeForm.data.live_stream_url}
                            onChange={(e) =>
                                modeForm.setData(
                                    'live_stream_url',
                                    e.target.value,
                                )
                            }
                            placeholder="rtmp://... or https://..."
                            className="font-mono"
                        />
                        <p className="text-xs text-muted-foreground">
                            RTMP, HLS, Icecast, or any URL ffmpeg can read.
                        </p>
                    </div>
                )}

                <Button
                    type="submit"
                    disabled={modeForm.processing}
                    className={`h-12 w-full font-display font-bold tracking-wide uppercase ${
                        currentMode === 'normal'
                            ? 'bg-online text-online-foreground hover:bg-online/90'
                            : 'bg-live text-live-foreground hover:bg-live/90'
                    }`}
                >
                    {currentMode === 'normal' ? 'Set to Normal' : 'Go Live'}
                </Button>
            </form>
        </section>
    );
}
