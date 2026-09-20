import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { MediaAsset } from '@/types/fm';
import { PiBadge } from './pi-badge';

function CommercialUploadForm() {
    const form = useForm<{ file: File | null; title: string }>({
        file: null,
        title: '',
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
                f.name.replace(/\.(wav|mp3|ogg)$/i, '').replace(/[_-]/g, ' '),
            );
        }
    };

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post('/admin/commercials/upload', {
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
            className="space-y-4 border border-border bg-card p-5"
        >
            <h3 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Upload Commercial
            </h3>
            <div className="space-y-1">
                <Label>WAV / MP3 / OGG (max 50 MB)</Label>
                <input
                    type="file"
                    accept=".wav,.mp3,.ogg,audio/wav,audio/mpeg,audio/ogg"
                    onChange={handleFile}
                    className="block w-full text-sm text-muted-foreground file:mr-4 file:border file:border-border file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-bold file:tracking-wide file:text-foreground file:uppercase hover:file:bg-card"
                />
                {form.errors.file && (
                    <p className="text-xs text-red-400">{form.errors.file}</p>
                )}
            </div>
            <div className="space-y-1">
                <Label>Title</Label>
                <Input
                    value={form.data.title}
                    onChange={(e) => form.setData('title', e.target.value)}
                    placeholder="Sponsor spot, PSA, promo"
                    required
                />
                {form.errors.title && (
                    <p className="text-xs text-red-400">{form.errors.title}</p>
                )}
            </div>
            <Button
                type="submit"
                disabled={
                    form.processing || !form.data.file || !form.data.title
                }
                className="h-10 w-full bg-red-600 font-display font-bold tracking-wide text-white uppercase hover:bg-red-700 disabled:opacity-40"
            >
                {form.processing ? 'Uploading...' : 'Upload Commercial'}
            </Button>
        </form>
    );
}

function CommercialRow({ commercial }: { commercial: MediaAsset }) {
    const [editing, setEditing] = useState(false);
    const editForm = useForm({
        title: commercial.title,
        rotation_order: String(commercial.rotation_order ?? 0),
    });
    const playForm = useForm({ commercial_id: String(commercial.id) });

    if (editing) {
        return (
            <div className="grid gap-2 px-4 py-3 md:grid-cols-[1fr_120px_auto_auto]">
                <Input
                    value={editForm.data.title}
                    onChange={(e) => editForm.setData('title', e.target.value)}
                    placeholder="Title"
                    autoFocus
                />
                <Input
                    type="number"
                    min={0}
                    value={editForm.data.rotation_order}
                    onChange={(e) =>
                        editForm.setData('rotation_order', e.target.value)
                    }
                    placeholder="Order"
                />
                <Button
                    size="sm"
                    disabled={editForm.processing}
                    className="bg-red-600 text-white hover:bg-red-700"
                    onClick={() =>
                        editForm.patch(`/admin/commercials/${commercial.id}`, {
                            onSuccess: () => setEditing(false),
                        })
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
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">
                    {commercial.title}
                </p>
                <p className="truncate text-xs text-muted-foreground">
                    <span className="font-mono">{commercial.filename}</span>
                    {commercial.file_size ? (
                        <span>
                            {' '}
                            · {(commercial.file_size / 1_048_576).toFixed(1)} MB
                        </span>
                    ) : null}
                    <span>
                        {' '}
                        · order {commercial.rotation_order ?? 0} · played{' '}
                        {commercial.play_count ?? 0}×
                    </span>
                </p>
            </div>
            <PiBadge
                devicesHave={commercial.devices_have}
                hasFile={commercial.has_file}
                deleteRequested={commercial.pi_delete_requested}
                active={commercial.active}
            />
            <button
                onClick={() =>
                    playForm.post('/admin/broadcast/force-commercial')
                }
                disabled={
                    !commercial.active ||
                    commercial.pi_delete_requested ||
                    playForm.processing
                }
                className="text-xs text-red-500 hover:text-red-400 disabled:text-muted-foreground/30"
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
                    router.patch(`/admin/commercials/${commercial.id}/toggle`)
                }
                className="text-xs text-muted-foreground hover:text-foreground"
            >
                {commercial.active ? 'Disable' : 'Enable'}
            </button>
            {!commercial.pi_delete_requested && (
                <button
                    onClick={() => {
                        if (confirm(`Delete "${commercial.title}"?`)) {
                            router.delete(
                                `/admin/commercials/${commercial.id}`,
                            );
                        }
                    }}
                    className="text-xs text-red-500/70 hover:text-red-400"
                >
                    Delete
                </button>
            )}
        </div>
    );
}

export function CommercialsSection({
    commercials,
}: {
    commercials: MediaAsset[];
}) {
    const [search, setSearch] = useState('');
    const filtered = commercials.filter((c) =>
        `${c.title} ${c.filename ?? ''}`
            .toLowerCase()
            .includes(search.toLowerCase()),
    );

    return (
        <div className="space-y-6">
            <CommercialUploadForm />
            <div className="space-y-3">
                <div className="flex items-center gap-3">
                    <Input
                        placeholder="Search commercials..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="max-w-xs"
                    />
                    <span className="text-xs text-muted-foreground">
                        {commercials.length} commercials
                    </span>
                </div>
                <div className="divide-y divide-border border border-border bg-card">
                    {filtered.map((c) => (
                        <CommercialRow key={c.id} commercial={c} />
                    ))}
                    {filtered.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-muted-foreground">
                            No commercials yet
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
