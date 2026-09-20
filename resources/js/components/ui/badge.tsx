import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import type * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Status pills. Every variant maps to a semantic token rather than a palette
 * literal, so a transmitting station is `variant="playing"` and not
 * `bg-red-500/10 text-red-400` spelled slightly differently in each of the
 * files that needed it. `playing` and `live` are distinct states, not synonyms:
 * a station is `playing` a track, or `live` over the top of one.
 */
const badgeVariants = cva(
    'inline-flex w-fit shrink-0 items-center justify-center gap-1.5 rounded-md border px-2 py-0.5 font-display text-[11px] font-semibold tracking-wide whitespace-nowrap uppercase transition-colors [&>svg]:size-3 [&>svg]:pointer-events-none',
    {
        variants: {
            variant: {
                neutral:
                    'border-border bg-surface-2 text-muted-foreground',
                brand: 'border-primary/30 bg-primary-soft text-primary',
                playing: 'border-playing/30 bg-playing-soft text-playing',
                live: 'border-live/30 bg-live-soft text-live',
                online: 'border-online/30 bg-online-soft text-online',
                offline: 'border-border bg-offline-soft text-offline',
                warning: 'border-warning/30 bg-warning-soft text-warning',
                info: 'border-info/30 bg-info-soft text-info',
                destructive:
                    'border-destructive/30 bg-destructive/12 text-destructive',
                solid: 'border-transparent bg-primary text-primary-foreground',
            },
        },
        defaultVariants: {
            variant: 'neutral',
        },
    },
);

/** The little status dot. `pulse` is reserved for genuinely live state. */
function BadgeDot({ pulse = false }: { pulse?: boolean }) {
    return (
        <span className="relative flex size-1.5">
            {pulse && (
                <span className="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:hidden" />
            )}
            <span className="relative inline-flex size-1.5 rounded-full bg-current" />
        </span>
    );
}

function Badge({
    className,
    variant,
    asChild = false,
    dot = false,
    pulse = false,
    children,
    ...props
}: React.ComponentProps<'span'> &
    VariantProps<typeof badgeVariants> & {
        asChild?: boolean;
        dot?: boolean;
        pulse?: boolean;
    }) {
    const Comp = asChild ? Slot : 'span';

    return (
        <Comp
            data-slot="badge"
            className={cn(badgeVariants({ variant }), className)}
            {...props}
        >
            {dot && <BadgeDot pulse={pulse} />}
            {children}
        </Comp>
    );
}

export { Badge, BadgeDot, badgeVariants };
