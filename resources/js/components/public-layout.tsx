import { Link, usePage } from '@inertiajs/react';
import { List, Music, Radio } from 'lucide-react';
import type { PropsWithChildren } from 'react';

const tabs = [
    { href: '/',      label: 'Home',  Icon: Radio },
    { href: '/songs', label: 'Songs', Icon: Music },
    { href: '/queue', label: 'Queue', Icon: List  },
];

export function PublicLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage<{ frequency: string }>();
    const freq = props.frequency ?? '96.9';
    const active = (href: string) =>
        href === '/' ? url === '/' : url.startsWith(href);

    return (
        <div className="min-h-screen bg-background text-foreground">
            {/* Header — logo only on mobile, logo + nav on desktop */}
            <header className="sticky top-0 z-40 border-b border-border bg-background/95 backdrop-blur-sm">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-2.5">
                    <Link href="/" className="group flex items-center gap-2">
                        <span className="font-display text-xl font-bold tracking-tight text-red-500 transition-colors group-hover:text-red-400">
                            FM
                        </span>
                        <span className="font-display text-xl font-bold tracking-tight text-foreground transition-colors group-hover:text-foreground/70">
                            PLAYLIST
                        </span>
                        <span className="ml-1 border border-red-500/40 bg-red-500/10 px-1.5 py-0.5 font-sans text-[10px] font-bold uppercase tracking-widest text-red-500">
                            {freq}
                        </span>
                    </Link>
                    {/* Desktop nav only */}
                    <nav className="hidden items-center gap-6 text-sm font-medium text-muted-foreground sm:flex">
                        {tabs.map(({ href, label }) => (
                            <Link
                                key={href}
                                href={href}
                                className={`py-1 transition-colors ${active(href) ? 'text-foreground' : 'hover:text-foreground'}`}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                </div>
            </header>

            {/* Page content — extra bottom padding on mobile for the tab bar */}
            <main className="mx-auto max-w-5xl px-4 py-6 pb-20 sm:pb-8">{children}</main>

            <footer
                className="hidden border-t border-border py-5 text-center sm:block"
            >
                <p className="font-display text-xs font-bold uppercase tracking-[0.3em] text-muted-foreground">
                    {freq} FM
                </p>
                <p className="mt-1 text-xs text-muted-foreground/50">
                    Fuck corporate media · Request a song · Hear it live
                </p>
            </footer>

            {/* Mobile bottom tab bar — hidden on desktop */}
            <nav
                className="fixed bottom-0 left-0 right-0 z-50 flex border-t border-border bg-background/95 backdrop-blur-sm sm:hidden"
                style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
            >
                {tabs.map(({ href, label, Icon }) => (
                    <Link
                        key={href}
                        href={href}
                        className={`flex flex-1 flex-col items-center gap-1 py-2 text-[10px] font-bold uppercase tracking-wider transition-colors ${
                            active(href)
                                ? 'text-red-500'
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
