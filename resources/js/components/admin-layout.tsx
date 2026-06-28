import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { useEffect, useState } from 'react';
import type { PiStatus } from '@/types/fm';

const nav = [
    { href: '/admin',           label: 'Dashboard' },
    { href: '/admin/broadcast', label: 'Broadcast' },
    { href: '/admin/sounds',    label: 'Sounds'    },
    { href: '/admin/settings',  label: 'Settings'  },
    { href: '/admin/tokens',    label: 'Pi Token'  },
    { href: '/admin/history',   label: 'History'   },
];

function PiStatusBar() {
    const [pi, setPi] = useState<PiStatus | null>(null);

    useEffect(() => {
        const poll = async () => {
            try {
                const res = await fetch('/api/pi-status');

                if (res.ok) {
setPi(await res.json());
}
            } catch { /* ignore */ }
        };
        poll();
        const id = setInterval(poll, 30_000);

        return () => clearInterval(id);
    }, []);

    if (!pi) {
return null;
}

    const connected = pi.online;
    const playing   = pi.status === 'playing';
    const live      = pi.status === 'live';

    return (
        <div className="flex items-center gap-4 border-b border-border bg-background px-4 py-1 text-[10px] font-bold uppercase tracking-widest font-display">
            <span className={`flex items-center gap-1 ${connected ? 'text-green-500' : 'text-muted-foreground/40'}`}>
                <span className={`inline-block h-1.5 w-1.5 rounded-full ${connected ? 'bg-green-500' : 'bg-muted-foreground/30'}`} />
                {connected ? 'Connected' : 'Offline'}
            </span>

            <span className={`flex items-center gap-1 ${playing ? 'text-red-500' : 'text-muted-foreground/30'}`}>
                <span className={`inline-block h-1.5 w-1.5 rounded-full ${playing ? 'bg-red-500 animate-pulse' : 'bg-muted-foreground/20'}`} />
                Playing
            </span>

            <span className={`flex items-center gap-1 ${live ? 'text-violet-400' : 'text-muted-foreground/30'}`}>
                <span className={`inline-block h-1.5 w-1.5 rounded-full ${live ? 'bg-violet-400 animate-pulse' : 'bg-muted-foreground/20'}`} />
                Live
            </span>

            {live && pi.ip && (
                <span className="ml-auto text-violet-400/70">
                    stream → {pi.ip}
                </span>
            )}
        </div>
    );
}

export function AdminLayout({ children, title }: PropsWithChildren<{ title?: string }>) {
    const { url } = usePage();
    const active = (href: string) =>
        href === '/admin' ? url === '/admin' : url.startsWith(href);

    return (
        <div className="min-h-screen bg-background">
            <header className="border-b border-border bg-card">
                <div className="mx-auto max-w-6xl px-4">
                    {/* Top row: logo + logout */}
                    <div className="flex items-center justify-between py-3">
                        <Link href="/" className="flex items-center gap-1">
                            <span className="font-display font-bold text-red-500">FM</span>
                            <span className="font-display font-bold text-foreground">PLAYLIST</span>
                        </Link>
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                        >
                            Logout
                        </Link>
                    </div>
                    {/* Nav row — scrollable on mobile */}
                    <nav className="-mx-4 flex overflow-x-auto px-4 scrollbar-none">
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`shrink-0 border-b-2 px-3 pb-2 pt-1 text-sm font-medium transition-colors ${
                                    active(item.href)
                                        ? 'border-red-500 text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </header>

            {/* Pi status bar — always visible across all admin pages */}
            <PiStatusBar />

            <main className="mx-auto max-w-6xl px-4 py-5">
                {title && (
                    <h1 className="mb-4 font-display text-xl font-bold text-foreground">{title}</h1>
                )}
                {children}
            </main>
        </div>
    );
}
