import { Link } from '@inertiajs/react';

import type { PaginatedResponse } from '@/types/fm';

/**
 * Laravel's paginator links, rendered.
 *
 * This was copied into the history, sounds and songs pages, where it had drifted
 * into three slightly different sets of classes. The listener-facing variant is
 * the one real difference, so it is a prop rather than a fourth copy.
 */
type Variant = 'admin' | 'listener';

const ACTIVE = 'bg-primary text-primary-foreground';

const INACTIVE: Record<Variant, string> = {
    admin: 'border border-border text-muted-foreground hover:text-foreground',
    listener:
        'border border-border text-muted-foreground transition-colors hover:border-primary hover:text-primary',
};

const BASE: Record<Variant, string> = {
    admin: 'px-3 py-1.5 text-xs font-medium',
    listener:
        'px-3 py-1.5 font-display text-xs font-bold tracking-wider uppercase',
};

export function Pagination<T>({
    page,
    variant = 'admin',
    className = '',
}: {
    page: PaginatedResponse<T>;
    variant?: Variant;
    className?: string;
}) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <div className={`flex flex-wrap justify-center gap-1 ${className}`}>
            {page.links.map((link, i) =>
                link.url ? (
                    <Link
                        key={i}
                        href={link.url}
                        className={`${BASE[variant]} ${link.active ? ACTIVE : INACTIVE[variant]}`}
                        // Labels are the paginator's own "&laquo; Previous" markup.
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span
                        key={i}
                        className="px-3 py-1.5 text-xs text-muted-foreground/30"
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ),
            )}
        </div>
    );
}
