import { createInertiaApp } from '@inertiajs/react';
import { lazy, Suspense } from 'react';
import type { ComponentType, ReactNode } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

type LayoutProps = { children: ReactNode };

/**
 * Layouts are referenced by `layout()` below, so a static import would pull every
 * one of them into the entry bundle — which meant the WebGL visualizer, its shader
 * source and the SSE client (all reachable from FmLiveLayout) shipped on /login and
 * on every admin page. Splitting them per-layout keeps each route's cost its own.
 *
 * The wrapper is defined once per layout at module scope so its identity is stable:
 * Inertia only keeps a layout mounted across navigations while the same component
 * reference comes back, which is what FmLiveLayout relies on to hold its SSE
 * connection and WebGL context open.
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

const AppLayout = lazyLayout(() => import('@/layouts/app-layout'));
const AuthLayout = lazyLayout(() => import('@/layouts/auth-layout'));
const FmLiveLayout = lazyLayout(() => import('@/layouts/fm-live-layout'));
const SettingsLayout = lazyLayout(() => import('@/layouts/settings/layout'));

const FM_LIVE_PAGES = new Set(['home', 'songs', 'queue', 'drive']);

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        // Listener pages share one persistent layout so the SSE connection + WebGL
        // visualizer stay mounted across navigations (see fm-live-layout.tsx).
        if (FM_LIVE_PAGES.has(name)) {
            return FmLiveLayout;
        }

        // Admin pages supply their own layout internally.
        if (name.startsWith('admin/')) {
            return null;
        }

        if (name.startsWith('auth/')) {
            return AuthLayout;
        }

        if (name.startsWith('settings/')) {
            return [AppLayout, SettingsLayout];
        }

        return AppLayout;
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
