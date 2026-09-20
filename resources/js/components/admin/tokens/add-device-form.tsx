import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Station } from '@/types/fm';

export function AddDeviceForm({
    stations,
    hasDevices,
}: {
    stations: Pick<Station, 'id' | 'name'>[];
    /** Only the wording of the submit button changes -- the first one reads "Generate Token". */
    hasDevices: boolean;
}) {
    const addForm = useForm({
        label: '',
        station_id: stations[0] ? String(stations[0].id) : '',
    });

    const addDevice = (e: React.FormEvent) => {
        e.preventDefault();
        addForm.post('/admin/tokens', {
            preserveScroll: true,
            onSuccess: () => addForm.reset('label'),
        });
    };

    return (
        <form
            onSubmit={addDevice}
            className="space-y-3 border border-border bg-card p-5"
        >
            <h2 className="font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                Add a Device
            </h2>
            <div className="grid gap-3 sm:grid-cols-[1fr_180px_auto]">
                <div className="space-y-1">
                    <Label>Label</Label>
                    <Input
                        value={addForm.data.label}
                        onChange={(e) =>
                            addForm.setData('label', e.target.value)
                        }
                        placeholder="e.g. Pi Zero — Car 1"
                        required
                    />
                </div>
                <div className="space-y-1">
                    <Label>Station</Label>
                    <Select
                        value={addForm.data.station_id}
                        onValueChange={(v) => addForm.setData('station_id', v)}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {stations.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <Button
                    type="submit"
                    disabled={addForm.processing || !addForm.data.label}
                    className="self-end bg-red-600 text-white hover:bg-red-700"
                >
                    {hasDevices ? 'Add Device' : 'Generate Token'}
                </Button>
            </div>
        </form>
    );
}
