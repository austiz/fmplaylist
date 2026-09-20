import { createInertiaApp } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { ConfirmProvider } from '@/components/confirm-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type LayoutProps = { children: ReactNode };

/**
 * Layouts are referenced by `layout()` below, so a static import would pull every
 * one of them into the entry bundle — the listener layout and its live-poll client
 * would ship on /login and on every admin page. Splitting them per-layout keeps
 * each route's cost its own.
 *
 * The wrapper is defined once per layout at module scope so its identity is stable:
 * Inertia only keeps a layout mounted across navigations while the same component
 * reference comes back, which is what FmLiveLayout relies on to hold its live poll
 * open rather than restarting it on every page.
 */
function lazyLayout(
    load: () => Promise<{ default: ComponentType<LayoutProps> }>,
) {
    const Loaded = lazy(load);

    return function LazyLayout({ children }: LayoutProps) {
        return (
            <Suspense fallback={null}>
                <Loaded>{children}</Loaded>
            </Suspense>
        );
    };
}

const AuthLayout = lazyLayout(() => import('@/layouts/auth-layout'));
const FmLiveLayout = lazyLayout(() => import('@/layouts/fm-live-layout'));

const FM_LIVE_PAGES = new Set(['home', 'songs', 'queue', 'drive']);

/**
 * The outermost layout on every page, whatever else wraps it.
 *
 * The toaster reads the shared `toast` prop, so it has to render inside the
 * Inertia page context. Mounting it beside the app in `withApp` put it
 * outside: `usePage` threw on first paint and took the whole tree down with
 * it, leaving a blank page. Being a layout also keeps it mounted across
 * navigations, so a toast raised by a redirect survives the page swap that
 * delivered it.
 */
function RootLayout({ children }: LayoutProps) {
    return (
        <>
            {children}
            <Toaster />
        </>
    );
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        // Listener pages share one persistent layout so the live poll stays
        // mounted across navigations (see fm-live-layout.tsx).
        if (FM_LIVE_PAGES.has(name)) {
            return [RootLayout, FmLiveLayout];
        }

        if (name.startsWith('auth/')) {
            return [RootLayout, AuthLayout];
        }

        // Admin and settings pages both render the operator shell themselves,
        // so the root layout is all Inertia has to supply.
        return RootLayout;
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                <ConfirmProvider>{app}</ConfirmProvider>
            </TooltipProvider>
        );
    },
    progress: {
        // Inertia writes this into a style tag before the stylesheet is
        // available, so it cannot be a var() — keep it in step with --primary.
        color: '#ED4042',
    },
});
