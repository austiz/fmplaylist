import { useForm } from '@inertiajs/react';
import { FieldError } from '@/components/field-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AdminLayout } from '@/layouts/admin-layout';
import type { Station } from '@/types/fm';

interface Props {
    stations: Station[];
}

export default function Stations({ stations }: Props) {
    const form = useForm({ name: '' });

    const create = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/admin/stations', { onSuccess: () => form.reset('name') });
    };

    return (
        <AdminLayout title="Stations">
            <div className="max-w-xl space-y-6">
                <div className="border border-border bg-card p-5">
                    <h2 className="mb-4 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Stations
                    </h2>
                    <div className="space-y-2">
                        {stations.map((s) => (
                            <div
                                key={s.id}
                                className="flex items-center justify-between border border-border bg-secondary/50 px-4 py-3"
                            >
                                <div>
                                    <p className="text-sm font-medium text-foreground">
                                        {s.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        /{s.slug}
                                        {s.is_default ? ' · default' : ''}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <form
                    onSubmit={create}
                    className="space-y-3 border border-border bg-card p-5"
                >
                    <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Create a Station
                    </h2>
                    <div className="space-y-1">
                        <Label>Name</Label>
                        <Input
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            placeholder="e.g. Downtown 96.9"
                            required
                        />
                        <FieldError message={form.errors.name} />
                    </div>
                    <Button
                        type="submit"
                        disabled={form.processing || !form.data.name}
                    >
                        Create Station
                    </Button>
                </form>
            </div>
        </AdminLayout>
    );
}
