import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { home } from '@/routes';

type Props = PropsWithChildren<{
    title?: string;
    description?: string;
}>;

/**
 * The seven auth pages, on the same surface ramp as everything else.
 *
 * This was two files -- a wrapper around a starter-kit template -- putting the
 * form loose on the page canvas under a breathing red glow. Loose fields on a
 * flat background is the one place the app looked unfinished, because there was
 * no object to anchor them: the eye had nothing to read as "this is the thing
 * you are filling in". It is a card now, lifted off the canvas by `shadow-raised`
 * and its inset top highlight, which is the same treatment every dialog and
 * popover in the operator shell gets.
 *
 * The glow stays, dimmed and behind the card, because the brand is a radio
 * transmitter and a faint carrier hum on the sign-in screen is on the nose in a
 * way that earns its two lines.
 */
export default function AuthLayout({ children, title, description }: Props) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-background p-6 md:p-10">
            <div
                aria-hidden
                className="pointer-events-none absolute -top-32 left-1/2 h-80 w-[36rem] -translate-x-1/2 rounded-full blur-3xl motion-safe:animate-[hero-breathe_4s_ease-in-out_infinite]"
                style={{
                    background:
                        'radial-gradient(ellipse, var(--primary), transparent 70%)',
                    opacity: 'var(--glow-opacity, 0.08)',
                }}
            />

            <div className="relative w-full max-w-sm space-y-6">
                <Link
                    href={home()}
                    className="flex items-center justify-center gap-2 transition-opacity hover:opacity-80"
                >
                    <span className="flex aspect-square size-8 items-center justify-center rounded-lg bg-primary font-display text-xs font-bold text-primary-foreground">
                        FM
                    </span>
                    <span className="font-display text-lg font-bold tracking-tight text-foreground">
                        PLAYLIST
                    </span>
                </Link>

                <Card className="gap-6 bg-surface-1 shadow-raised">
                    <CardContent className="space-y-6">
                        {(title || description) && (
                            <div className="space-y-1.5 text-center">
                                {title && (
                                    <h1 className="font-display text-lg font-bold text-foreground">
                                        {title}
                                    </h1>
                                )}
                                {description && (
                                    <p className="text-sm text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}

                        {children}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
