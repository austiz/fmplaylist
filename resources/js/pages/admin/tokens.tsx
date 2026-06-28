import { router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminLayout } from '@/components/admin-layout';
import { Button } from '@/components/ui/button';

interface Props {
    tokens: { id: number; label: string; last_seen_at: string | null; created_at: string }[];
    newToken: string | null;
    appUrl: string;
}

export default function Tokens({ tokens, newToken, appUrl }: Props) {
    const [copiedToken, setCopiedToken] = useState(false);
    const [copiedCmd, setCopiedCmd] = useState(false);

    const installCmd = newToken
        ? `curl -fsSL ${appUrl}/pi/setup.sh | sudo bash -s -- ${newToken}`
        : null;

    const copyToken = () => {
        navigator.clipboard.writeText(newToken!);
        setCopiedToken(true);
        setTimeout(() => setCopiedToken(false), 2000);
    };

    const copyCmd = () => {
        navigator.clipboard.writeText(installCmd!);
        setCopiedCmd(true);
        setTimeout(() => setCopiedCmd(false), 2000);
    };

    const regenerate = () => {
        if (confirm('This will invalidate the current Pi token. The Pi will stop working until it is updated. Continue?')) {
            router.post('/admin/tokens/regenerate');
        }
    };

    return (
        <AdminLayout title="Pi API Token">
            <div className="max-w-2xl space-y-6">
                {newToken && (
                    <div className="rounded-xl border-2 border-red-300 bg-red-50 p-5 dark:border-red-800 dark:bg-red-950/30 space-y-4">
                        <p className="text-sm font-bold text-red-900 dark:text-red-200">
                            New token generated — shown once only
                        </p>

                        {/* Install command — the main thing to copy */}
                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">
                                Run this on your Pi (sets everything up automatically)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 rounded bg-white px-3 py-2 text-xs font-mono text-zinc-900 dark:bg-zinc-900 dark:text-white break-all">
                                    {installCmd}
                                </code>
                                <Button size="sm" onClick={copyCmd} className="shrink-0">
                                    {copiedCmd ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>

                        {/* Raw token — secondary, for manual config edits */}
                        <div>
                            <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-400">
                                Raw token (for manual config.json edits)
                            </p>
                            <div className="flex gap-2">
                                <code className="flex-1 rounded bg-white px-3 py-2 text-xs font-mono text-zinc-900 dark:bg-zinc-900 dark:text-white break-all">
                                    {newToken}
                                </code>
                                <Button size="sm" variant="outline" onClick={copyToken} className="shrink-0">
                                    {copiedToken ? 'Copied!' : 'Copy'}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                <div className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                    <h2 className="mb-4 font-semibold text-zinc-900 dark:text-white">Active Tokens</h2>
                    {tokens.length === 0 ? (
                        <p className="text-sm text-zinc-400">No tokens yet — generate one below.</p>
                    ) : (
                        <div className="space-y-2">
                            {tokens.map((t) => (
                                <div key={t.id} className="flex items-center justify-between rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800">
                                    <div>
                                        <p className="text-sm font-medium text-zinc-900 dark:text-white">{t.label}</p>
                                        <p className="text-xs text-zinc-500">
                                            Last seen: {t.last_seen_at ?? 'never'} · Created {t.created_at}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    <Button onClick={regenerate} className="mt-4 bg-red-600 hover:bg-red-700 text-white">
                        {tokens.length > 0 ? 'Regenerate Token' : 'Generate Token'}
                    </Button>
                </div>
            </div>
        </AdminLayout>
    );
}
