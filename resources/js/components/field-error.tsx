import type { HTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * The validation message under a field. Renders nothing when there is no error,
 * so call sites don't repeat the `{form.errors.x && …}` guard around the markup.
 *
 * Replaces three components that did the same job in three sizes and two shades
 * of hand-picked red -- `field-error` (text-xs/red-400), `input-error`
 * (text-sm/red-600 with a dark: override the dark-only app never used) and an
 * inline copy in pages/songs.tsx.
 */
export function FieldError({
    message,
    className,
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p
            {...props}
            className={cn('text-xs text-destructive', className)}
            role="alert"
        >
            {message}
        </p>
    );
}

export default FieldError;
