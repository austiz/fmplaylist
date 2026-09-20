import { useForm } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';

export function EmergencyBanner() {
    const confirm = useConfirm();
    const emergencyForm = useForm({});

    return (
        <div className="mb-6 border border-destructive/40 bg-destructive/8 p-4">
            <div className="flex items-start justify-between gap-6">
                <div>
                    <p className="font-display text-xs font-bold tracking-widest text-destructive uppercase">
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
                    className="h-12 shrink-0 bg-destructive font-display font-bold tracking-wide text-destructive-foreground uppercase hover:bg-destructive/90 disabled:opacity-60"
                    onClick={async () => {
                        const ok = await confirm({
                            title: 'Start the emergency broadcast?',
                            description:
                                'This cuts the current song, clears the entire queue, and plays the emergency announcement on air immediately.',
                            confirmLabel: 'Broadcast now',
                            variant: 'destructive',
                        });

                        if (ok) {
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
