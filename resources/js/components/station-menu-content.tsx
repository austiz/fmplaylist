import { Link, router } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import {
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import type { Station } from '@/types/fm';

type Props = {
    stations: Station[];
    activeStation: Station;
};

export function StationMenuContent({ stations, activeStation }: Props) {
    const switchStation = (value: string) => {
        if (Number(value) === activeStation.id) {
            return;
        }
        router.post('/admin/stations/switch', { station_id: Number(value) }, { preserveScroll: true });
    };

    return (
        <>
            <DropdownMenuLabel className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                Active Station
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup value={String(activeStation.id)} onValueChange={switchStation}>
                {stations.map((station) => (
                    <DropdownMenuRadioItem key={station.id} value={String(station.id)}>
                        {station.name}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link href="/admin/stations" className="block w-full cursor-pointer">
                    <Settings className="mr-2" />
                    Manage stations
                </Link>
            </DropdownMenuItem>
        </>
    );
}
