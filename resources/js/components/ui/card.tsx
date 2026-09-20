import type * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * The one card. Roughly fifteen files used to hand-roll
 * `border border-border bg-card p-5`, which is why padding and radius drifted
 * between panels that sat side by side.
 *
 * `shadow-card` pairs a drop shadow with a 1px inset top highlight -- on a dark
 * surface that highlight is what reads as an edge catching light, and without it
 * a raised card just looks like a lighter rectangle.
 *
 * Half the panels in the admin are forms with their own block flow, so they
 * cannot be this component -- and that is exactly how the radius drifted in the
 * first place: whatever could not adopt the card kept a copy of its skin, and
 * the copies stopped matching. `cardSkin` is the skin on its own, for those.
 */
export const cardSkin =
    'rounded-xl border border-border bg-card text-card-foreground shadow-card';

function Card({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card"
            className={cn(cardSkin, 'flex flex-col gap-5 py-5', className)}
            {...props}
        />
    );
}

function CardHeader({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-header"
            className={cn(
                'flex items-start justify-between gap-4 px-5',
                className,
            )}
            {...props}
        />
    );
}

function CardTitle({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-title"
            className={cn(
                'font-display text-sm font-semibold tracking-tight text-foreground',
                className,
            )}
            {...props}
        />
    );
}

function CardDescription({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-description"
            className={cn('text-sm text-muted-foreground', className)}
            {...props}
        />
    );
}

function CardContent({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div data-slot="card-content" className={cn('px-5', className)} {...props} />
    );
}

function CardFooter({ className, ...props }: React.ComponentProps<'div'>) {
    return (
        <div
            data-slot="card-footer"
            className={cn(
                'flex items-center gap-2 border-t border-border px-5 pt-4',
                className,
            )}
            {...props}
        />
    );
}

export { Card, CardHeader, CardFooter, CardTitle, CardDescription, CardContent };
