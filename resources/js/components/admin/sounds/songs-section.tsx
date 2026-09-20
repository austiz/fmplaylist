import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LibrarySearch } from '@/components/admin/sounds/library-search';
import { useConfirm } from '@/components/confirm-dialog';
import { FieldError } from '@/components/field-error';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import { cardSkin } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDebouncedSearch } from '@/hooks/use-debounced-search';
import { cn } from '@/lib/utils';
import type { MediaAsset, PaginatedResponse } from '@/types/fm';
import { PiBadge } from './pi-badge';

function SongUploadForm() {
    const form = useForm<{ file: File | null; title: string; artist: string }>({
        file: null,
        title: '',
        artist: '',
    });

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0];

        if (!f) {
            return;
        }

        form.setData('file', f);

        if (!form.data.title) {
            form.setData(
                'title',
                f.name.replace(/\.wav$/i, '').replace(/[_-]/g, ' '),
            );
        }
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/songs/upload', {
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className={cn(cardSkin, 'block space-y-4 p-5')}
        >
            <h3 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Upload Song
            </h3>
            <div className="space-y-1">
                <Label>WAV file (max 50 MB)</Label>
                <input
                    type="file"
                    accept=".wav,audio/wav"
                    onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:tracking-wide file:text-foreground file:uppercase hover:file:bg-card"
                />
                <FieldError message={form.errors.file} />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label>Title</Label>
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="Song title"
                        required
                    />
                </div>
                <div className="space-y-1">
                    <Label>Artist (optional)</Label>
                    <Input
                        value={form.data.artist}
                        onChange={(e) => form.setData('artist', e.target.value)}
                        placeholder="Artist name"
                    />
                </div>
            </div>
            <Button
                type="submit"
                disabled={
                    form.processing || !form.data.file || !form.data.title
                }
                className="h-10 w-full font-display font-bold tracking-wide uppercase disabled:opacity-40"
            >
                {form.processing
                    ? 'Uploading...'
                    : 'Upload — Pi downloads automatically'}
            </Button>
        </form>
    );
}

function SongRow({ song }: { song: MediaAsset }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(false);
    const form = useForm({ title: song.title, artist: song.artist });

    if (editing) {
        return (
            <div className="flex items-center gap-2 px-4 py-2">
                <div className="flex flex-1 flex-col gap-1 sm:flex-row sm:gap-2">
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="Title"
                        className="h-8 text-sm"
                        autoFocus
                    />
                    <Input
                        value={form.data.artist}
                        onChange={(e) => form.setData('artist', e.target.value)}
                        placeholder="Artist"
                        className="h-8 text-sm"
                    />
                </div>
                <Button
                    size="sm"
                    className="shrink-0"
                    disabled={form.processing}
                    onClick={() =>
                        form.patch(`/admin/songs/${song.id}`, {
                            onSuccess: () => setEditing(false),
                        })
                    }
                >
                    Save
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    className="shrink-0"
                    onClick={() => setEditing(false)}
                >
                    Cancel
                </Button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">
                    {song.title}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    {song.artist && <span>{song.artist} · </span>}
                    <span className="font-mono">{song.filename}</span>
                    {song.file_size && (
                        <span>
                            {' '}
                            · {(song.file_size / 1_048_576).toFixed(1)} MB
                        </span>
                    )}
                </p>
            </div>
            {song.duration_formatted && (
                <span className="shrink-0 text-xs text-muted-foreground">
                    {song.duration_formatted}
                </span>
            )}
            <PiBadge
                devicesHave={song.devices_have}
                hasFile={song.has_file}
                deleteRequested={song.pi_delete_requested}
            />
            <button
                onClick={() => setEditing(true)}
                className="shrink-0 text-xs text-muted-foreground hover:text-foreground"
            >
                Edit
            </button>
            <button
                onClick={() => router.patch(`/admin/songs/${song.id}/toggle`)}
                className="shrink-0 text-xs text-muted-foreground hover:text-foreground"
            >
                {song.active ? 'Hide' : 'Show'}
            </button>
            {!song.pi_delete_requested && (
                <button
                    onClick={async () => {
                        const ok = await confirm({
                            title: `Delete "${song.title}"?`,
                            description:
                                'The file is removed from the library and from every Pi holding a copy. This cannot be undone.',
                            confirmLabel: 'Delete',
                            variant: 'destructive',
                        });

                        if (ok) {
                            router.delete(`/admin/songs/${song.id}`);
                        }
                    }}
                    className="shrink-0 text-xs text-destructive/70 hover:text-destructive"
                >
                    Delete
                </button>
            )}
        </div>
    );
}

export function SongsSection({
    songs,
    search,
}: {
    songs: PaginatedResponse<MediaAsset>;
    search: string;
}) {
    const pending = songs.data.filter(
        (s) => s.devices_have === 0 && !s.pi_delete_requested,
    ).length;
    // The tab rides along: search is a fresh visit, and without it the URL
    // would come back pointing at the default tab mid-keystroke.
    const handleSearch = useDebouncedSearch('/admin/sounds', {
        params: { tab: 'songs' },
        only: ['songs'],
    });

    return (
        <div className="space-y-6">
            <SongUploadForm />
            <div className="space-y-3">
                <LibrarySearch
                    placeholder="Search songs..."
                    defaultValue={search}
                    onChange={handleSearch}
                    summary={
                        <>
                            {songs.total} songs
                            {pending > 0 && (
                                <span className="ml-2 text-warning">
                                    {pending} pending
                                </span>
                            )}
                        </>
                    }
                />
                <div
                    className={cn(
                        cardSkin,
                        'divide-y divide-border overflow-hidden',
                    )}
                >
                    {songs.data.map((s) => (
                        <SongRow key={s.id} song={s} />
                    ))}
                    {songs.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                            No songs found
                        </p>
                    )}
                </div>
                <Pagination page={songs} />
            </div>
        </div>
    );
}
