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
        activeColor: 'border-green-500 bg-green-500/5',
    },
    {
        id: 'phone_stream',
        label: 'Phone Stream',
        sub: 'Go live from Larix Broadcaster',
        color: 'border-border',
        activeColor: 'border-violet-500 bg-violet-500/5',
    },
    {
        id: 'usb_input',
        label: 'USB Input',
        sub: 'Broadcast from USB mic / mixer',
        color: 'border-border',
        activeColor: 'border-violet-500 bg-violet-500/5',
    },
    {
        id: 'custom_stream',
        label: 'Custom Stream',
        sub: 'Any RTMP, HLS, or HTTP audio URL',
        color: 'border-border',
        activeColor: 'border-blue-500 bg-blue-500/5',
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
                    <div className="border border-violet-500/20 bg-violet-500/5 p-4 text-sm">
                        <p className="font-semibold text-violet-300">
                            Stream URL for Larix Broadcaster (iOS/Android):
                        </p>
                        <p className="mt-1 font-mono text-violet-400">
                            {rtmpUrl}
                        </p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            Larix → Settings → Connections → Add → RTMP → paste
                            URL above → Stream Name: <code>live</code>
                        </p>
                        {!pi.ip && (
                            <p className="mt-2 text-xs text-yellow-500">
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
                            ? 'bg-green-600 text-white hover:bg-green-700'
                            : 'bg-violet-600 text-white hover:bg-violet-700'
                    }`}
                >
                    {currentMode === 'normal' ? 'Set to Normal' : 'Go Live'}
                </Button>
            </form>
        </section>
    );
}
