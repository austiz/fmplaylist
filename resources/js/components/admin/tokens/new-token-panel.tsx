import { CopyField } from '@/components/copy-field';

/**
 * The one and only time a freshly generated token is visible. Both fields are the
 * same value -- the curl line is just the token wrapped in the installer command.
 */
export function NewTokenPanel({
    newToken,
    appUrl,
}: {
    newToken: string;
    appUrl: string;
}) {
    return (
        <div className="space-y-4 border border-red-500/40 bg-red-500/5 p-5">
            <p className="text-sm font-bold text-red-400">
                New token generated — shown once only
            </p>

            <CopyField
                heading="Run this on the Pi (sets everything up automatically)"
                text={`curl -fsSL ${appUrl}/pi/setup.sh | sudo bash -s -- ${newToken}`}
            />

            <CopyField
                heading="Raw token (for manual config.json edits)"
                text={newToken}
                variant="outline"
            />
        </div>
    );
}
