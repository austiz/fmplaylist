import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Commercial, Song, SoundByte } from '@/types/fm';

interface PiInfo {
    online: boolean;
    status: string;
    mode: string;
    ip: string | null;
    last_seen: string | null;
}

interface Props {
    songs: Song[];
    commercials: Pick<Commercial, 'id' | 'title' | 'play_count'>[];
    soundBytes: Pick<SoundByte, 'id' | 'title' | 'category'>[];
    settings: Record<string, string>;
    pi: PiInfo;
    nowPlaying: { title: string; artist: string; type: string } | null;
}

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

export default function Broadcast({ songs, commercials, soundBytes, settings, pi, nowPlaying }: Props) {
    const { props } = usePage<{ flash: { success?: string } }>();

    const modeForm = useForm({
        broadcast_mode:   settings.broadcast_mode   ?? 'normal',
        live_stream_url:  settings.live_stream_url  ?? '',
        live_alsa_device: settings.live_alsa_device ?? 'hw:1,0',
    });

    const rdsForm = useForm({
        rds_rt_mode: settings.rds_rt_mode ?? 'auto',
        rds_rt:      settings.rds_rt      ?? '',
        rds_ps:      settings.rds_ps      ?? '',
    });

    const skipForm      = useForm({});
    const emergencyForm = useForm({});
    const playNowForm   = useForm({ song_id: '' });
    const commercialForm = useForm({ commercial_id: '' });
    const soundByteForm = useForm({ sound_byte_id: '' });
    const [songSearch, setSongSearch] = useState('');
    const [commercialSearch, setCommercialSearch] = useState('');
    const [soundByteSearch, setSoundByteSearch] = useState('');

    const filteredSongs = songs.filter(s =>
        `${s.title} ${s.artist}`.toLowerCase().includes(songSearch.toLowerCase())
    );
    const filteredCommercials = commercials.filter(c =>
        c.title.toLowerCase().includes(commercialSearch.toLowerCase())
    );
    const filteredSoundBytes = soundBytes.filter(s =>
        `${s.title} ${s.category}`.toLowerCase().includes(soundByteSearch.toLowerCase())
    );

    const currentMode = modeForm.data.broadcast_mode;
    const piLive = pi.status === 'live';
    const rtmpUrl = pi.ip ? `rtmp://${pi.ip}:1935/live` : 'rtmp://PI_IP:1935/live';

    return (
        <AdminLayout title="Broadcast">
            {props.flash?.success && (
                <div className="mb-6 border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                    {props.flash.success}
                </div>
            )}

            {/* Emergency Broadcast */}
            <div className="mb-6 border border-red-500/40 bg-red-500/5 p-4">
                <div className="flex items-start justify-between gap-6">
                    <div>
                        <p className="font-display text-xs font-bold uppercase tracking-widest text-red-500">Emergency Broadcast</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Immediately cuts the current song, clears the queue, and plays <code>announcement.wav</code> from the Pi.
                        </p>
                    </div>
                    <Button
                        type="button"
                        disabled={emergencyForm.processing}
                        className="shrink-0 h-12 bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-60"
                        onClick={() => {
                            if (confirm('⚠ This will cut the current song, clear the entire queue, and play the emergency announcement on air. Continue?')) {
                                emergencyForm.post('/admin/broadcast/emergency');
                            }
                        }}
                    >
                        🚨 EMERGENCY
                    </Button>
                </div>
            </div>

            {/* Pi Status Card */}
            <div className={`mb-5 border p-4 ${pi.online ? 'border-green-500/30 bg-green-500/5' : 'border-border bg-card'}`}>
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Pi Status</p>
                        <div className="mt-2 flex flex-wrap items-center gap-4">
                            <span className={`flex items-center gap-1.5 text-sm font-bold ${pi.online ? 'text-green-400' : 'text-muted-foreground/40'}`}>
                                <span className={`h-2 w-2 rounded-full ${pi.online ? 'bg-green-400' : 'bg-muted-foreground/30'}`} />
                                {pi.online ? 'Connected' : 'Offline'}
                            </span>
                            <span className={`flex items-center gap-1.5 text-sm font-bold ${pi.status === 'playing' ? 'text-red-400' : 'text-muted-foreground/30'}`}>
                                <span className={`h-2 w-2 rounded-full ${pi.status === 'playing' ? 'animate-pulse bg-red-400' : 'bg-muted-foreground/20'}`} />
                                Playing
                            </span>
                            <span className={`flex items-center gap-1.5 text-sm font-bold ${piLive ? 'text-violet-400' : 'text-muted-foreground/30'}`}>
                                <span className={`h-2 w-2 rounded-full ${piLive ? 'animate-pulse bg-violet-400' : 'bg-muted-foreground/20'}`} />
                                Live
                            </span>
                        </div>
                        {pi.online && pi.ip && (
                            <p className="mt-1 text-xs text-muted-foreground">Pi IP: <span className="font-mono text-foreground">{pi.ip}</span></p>
                        )}
                        {pi.last_seen && (
                            <p className="mt-0.5 text-xs text-muted-foreground/50">Last seen {pi.last_seen}</p>
                        )}
                    </div>
                    {nowPlaying && (
                        <div className="text-right">
                            <p className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Now Playing</p>
                            <p className="mt-1 text-sm font-semibold text-foreground">{nowPlaying.title}</p>
                            {nowPlaying.artist && <p className="text-xs text-muted-foreground">{nowPlaying.artist}</p>}
                        </div>
                    )}
                </div>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                {/* Left column — Broadcast Mode */}
                <div className="space-y-6">
                    <section>
                        <h2 className="mb-2 font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Broadcast Mode</h2>
                        <form
                            onSubmit={(e) => {
 e.preventDefault(); modeForm.post('/admin/broadcast/mode'); 
}}
                            className="space-y-2"
                        >
                            {MODES.map((m) => (
                                <button
                                    key={m.id}
                                    type="button"
                                    onClick={() => modeForm.setData('broadcast_mode', m.id)}
                                    className={`w-full border p-3 text-left transition-colors ${
                                        currentMode === m.id ? m.activeColor : 'border-border bg-card hover:bg-secondary'
                                    }`}
                                >
                                    <p className="text-sm font-semibold text-foreground">{m.label}</p>
                                    <p className="text-xs text-muted-foreground">{m.sub}</p>
                                </button>
                            ))}

                            {/* Extra fields per mode */}
                            {currentMode === 'phone_stream' && (
                                <div className="border border-violet-500/20 bg-violet-500/5 p-4 text-sm">
                                    <p className="font-semibold text-violet-300">Stream URL for Larix Broadcaster (iOS/Android):</p>
                                    <p className="mt-1 font-mono text-violet-400">{rtmpUrl}</p>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Larix → Settings → Connections → Add → RTMP → paste URL above → Stream Name: <code>live</code>
                                    </p>
                                    {!pi.ip && (
                                        <p className="mt-2 text-xs text-yellow-500">⚠ Pi IP unknown — Pi must connect first</p>
                                    )}
                                </div>
                            )}

                            {currentMode === 'usb_input' && (
                                <div className="space-y-1 p-1">
                                    <Label>ALSA Device</Label>
                                    <Input
                                        value={modeForm.data.live_alsa_device}
                                        onChange={(e) => modeForm.setData('live_alsa_device', e.target.value)}
                                        placeholder="hw:1,0"
                                        className="font-mono"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        USB audio interface is usually <code>hw:1,0</code>. Run <code>arecord -l</code> on Pi to list devices.
                                    </p>
                                </div>
                            )}

                            {currentMode === 'custom_stream' && (
                                <div className="space-y-1 p-1">
                                    <Label>Stream URL</Label>
                                    <Input
                                        value={modeForm.data.live_stream_url}
                                        onChange={(e) => modeForm.setData('live_stream_url', e.target.value)}
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
                                className={`h-12 w-full font-display font-bold uppercase tracking-wide ${
                                    currentMode === 'normal'
                                        ? 'bg-green-600 text-white hover:bg-green-700'
                                        : 'bg-violet-600 text-white hover:bg-violet-700'
                                }`}
                            >
                                {currentMode === 'normal' ? 'Set to Normal' : 'Go Live'}
                            </Button>
                        </form>
                    </section>

                    {/* Song controls */}
                    <section>
                        <h2 className="mb-2 font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Song Control</h2>
                        <div className="space-y-2">
                            {/* Skip */}
                            <Button
                                type="button"
                                variant="outline"
                                disabled={skipForm.processing}
                                className="h-12 w-full border-red-500/30 text-red-400 hover:bg-red-500/10"
                                onClick={() => {
                                    if (confirm('Skip current song?')) {
skipForm.post('/admin/broadcast/skip');
}
                                }}
                            >
                                Skip Current Song
                            </Button>

                            {/* Play Now */}
                            <form onSubmit={(e) => {
 e.preventDefault(); playNowForm.post('/admin/broadcast/play-now'); 
}}
                                className="space-y-2">
                                <Input
                                    placeholder="Search songs..."
                                    value={songSearch}
                                    onChange={(e) => setSongSearch(e.target.value)}
                                />
                                <div className="max-h-48 divide-y divide-border overflow-y-auto border border-border bg-card">
                                    {filteredSongs.slice(0, 30).map((song) => (
                                        <button
                                            key={song.id}
                                            type="button"
                                            onClick={() => playNowForm.setData('song_id', String(song.id))}
                                            className={`w-full px-4 py-2.5 text-left text-sm transition-colors hover:bg-secondary ${
                                                playNowForm.data.song_id === String(song.id)
                                                    ? 'bg-red-500/10 text-foreground'
                                                    : 'text-foreground'
                                            }`}
                                        >
                                            <span className="font-medium">{song.title}</span>
                                            {song.artist && <span className="ml-2 text-xs text-muted-foreground">{song.artist}</span>}
                                        </button>
                                    ))}
                                    {filteredSongs.length === 0 && (
                                        <p className="px-4 py-3 text-sm text-muted-foreground">No songs found</p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={playNowForm.processing || !playNowForm.data.song_id}
                                    className="h-12 w-full bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-40"
                                >
                                    Play Now
                                </Button>
                            </form>
                        </div>
                    </section>

                    <section>
                        <h2 className="mb-2 font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Breaks & Drops</h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    commercialForm.post('/admin/broadcast/force-commercial');
                                }}
                                className="space-y-2 border border-border bg-card p-3"
                            >
                                <div>
                                    <p className="text-sm font-semibold text-foreground">Commercial</p>
                                    <p className="text-xs text-muted-foreground">Inject a spot on the next Pi poll.</p>
                                </div>
                                <Input
                                    placeholder="Search commercials..."
                                    value={commercialSearch}
                                    onChange={(e) => setCommercialSearch(e.target.value)}
                                />
                                <div className="max-h-40 divide-y divide-border overflow-y-auto border border-border">
                                    {filteredCommercials.slice(0, 20).map((commercial) => (
                                        <button
                                            key={commercial.id}
                                            type="button"
                                            onClick={() => commercialForm.setData('commercial_id', String(commercial.id))}
                                            className={`w-full px-3 py-2 text-left text-sm transition-colors hover:bg-secondary ${
                                                commercialForm.data.commercial_id === String(commercial.id)
                                                    ? 'bg-red-500/10 text-foreground'
                                                    : 'text-foreground'
                                            }`}
                                        >
                                            <span className="font-medium">{commercial.title}</span>
                                            <span className="ml-2 text-xs text-muted-foreground">played {commercial.play_count ?? 0}</span>
                                        </button>
                                    ))}
                                    {filteredCommercials.length === 0 && (
                                        <p className="px-3 py-3 text-sm text-muted-foreground">No commercials found</p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={commercialForm.processing || !commercialForm.data.commercial_id}
                                    className="h-10 w-full bg-red-600 text-white hover:bg-red-700 disabled:opacity-40"
                                >
                                    Play Commercial
                                </Button>
                            </form>

                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    soundByteForm.post('/admin/broadcast/force-sound-byte');
                                }}
                                className="space-y-2 border border-border bg-card p-3"
                            >
                                <div>
                                    <p className="text-sm font-semibold text-foreground">Sound Byte</p>
                                    <p className="text-xs text-muted-foreground">Trigger a jingle, shoutout, ID, or drop.</p>
                                </div>
                                <Input
                                    placeholder="Search sound bytes..."
                                    value={soundByteSearch}
                                    onChange={(e) => setSoundByteSearch(e.target.value)}
                                />
                                <div className="max-h-40 divide-y divide-border overflow-y-auto border border-border">
                                    {filteredSoundBytes.slice(0, 20).map((soundByte) => (
                                        <button
                                            key={soundByte.id}
                                            type="button"
                                            onClick={() => soundByteForm.setData('sound_byte_id', String(soundByte.id))}
                                            className={`w-full px-3 py-2 text-left text-sm transition-colors hover:bg-secondary ${
                                                soundByteForm.data.sound_byte_id === String(soundByte.id)
                                                    ? 'bg-red-500/10 text-foreground'
                                                    : 'text-foreground'
                                            }`}
                                        >
                                            <span className="font-medium">{soundByte.title}</span>
                                            <span className="ml-2 text-xs uppercase text-muted-foreground">{soundByte.category}</span>
                                        </button>
                                    ))}
                                    {filteredSoundBytes.length === 0 && (
                                        <p className="px-3 py-3 text-sm text-muted-foreground">No sound bytes found</p>
                                    )}
                                </div>
                                <Button
                                    type="submit"
                                    disabled={soundByteForm.processing || !soundByteForm.data.sound_byte_id}
                                    className="h-10 w-full bg-red-600 text-white hover:bg-red-700 disabled:opacity-40"
                                >
                                    Play Sound Byte
                                </Button>
                            </form>
                        </div>
                    </section>
                </div>

                {/* Right column — RDS Editor */}
                <div>
                    <h2 className="mb-2 font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">RDS Messages</h2>
                    <form
                        onSubmit={(e) => {
 e.preventDefault(); rdsForm.post('/admin/broadcast/rds'); 
}}
                        className="space-y-4 border border-border bg-card p-4"
                    >
                        {/* PS override */}
                        <div className="space-y-1">
                            <Label>Program Service (PS) — station name on radio display</Label>
                            <Input
                                value={rdsForm.data.rds_ps}
                                onChange={(e) => rdsForm.setData('rds_ps', e.target.value.slice(0, 8))}
                                placeholder="96.9 FM  (leave blank to use Callsign)"
                                maxLength={8}
                                className="font-mono"
                            />
                            <p className="text-xs text-muted-foreground">
                                Max 8 chars. Shown as the station name on every car radio. Leave blank to use the callsign from Settings.
                            </p>
                            {rdsForm.data.rds_ps && (
                                <p className="font-mono text-xs text-muted-foreground">
                                    Preview: <span className="text-foreground">&ldquo;{rdsForm.data.rds_ps.padEnd(8)}&rdquo;</span>
                                </p>
                            )}
                        </div>

                        {/* RT mode toggle */}
                        <div className="space-y-2">
                            <Label>RadioText (RT) — scrolling message on radio display</Label>
                            <div className="flex gap-2">
                                {(['auto', 'custom'] as const).map((m) => (
                                    <button
                                        key={m}
                                        type="button"
                                        onClick={() => rdsForm.setData('rds_rt_mode', m)}
                                        className={`flex-1 border py-2 text-xs font-bold uppercase tracking-widest transition-colors ${
                                            rdsForm.data.rds_rt_mode === m
                                                ? 'border-red-500 bg-red-500/10 text-red-400'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {m === 'auto' ? 'Auto (song title)' : 'Custom message'}
                                    </button>
                                ))}
                            </div>
                            {rdsForm.data.rds_rt_mode === 'custom' && (
                                <div className="space-y-1">
                                    <Input
                                        value={rdsForm.data.rds_rt}
                                        onChange={(e) => rdsForm.setData('rds_rt', e.target.value.slice(0, 64))}
                                        placeholder="e.g. REQUEST SONGS AT FMPLAYLIST.COM"
                                        maxLength={64}
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        {rdsForm.data.rds_rt.length}/64 chars. Scrolls across the radio display on all RDS radios.
                                    </p>
                                </div>
                            )}
                            {rdsForm.data.rds_rt_mode === 'auto' && (
                                <p className="text-xs text-muted-foreground">
                                    RadioText shows the current song title and artist automatically.
                                </p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            disabled={rdsForm.processing}
                            className="h-12 w-full bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700"
                        >
                            Save RDS Settings
                        </Button>

                        <p className="text-xs text-muted-foreground/50">
                            Changes apply to Pi within 30 seconds (next heartbeat).
                        </p>
                    </form>

                    {/* Reference */}
                    <div className="mt-4 border border-border bg-card p-4 text-xs text-muted-foreground">
                        <p className="mb-2 font-display font-bold uppercase tracking-widest text-foreground">Radio Display Preview</p>
                        <div className="font-mono leading-relaxed">
                            <p className="text-foreground">┌──────────────────────┐</p>
                            <p><span className="text-foreground">│ </span><span className="text-yellow-400">{(rdsForm.data.rds_ps || settings.rds_ps || '96.9 FM ').padEnd(8).slice(0,8)}</span><span className="text-foreground">              │</span></p>
                            <p><span className="text-foreground">│ </span><span className="text-green-400 truncate">{rdsForm.data.rds_rt_mode === 'custom' ? (rdsForm.data.rds_rt || 'Custom message here...').slice(0,20) : 'Song Title - Artist'}</span><span className="text-foreground"> │</span></p>
                            <p className="text-foreground">└──────────────────────┘</p>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    );
}
