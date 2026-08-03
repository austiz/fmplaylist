import { useEffect, useState } from 'react';

const STAGGER_MS = 28;
// Must match the `flap-char` keyframe duration in app.css — content swaps at the
// animation's rotateX(-90deg) midpoint, when the glyph is edge-on and invisible.
const FLIP_MS = 380;

function useSplitFlapDisplay(text: string): {
    display: string;
    generation: number;
} {
    const [display, setDisplay] = useState(text);
    const [prevText, setPrevText] = useState(text);
    const [generation, setGeneration] = useState(0);

    // Adjust state during render in response to a prop change — React's recommended
    // alternative to an Effect for this (see https://react.dev/learn/you-might-not-need-an-effect).
    if (text !== prevText) {
        setPrevText(text);
        setGeneration((g) => g + 1);
    }

    // The actual flip timers are a genuine side effect (subscribing to setTimeout),
    // so an Effect is the right place for them — setState only happens inside the
    // timeout callbacks, never synchronously in the effect body.
    useEffect(() => {
        if (text === display) {
            return;
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const timer = setTimeout(() => setDisplay(text), 0);

            return () => clearTimeout(timer);
        }

        const len = Math.max(display.length, text.length);
        const timers: ReturnType<typeof setTimeout>[] = [];

        for (let i = 0; i < len; i++) {
            const delay = i * STAGGER_MS + FLIP_MS / 2;
            timers.push(
                setTimeout(() => {
                    setDisplay((cur) => {
                        const chars = cur.padEnd(len, ' ').split('');
                        chars[i] = text[i] ?? '';

                        return chars.join('').replace(/ +$/, '');
                    });
                }, delay),
            );
        }

        return () => timers.forEach(clearTimeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text]);

    return { display, generation };
}

/** Kinetic "departure board" title swap — flips each character when `text` changes. */
export function SplitFlapText({
    text,
    className,
}: {
    text: string;
    className?: string;
}) {
    const { display, generation } = useSplitFlapDisplay(text);

    return (
        <span className={className}>
            {display.split('').map((ch, i) => (
                <span
                    key={`${generation}-${i}`}
                    className={
                        generation > 0
                            ? 'inline-block animate-flap-char'
                            : 'inline-block'
                    }
                    style={{ animationDelay: `${i * STAGGER_MS}ms` }}
                >
                    {ch === ' ' ? ' ' : ch}
                </span>
            ))}
        </span>
    );
}
