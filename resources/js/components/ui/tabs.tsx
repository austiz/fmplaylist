import * as TabsPrimitive from '@radix-ui/react-tabs';
import type * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Replaces three hand-rolled tab strips that each reimplemented the underline,
 * the active colour and the hover -- and none of which were keyboard navigable,
 * since they were plain buttons with no roving tabindex.
 */
function Tabs({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Root>) {
    return (
        <TabsPrimitive.Root
            data-slot="tabs"
            className={cn('flex flex-col gap-6', className)}
            {...props}
        />
    );
}

function TabsList({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.List>) {
    return (
        <TabsPrimitive.List
            data-slot="tabs-list"
            className={cn(
                'flex items-center gap-1 border-b border-border',
                className,
            )}
            {...props}
        />
    );
}

function TabsTrigger({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Trigger>) {
    return (
        <TabsPrimitive.Trigger
            data-slot="tabs-trigger"
            className={cn(
                '-mb-px inline-flex items-center gap-2 border-b-2 border-transparent px-3 pt-1 pb-3 text-sm font-medium text-muted-foreground transition-colors',
                'hover:text-foreground',
                'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                'data-[state=active]:border-primary data-[state=active]:text-foreground',
                'disabled:pointer-events-none disabled:opacity-50',
                className,
            )}
            {...props}
        />
    );
}

function TabsContent({
    className,
    ...props
}: React.ComponentProps<typeof TabsPrimitive.Content>) {
    return (
        <TabsPrimitive.Content
            data-slot="tabs-content"
            className={cn('outline-none', className)}
            {...props}
        />
    );
}

export { Tabs, TabsList, TabsTrigger, TabsContent };
