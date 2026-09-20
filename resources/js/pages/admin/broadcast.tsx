import { BreaksAndDrops } from '@/components/admin/broadcast/breaks-and-drops';
import { BroadcastModeForm } from '@/components/admin/broadcast/broadcast-mode-form';
import { EmergencyBanner } from '@/components/admin/broadcast/emergency-banner';
import { PiStatusCard } from '@/components/admin/broadcast/pi-status-card';
import { RdsEditor } from '@/components/admin/broadcast/rds-editor';
import { SongControls } from '@/components/admin/broadcast/song-controls';
import { AdminLayout } from '@/layouts/admin-layout';
import type { BroadcastPiStatus, MediaAsset, Song } from '@/types/fm';

interface Props {
    songs: Song[];
    commercials: Pick<MediaAsset, 'id' | 'title' | 'play_count'>[];
    soundBytes: Pick<MediaAsset, 'id' | 'title' | 'category'>[];
    settings: Record<string, string>;
    pi: BroadcastPiStatus;
    nowPlaying: { title: string; artist: string; type: string } | null;
}

export default function Broadcast({
    songs,
    commercials,
    soundBytes,
    settings,
    pi,
    nowPlaying,
}: Props) {
    return (
        <AdminLayout title="Broadcast">
            <EmergencyBanner />
            <PiStatusCard pi={pi} nowPlaying={nowPlaying} />

            <div className="grid gap-6 lg:grid-cols-2">
                <div className="space-y-6">
                    <BroadcastModeForm settings={settings} pi={pi} />
                    <SongControls songs={songs} />
                    <BreaksAndDrops
                        commercials={commercials}
                        soundBytes={soundBytes}
                    />
                </div>

                <RdsEditor settings={settings} />
            </div>
        </AdminLayout>
    );
}
