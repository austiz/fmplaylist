import { useState } from 'react';
import type { ReactNode } from 'react';
import { Input } from '@/components/ui/input';

/**
 * The search box + scrolling single-select list the Broadcast page used to spell
 * out three times, once each for songs, commercials and sound bytes.
 *
 * The three copies differed only in what they matched on, what they printed next
 * to the title, and how tall the rows were -- so those are the props.
 */
const SIZES = {
    lg: { list: 'max-h-48 bg-card', row: 'px-4 py-2.5', empty: 'px-4 py-3' },
    sm: { list: 'max-h-40', row: 'px-3 py-2', empty: 'px-3 py-3' },
} as const;

export function SearchablePickList<T extends { id: number }>({
    items,
    placeholder,
    emptyText,
    selectedId,
    onSelect,
    searchText,
    renderRow,
    limit = 20,
    size = 'sm',
}: {
    items: T[];
    placeholder: string;
    emptyText: string;
    /** The form's current value -- a string, because that is what `useForm` holds. */
    selectedId: string;
    onSelect: (id: number) => void;
    /** Everything the search box should match against, for one item. */
    searchText: (item: T) => string;
    renderRow: (item: T) => ReactNode;
    limit?: number;
    size?: keyof typeof SIZES;
}) {
    const [search, setSearch] = useState('');
    const cls = SIZES[size];

    const matches = items.filter((item) =>
        searchText(item).toLowerCase().includes(search.toLowerCase()),
    );

    return (
        <>
            <Input
                placeholder={placeholder}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
            />
            <div
                className={`divide-y divide-border overflow-y-auto border border-border ${cls.list}`}
            >
                {matches.slice(0, limit).map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        onClick={() => onSelect(item.id)}
                        className={`w-full text-left text-sm transition-colors hover:bg-secondary ${cls.row} ${
                            selectedId === String(item.id)
                                ? 'bg-red-500/10 text-foreground'
                                : 'text-foreground'
                        }`}
                    >
                        {renderRow(item)}
                    </button>
                ))}
                {matches.length === 0 && (
                    <p className={`text-sm text-muted-foreground ${cls.empty}`}>
                        {emptyText}
                    </p>
                )}
            </div>
        </>
    );
}
