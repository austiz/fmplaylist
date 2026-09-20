import type { PropsWithChildren, ReactNode } from 'react';

import { AdminSidebar } from '@/components/admin/admin-sidebar';
import { CommandPalette } from '@/components/admin/command-palette';
import { flatNav } from '@/components/admin/nav-items';
import {
    SidebarInset,
    SidebarProvider,
    SidebarTrigger,
} from '@/components/ui/sidebar';

type Props = PropsWithChildren<{
    title?: string;
    description?: string;
    /** Page-level actions, rendered at the right of the top bar. */
    actions?: ReactNode;
}>;

/**
 * The operator shell: a collapsible rail plus a sticky top bar.
 *
 * The previous shell was a header with seven flat nav links above a full-width
 * transmitter strip, which cost two rows of vertical space on every page and had
 * nowhere to put an eighth destination. The rail collapses to icons (Ctrl/Cmd+B,
 * remembered in a cookie) and becomes a sheet on mobile, so the nav is reachable
 * without spending the room permanently.
 */
export function AdminLayout({ children, title, description, actions }: Props) {
    return (
        <SidebarProvider>
            <AdminSidebar />

            <SidebarInset className="min-w-0">
                <header className="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-sm">
                    <SidebarTrigger className="-ml-1" />

                    <div className="min-w-0 flex-1">
                        {title && (
                            <h1 className="truncate font-display text-sm font-semibold text-foreground">
                                {title}
                            </h1>
                        )}
                        {description && (
                            <p className="truncate text-xs text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>

                    {actions}

                    <span className="hidden items-center gap-1 rounded-md border border-border px-1.5 py-0.5 font-display text-[10px] font-semibold text-muted-foreground/70 sm:inline-flex">
                        <kbd className="font-sans">⌘K</kbd>
                    </span>
                </header>

                <main className="min-w-0 flex-1 p-4 sm:p-6">{children}</main>
            </SidebarInset>

            <CommandPalette nav={flatNav} />
        </SidebarProvider>
    );
}

export default AdminLayout;
