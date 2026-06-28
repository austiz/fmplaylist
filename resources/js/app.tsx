import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        // FM playlist pages have their own layouts — no wrapper needed
        if (
            name === 'home' ||
            name === 'songs' ||
            name === 'queue' ||
            name.startsWith('admin/')
        ) {
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
