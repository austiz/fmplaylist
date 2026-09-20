import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';

export function EmergencyBanner() {
    const emergencyForm = useForm({});

    return (
        <div className="mb-6 border border-red-500/40 bg-red-500/5 p-4">
            <div className="flex items-start justify-between gap-6">
                <div>
                    <p className="font-display text-xs font-bold tracking-widest text-red-500 uppercase">
                        Emergency Broadcast
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Immediately cuts the current song, clears the queue, and
                        plays <code>announcement.wav</code> from the Pi.
                    </p>
                </div>
                <Button
                    type="button"
                    disabled={emergencyForm.processing}
                    className="h-12 shrink-0 bg-red-600 font-display font-bold tracking-wide text-white uppercase hover:bg-red-700 disabled:opacity-60"
                    onClick={() => {
                        if (
                            confirm(
                                '⚠ This will cut the current song, clear the entire queue, and play the emergency announcement on air. Continue?',
                            )
                        ) {
                            emergencyForm.post('/admin/broadcast/emergency');
                        }
                    }}
                >
                    🚨 EMERGENCY
                </Button>
            </div>
        </div>
    );
}
