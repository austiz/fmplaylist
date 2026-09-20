import { router } from '@inertiajs/react';
import { useCallback } from 'react';

interface ReloadOptions {
    only: string[];
}

function supportsViewTransitions(): boolean {
    return (
        typeof document !== 'undefined' &&
        'startViewTransition' in document &&
        !window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * Wraps an Inertia partial reload in a View Transition so elements with a stable
 * `viewTransitionName` (e.g. queue rows) animate to their new position/state instead of
 * snapping. Falls back to a plain reload when the API is unsupported or motion is reduced.
 */
export function useViewTransitionReload(): (options: ReloadOptions) => void {
    // Stable identity: callers pass this straight into effect dependency arrays,
    // where a fresh function every render would restart their polling.
    return useCallback((options: ReloadOptions) => {
        if (!supportsViewTransitions()) {
            router.reload({ only: options.only });

            return;
        }

        document.startViewTransition(() => {
            return new Promise<void>((resolve) => {
                router.reload({
                    only: options.only,
                    onSuccess: () => {
                        // Inertia's page-store update has already triggered React's re-render
                        // by the time onSuccess fires; wait a couple of frames so the commit
                        // actually paints before the browser snapshots the "after" state.
                        requestAnimationFrame(() =>
                            requestAnimationFrame(() => resolve()),
                        );
                    },
                    onError: () => resolve(),
                });
            });
        });
    }, []);
}
