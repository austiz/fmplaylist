interface BarSparklineProps {
    values: number[];
    color?: string;
    labels?: string[];
    className?: string;
}

/** Hand-rolled inline SVG bar chart, no chart library. Bars scale to the tallest value. */
export function BarSparkline({
    values,
    color = 'var(--color-chart-1)',
    labels,
    className,
}: BarSparklineProps) {
    const max = Math.max(1, ...values);
    const w = 100 / values.length;

    return (
        <div className={className}>
            <svg
                viewBox="0 0 100 32"
                preserveAspectRatio="none"
                className="h-16 w-full"
                aria-hidden
            >
                {values.map((v, i) => {
                    const h = (v / max) * 30;

                    return (
                        <rect
                            key={i}
                            x={i * w + w * 0.15}
                            y={32 - h}
                            width={w * 0.7}
                            height={h}
                            fill={color}
                            opacity={v === 0 ? 0.15 : 0.9}
                        />
                    );
                })}
            </svg>
            {labels && (
                <div className="mt-1 flex justify-between font-display text-[9px] text-muted-foreground/50">
                    <span>{labels[0]}</span>
                    <span>{labels[labels.length - 1]}</span>
                </div>
            )}
        </div>
    );
}

interface LineSparklineProps {
    points: { label: string; value: number }[];
    color?: string;
    className?: string;
}

/** Hand-rolled inline SVG line/area chart for short trend series (e.g. last-7-days). */
export function LineSparkline({
    points,
    color = 'var(--color-chart-3)',
    className,
}: LineSparklineProps) {
    const max = Math.max(1, ...points.map((p) => p.value));
    const step = points.length > 1 ? 100 / (points.length - 1) : 0;
    const coords = points.map((p, i) => {
        const x = points.length > 1 ? i * step : 50;
        const y = 32 - (p.value / max) * 28;

        return `${x},${y}`;
    });
    const line = coords.join(' ');
    const area = `0,32 ${line} 100,32`;

    return (
        <div className={className}>
            <svg
                viewBox="0 0 100 32"
                preserveAspectRatio="none"
                className="h-16 w-full"
                aria-hidden
            >
                <polygon points={area} fill={color} opacity={0.12} />
                <polyline
                    points={line}
                    fill="none"
                    stroke={color}
                    strokeWidth={1.5}
                    vectorEffect="non-scaling-stroke"
                />
            </svg>
            <div className="mt-1 flex justify-between font-display text-[9px] text-muted-foreground/50">
                <span>{points[0]?.label}</span>
                <span>{points[points.length - 1]?.label}</span>
            </div>
        </div>
    );
}
