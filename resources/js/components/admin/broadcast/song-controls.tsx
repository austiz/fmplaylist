import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { Song } from '@/types/fm';
import { SearchablePickList } from './searchable-pick-list';

export function SongControls({ songs }: { songs: Song[] }) {
    const skipForm = useForm({});
    const playNowForm = useForm({ song_id: '' });

    return (
        <section>
            <h2 className="mb-2 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Song Control
            </h2>
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
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        playNowForm.post('/admin/broadcast/play-now', {
                            onSuccess: () => playNowForm.reset(),
                        });
                    }}
                    className="space-y-2"
                >
                    <SearchablePickList
                        items={songs}
                        placeholder="Search songs..."
                        emptyText="No songs found"
                        selectedId={playNowForm.data.song_id}
                        onSelect={(id) =>
                            playNowForm.setData('song_id', String(id))
                        }
                        searchText={(song) => `${song.title} ${song.artist}`}
                        renderRow={(song) => (
                            <>
                                <span className="font-medium">
                                    {song.title}
                                </span>
                                {song.artist && (
                                    <span className="ml-2 text-xs text-muted-foreground">
                                        {song.artist}
                                    </span>
                                )}
                            </>
                        )}
                        limit={30}
                        size="lg"
                    />
                    <Button
                        type="submit"
                        disabled={
                            playNowForm.processing || !playNowForm.data.song_id
                        }
                        className="h-12 w-full bg-red-600 font-display font-bold tracking-wide text-white uppercase hover:bg-red-700 disabled:opacity-40"
                    >
                        Play Now
                    </Button>
                </form>
            </div>
        </section>
    );
}
