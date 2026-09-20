import { createContext, use, useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type ConfirmOptions = {
    title: string;
    description?: ReactNode;
    /** Defaults to "Confirm". Say what will happen: "Delete", "Skip", "Remove". */
    confirmLabel?: string;
    cancelLabel?: string;
    /** `destructive` reds the action button; use it when the result is not undoable. */
    variant?: 'default' | 'destructive';
};

type Pending = ConfirmOptions & { resolve: (ok: boolean) => void };

const ConfirmContext = createContext<
    ((options: ConfirmOptions) => Promise<boolean>) | null
>(null);

/**
 * Replaces twelve native `confirm()` calls. Those blocked the main thread, could
 * not be styled, are suppressed outright by some mobile browsers after a previous
 * dismissal, and rendered the raw string -- so the destructive ones looked exactly
 * as urgent as "Skip current song?".
 *
 * The await-able shape means call sites keep reading top to bottom:
 *
 *   if (!(await confirm({ title: `Delete "${song.title}"?`, variant: 'destructive' }))) return;
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [pending, setPending] = useState<Pending | null>(null);
    const pendingRef = useRef<Pending | null>(null);

    const confirm = useCallback(
        (options: ConfirmOptions) =>
            new Promise<boolean>((resolve) => {
                const next = { ...options, resolve };
                pendingRef.current = next;
                setPending(next);
            }),
        [],
    );

    // Radix closes on Escape, on overlay click and on the cancel button; all of
    // them land here, so the promise always settles rather than leaking a
    // suspended caller when the dialog is dismissed without a choice.
    const settle = useCallback((ok: boolean) => {
        pendingRef.current?.resolve(ok);
        pendingRef.current = null;
        setPending(null);
    }, []);

    return (
        <ConfirmContext value={confirm}>
            {children}
            <Dialog
                open={pending !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        settle(false);
                    }
                }}
            >
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{pending?.title}</DialogTitle>
                        {pending?.description && (
                            <DialogDescription>
                                {pending.description}
                            </DialogDescription>
                        )}
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => settle(false)}>
                            {pending?.cancelLabel ?? 'Cancel'}
                        </Button>
                        <Button
                            autoFocus
                            variant={
                                pending?.variant === 'destructive'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={() => settle(true)}
                        >
                            {pending?.confirmLabel ?? 'Confirm'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext>
    );
}

export function useConfirm(): (options: ConfirmOptions) => Promise<boolean> {
    const confirm = use(ConfirmContext);

    if (!confirm) {
        throw new Error('useConfirm must be used within a ConfirmProvider');
    }

    return confirm;
}
