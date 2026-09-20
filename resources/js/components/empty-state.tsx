import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * What a list looks like with nothing in it. Four places wrote their own, and
 * they disagreed on whether an empty list is a failure -- two of them rendered
 * muted grey text that read as a loading state that never finished.
 */
export function EmptyState({
    icon,
    title,
    description,
    action,
    className,
}: {
    icon?: ReactNode;
    title: ReactNode;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border px-6 py-12 text-center',
                className,
            )}
        >
            {icon && (
                <span className="text-muted-foreground/50 [&>svg]:size-7">
                    {icon}
                </span>
            )}
            <div className="space-y-1">
                <p className="font-display text-sm font-semibold text-foreground">
                    {title}
                </p>
                {description && (
                    <p className="mx-auto max-w-sm text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </div>
    );
}
