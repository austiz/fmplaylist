import { useEffect, useRef } from 'react';
import { useFmLive } from '@/hooks/use-fm-live';
import { getCommutePalette } from '@/lib/commute';

const BARS = [
    'animate-bar-a',
    'animate-bar-b',
    'animate-bar-c',
    'animate-bar-d',
    'animate-bar-e',
] as const;

interface Particle {
    x: number;
    y: number;
    vx: number;
    vy: number;
    rot: number;
    vr: number;
    color: string;
    size: number;
}

/**
 * Fires when the listener's own requested song hits the air (driven by `onAirTitle` from
 * FmLive). Haptic buzz + a one-shot confetti burst + a celebratory banner. Under
 * `prefers-reduced-motion` it degrades to the banner alone.
 */
export function Celebration() {
    const { onAirTitle, dismissOnAir } = useFmLive();
    const canvasRef = useRef<HTMLCanvasElement>(null);

    useEffect(() => {
        if (!onAirTitle) {
            return;
        }

        try {
            navigator.vibrate?.([40, 30, 40, 30, 140]);
        } catch {
            /* unsupported */
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        const canvas = canvasRef.current;
        const ctx = canvas?.getContext('2d');

        if (!canvas || !ctx) {
            return;
        }

        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = window.innerWidth * dpr;
        canvas.height = window.innerHeight * dpr;
        ctx.scale(dpr, dpr);
        const W = window.innerWidth;
        const H = window.innerHeight;

        const pal = getCommutePalette();
        const palette = [
            `rgb(${pal.viz.high.map((v) => Math.round(v * 255)).join(',')})`,
            `rgb(${pal.viz.mid.map((v) => Math.round(v * 255)).join(',')})`,
            '#4ade80',
            '#ffffff',
        ];

        const particles: Particle[] = Array.from({ length: 90 }, () => ({
            x: W / 2 + (Math.random() - 0.5) * 120,
            y: H * 0.75,
            vx: (Math.random() - 0.5) * 9,
            vy: -Math.random() * 13 - 5,
            rot: Math.random() * Math.PI,
            vr: (Math.random() - 0.5) * 0.4,
            color: palette[(Math.random() * palette.length) | 0],
            size: 4 + Math.random() * 5,
        }));

        const start = performance.now();
        let raf = 0;
        const tick = (now: number) => {
            const life = now - start;
            ctx.clearRect(0, 0, W, H);

            for (const p of particles) {
                p.vy += 0.4; // gravity
                p.vx *= 0.99;
                p.x += p.vx;
                p.y += p.vy;
                p.rot += p.vr;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.globalAlpha = Math.max(0, 1 - life / 1500);
                ctx.fillStyle = p.color;
                ctx.fillRect(-p.size / 2, -p.size / 2, p.size, p.size * 0.6);
                ctx.restore();
            }

            if (life < 1600) {
                raf = requestAnimationFrame(tick);
            } else {
                ctx.clearRect(0, 0, W, H);
            }
        };
        raf = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(raf);
    }, [onAirTitle]);

    if (!onAirTitle) {
        return null;
    }

    return (
        <>
            <canvas
                ref={canvasRef}
                aria-hidden
                className="pointer-events-none fixed inset-0 z-[60]"
            />
            <div className="fixed right-4 bottom-4 left-4 z-[61] border border-online/30 bg-background/95 px-4 py-3 shadow-2xl backdrop-blur-sm sm:right-6 sm:left-auto sm:w-80">
                <div className="flex items-start gap-3">
                    <div className="flex h-5 shrink-0 items-end gap-0.75 pt-0.5">
                        {BARS.map((cls, i) => (
                            <span
                                key={i}
                                className={`inline-block h-full w-0.75 origin-bottom rounded-full bg-online ${cls}`}
                            />
                        ))}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="font-display text-xs font-bold tracking-wider text-online uppercase">
                            Your song is ON AIR!
                        </p>
                        <p className="mt-0.5 truncate text-sm font-medium text-foreground">
                            {onAirTitle}
                        </p>
                    </div>
                    <button
                        onClick={dismissOnAir}
                        className="shrink-0 text-muted-foreground/40 transition-colors hover:text-foreground"
                        aria-label="Dismiss"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </>
    );
}
