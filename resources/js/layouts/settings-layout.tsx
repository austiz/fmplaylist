import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { AdminLayout } from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';

const tabs = [
    { href: '/settings/profile', label: 'Profile' },
    { href: '/settings/security', label: 'Security' },
];

/**
 * Account settings, inside the operator shell.
 *
 * These two pages used to be the only reason the Laravel starter kit's whole
 * shell survived -- an `AppLayout` wrapping an `AppSidebar` whose nav pointed at
 * `/dashboard`, a route that only redirects to `/admin`. An operator editing
 * their password was looking at a different product than the one they were
 * running a radio station from. Same sidebar now; the Profile/Security split
 * becomes a segmented control instead of a second vertical nav.
 */
export function SettingsLayout({ children }: PropsWithChildren) {
    const { url } = usePage();

    return (
        <AdminLayout
            title="Account"
            description="Your profile, password and sign-in methods"
        >
            <div className="mx-auto max-w-2xl space-y-6">
                <nav
                    aria-label="Account settings"
                    className="inline-flex gap-1 rounded-lg border border-border bg-surface-2 p-1"
                >
                    {tabs.map((tab) => {
                        const active = url.split('?')[0] === tab.href;

                        return (
                            <Link
                                key={tab.href}
                                href={tab.href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    active
                                        ? 'bg-surface-3 text-foreground shadow-card'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </Link>
                        );
                    })}
                </nav>

                <div className="space-y-10">{children}</div>
            </div>
        </AdminLayout>
    );
}

export default SettingsLayout;
