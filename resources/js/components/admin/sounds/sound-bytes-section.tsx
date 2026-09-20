import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { LibrarySearch } from '@/components/admin/sounds/library-search';
import { useConfirm } from '@/components/confirm-dialog';
import { FieldError } from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { cardSkin } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { MediaAsset } from '@/types/fm';
import { PiBadge } from './pi-badge';

const CATEGORIES = [
    { value: 'jingle', label: 'Jingle' },
    { value: 'shoutout', label: 'Shoutout' },
    { value: 'drop', label: 'Drop' },
    { value: 'id', label: 'ID' },
] as const;

type Category = (typeof CATEGORIES)[number]['value'];

function SoundByteUploadForm() {
    const form = useForm<{
        file: File | null;
        title: string;
        category: Category;
    }>({ file: null, title: '', category: 'jingle' });

    const handleFile = (e: React.ChangeEvent<HTMLInputElement>) => {
        const f = e.target.files?.[0];

        if (!f) {
            return;
        }

        form.setData('file', f);

        if (!form.data.title) {
            form.setData(
                'title',
                f.name.replace(/\.(wav|mp3|ogg)$/i, '').replace(/[_-]/g, ' '),
            );
        }
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/sound-bytes/upload', {
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className={cn(cardSkin, 'block space-y-4 p-5')}
        >
            <h3 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Upload Sound Byte
            </h3>
            <div className="space-y-1">
                <Label>WAV / MP3 / OGG (max 20 MB)</Label>
                <input
                    type="file"
                    accept=".wav,.mp3,.ogg,audio/wav,audio/mpeg,audio/ogg"
                    onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:tracking-wide file:text-foreground file:uppercase hover:file:bg-card"
                />
                <FieldError message={form.errors.file} />
            </div>
            <div className="grid gap-3 sm:grid-cols-[1fr_160px]">
                <div className="space-y-1">
                    <Label>Title</Label>
                    <Input
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="Laser drop, local shoutout, sweep"
                        required
                    />
                    <FieldError message={form.errors.title} />
                </div>
                <div className="space-y-1">
                    <Label>Category</Label>
                    <Select
                        value={form.data.category}
                        onValueChange={(v) =>
                            form.setData('category', v as Category)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CATEGORIES.map((cat) => (
                                <SelectItem key={cat.value} value={cat.value}>
                                    {cat.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <Button
                type="submit"
                disabled={
                    form.processing || !form.data.file || !form.data.title
                }
                className="h-10 w-full font-display font-bold tracking-wide uppercase disabled:opacity-40"
            >
                {form.processing ? 'Uploading...' : 'Upload Sound Byte'}
            </Button>
        </form>
    );
}

function SoundByteRow({ soundByte }: { soundByte: MediaAsset }) {
    const confirm = useConfirm();
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        title: soundByte.title,
        category: soundByte.category,
        rds_ps: soundByte.rds_ps ?? '',
    });
    const playForm = useForm({ sound_byte_id: String(soundByte.id) });

    if (editing) {
        return (
            <div className="space-y-2 px-4 py-3">
                <div className="grid gap-2 md:grid-cols-[1fr_160px_120px]">
                    <Input
                        value={editForm.data.title}
                        onChange={(e) =>
                            editForm.setData('title', e.target.value)
                        }
                        placeholder="Title"
                        autoFocus
                    />
                    <Select
                        value={editForm.data.category}
                        onValueChange={(v) =>
                            editForm.setData('category', v as Category)
                        }
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CATEGORIES.map((cat) => (
                                <SelectItem key={cat.value} value={cat.value}>
                                    {cat.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <div className="space-y-1">
                        <Input
                            value={editForm.data.rds_ps}
                            onChange={(e) =>
                                editForm.setData(
                                    'rds_ps',
                                    e.target.value.toUpperCase().slice(0, 8),
                                )
                            }
                            placeholder="RDS (8 ch)"
                            maxLength={8}
                            className="font-mono uppercase"
                        />
                        <FieldError message={editForm.errors.rds_ps} />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    RDS PS shown on radio dial while this byte plays. Leave
                    blank to use station default.
                </p>
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        disabled={editForm.processing}
                        onClick={() =>
                            editForm.patch(
                                `/admin/sound-bytes/${soundByte.id}`,
                                { onSuccess: () => setEditing(false) },
                            )
                        }
                    >
                        Save
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setEditing(false)}
                    >
                        Cancel
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">
                    {soundByte.title}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="tracking-wide uppercase">
                        {soundByte.category}
                    </span>
                    {soundByte.rds_ps && (
                        <span className="ml-1 font-mono text-primary/70">
                            [{soundByte.rds_ps}]
                        </span>
                    )}
                    <span> · </span>
                    <span className="font-mono">{soundByte.filename}</span>
                    {soundByte.file_size ? (
                        <span>
                            {' '}
                            · {(soundByte.file_size / 1_048_576).toFixed(1)} MB
                        </span>
                    ) : null}
                </p>
            </div>
            <PiBadge
                devicesHave={soundByte.devices_have}
                hasFile={soundByte.has_file}
                deleteRequested={soundByte.pi_delete_requested}
                active={soundByte.active}
            />
            <button
                onClick={() =>
                    playForm.post('/admin/broadcast/force-sound-byte')
                }
                disabled={
                    !soundByte.active ||
                    soundByte.pi_delete_requested ||
                    playForm.processing
                }
                className="text-xs text-destructive hover:text-destructive disabled:text-muted-foreground/30"
            >
                Play Now
            </button>
            <button
                onClick={() => setEditing(true)}
                className="text-xs text-muted-foreground hover:text-foreground"
            >
                Edit
            </button>
            <button
                onClick={() =>
                    router.patch(`/admin/sound-bytes/${soundByte.id}/toggle`)
                }
                className="text-xs text-muted-foreground hover:text-foreground"
            >
                {soundByte.active ? 'Disable' : 'Enable'}
            </button>
            {!soundByte.pi_delete_requested && (
                <button
                    onClick={async () => {
                        const ok = await confirm({
                            title: `Delete "${soundByte.title}"?`,
                            description:
                                'The file is removed from the library and from every Pi holding a copy. This cannot be undone.',
                            confirmLabel: 'Delete',
                            variant: 'destructive',
                        });

                        if (ok) {
                            router.delete(`/admin/sound-bytes/${soundByte.id}`);
                        }
                    }}
                    className="text-xs text-destructive/70 hover:text-destructive"
                >
                    Delete
                </button>
            )}
        </div>
    );
}

export function SoundBytesSection({
    soundBytes,
}: {
    soundBytes: MediaAsset[];
}) {
    const [search, setSearch] = useState('');
    const filtered = soundBytes.filter((sb) =>
        `${sb.title} ${sb.category} ${sb.filename ?? ''}`
            .toLowerCase()
            .includes(search.toLowerCase()),
    );

    return (
        <div className="space-y-6">
            <SoundByteUploadForm />
            <div className="space-y-3">
                <LibrarySearch
                    placeholder="Search sound bytes..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    summary={`${soundBytes.length} sound bytes`}
                />
                <div
                    className={cn(
                        cardSkin,
                        'divide-y divide-border overflow-hidden',
                    )}
                >
                    {filtered.map((sb) => (
                        <SoundByteRow key={sb.id} soundByte={sb} />
                    ))}
                    {filtered.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                            No sound bytes yet
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
