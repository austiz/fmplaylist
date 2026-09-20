// Credit: https://usehooks-ts.com/
import { useEffect, useRef, useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

/**
 * @param resetAfter  ms until the copied value clears, so a "Copied!" label goes
 *                    back to "Copy" on its own. Pass 0 to keep it indefinitely.
 */
export function useClipboard(resetAfter = 2000): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);
    const timeout = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timeout.current), []);

    const copy: CopyFn = async (text) => {
        if (!navigator?.clipboard) {
            console.warn('Clipboard not supported');

            return false;
        }

        clearTimeout(timeout.current);

        try {
            await navigator.clipboard.writeText(text);
            setCopiedText(text);

            if (resetAfter > 0) {
                timeout.current = setTimeout(
                    () => setCopiedText(null),
                    resetAfter,
                );
            }

            return true;
        } catch (error) {
            console.warn('Copy failed', error);
            setCopiedText(null);

            return false;
        }
    };

    return [copiedText, copy];
}
