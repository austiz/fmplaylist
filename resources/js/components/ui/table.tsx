import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react';
import type * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * There was not a single <table> in this codebase -- every list of records was a
 * stack of divs, which is why none of them could sort, none had a header that
 * stayed put while scrolling, and screen readers got no row/column relationship.
 */
function Table({ className, ...props }: React.ComponentProps<'table'>) {
    return (
        <div
            data-slot="table-container"
            className="relative w-full overflow-x-auto rounded-xl border border-border bg-card shadow-card"
        >
            <table
                data-slot="table"
                className={cn('w-full caption-bottom text-sm', className)}
                {...props}
            />
        </div>
    );
}

function TableHeader({ className, ...props }: React.ComponentProps<'thead'>) {
    return (
        <thead
            data-slot="table-header"
            className={cn(
                'sticky top-0 z-10 bg-surface-2 [&_tr]:border-b [&_tr]:border-border',
                className,
            )}
            {...props}
        />
    );
}

function TableBody({ className, ...props }: React.ComponentProps<'tbody'>) {
    return (
        <tbody
            data-slot="table-body"
            className={cn('[&_tr:last-child]:border-0', className)}
            {...props}
        />
    );
}

function TableRow({ className, ...props }: React.ComponentProps<'tr'>) {
    return (
        <tr
            data-slot="table-row"
            className={cn(
                'border-b border-border transition-colors hover:bg-surface-2/60 data-[state=selected]:bg-surface-3',
                className,
            )}
            {...props}
        />
    );
}

function TableHead({ className, ...props }: React.ComponentProps<'th'>) {
    return (
        <th
            data-slot="table-head"
            className={cn(
                'h-10 px-3 text-left align-middle font-display text-[11px] font-semibold tracking-wider text-muted-foreground uppercase whitespace-nowrap',
                '[&:has([role=checkbox])]:w-0 [&:has([role=checkbox])]:pr-0',
                className,
            )}
            {...props}
        />
    );
}

function TableCell({ className, ...props }: React.ComponentProps<'td'>) {
    return (
        <td
            data-slot="table-cell"
            className={cn(
                'px-3 py-2.5 align-middle',
                '[&:has([role=checkbox])]:w-0 [&:has([role=checkbox])]:pr-0',
                className,
            )}
            {...props}
        />
    );
}

export type SortDirection = 'asc' | 'desc';

/**
 * A sortable column header. Renders the neutral two-way chevron until this
 * column is the active sort, so the affordance is visible before the first click
 * rather than appearing only once something is already sorted.
 */
function TableSortHead({
    active,
    direction = 'asc',
    onSort,
    children,
    className,
    ...props
}: React.ComponentProps<'th'> & {
    active?: boolean;
    direction?: SortDirection;
    onSort?: () => void;
}) {
    const Icon = !active ? ChevronsUpDown : direction === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead
            aria-sort={
                active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'
            }
            className={cn('p-0', className)}
            {...props}
        >
            <button
                type="button"
                onClick={onSort}
                className={cn(
                    'flex h-10 w-full items-center gap-1.5 px-3 transition-colors hover:text-foreground',
                    'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    active && 'text-foreground',
                )}
            >
                {children}
                <Icon className={cn('size-3', !active && 'opacity-40')} />
            </button>
        </TableHead>
    );
}

export {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
    TableSortHead,
};
