import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-background p-6 md:p-10">
            <div
                className="pointer-events-none absolute -top-20 left-1/2 h-64 w-96 -translate-x-1/2 rounded-full blur-3xl"
                style={{
                    background:
                        'radial-gradient(ellipse, oklch(0.55 0.24 27 / var(--glow-opacity, 0.1)), transparent)',
                    animation: 'hero-breathe 4s ease-in-out infinite',
                }}
            />
            <div className="relative w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="group flex items-center gap-2"
                        >
                            <span className="font-display text-xl font-bold tracking-tight text-red-500 transition-colors group-hover:text-red-400">
                                FM
                            </span>
                            <span className="font-display text-xl font-bold tracking-tight text-foreground transition-colors group-hover:text-foreground/70">
                                PLAYLIST
                            </span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="font-display text-xl font-bold text-foreground">
                                {title}
                            </h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
