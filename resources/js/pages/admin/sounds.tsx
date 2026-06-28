import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Commercial, PaginatedResponse, SoundByte } from '@/types/fm';

// ── Types ─────────────────────────────────────────────────────────────────────

interface AdminSong {
    id: number;
    title: string;
    artist: string;
    filename: string;
    duration_formatted?: string;
    file_size: number | null;
    available: boolean;
    web_uploaded: boolean;
    needs_pi_download: boolean;
    pi_delete_requested: boolean;
    created_at: string;
}

interface Props {
    songs: PaginatedResponse<AdminSong>;
    commercials: Commercial[];
    soundBytes: SoundByte[];
}

type Tab = 'songs' | 'commercials' | 'sound-bytes';

const CATEGORIES = [
    { value: 'jingle',   label: 'Jingle'   },
    { value: 'shoutout', label: 'Shoutout' },
    { value: 'drop',     label: 'Drop'     },
    { value: 'id',       label: 'ID'       },
] as const;

type Category = (typeof CATEGORIES)[number]['value'];

// ── Shared status badge ────────────────────────────────────────────────────────

function PiBadge({ needsDownload, deleteRequested, active }: {
    needsDownload?: boolean;
    deleteRequested?: boolean;
    active?: boolean;
}) {
    if (deleteRequested) {
return <span className="shrink-0 bg-red-500/15 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-red-400">Deleting</span>;
}

    if (needsDownload)   {
return <span className="shrink-0 bg-yellow-500/15 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-yellow-400">Pending ↓</span>;
}

    if (active === false) {
return <span className="shrink-0 bg-secondary px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-muted-foreground/50">Off</span>;
}

    return <span className="shrink-0 bg-green-500/15 px-2 py-0.5 text-xs font-bold uppercase tracking-wide text-green-400">On Pi</span>;
}

// ── Songs section ─────────────────────────────────────────────────────────────

function SongUploadForm() {
    const form = useForm<{ file: File | null; title: string; artist: string }>({ file: null, title: '', artist: '' });

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0];

        if (!f) {
return;
}

        form.setData('file', f);

        if (!form.data.title) {
form.setData('title', f.name.replace(/\.wav$/i, '').replace(/[_-]/g, ' '));
}
    };

    return (
        <form
            onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/songs/upload', { forceFormData: true, onSuccess: () => form.reset() }); 
}}
            className="space-y-4 border border-border bg-card p-5"
        >
            <h3 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Upload Song</h3>
            <div className="space-y-1">
                <Label>WAV file (max 50 MB)</Label>
                <input type="file" accept=".wav,audio/wav" onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wide file:text-foreground hover:file:bg-card" />
                {form.errors.file && <p className="text-xs text-red-400">{form.errors.file}</p>}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label>Title</Label>
                    <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Song title" required />
                </div>
                <div className="space-y-1">
                    <Label>Artist (optional)</Label>
                    <Input value={form.data.artist} onChange={(e) => form.setData('artist', e.target.value)} placeholder="Artist name" />
                </div>
            </div>
            <Button type="submit" disabled={form.processing || !form.data.file || !form.data.title}
                className="h-10 w-full bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-40">
                {form.processing ? 'Uploading...' : 'Upload — Pi downloads automatically'}
            </Button>
        </form>
    );
}

function SongRow({ song }: { song: AdminSong }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ title: song.title, artist: song.artist });

    if (editing) {
        return (
            <div className="flex items-center gap-2 px-4 py-2">
                <div className="flex flex-1 flex-col gap-1 sm:flex-row sm:gap-2">
                    <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Title" className="h-8 text-sm" autoFocus />
                    <Input value={form.data.artist} onChange={(e) => form.setData('artist', e.target.value)} placeholder="Artist" className="h-8 text-sm" />
                </div>
                <Button size="sm" className="shrink-0 bg-red-600 text-white hover:bg-red-700" disabled={form.processing}
                    onClick={() => form.patch(`/admin/songs/${song.id}`, { onSuccess: () => setEditing(false) })}>Save</Button>
                <Button size="sm" variant="outline" className="shrink-0" onClick={() => setEditing(false)}>Cancel</Button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">{song.title}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {song.artist && <span>{song.artist} · </span>}
                    <span className="font-mono">{song.filename}</span>
                    {song.file_size && <span> · {(song.file_size / 1_048_576).toFixed(1)} MB</span>}
                </p>
            </div>
            {song.duration_formatted && <span className="shrink-0 text-xs text-muted-foreground">{song.duration_formatted}</span>}
            <PiBadge needsDownload={song.needs_pi_download} deleteRequested={song.pi_delete_requested} />
            <button onClick={() => setEditing(true)} className="shrink-0 text-xs text-muted-foreground hover:text-foreground">Edit</button>
            <button onClick={() => router.patch(`/admin/songs/${song.id}/toggle`)} className="shrink-0 text-xs text-muted-foreground hover:text-foreground">
                {song.available ? 'Hide' : 'Show'}
            </button>
            {!song.pi_delete_requested && (
                <button onClick={() => {
 if (confirm(`Delete "${song.title}"?`)) {
router.delete(`/admin/songs/${song.id}`);
} 
}}
                    className="shrink-0 text-xs text-red-500/70 hover:text-red-400">Delete</button>
            )}
        </div>
    );
}

function SongsSection({ songs }: { songs: PaginatedResponse<AdminSong> }) {
    const [search, setSearch] = useState('');
    const filtered = songs.data.filter(s => `${s.title} ${s.artist} ${s.filename}`.toLowerCase().includes(search.toLowerCase()));
    const pending  = songs.data.filter(s => s.needs_pi_download).length;

    return (
        <div className="space-y-6">
            <SongUploadForm />
            <div className="space-y-3">
                <div className="flex items-center gap-3">
                    <Input placeholder="Search songs..." value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-xs" />
                    <span className="text-xs text-muted-foreground">
                        {songs.total} songs
                        {pending > 0 && <span className="ml-2 text-yellow-400">{pending} pending</span>}
                    </span>
                </div>
                <div className="divide-y divide-border border border-border bg-card">
                    {filtered.map(s => <SongRow key={s.id} song={s} />)}
                    {filtered.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted-foreground">No songs found</p>}
                </div>
                {songs.last_page > 1 && (
                    <div className="flex justify-center gap-1">
                        {songs.links.map((link, i) =>
                            link.url
                                ? <Link key={i} href={link.url} className={`px-3 py-1.5 text-xs font-medium ${link.active ? 'bg-red-600 text-white' : 'border border-border text-muted-foreground hover:text-foreground'}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                                : <span key={i} className="px-3 py-1.5 text-xs text-muted-foreground/30" dangerouslySetInnerHTML={{ __html: link.label }} />
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}

// ── Commercials section ───────────────────────────────────────────────────────

function CommercialUploadForm() {
    const form = useForm<{ file: File | null; title: string }>({ file: null, title: '' });

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0];

        if (!f) {
return;
}

        form.setData('file', f);

        if (!form.data.title) {
form.setData('title', f.name.replace(/\.(wav|mp3|ogg)$/i, '').replace(/[_-]/g, ' '));
}
    };

    return (
        <form
            onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/commercials/upload', { forceFormData: true, onSuccess: () => form.reset() }); 
}}
            className="space-y-4 border border-border bg-card p-5"
        >
            <h3 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Upload Commercial</h3>
            <div className="space-y-1">
                <Label>WAV / MP3 / OGG (max 50 MB)</Label>
                <input type="file" accept=".wav,.mp3,.ogg,audio/wav,audio/mpeg,audio/ogg" onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wide file:text-foreground hover:file:bg-card" />
                {form.errors.file && <p className="text-xs text-red-400">{form.errors.file}</p>}
            </div>
            <div className="space-y-1">
                <Label>Title</Label>
                <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Sponsor spot, PSA, promo" required />
                {form.errors.title && <p className="text-xs text-red-400">{form.errors.title}</p>}
            </div>
            <Button type="submit" disabled={form.processing || !form.data.file || !form.data.title}
                className="h-10 w-full bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-40">
                {form.processing ? 'Uploading...' : 'Upload Commercial'}
            </Button>
        </form>
    );
}

function CommercialRow({ commercial }: { commercial: Commercial }) {
    const [editing, setEditing] = useState(false);
    const editForm = useForm({ title: commercial.title, rotation_order: String(commercial.rotation_order ?? 0) });
    const playForm = useForm({ commercial_id: String(commercial.id) });

    if (editing) {
        return (
            <div className="grid gap-2 px-4 py-3 md:grid-cols-[1fr_120px_auto_auto]">
                <Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} placeholder="Title" autoFocus />
                <Input type="number" min={0} value={editForm.data.rotation_order} onChange={(e) => editForm.setData('rotation_order', e.target.value)} placeholder="Order" />
                <Button size="sm" disabled={editForm.processing} className="bg-red-600 text-white hover:bg-red-700"
                    onClick={() => editForm.patch(`/admin/commercials/${commercial.id}`, { onSuccess: () => setEditing(false) })}>Save</Button>
                <Button size="sm" variant="outline" onClick={() => setEditing(false)}>Cancel</Button>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">{commercial.title}</p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="font-mono">{commercial.filename}</span>
                    {commercial.file_size ? <span> · {(commercial.file_size / 1_048_576).toFixed(1)} MB</span> : null}
                    <span> · order {commercial.rotation_order ?? 0} · played {commercial.play_count ?? 0}×</span>
                </p>
            </div>
            <PiBadge needsDownload={commercial.needs_pi_download} deleteRequested={commercial.pi_delete_requested} active={commercial.active} />
            <button onClick={() => playForm.post('/admin/broadcast/force-commercial')}
                disabled={!commercial.active || commercial.pi_delete_requested || playForm.processing}
                className="text-xs text-red-500 hover:text-red-400 disabled:text-muted-foreground/30">Play Now</button>
            <button onClick={() => setEditing(true)} className="text-xs text-muted-foreground hover:text-foreground">Edit</button>
            <button onClick={() => router.patch(`/admin/commercials/${commercial.id}/toggle`)} className="text-xs text-muted-foreground hover:text-foreground">
                {commercial.active ? 'Disable' : 'Enable'}
            </button>
            {!commercial.pi_delete_requested && (
                <button onClick={() => {
 if (confirm(`Delete "${commercial.title}"?`)) {
router.delete(`/admin/commercials/${commercial.id}`);
} 
}}
                    className="text-xs text-red-500/70 hover:text-red-400">Delete</button>
            )}
        </div>
    );
}

function CommercialsSection({ commercials }: { commercials: Commercial[] }) {
    const [search, setSearch] = useState('');
    const filtered = commercials.filter(c => `${c.title} ${c.filename ?? ''}`.toLowerCase().includes(search.toLowerCase()));

    return (
        <div className="space-y-6">
            <CommercialUploadForm />
            <div className="space-y-3">
                <div className="flex items-center gap-3">
                    <Input placeholder="Search commercials..." value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-xs" />
                    <span className="text-xs text-muted-foreground">{commercials.length} commercials</span>
                </div>
                <div className="divide-y divide-border border border-border bg-card">
                    {filtered.map(c => <CommercialRow key={c.id} commercial={c} />)}
                    {filtered.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted-foreground">No commercials yet</p>}
                </div>
            </div>
        </div>
    );
}

// ── Sound Bytes section ───────────────────────────────────────────────────────

function SoundByteUploadForm() {
    const form = useForm<{ file: File | null; title: string; category: Category }>({ file: null, title: '', category: 'jingle' });

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0];

        if (!f) {
return;
}

        form.setData('file', f);

        if (!form.data.title) {
form.setData('title', f.name.replace(/\.(wav|mp3|ogg)$/i, '').replace(/[_-]/g, ' '));
}
    };

    return (
        <form
            onSubmit={(e) => {
 e.preventDefault(); form.post('/admin/sound-bytes/upload', { forceFormData: true, onSuccess: () => form.reset() }); 
}}
            className="space-y-4 border border-border bg-card p-5"
        >
            <h3 className="font-display text-xs font-bold uppercase tracking-widest text-muted-foreground">Upload Sound Byte</h3>
            <div className="space-y-1">
                <Label>WAV / MP3 / OGG (max 20 MB)</Label>
                <input type="file" accept=".wav,.mp3,.ogg,audio/wav,audio/mpeg,audio/ogg" onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:uppercase file:tracking-wide file:text-foreground hover:file:bg-card" />
                {form.errors.file && <p className="text-xs text-red-400">{form.errors.file}</p>}
            </div>
            <div className="grid gap-3 sm:grid-cols-[1fr_160px]">
                <div className="space-y-1">
                    <Label>Title</Label>
                    <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Laser drop, local shoutout, sweep" required />
                    {form.errors.title && <p className="text-xs text-red-400">{form.errors.title}</p>}
                </div>
                <div className="space-y-1">
                    <Label>Category</Label>
                    <Select value={form.data.category} onValueChange={(v) => form.setData('category', v as Category)}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {CATEGORIES.map(cat => <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <Button type="submit" disabled={form.processing || !form.data.file || !form.data.title}
                className="h-10 w-full bg-red-600 font-display font-bold uppercase tracking-wide text-white hover:bg-red-700 disabled:opacity-40">
                {form.processing ? 'Uploading...' : 'Upload Sound Byte'}
            </Button>
        </form>
    );
}

function SoundByteRow({ soundByte }: { soundByte: SoundByte }) {
    const [editing, setEditing] = useState(false);
    const editForm = useForm({ title: soundByte.title, category: soundByte.category, rds_ps: soundByte.rds_ps ?? '' });
    const playForm = useForm({ sound_byte_id: String(soundByte.id) });

    if (editing) {
        return (
            <div className="space-y-2 px-4 py-3">
                <div className="grid gap-2 md:grid-cols-[1fr_160px_120px]">
                    <Input value={editForm.data.title} onChange={(e) => editForm.setData('title', e.target.value)} placeholder="Title" autoFocus />
                    <Select value={editForm.data.category} onValueChange={(v) => editForm.setData('category', v as Category)}>
                        <SelectTrigger><SelectValue /></SelectTrigger>
                        <SelectContent>
                            {CATEGORIES.map(cat => <SelectItem key={cat.value} value={cat.value}>{cat.label}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <div className="space-y-1">
                        <Input
                            value={editForm.data.rds_ps}
                            onChange={(e) => editForm.setData('rds_ps', e.target.value.toUpperCase().slice(0, 8))}
                            placeholder="RDS (8 ch)"
                            maxLength={8}
                            className="font-mono uppercase"
                        />
                        {editForm.errors.rds_ps && <p className="text-xs text-red-400">{editForm.errors.rds_ps}</p>}
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">RDS PS shown on radio dial while this byte plays. Leave blank to use station default.</p>
                <div className="flex gap-2">
                    <Button size="sm" disabled={editForm.processing} className="bg-red-600 text-white hover:bg-red-700"
                        onClick={() => editForm.patch(`/admin/sound-bytes/${soundByte.id}`, { onSuccess: () => setEditing(false) })}>Save</Button>
                    <Button size="sm" variant="outline" onClick={() => setEditing(false)}>Cancel</Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">{soundByte.title}</p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="uppercase tracking-wide">{soundByte.category}</span>
                    {soundByte.rds_ps && <span className="ml-1 font-mono text-red-500/70">[{soundByte.rds_ps}]</span>}
                    <span> · </span>
                    <span className="font-mono">{soundByte.filename}</span>
                    {soundByte.file_size ? <span> · {(soundByte.file_size / 1_048_576).toFixed(1)} MB</span> : null}
                </p>
            </div>
            <PiBadge needsDownload={soundByte.needs_pi_download} deleteRequested={soundByte.pi_delete_requested} active={soundByte.active} />
            <button onClick={() => playForm.post('/admin/broadcast/force-sound-byte')}
                disabled={!soundByte.active || soundByte.pi_delete_requested || playForm.processing}
                className="text-xs text-red-500 hover:text-red-400 disabled:text-muted-foreground/30">Play Now</button>
            <button onClick={() => setEditing(true)} className="text-xs text-muted-foreground hover:text-foreground">Edit</button>
            <button onClick={() => router.patch(`/admin/sound-bytes/${soundByte.id}/toggle`)} className="text-xs text-muted-foreground hover:text-foreground">
                {soundByte.active ? 'Disable' : 'Enable'}
            </button>
            {!soundByte.pi_delete_requested && (
                <button onClick={() => {
 if (confirm(`Delete "${soundByte.title}"?`)) {
router.delete(`/admin/sound-bytes/${soundByte.id}`);
} 
}}
                    className="text-xs text-red-500/70 hover:text-red-400">Delete</button>
            )}
        </div>
    );
}

function SoundBytesSection({ soundBytes }: { soundBytes: SoundByte[] }) {
    const [search, setSearch] = useState('');
    const filtered = soundBytes.filter(sb => `${sb.title} ${sb.category} ${sb.filename ?? ''}`.toLowerCase().includes(search.toLowerCase()));

    return (
        <div className="space-y-6">
            <SoundByteUploadForm />
            <div className="space-y-3">
                <div className="flex items-center gap-3">
                    <Input placeholder="Search sound bytes..." value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-xs" />
                    <span className="text-xs text-muted-foreground">{soundBytes.length} sound bytes</span>
                </div>
                <div className="divide-y divide-border border border-border bg-card">
                    {filtered.map(sb => <SoundByteRow key={sb.id} soundByte={sb} />)}
                    {filtered.length === 0 && <p className="px-4 py-8 text-center text-sm text-muted-foreground">No sound bytes yet</p>}
                </div>
            </div>
        </div>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

const TABS: { key: Tab; label: (p: Props) => string }[] = [
    { key: 'songs',       label: (p) => `Songs (${p.songs.total})` },
    { key: 'commercials', label: (p) => `Commercials (${p.commercials.length})` },
    { key: 'sound-bytes', label: (p) => `Sound Bytes (${p.soundBytes.length})` },
];

export default function Sounds(props: Props) {
    const { songs, commercials, soundBytes } = props;
    const { props: page } = usePage<{ flash: { success?: string } }>();
    const [tab, setTab] = useState<Tab>('songs');

    return (
        <AdminLayout title="Sounds">
            {page.flash?.success && (
                <div className="mb-6 border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                    {page.flash.success}
                </div>
            )}

            {/* Tab bar */}
            <div className="mb-6 flex border-b border-border">
                {TABS.map(({ key, label }) => (
                    <button
                        key={key}
                        onClick={() => setTab(key)}
                        className={`px-4 pb-3 pt-1 text-sm font-medium transition-colors border-b-2 -mb-px ${
                            tab === key
                                ? 'border-red-500 text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        {label(props)}
                    </button>
                ))}
            </div>

            {tab === 'songs'       && <SongsSection songs={songs} />}
            {tab === 'commercials' && <CommercialsSection commercials={commercials} />}
            {tab === 'sound-bytes' && <SoundBytesSection soundBytes={soundBytes} />}
        </AdminLayout>
    );
}
