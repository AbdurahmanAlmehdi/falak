import { describe, expect, it } from 'vitest';
import {
    combine,
    formatMs,
    formatRate,
    hasValues,
    latest,
    mergePoints,
    queryInsightsState,
    reportingNote,
    severityTone,
    sortRecommendations,
    splitBy,
    type MetricSeriesDto,
    type Recommendation,
} from './observability';

const series = (labels: Record<string, string>, points: [number, number | null][]): MetricSeriesDto => ({ labels, points });

describe('combine', () => {
    it('sums series per key by timestamp, or takes the max', () => {
        const points = combine(
            {
                used: [
                    series({}, [
                        [1, 2],
                        [2, 3],
                    ]),
                    series({}, [
                        [1, 1],
                        [2, null],
                    ]),
                ],
                limit: [series({ a: '1' }, [[1, 10]]), series({ a: '2' }, [[1, 8]])],
            },
            { limit: 'max' },
        );

        expect(points).toEqual([
            { t: 1, used: 3, limit: 10 },
            { t: 2, used: 3 },
        ]);
        expect(latest(points, 'used')).toBe(3);
        expect(latest(points, 'limit')).toBe(10);
    });
});

describe('splitBy', () => {
    it('makes one key per label value, the largest first, folding the rest into other', () => {
        const input = [
            series({ falak_db_name: 'small' }, [[1, 1]]),
            series({ falak_db_name: 'big' }, [[1, 100]]),
            series({ falak_db_name: 'mid' }, [[1, 10]]),
        ];
        const { keys, points } = splitBy(input, 'falak_db_name', 2);

        expect(keys).toEqual(['big', 'mid', 'other']);
        expect(points).toEqual([{ t: 1, big: 100, mid: 10, other: 1 }]);
        expect(splitBy(undefined, 'x')).toEqual({ keys: [], points: [] });
    });
});

describe('mergePoints', () => {
    it('merges point lists by timestamp', () => {
        expect(
            mergePoints(
                [
                    { t: 2, a: 1 },
                    { t: 1, a: 2 },
                ],
                [{ t: 1, b: 3 }],
            ),
        ).toEqual([
            { t: 1, a: 2, b: 3 },
            { t: 2, a: 1 },
        ]);
    });
});

describe('formatting', () => {
    it('formats durations and rates', () => {
        expect(formatMs(0.5)).toBe('0.5 ms');
        expect(formatMs(250)).toBe('250 ms');
        expect(formatMs(2500)).toBe('2.50 s');
        expect(formatMs(90_000)).toBe('1.5 min');
        expect(formatMs(null)).toBe('—');
        expect(formatRate(0.25)).toBe('0.25');
        expect(formatRate(12.34)).toBe('12.3');
        expect(formatRate(4200)).toBe('4.2k');
    });

    it('tells whether a chart has data', () => {
        expect(hasValues([series({}, [[1, null]])])).toBe(false);
        expect(hasValues([series({}, [[1, 0]])])).toBe(true);
        expect(hasValues(undefined)).toBe(false);
    });
});

describe('recommendations', () => {
    it('orders by severity, keeping the order of equals', () => {
        const items: Recommendation[] = [
            { key: 'a', severity: 'info', title: 'a', detail: '' },
            { key: 'b', severity: 'critical', title: 'b', detail: '' },
            { key: 'c', severity: 'warning', title: 'c', detail: '' },
            { key: 'd', severity: 'info', title: 'd', detail: '' },
        ];

        expect(sortRecommendations(items).map((item) => item.key)).toEqual(['b', 'c', 'a', 'd']);
        expect(severityTone('critical')).toBe('danger');
        expect(severityTone('info')).toBe('info');
    });
});

describe('insights state', () => {
    it('explains a missing report and the query insights status', () => {
        expect(reportingNote('ok')).toBeNull();
        expect(reportingNote('image_outdated')).toContain('Upgrade');
        expect(reportingNote('agent_outdated')).toContain('agent');

        expect(queryInsightsState({ enabled: true, status: 'on', reason: null, can_enable: false }, 'postgresql')).toEqual({
            tone: 'success',
            label: 'On',
            text: 'On',
        });
        expect(queryInsightsState({ enabled: true, status: 'off', reason: null, can_enable: true, pending: true }, 'postgresql').label).toBe('Pending');
        expect(
            queryInsightsState({ enabled: true, status: 'off', reason: null, can_enable: true, pending: true, error: 'Not applied: no agent' }, 'mariadb').tone,
        ).toBe('danger');
        expect(queryInsightsState({ enabled: false, status: 'unavailable', reason: 'read-only', can_enable: false }, 'postgresql').text).toBe('read-only');
        expect(queryInsightsState({ enabled: false, status: 'on', reason: null, can_enable: false }, 'redis').text).toBe('On (SLOWLOG)');
        expect(queryInsightsState({ enabled: true, status: 'off', reason: null, can_enable: false }, 'postgresql').tone).toBe('warning');
        expect(queryInsightsState({ enabled: false, status: 'off', reason: 'performance_schema is off', can_enable: false }, 'mysql').text).toBe(
            'Off: performance_schema is off',
        );
        expect(queryInsightsState({ enabled: false, status: null, reason: null, can_enable: true }, 'mariadb').text).toContain('no report');
    });
});
