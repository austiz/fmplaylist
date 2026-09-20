import { useState } from 'react';
import { CommercialsSection } from '@/components/admin/sounds/commercials-section';
import { DeviceCountContext } from '@/components/admin/sounds/pi-badge';
import { SongsSection } from '@/components/admin/sounds/songs-section';
import { SoundBytesSection } from '@/components/admin/sounds/sound-bytes-section';
import { AdminLayout } from '@/layouts/admin-layout';
import type { MediaAsset, PaginatedResponse } from '@/types/fm';

interface Props {
    songs: PaginatedResponse<MediaAsset>;
    commercials: MediaAsset[];
    soundBytes: MediaAsset[];
    /** Pi devices that have checked in at least once — the denominator for "on Pi". */
    deviceCount: number;
    search: string;
}

type Tab = 'songs' | 'commercials' | 'sound-bytes';

const TABS: { key: Tab; label: (p: Props) => string }[] = [
    { key: 'songs', label: (p) => `Songs (${p.songs.total})` },
    {
        key: 'commercials',
        label: (p) => `Commercials (${p.commercials.length})`,
    },
    {
        key: 'sound-bytes',
        label: (p) => `Sound Bytes (${p.soundBytes.length})`,
    },
];

export default function Sounds(props: Props) {
    const { songs, commercials, soundBytes, deviceCount, search } = props;
    const [tab, setTab] = useState<Tab>('songs');

    return (
        <DeviceCountContext.Provider value={deviceCount}>
            <AdminLayout title="Sounds">
                {/* Tab bar */}
                <div className="mb-6 flex border-b border-border">
                    {TABS.map(({ key, label }) => (
                        <button
                            key={key}
                            onClick={() => setTab(key)}
                            className={`-mb-px border-b-2 px-4 pt-1 pb-3 text-sm font-medium transition-colors ${
                                tab === key
                                    ? 'border-primary text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {label(props)}
                        </button>
                    ))}
                </div>

                {tab === 'songs' && (
                    <SongsSection songs={songs} search={search} />
                )}
                {tab === 'commercials' && (
                    <CommercialsSection commercials={commercials} />
                )}
                {tab === 'sound-bytes' && (
                    <SoundBytesSection soundBytes={soundBytes} />
                )}
            </AdminLayout>
        </DeviceCountContext.Provider>
    );
}
