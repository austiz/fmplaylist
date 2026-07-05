import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                <div className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 className="mb-4 font-semibold text-zinc-900 dark:text-white">Stations</h2>
                    <div className="space-y-2">
                        {stations.map((s) => (
                            <div key={s.id} className="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                                <div>
                                    <p className="text-sm font-medium text-zinc-900 dark:text-white">{s.name}</p>
                                    <p className="text-xs text-zinc-500">/{s.slug}{s.is_default ? ' · default' : ''}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>

                <form onSubmit={create} className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 space-y-3">
                    <h2 className="font-semibold text-zinc-900 dark:text-white">Create a Station</h2>
                    <div className="space-y-1">
                        <Label>Name</Label>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="e.g. Downtown 96.9"
                            required
                        />
                        {form.errors.name && <p className="text-xs text-red-400">{form.errors.name}</p>}
                    </div>
                    <Button type="submit" disabled={form.processing || !form.data.name} className="bg-red-600 hover:bg-red-700 text-white">
                        Create Station
                    </Button>
                </form>
            </div>
        </AdminLayout>
    );
}
