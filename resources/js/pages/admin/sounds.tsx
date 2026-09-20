import { router, usePage } from '@inertiajs/react';

import { CommercialsSection } from '@/components/admin/sounds/commercials-section';
import { DeviceCountContext } from '@/components/admin/sounds/pi-badge';
import { SongsSection } from '@/components/admin/sounds/songs-section';
import { SoundBytesSection } from '@/components/admin/sounds/sound-bytes-section';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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

const TABS = ['songs', 'commercials', 'sound-bytes'] as const;

type Tab = (typeof TABS)[number];

/**
 * Which tab the URL is asking for, defaulting to songs.
 *
 * The tab used to be `useState`, which meant every partial reload the page
 * makes -- the songs search, an upload finishing, a delete -- dropped you back
 * onto Songs from whichever tab you were working in. Reading it from the URL
 * also makes a tab linkable, which is the whole reason to put it there.
 */
function tabFrom(url: string): Tab {
    const query = url.split('?')[1] ?? '';
    const asked = new URLSearchParams(query).get('tab');

    return TABS.includes(asked as Tab) ? (asked as Tab) : 'songs';
}

export default function Sounds(props: Props) {
    const { songs, commercials, soundBytes, deviceCount, search } = props;
    const { url } = usePage();
    const tab = tabFrom(url);

    const select = (next: string) =>
        router.get(
            '/admin/sounds',
            { tab: next, ...(search ? { search } : {}) },
            {
                preserveState: true,
                preserveScroll: true,
                // Replaces rather than pushes: back should leave Sounds, not
                // walk you through every tab you happened to look at first.
                replace: true,
            },
        );

    return (
        <DeviceCountContext value={deviceCount}>
            <AdminLayout title="Sounds">
                <Tabs value={tab} onValueChange={select}>
                    <TabsList>
                        <TabsTrigger value="songs">
                            Songs ({songs.total})
                        </TabsTrigger>
                        <TabsTrigger value="commercials">
                            Commercials ({commercials.length})
                        </TabsTrigger>
                        <TabsTrigger value="sound-bytes">
                            Sound Bytes ({soundBytes.length})
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="songs">
                        <SongsSection songs={songs} search={search} />
                    </TabsContent>
                    <TabsContent value="commercials">
                        <CommercialsSection commercials={commercials} />
                    </TabsContent>
                    <TabsContent value="sound-bytes">
                        <SoundBytesSection soundBytes={soundBytes} />
                    </TabsContent>
                </Tabs>
            </AdminLayout>
        </DeviceCountContext>
    );
}
