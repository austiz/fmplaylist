import { usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Surfaces redirect feedback as a toast. The server shares one `toast` prop
 * (HandleInertiaRequests::resolveToast) built from the session's `success`/`error`
 * keys, so both `->with('success', ...)` and an explicit toast arrive the same way
 * and there is one place to render them.
 *
 * Fires on prop identity rather than on message text: two identical messages in a
 * row -- "Current song skipped." twice -- are two separate events and should show
 * twice, but a re-render with the same prop object is not.
 */
export function useFlashToast(): void {
    const { toast: flash } = usePage<{ toast: FlashToast | null }>().props;
    const shown = useRef<FlashToast | null>(null);

    useEffect(() => {
        if (!flash || shown.current === flash) {
            return;
        }

        shown.current = flash;
        toast[flash.type](flash.message);
    }, [flash]);
}
