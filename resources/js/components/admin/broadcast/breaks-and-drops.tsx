import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import type { MediaAsset } from '@/types/fm';
import { SearchablePickList } from './searchable-pick-list';

export function BreaksAndDrops({
    commercials,
    soundBytes,
}: {
    commercials: Pick<MediaAsset, 'id' | 'title' | 'play_count'>[];
    soundBytes: Pick<MediaAsset, 'id' | 'title' | 'category'>[];
}) {
    const commercialForm = useForm({ commercial_id: '' });
    const soundByteForm = useForm({ sound_byte_id: '' });

    return (
        <section>
            <h2 className="mb-2 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Breaks & Drops
            </h2>
            <div className="grid gap-4 sm:grid-cols-2">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        commercialForm.post(
                            '/admin/broadcast/force-commercial',
                            { onSuccess: () => commercialForm.reset() },
                        );
                    }}
                    className="space-y-2 border border-border bg-card p-3"
                >
                    <div>
                        <p className="text-sm font-semibold text-foreground">
                            Commercial
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Inject a spot on the next Pi poll.
                        </p>
                    </div>
                    <SearchablePickList
                        items={commercials}
                        placeholder="Search commercials..."
                        emptyText="No commercials found"
                        selectedId={commercialForm.data.commercial_id}
                        onSelect={(id) =>
                            commercialForm.setData('commercial_id', String(id))
                        }
                        searchText={(commercial) => commercial.title}
                        renderRow={(commercial) => (
                            <>
                                <span className="font-medium">
                                    {commercial.title}
                                </span>
                                <span className="ml-2 text-xs text-muted-foreground">
                                    played {commercial.play_count ?? 0}
                                </span>
                            </>
                        )}
                    />
                    <Button
                        type="submit"
                        disabled={
                            commercialForm.processing ||
                            !commercialForm.data.commercial_id
                        }
                        className="h-10 w-full disabled:opacity-40"
                    >
                        Play Commercial
                    </Button>
                </form>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        soundByteForm.post(
                            '/admin/broadcast/force-sound-byte',
                            { onSuccess: () => soundByteForm.reset() },
                        );
                    }}
                    className="space-y-2 border border-border bg-card p-3"
                >
                    <div>
                        <p className="text-sm font-semibold text-foreground">
                            Sound Byte
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Trigger a jingle, shoutout, ID, or drop.
                        </p>
                    </div>
                    <SearchablePickList
                        items={soundBytes}
                        placeholder="Search sound bytes..."
                        emptyText="No sound bytes found"
                        selectedId={soundByteForm.data.sound_byte_id}
                        onSelect={(id) =>
                            soundByteForm.setData('sound_byte_id', String(id))
                        }
                        searchText={(soundByte) =>
                            `${soundByte.title} ${soundByte.category}`
                        }
                        renderRow={(soundByte) => (
                            <>
                                <span className="font-medium">
                                    {soundByte.title}
                                </span>
                                <span className="ml-2 text-xs text-muted-foreground uppercase">
                                    {soundByte.category}
                                </span>
                            </>
                        )}
                    />
                    <Button
                        type="submit"
                        disabled={
                            soundByteForm.processing ||
                            !soundByteForm.data.sound_byte_id
                        }
                        className="h-10 w-full disabled:opacity-40"
                    >
                        Play Sound Byte
                    </Button>
                </form>
            </div>
        </section>
    );
}
