import type { ReactNode } from 'react';

import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * A single headline number. `tabular-nums` matters more than it looks: these
 * values tick live off the /api/live poll, and without fixed-width digits the
 * whole row jitters sideways every time a count crosses a digit boundary.
 *
 * `delay` drives the staggered mount-in; it is an inline style because the
 * stagger is per-index and Tailwind cannot generate a class per position.
 */
export function StatTile({
    label,
    value,
    hint,
    icon,
    delay = 0,
    className,
}: {
    label: ReactNode;
    value: ReactNode;
    hint?: ReactNode;
    icon?: ReactNode;
    delay?: number;
    className?: string;
}) {
    return (
        <Card
            className={cn('animate-card-in gap-0 py-4', className)}
            style={{ animationDelay: `${delay}ms` }}
        >
            <div className="flex items-start justify-between gap-3 px-5">
                <p className="font-display text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    {label}
                </p>
                {icon && (
                    <span className="text-muted-foreground/60 [&>svg]:size-4">
                        {icon}
                    </span>
                )}
            </div>
            <p className="mt-1.5 px-5 font-display text-3xl font-bold text-foreground tabular-nums">
                {value}
            </p>
            {hint && (
                <p className="mt-0.5 px-5 text-sm text-muted-foreground">
                    {hint}
                </p>
            )}
        </Card>
    );
}
