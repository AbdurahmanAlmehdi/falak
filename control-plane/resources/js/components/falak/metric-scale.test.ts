import { describe, expect, it } from 'vitest';
import { axisLabel, axisWidth, niceTicks, splitSeries } from './metric-scale';

const rate = (value: number) => (value >= 100 ? value.toFixed(0) : value >= 1 ? value.toFixed(1) : value.toFixed(2));

describe('splitSeries', () => {
    const series = [
        { key: 'site', stack: 'cpu' },
        { key: 'rest', stack: 'cpu' },
        { key: 'cache' },
        { key: 'limit', reference: true },
        { key: 'capacity', reference: true },
    ];

    it('scales to the usage and leaves far references off the chart', () => {
        const data = [
            { t: 1, site: 0.008, rest: 0.3, limit: 0.5, capacity: 8 },
            { t: 2, site: 0.01, rest: 0.2, limit: 0.5, capacity: 8 },
        ];
        const split = splitSeries(data, series, true);

        expect(split.drawn.map((item) => item.key)).toEqual(['site', 'rest', 'limit']);
        expect(split.offScale).toEqual([{ item: series[4], max: 8 }]);
        expect(split.max).toBe(0.5);
    });

    it('leaves out series without points and references without usage', () => {
        expect(splitSeries([{ t: 1, site: 1, limit: null }], series, true).drawn.map((item) => item.key)).toEqual(['site']);
        const alone = splitSeries([{ t: 1, limit: 4 }], series, true);
        expect(alone.drawn).toEqual([]);
        expect(alone.offScale).toEqual([]);
    });
});

describe('niceTicks', () => {
    it('ends at a round value at or above the max', () => {
        expect(niceTicks(0.308, rate)).toEqual([0, 0.1, 0.2, 0.3, 0.4]);
        expect(niceTicks(280_000, (value) => value.toFixed(0))).toEqual([0, 100_000, 200_000, 300_000]);
        expect(niceTicks(0, rate)).toEqual([0, 0.25, 0.5, 0.75, 1]);
    });

    it('never repeats a label', () => {
        const ticks = niceTicks(0.035, (value) => `${value.toFixed(2)}/s`);
        const labels = ticks.map((tick) => axisLabel(tick, (value) => `${value.toFixed(2)}/s`));

        expect(new Set(labels).size).toBe(labels.length);
    });
});

describe('axisLabel', () => {
    it('compacts large plain numbers only', () => {
        expect(axisLabel(280_000, (value) => value.toFixed(0))).toBe('280K');
        expect(axisLabel(9_000, (value) => value.toFixed(0))).toBe('9000');
        expect(axisLabel(90, (value) => `${value.toFixed(1)} ms`)).toBe('90.0 ms');
    });

    it('sizes the axis for the longest label', () => {
        expect(axisWidth([0, 100], (value) => `${value.toFixed(1)} ms`)).toBeGreaterThanOrEqual(8 * 6.5);
    });

    it("counts the unit's capitals wider than digits", () => {
        expect(axisWidth([0, 954], (value) => `${value} MB`)).toBeGreaterThan(axisWidth([0, 954], (value) => `${value} ms`));
    });
});
