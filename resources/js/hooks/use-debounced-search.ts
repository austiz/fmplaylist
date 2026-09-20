import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

/**
 * A search box that reloads the page's props rather than filtering in the browser.
 *
 * Both libraries are server-paginated, so filtering the loaded page would silently
 * miss everything outside it. Written out twice before this, and neither copy
 * cleared its pending timer on unmount -- navigating away mid-keystroke fired a
 * visit against the page you had just left.
 */
export function useDebouncedSearch(
    url: string,
    {
        params = {},
        only,
        delay = 300,
    }: {
        params?: Record<string, string>;
        only?: string[];
        delay?: number;
    } = {},
) {
    const timeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timeout.current), []);

    // Not memoized: the result is an uncontrolled input's onChange, so a fresh
    // identity each render costs nothing and keeps the options honest.
    return (e: React.ChangeEvent<HTMLInputElement>) => {
        const search = e.target.value;

        clearTimeout(timeout.current);
        timeout.current = setTimeout(() => {
            router.get(
                url,
                { ...params, search },
                {
                    preserveState: true,
                    replace: true,
                    ...(only ? { only } : {}),
                },
            );
        }, delay);
    };
}
