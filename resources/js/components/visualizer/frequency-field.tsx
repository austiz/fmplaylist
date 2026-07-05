import { useEffect, useRef, useState } from 'react';
import { useFmLive } from '@/hooks/use-fm-live';
import type { CommutePalette } from '@/lib/commute';
import { FRAG, VERT } from './field.glsl';

interface Props {
    /** Page-level weight 0‥1 — full on home/drive, low behind lists for readability. */
    weight?: number;
    className?: string;
}

function compile(gl: WebGLRenderingContext, type: number, src: string): WebGLShader | null {
    const sh = gl.createShader(type);

    if (!sh) {
return null;
}

    gl.shaderSource(sh, src);
    gl.compileShader(sh);

    if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) {
        gl.deleteShader(sh);

        return null;
    }

    return sh;
}

function toCssGradient(p: CommutePalette): string {
    const rgb = (c: [number, number, number]) =>
        `rgb(${c.map((v) => Math.round(v * 255)).join(',')})`;

    return `radial-gradient(120% 90% at 50% 100%, ${rgb(p.viz.mid)}55, transparent 60%), radial-gradient(80% 60% at 50% 110%, ${rgb(p.viz.high)}33, transparent 55%)`;
}

/**
 * The single WebGL frequency field for the whole listener session, mounted once in the
 * persistent layout and rendered behind all content. Simulated spectrum (no real audio),
 * art-directed by the commute palette and the current now-playing/pi state.
 *
 * Guardrails: low-power context, DPR capped, rAF paused when the tab is hidden, fps throttled
 * on touch devices, static gradient under `prefers-reduced-motion`, CSS-gradient fallback if
 * WebGL is unavailable, and a `webglcontextlost`/`restored` recovery path — mobile browsers
 * reclaim GL contexts under memory pressure, and this canvas lives for the whole session, so a
 * driving listener locking their phone repeatedly must not leave the field permanently black.
 */
export function FrequencyField({ weight = 1, className }: Props) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const [fallback, setFallback] = useState(false);
    const { nowPlaying, piStatus, palette } = useFmLive();

    // Imperative state read by the render loop (avoids re-renders per frame).
    const energyRef = useRef(0.4 * weight);
    const targetRef = useRef(0.4 * weight);
    const seedRef = useRef(0.3);
    const startedAtRef = useRef<number | null>(null);
    const durationRef = useRef(0);
    const paletteRef = useRef<CommutePalette>(palette);

    // Sync live state -> render targets.
    useEffect(() => {
        const playing = nowPlaying?.type === 'song' && !!nowPlaying.song;
        const live = piStatus?.status === 'live';
        targetRef.current = ((playing ? 1.0 : 0.4) + (live ? 0.18 : 0)) * weight;

        const id = nowPlaying?.song?.id ?? 0;
        seedRef.current = ((id * 2654435761) % 1000) / 1000;
        startedAtRef.current = nowPlaying?.started_at ? Date.parse(nowPlaying.started_at) : null;
        durationRef.current = nowPlaying?.song?.duration_seconds ?? 0;
    }, [nowPlaying, piStatus, weight]);

    // Mirror the shared palette (rolls over on FmLiveProvider's 60s timer) into a ref so
    // the imperative render loop can read it without re-rendering every frame.
    useEffect(() => {
        paletteRef.current = palette;
    }, [palette]);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
return;
}

        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const coarse = window.matchMedia('(pointer: coarse)').matches;

        let gl: WebGLRenderingContext | null = null;
        let teardownSession: (() => void) | null = null;

        // (Re)builds the GL program/buffers and starts the render loop. Called on mount and
        // again after a `webglcontextrestored` event, since lost contexts take all GL resources
        // (shaders, buffers, program) down with them.
        const startSession = () => {
            gl = canvas.getContext('webgl', {
                antialias: false,
                alpha: false,
                depth: false,
                powerPreference: 'low-power',
            }) as WebGLRenderingContext | null;

            if (!gl) {
 setFallback(true);

 return; 
}

            setFallback(false);

            const vs = compile(gl, gl.VERTEX_SHADER, VERT);
            const fs = compile(gl, gl.FRAGMENT_SHADER, FRAG);
            const prog = gl.createProgram();

            if (!vs || !fs || !prog) {
 setFallback(true);

 return; 
}

            gl.attachShader(prog, vs);
            gl.attachShader(prog, fs);
            gl.linkProgram(prog);

            if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
 setFallback(true);

 return; 
}

            gl.useProgram(prog);

            // Fullscreen triangle-strip quad.
            const buf = gl.createBuffer();
            gl.bindBuffer(gl.ARRAY_BUFFER, buf);
            gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW);
            const loc = gl.getAttribLocation(prog, 'a_pos');
            gl.enableVertexAttribArray(loc);
            gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0);

            const u = {
                res: gl.getUniformLocation(prog, 'u_res'),
                time: gl.getUniformLocation(prog, 'u_time'),
                intensity: gl.getUniformLocation(prog, 'u_intensity'),
                seed: gl.getUniformLocation(prog, 'u_seed'),
                progress: gl.getUniformLocation(prog, 'u_progress'),
                bps: gl.getUniformLocation(prog, 'u_bps'),
                low: gl.getUniformLocation(prog, 'u_low'),
                mid: gl.getUniformLocation(prog, 'u_mid'),
                high: gl.getUniformLocation(prog, 'u_high'),
            };

            const dprCap = coarse ? 1.5 : 2;
            const resize = () => {
                const dpr = Math.min(window.devicePixelRatio || 1, dprCap);
                const w = Math.floor(canvas.clientWidth * dpr);
                const h = Math.floor(canvas.clientHeight * dpr);

                if (canvas.width !== w || canvas.height !== h) {
                    canvas.width = w;
                    canvas.height = h;
                    gl!.viewport(0, 0, w, h);
                }
            };

            const draw = (tSec: number) => {
                resize();
                const p = paletteRef.current;
                let progress = 0;

                if (startedAtRef.current && durationRef.current > 0) {
                    progress = Math.min(1, Math.max(0, (Date.now() - startedAtRef.current) / 1000 / durationRef.current));
                }

                gl!.uniform2f(u.res, canvas.width, canvas.height);
                gl!.uniform1f(u.time, tSec);
                gl!.uniform1f(u.intensity, energyRef.current);
                gl!.uniform1f(u.seed, seedRef.current);
                gl!.uniform1f(u.progress, progress);
                gl!.uniform1f(u.bps, 1.6 + seedRef.current * 0.8);
                gl!.uniform3fv(u.low, p.viz.low);
                gl!.uniform3fv(u.mid, p.viz.mid);
                gl!.uniform3fv(u.high, p.viz.high);
                gl!.drawArrays(gl!.TRIANGLE_STRIP, 0, 4);
            };

            // Reduced motion: one static frame, no loop.
            if (reduce) {
                energyRef.current = targetRef.current;
                draw(0);
                const onResize = () => draw(0);
                window.addEventListener('resize', onResize);
                teardownSession = () => window.removeEventListener('resize', onResize);

                return;
            }

            let raf = 0;
            let last = 0;
            const minFrame = coarse ? 1000 / 30 : 0;
            const start = performance.now();

            const loop = (now: number) => {
                raf = requestAnimationFrame(loop);

                if (document.hidden) {
return;
}

                if (now - last < minFrame) {
return;
}

                last = now;
                // Ease energy toward target so state changes glide.
                energyRef.current += (targetRef.current - energyRef.current) * 0.05;
                draw((now - start) / 1000);
            };
            raf = requestAnimationFrame(loop);

            const onVis = () => {
 if (!document.hidden) {
last = 0;
} 
};
            document.addEventListener('visibilitychange', onVis);

            teardownSession = () => {
                cancelAnimationFrame(raf);
                document.removeEventListener('visibilitychange', onVis);
            };
        };

        const onContextLost = (e: Event) => {
            e.preventDefault(); // signals the browser we want to recover
            teardownSession?.();
            teardownSession = null;
        };
        const onContextRestored = () => startSession();

        canvas.addEventListener('webglcontextlost', onContextLost, false);
        canvas.addEventListener('webglcontextrestored', onContextRestored, false);

        startSession();

        return () => {
            canvas.removeEventListener('webglcontextlost', onContextLost);
            canvas.removeEventListener('webglcontextrestored', onContextRestored);
            teardownSession?.();
            gl?.getExtension('WEBGL_lose_context')?.loseContext();
        };
    }, []);

    if (fallback) {
        return (
            <div
                className={className}
                aria-hidden
                style={{ background: toCssGradient(palette), opacity: weight }}
            />
        );
    }

    return <canvas ref={canvasRef} aria-hidden className={className} style={{ pointerEvents: 'none' }} />;
}
