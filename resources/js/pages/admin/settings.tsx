import { SavedNetworksPanel } from '@/components/admin/settings/saved-networks-panel';
import { StationForm } from '@/components/admin/settings/station-form';
import { WifiPanel } from '@/components/admin/settings/wifi-panel';
import { AdminLayout } from '@/layouts/admin-layout';
import type { WifiInfo } from '@/types/fm';

interface Props {
    settings: Record<string, string>;
    wifi: WifiInfo;
}

export default function Settings({ settings, wifi }: Props) {
    return (
        <AdminLayout title="Settings">
            <StationForm settings={settings} />
            <WifiPanel wifi={wifi} />
            <SavedNetworksPanel wifi={wifi} />
        </AdminLayout>
    );
}
