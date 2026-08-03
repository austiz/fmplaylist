import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';

interface Action {
    id: string;
    label: string;
    run: () => void;
}

interface CommandPaletteProps {
    /** Reused from AdminLayout's own nav array so palette entries never drift out of sync. */
    nav: { href: string; label: string }[];
}

/** Cmd/Ctrl+K admin command palette — hand-rolled on Radix Dialog, no cmdk dependency
 *  needed for a small fixed action set. */
export function CommandPalette({ nav }: CommandPaletteProps) {
    const [open, setOpen] = useState(false);
    const [prevOpen, setPrevOpen] = useState(open);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    // Reset the search/selection when the dialog opens — adjusting state during render in
    // response to a prop/state change, per React's alternative to an Effect for this exact
    // case (see https://react.dev/learn/you-might-not-need-an-effect).
    if (open !== prevOpen) {
        setPrevOpen(open);

        if (open) {
            setQuery('');
            setActiveIndex(0);
        }
    }

    useEffect(() => {
        const onKeyDown = (e: KeyboardEvent) => {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    // Focusing the input is a genuine side effect (an external DOM API), not a setState
    // call, so an Effect is the right tool here.
    useEffect(() => {
        if (!open) {
            return;
        }

        const raf = requestAnimationFrame(() => inputRef.current?.focus());

        return () => cancelAnimationFrame(raf);
    }, [open]);

    const actions = useMemo<Action[]>(() => {
        const quickActions: Action[] = [
            {
                id: 'skip',
                label: 'Skip current song',
                run: () => {
                    if (confirm('Skip current song?')) {
                        router.post('/admin/broadcast/skip');
                    }
                },
            },
            {
                id: 'emergency',
                label: 'Emergency broadcast',
                run: () => {
                    if (
                        confirm(
                            '⚠ This will cut the current song, clear the entire queue, and play the emergency announcement on air. Continue?',
                        )
                    ) {
                        router.post('/admin/broadcast/emergency');
                    }
                },
            },
        ];

        const navActions: Action[] = nav.map((item) => ({
            id: `nav-${item.href}`,
            label: `Go to ${item.label}`,
            run: () => router.visit(item.href),
        }));

        return [...quickActions, ...navActions];
    }, [nav]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q
            ? actions.filter((a) => a.label.toLowerCase().includes(q))
            : actions;
    }, [actions, query]);

    const runAndClose = (action: Action) => {
        action.run();
        setOpen(false);
    };

    const onInputKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex((i) => Math.min(i + 1, filtered.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex((i) => Math.max(i - 1, 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const action = filtered[activeIndex];

            if (action) {
                runAndClose(action);
            }
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent
                className="top-[20%] max-w-md translate-y-0 gap-0 overflow-hidden p-0"
                onOpenAutoFocus={(e) => e.preventDefault()}
            >
                <DialogTitle className="sr-only">Command Palette</DialogTitle>
                <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
                    <Search
                        size={16}
                        className="shrink-0 text-muted-foreground"
                    />
                    <input
                        ref={inputRef}
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setActiveIndex(0);
                        }}
                        onKeyDown={onInputKeyDown}
                        placeholder="Jump to a page or run a command..."
                        className="w-full bg-transparent text-sm text-foreground placeholder:text-muted-foreground/50 focus:outline-none"
                    />
                </div>
                <div className="max-h-72 overflow-y-auto py-1">
                    {filtered.length === 0 && (
                        <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                            No matches.
                        </p>
                    )}
                    {filtered.map((action, i) => (
                        <button
                            key={action.id}
                            onClick={() => runAndClose(action)}
                            onMouseEnter={() => setActiveIndex(i)}
                            className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm transition-colors ${
                                i === activeIndex
                                    ? 'bg-secondary text-foreground'
                                    : 'text-muted-foreground'
                            }`}
                        >
                            <span>{action.label}</span>
                            {i === activeIndex && (
                                <CornerDownLeft
                                    size={13}
                                    className="shrink-0 opacity-50"
                                />
                            )}
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
