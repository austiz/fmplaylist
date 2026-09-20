import { Link, usePage } from '@inertiajs/react';
import { Gauge, List, Music, Radio } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { withStation } from '@/lib/station';
import type { Station } from '@/types/fm';

const navLinks = [
    { href: '/', label: 'Home' },
    { href: '/songs', label: 'Songs' },
    { href: '/queue', label: 'Queue' },
    { href: '/drive', label: 'Drive' },
];

const tabs = [
    { href: '/', label: 'Home', Icon: Radio },
    { href: '/songs', label: 'Songs', Icon: Music },
    { href: '/queue', label: 'Queue', Icon: List },
    { href: '/drive', label: 'Drive', Icon: Gauge },
];

export function PublicLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage<{
        frequency: string;
        publicStation: Station | null;
    }>();
    const freq = props.frequency ?? '96.9';
    const stationSlug = props.publicStation?.slug;
    const tagged = (href: string) => withStation(href, stationSlug);
    const path = url.split('?')[0] || '/';
    const active = (href: string) =>
        href === '/' ? path === '/' : path.startsWith(href);

    return (
        <div className="min-h-screen bg-background text-foreground">
            {/* Header — logo only on mobile, logo + nav on desktop */}
            <header className="sticky top-0 z-40 border-b border-border bg-background/85 backdrop-blur-sm">
                <div className="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
                    <Link
                        href={tagged('/')}
                        className="group flex items-center gap-2"
                    >
                        <span className="flex aspect-square size-7 items-center justify-center rounded-lg bg-primary font-display text-[11px] font-bold text-primary-foreground">
                            FM
                        </span>
                        <span className="font-display text-lg font-bold tracking-tight text-foreground">
                            PLAYLIST
                        </span>
                        <span className="ml-1 rounded-md border border-primary/40 bg-primary-soft px-1.5 py-0.5 font-sans text-[10px] font-bold tracking-widest text-primary uppercase">
                            {freq}
                        </span>
                    </Link>
                    <nav className="hidden items-center gap-6 text-sm font-medium text-muted-foreground sm:flex">
                        {navLinks.map(({ href, label }) => (
                            <Link
                                key={label}
                                href={tagged(href)}
                                className={`rounded-md px-2 py-1 transition-colors ${
                                    active(href)
                                        ? 'bg-surface-2 text-foreground'
                                        : 'hover:text-foreground'
                                }`}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </header>

            {/* Page content — extra bottom padding on mobile for the tab bar */}
            <main className="mx-auto max-w-5xl px-4 py-6 pb-24 sm:pb-10">
                {children}
            </main>

            <footer className="hidden border-t border-border py-5 text-center sm:block">
                <p className="font-display text-xs font-bold tracking-[0.3em] text-muted-foreground uppercase">
                    {freq} FM
                </p>
                <p className="mt-1 text-xs text-muted-foreground/60">
                    Fuck corporate media · Request a song · Hear it live
                </p>
            </footer>

            {/* Mobile bottom tab bar — hidden on desktop */}
            <nav
                className="fixed right-0 bottom-0 left-0 z-50 flex border-t border-border bg-background/90 backdrop-blur-md sm:hidden"
                style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                {tabs.map(({ href, label, Icon }) => (
                    <Link
                        key={label}
                        href={tagged(href)}
                        className={`flex flex-1 flex-col items-center gap-1 py-2 text-[10px] font-bold tracking-wider uppercase transition-colors ${
                            active(href)
                                ? 'text-primary'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Icon
                            size={22}
                            strokeWidth={active(href) ? 2.5 : 1.75}
                        />
                        {label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
