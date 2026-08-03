import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { useEffect, useState } from 'react';
import { CommandPalette } from '@/components/admin/command-palette';
import { StationMenuContent } from '@/components/station-menu-content';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { PiStatus, Station } from '@/types/fm';

const nav = [
    { href: '/admin', label: 'Dashboard' },
    { href: '/admin/broadcast', label: 'Broadcast' },
    { href: '/admin/sounds', label: 'Sounds' },
    { href: '/admin/settings', label: 'Settings' },
    { href: '/admin/stations', label: 'Stations' },
    { href: '/admin/tokens', label: 'Pi Token' },
    { href: '/admin/history', label: 'History' },
];

function PiStatusBar({ stationSlug }: { stationSlug?: string }) {
    const [pi, setPi] = useState<PiStatus | null>(null);
    const [updating, setUpdating] = useState(false);

    useEffect(() => {
        const poll = async () => {
            try {
                const url = stationSlug
                    ? `/api/pi-status?station=${stationSlug}`
                    : '/api/pi-status';
                const res = await fetch(url);

                if (res.ok) {
                    setPi(await res.json());
                }
            } catch {
                /* ignore */
            }
        };
        poll();
        const id = setInterval(poll, 30_000);

        return () => clearInterval(id);
    }, [stationSlug]);

    if (!pi) {
        return null;
    }

    const connected = pi.online;
    const playing = pi.status === 'playing';
    const live = pi.status === 'live';

    const pushUpdate = () => {
        setUpdating(true);
        router.post(
            '/admin/pi/update',
            {},
            {
                preserveScroll: true,
                onFinish: () => setUpdating(false),
            },
        );
    };

    return (
        <div className="flex items-center gap-4 border-b border-border bg-background px-4 py-1 font-display text-[10px] font-bold tracking-widest uppercase">
            <span
                className={`flex items-center gap-1 ${connected ? 'text-green-500' : 'text-muted-foreground/40'}`}
            >
                <span
                    className={`inline-block h-1.5 w-1.5 rounded-full ${connected ? 'bg-green-500' : 'bg-muted-foreground/30'}`}
                />
                {connected ? 'Connected' : 'Offline'}
            </span>

            <span
                className={`flex items-center gap-1 ${playing ? 'text-red-500' : 'text-muted-foreground/30'}`}
            >
                <span
                    className={`inline-block h-1.5 w-1.5 rounded-full ${playing ? 'animate-pulse bg-red-500' : 'bg-muted-foreground/20'}`}
                />
                Playing
            </span>

            <span
                className={`flex items-center gap-1 ${live ? 'text-violet-400' : 'text-muted-foreground/30'}`}
            >
                <span
                    className={`inline-block h-1.5 w-1.5 rounded-full ${live ? 'animate-pulse bg-violet-400' : 'bg-muted-foreground/20'}`}
                />
                Live
            </span>

            {live && pi.ip && (
                <span className="ml-auto text-violet-400/70">
                    stream → {pi.ip}
                </span>
            )}

            {pi.update_available && (
                <span
                    className={`flex items-center gap-2 text-amber-500 ${live && pi.ip ? '' : 'ml-auto'}`}
                >
                    <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-amber-500" />
                    Pi update available
                    <button
                        type="button"
                        onClick={pushUpdate}
                        disabled={updating}
                        className="rounded-sm border border-amber-500/40 px-1.5 py-0.5 tracking-normal text-amber-500 normal-case transition-colors hover:bg-amber-500/10 disabled:opacity-50"
                    >
                        {updating ? 'Queuing…' : 'Update'}
                    </button>
                </span>
            )}
        </div>
    );
}

export function AdminLayout({
    children,
    title,
}: PropsWithChildren<{ title?: string }>) {
    const { url, props } = usePage<{
        activeStation: Station | null;
        stations: Station[] | null;
        flash: { success?: string; error?: string };
    }>();
    const active = (href: string) =>
        href === '/admin' ? url === '/admin' : url.startsWith(href);

    const { activeStation, stations, flash } = props;

    return (
        <div className="min-h-screen bg-background">
            <header className="border-b border-border bg-card">
                <div className="mx-auto max-w-6xl px-4">
                    {/* Top row: logo + station switcher + logout */}
                    <div className="flex items-center justify-between py-3">
                        <Link href="/" className="flex items-center gap-1">
                            <span className="font-display font-bold text-red-500">
                                FM
                            </span>
                            <span className="font-display font-bold text-foreground">
                                PLAYLIST
                            </span>
                        </Link>
                        <div className="flex items-center gap-4">
                            {activeStation &&
                                stations &&
                                stations.length > 0 && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger className="flex items-center gap-1 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                                            {activeStation.name}
                                            <ChevronDown className="h-3 w-3" />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-56"
                                        >
                                            <StationMenuContent
                                                stations={stations}
                                                activeStation={activeStation}
                                            />
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            <span className="hidden items-center gap-1 rounded-sm border border-border px-1.5 py-0.5 font-display text-[10px] font-bold text-muted-foreground/60 uppercase sm:inline-flex">
                                <kbd className="font-sans">⌘K</kbd>
                            </span>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                            >
                                Logout
                            </Link>
                        </div>
                    </div>
                    {/* Nav row — scrollable on mobile */}
                    <nav className="-mx-4 flex scrollbar-none overflow-x-auto px-4">
                        {nav.map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={`shrink-0 border-b-2 px-3 pt-1 pb-2 text-sm font-medium transition-colors ${
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
            <PiStatusBar stationSlug={activeStation?.slug} />

            <main className="mx-auto max-w-6xl px-4 py-5">
                {title && (
                    <h1 className="mb-4 font-display text-xl font-bold text-foreground">
                        {title}
                    </h1>
                )}
                {flash?.success && (
                    <div className="mb-4 border-l-2 border-green-500 bg-green-500/10 px-4 py-3 text-sm text-green-400">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="mb-4 border-l-2 border-red-500 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                        {flash.error}
                    </div>
                )}
                {children}
            </main>

            <CommandPalette nav={nav} />
        </div>
    );
}
