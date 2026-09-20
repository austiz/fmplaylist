import { Search } from 'lucide-react';
import type { ReactNode } from 'react';

import { Input } from '@/components/ui/input';

/**
 * The search row above a media library: a box on the left, a count on the right.
 *
 * Written out three times before this, with three different placeholders and no
 * icon on any of them. The transport deliberately still differs per library --
 * songs are paginated fifty at a time, so filtering them in the browser would
 * quietly search one page of a library that has many; commercials and sound
 * bytes arrive whole, so a round trip to filter a list already in hand would be
 * slower for no gain. What is shared is what the operator sees, which is why
 * this takes raw input props and lets each caller wire its own.
 */
export function LibrarySearch({
    summary,
    ...input
}: { summary: ReactNode } & React.ComponentProps<typeof Input>) {
    return (
        <div className="flex items-center gap-3">
            <div className="relative max-w-xs flex-1">
                <Search
                    aria-hidden
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input type="search" className="pl-9" {...input} />
            </div>
            <span className="text-xs text-muted-foreground tabular-nums">
                {summary}
            </span>
        </div>
    );
}
