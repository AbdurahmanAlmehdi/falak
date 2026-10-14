import { describe, expect, it } from 'vitest';
import {
    actionLabel,
    countryRows,
    dailyTotals,
    formatCount,
    formatPercent,
    hourlyPoints,
    statusRows,
    type CloudflareTraffic,
} from './cloudflare-analytics';

const traffic: CloudflareTraffic = {
    requests: 1000,
    cached_requests: 700,
    cached_ratio: 0.7,
    bytes: 16000,
    cached_bytes: 7000,
    visits: 100,
    cache_status: [{ status: 'hit', requests: 600 }],
    countries: [
        { country: 'DE', requests: 700 },
        { country: '', requests: 100 },
    ],
    status_classes: { '2xx': 900, '3xx': 0, '4xx': 75, '5xx': 25, other: 0 },
    series: [
        { t: '2026-10-15T11:00:00+00:00', cached: 300, origin: 200 },
        { t: '2026-10-15T10:00:00+00:00', cached: 400, origin: 100 },
    ],
};

describe('formatting', () => {
    it('formats counts and percentages for tiles', () => {
        expect(formatCount(999)).toBe('999');
        expect(formatCount(1234)).toBe('1.2K');
        expect(formatCount(null)).toBe('—');
        expect(formatPercent(0.7012)).toBe('70%');
        expect(formatPercent(0.0004)).toBe('<1%');
        expect(formatPercent(0)).toBe('0%');
        expect(formatPercent(null)).toBe('—');
        expect(actionLabel('managed_challenge')).toBe('Managed challenge');
    });
});

describe('folding', () => {
    it('orders the hourly series by time, in ms', () => {
        expect(hourlyPoints(traffic)).toEqual([
            { t: Date.parse('2026-10-15T10:00:00Z'), cached: 400, origin: 100 },
            { t: Date.parse('2026-10-15T11:00:00Z'), cached: 300, origin: 200 },
        ]);
        expect(hourlyPoints(null)).toEqual([]);
    });

    it('keeps status classes with requests, with their share', () => {
        expect(statusRows(traffic)).toEqual([
            { label: '2xx', requests: 900, share: 0.9 },
            { label: '4xx', requests: 75, share: 0.075 },
            { label: '5xx', requests: 25, share: 0.025 },
        ]);
    });

    it('names unknown countries and shares them of all requests', () => {
        expect(countryRows(traffic)).toEqual([
            { country: 'DE', requests: 700, share: 0.7 },
            { country: 'Unknown', requests: 100, share: 0.1 },
        ]);
    });

    it('sums the zone days', () => {
        expect(dailyTotals(null)).toBeNull();
        expect(
            dailyTotals([
                { date: '2026-10-13', requests: 100, cached_requests: 50, bytes: 10, cached_bytes: 5, threats: 1 },
                { date: '2026-10-14', requests: 300, cached_requests: 250, bytes: 30, cached_bytes: 20, threats: 2 },
            ]),
        ).toEqual({ requests: 400, cachedRatio: 0.75, bytes: 40, threats: 3 });
    });
});
