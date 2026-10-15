/** The y-axis of MetricChart: scaled to the usage series, limits drawn only near it, ticks with distinct labels. */

type Point = { t: number | string } & Record<string, number | string | null>;
type Series = { key: string; stack?: string; reference?: boolean };

/** A reference (limit, capacity) is drawn only within this factor of the largest usage value. */
export const REFERENCE_RANGE = 2;

export function hasPoints(data: Point[], key: string): boolean {
    return data.some((point) => typeof point[key] === 'number');
}

/** Largest value of the series per point, stacked series summed per stack; null without any value. */
export function seriesMax(data: Point[], series: Series[], stacked: boolean): number | null {
    let max: number | null = null;

    data.forEach((point) => {
        const sums = new Map<string, number>();
        series.forEach((item) => {
            const value = point[item.key];
            if (typeof value !== 'number') return;
            const group = stacked && item.stack ? `stack:${item.stack}` : item.key;
            sums.set(group, (sums.get(group) ?? 0) + value);
        });
        sums.forEach((value) => {
            if (max === null || value > max) max = value;
        });
    });

    return max;
}

/**
 * Which series are drawn: those with points, references only within REFERENCE_RANGE of the usage (the rest are
 * `offScale`, listed in the legend with their value). `max` is the top of what is drawn.
 */
export function splitSeries<T extends Series>(
    data: Point[],
    series: T[],
    stacked: boolean,
): { drawn: T[]; offScale: { item: T; max: number }[]; max: number | null } {
    const withPoints = series.filter((item) => hasPoints(data, item.key));
    const usage = withPoints.filter((item) => !item.reference);
    const usageMax = seriesMax(data, usage, stacked);
    const drawn: T[] = [];
    const offScale: { item: T; max: number }[] = [];
    let max = usageMax;

    withPoints.forEach((item) => {
        if (!item.reference) return drawn.push(item);
        // No usage: a limit alone says nothing.
        if (usageMax === null) return;
        const referenceMax = seriesMax(data, [item], false) ?? 0;
        if (referenceMax > REFERENCE_RANGE * usageMax) return offScale.push({ item, max: referenceMax });
        drawn.push(item);
        max = Math.max(max ?? 0, referenceMax);
    });

    return { drawn: series.filter((item) => drawn.includes(item)), offScale, max };
}

const compactNumber = new Intl.NumberFormat('en', { notation: 'compact', maximumFractionDigits: 1 });

/** An axis label: `format`, plain numbers from 10,000 compacted (280000 → 280K). */
export function axisLabel(value: number, format: (value: number) => string): string {
    const label = format(value);

    return Math.abs(value) >= 10_000 && /^-?[\d,]+(\.\d+)?$/.test(label) ? compactNumber.format(value) : label;
}

/** 0 to at least `max` in 4 (else 3, 2, 1) round steps whose labels all differ once formatted. */
export function niceTicks(max: number, format: (value: number) => string): number[] {
    const top = max > 0 ? max : 1;

    for (const count of [4, 3, 2, 1]) {
        const raw = top / count;
        const magnitude = 10 ** Math.floor(Math.log10(raw));
        const step = [1, 2, 2.5, 5, 10].map((factor) => factor * magnitude).find((candidate) => candidate >= raw * (1 - 1e-9))!;
        const ticks: number[] = [];
        for (let index = 0; ticks.length === 0 || ticks[ticks.length - 1] < top * (1 - 1e-9); index++) {
            ticks.push(Number((index * step).toPrecision(12)));
        }
        const labels = ticks.map((tick) => axisLabel(tick, format));
        if (new Set(labels).size === labels.length) return ticks;
    }

    return [0, top];
}

/** Axis width for the longest label (11px sans digits ≈ 6.5px each). */
export function axisWidth(ticks: number[], format: (value: number) => string): number {
    const longest = Math.max(0, ...ticks.map((tick) => axisLabel(tick, format).length));

    return Math.max(28, Math.ceil(longest * 6.5) + 8);
}
