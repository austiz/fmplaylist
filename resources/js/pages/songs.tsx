import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Pagination } from '@/components/pagination';
import { PublicLayout } from '@/components/public-layout';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDebouncedSearch } from '@/hooks/use-debounced-search';
import { pushRecent } from '@/lib/recents';
import type { PaginatedResponse, Song, Station } from '@/types/fm';

interface Props {
    songs: PaginatedResponse<Song>;
    search: string;
    station: Station;
}

function RequestDialog({
    song,
    station,
    onClose,
}: {
    song: Song;
    station: Station;
    onClose: () => void;
}) {
    const { data, setData, post, processing, reset } = useForm({ name: '' });
    const [serverError, setServerError] = useState('');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        setServerError('');
        post(
            `/songs/${song.id}/request?station=${encodeURIComponent(station.slug)}`,
            {
                onSuccess: () => {
                    try {
                        localStorage.setItem(
                            'fm.my_request',
                            JSON.stringify({
                                songId: song.id,
                                title: song.title,
                            }),
                        );
                    } catch {
                        /* private/incognito may block localStorage */
                    }

                    pushRecent({
                        songId: song.id,
                        title: song.title,
                        artist: song.artist ?? '',
                    });

                    try {
                        navigator.vibrate?.(30);
                    } catch {
                        /* unsupported */
                    }

                    reset();
                    onClose();
                },
                onError: (errors) => {
                    setServerError(
                        (errors as Record<string, string>).message ??
                            'Too many requests — try again in a moment.',
                    );
                },
            },
        );
    };

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent
                className="max-sm:top-auto max-sm:right-0 max-sm:bottom-0 max-sm:left-0 max-sm:max-w-none max-sm:translate-x-0 max-sm:translate-y-0 max-sm:rounded-t-2xl max-sm:rounded-b-none sm:max-w-sm"
                style={{ paddingBottom: 'env(safe-area-inset-bottom, 1.5rem)' }}
            >
                <DialogHeader>
                    <DialogTitle className="font-display font-bold">
                        {song.title}
                    </DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4 pt-2">
                    <div className="space-y-2">
                        <Label
                            htmlFor="name"
                            className="text-sm text-muted-foreground"
                        >
                            Your name (optional)
                        </Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Anonymous"
                            maxLength={50}
                            className="h-14 text-base"
                        />
                    </div>
                    {serverError && (
                        <p className="text-sm text-red-400">{serverError}</p>
                    )}
                    <Button
                        type="submit"
                        disabled={processing}
                        className="h-14 w-full bg-red-600 font-display font-bold tracking-wide text-white uppercase hover:bg-red-700 disabled:opacity-60"
                    >
                        {processing ? 'Adding...' : 'Add to Queue'}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        className="h-12 w-full"
                    >
                        Cancel
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Songs({ songs, search, station }: Props) {
    const [requesting, setRequesting] = useState<Song | null>(null);
    const { props } = usePage<{ flash: { success?: string } }>();
    const flash = props.flash;
    const handleSearch = useDebouncedSearch('/songs', {
        params: { station: station.slug },
    });

    return (
        <PublicLayout>
            <div className="space-y-4">
                <div>
                    <p className="font-display text-xs font-bold tracking-[0.2em] text-muted-foreground uppercase">
                        {songs.total} tracks available
                    </p>
                    <h1 className="mt-1 font-display text-3xl font-bold text-foreground">
                        Song Library
                    </h1>
                </div>

                {flash?.success && (
                    <div className="border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                        {flash.success}
                    </div>
                )}

                <Input
                    placeholder="Search by title or artist..."
                    defaultValue={search}
                    onChange={handleSearch}
                    className="h-10 max-w-md"
                />

                <div className="divide-y divide-border border border-border bg-card/40 backdrop-blur-sm">
                    {songs.data.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                            No songs found.
                        </p>
                    )}
                    {songs.data.map((song) => (
                        <div
                            key={song.id}
                            className="group flex cursor-pointer items-center gap-3 px-4 py-3 transition-colors hover:bg-card active:bg-card"
                            onClick={() => setRequesting(song)}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium text-foreground">
                                    {song.title}
                                </p>
                                {song.artist && (
                                    <p className="truncate text-xs text-muted-foreground">
                                        {song.artist}
                                    </p>
                                )}
                            </div>
                            {song.duration_formatted && (
                                <span className="shrink-0 text-xs text-muted-foreground/50 tabular-nums">
                                    {song.duration_formatted}
                                </span>
                            )}
                            <span className="shrink-0 border border-red-500/40 px-2.5 py-1.5 text-xs font-bold tracking-wider text-red-500 uppercase transition-colors group-hover:bg-red-500 group-hover:text-foreground">
                                Request
                            </span>
                        </div>
                    ))}
                </div>

                <Pagination page={songs} variant="listener" />
            </div>

            {requesting && (
                <RequestDialog
                    song={requesting}
                    station={station}
                    onClose={() => setRequesting(null)}
                />
            )}
        </PublicLayout>
    );
}
