import { AddDeviceForm } from '@/components/admin/tokens/add-device-form';
import { DeviceRow } from '@/components/admin/tokens/device-row';
import { NewTokenPanel } from '@/components/admin/tokens/new-token-panel';
import { AdminLayout } from '@/layouts/admin-layout';
import type { PiDevice, Station } from '@/types/fm';

interface Props {
    tokens: PiDevice[];
    stations: Pick<Station, 'id' | 'name'>[];
    newToken: string | null;
    appUrl: string;
}

export default function Tokens({ tokens, stations, newToken, appUrl }: Props) {
    return (
        <AdminLayout title="Devices">
            <div className="max-w-3xl space-y-6">
                {newToken && (
                    <NewTokenPanel newToken={newToken} appUrl={appUrl} />
                )}

                <div className="border border-border bg-card p-5">
                    <h2 className="mb-4 font-display text-xs font-bold tracking-widest text-muted-foreground uppercase">
                        Devices
                    </h2>
                    {tokens.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No devices yet — add one below.
                        </p>
                    ) : (
                        <div className="space-y-2">
                            {tokens.map((t) => (
                                <DeviceRow
                                    key={t.id}
                                    t={t}
                                    stations={stations}
                                />
                            ))}
                        </div>
                    )}
                </div>

                <AddDeviceForm
                    stations={stations}
                    hasDevices={tokens.length > 0}
                />
            </div>
        </AdminLayout>
    );
}
