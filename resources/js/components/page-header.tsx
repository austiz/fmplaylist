import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * The heading-plus-actions row that eighteen places were spelling out by hand,
 * each with its own font size, tracking and gap. `actions` is a slot rather than
 * a prop list so the caller keeps control of what sits on the right.
 */
export function PageHeader({
    title,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-4',
                className,
            )}
        >
            <div className="min-w-0 space-y-1">
                <h1 className="font-display text-xl font-bold tracking-tight text-foreground">
                    {title}
                </h1>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}

/** The same shape one level down, inside a card or a section. */
export function SectionHeader({
    title,
    description,
    actions,
    className,
}: {
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3',
                className,
            )}
        >
            <div className="min-w-0 space-y-0.5">
                <h2 className="font-display text-sm font-semibold tracking-wide text-foreground">
                    {title}
                </h2>
                {description && (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex shrink-0 items-center gap-2">
                    {actions}
                </div>
            )}
        </div>
    );
}
