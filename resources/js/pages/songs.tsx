import { useForm } from '@inertiajs/react';
import { SearchX } from 'lucide-react';
import { useState } from 'react';

import { EmptyState } from '@/components/empty-state';
import { FieldError } from '@/components/field-error';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { PublicLayout } from '@/components/public-layout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
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
                    {song.artist && (
                        <p className="text-sm text-muted-foreground">
                            {song.artist}
                        </p>
                    )}
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
                    <FieldError message={serverError} />
                    <Button
                        type="submit"
                        disabled={processing}
                        className="h-14 w-full font-display font-bold tracking-wide uppercase disabled:opacity-60"
                    >
                        {processing ? 'Adding…' : 'Add to queue'}
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
    const handleSearch = useDebouncedSearch('/songs', {
        params: { station: station.slug },
    });

    return (
        <PublicLayout>
            <div className="space-y-5">
                <PageHeader
                    title="Song library"
                    description={`${songs.total} tracks available`}
                />

                <Input
                    type="search"
                    placeholder="Search by title or artist…"
                    defaultValue={search}
                    onChange={handleSearch}
                    aria-label="Search songs"
                    className="h-11 max-w-md"
                />

                {songs.data.length === 0 ? (
                    <EmptyState
                        icon={<SearchX size={22} />}
                        title="No songs found"
                        description={
                            search
                                ? `Nothing matches “${search}”.`
                                : 'The library is empty right now.'
                        }
                    />
                ) : (
                    <Card className="gap-0 overflow-hidden py-0">
                        <ul className="divide-y divide-border">
                            {songs.data.map((song) => (
                                <li key={song.id}>
                                    {/* A button, not a clickable div: this row is
                                        the only way to request a song, and the old
                                        markup was unreachable by keyboard. */}
                                    <button
                                        type="button"
                                        onClick={() => setRequesting(song)}
                                        className="group flex w-full items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-surface-2 focus-visible:bg-surface-2 focus-visible:outline-none"
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
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {song.duration_formatted}
                                            </span>
                                        )}
                                        <span className="shrink-0 rounded-md border border-primary/40 px-2.5 py-1.5 font-display text-[11px] font-semibold tracking-wider text-primary uppercase transition-colors group-hover:bg-primary group-hover:text-primary-foreground group-focus-visible:bg-primary group-focus-visible:text-primary-foreground">
                                            Request
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

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
