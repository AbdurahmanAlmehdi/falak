import { describe, expect, it } from 'vitest';
import {
    combine,
    formatCores,
    hasValues,
    latest,
    memorySeries,
    oomKills,
    peak,
    quietAsZero,
    serviceRows,
    unmeasuredNote,
    withLabel,
} from './site-metrics';
import { type MetricSeriesDto } from './types';

const series = (labels: Record<string, string>, points: [number, number | null][]): MetricSeriesDto => ({ labels, points });

describe('combine', () => {
    it('sums a key over its series and keeps keys apart, ordered by time', () => {
        const points = combine(
            {
                site: [
                    series({}, [
                        [20, 0.5],
                        [10, 0.25],
                    ]),
                ],
                limit: [
                    series({ falak_server_id: 'A' }, [
                        [10, 1],
                        [20, 1],
                    ]),
                    series({ falak_server_id: 'B' }, [
                        [10, 2],
                        [20, null],
                    ]),
                ],
            },
            { limit: 'max' },
        );

        expect(points).toEqual([
            { t: 10, site: 0.25, limit: 2 },
            { t: 20, site: 0.5, limit: 1 },
        ]);
    });

    it('leaves a key unset where every series is null', () => {
        expect(
            combine({
                a: [
                    series({}, [
                        [10, null],
                        [20, 3],
                    ]),
                    series({}, [[10, null]]),
                ],
            }),
        ).toEqual([{ t: 10 }, { t: 20, a: 3 }]);
    });
});

describe('withLabel / hasValues', () => {
    it('picks series by label and tells empty results apart', () => {
        const memory = [series({ falak_memory_type: 'used' }, [[10, 100]]), series({ falak_memory_type: 'cache' }, [[10, null]])];

        expect(withLabel(memory, 'falak_memory_type', 'cache')).toHaveLength(1);
        expect(hasValues(withLabel(memory, 'falak_memory_type', 'cache'))).toBe(false);
        expect(hasValues(memory)).toBe(true);
        expect(hasValues(undefined)).toBe(false);
    });
});

describe('latest / peak', () => {
    it('reads the last and the largest number', () => {
        const points = [
            { t: 1, value: 4 },
            { t: 2, value: 9 },
            { t: 3, value: null },
        ];

        expect(latest(points)).toBe(9);
        expect(peak(points)).toBe(9);
        expect(latest([])).toBeNull();
    });
});

describe('oomKills', () => {
    it('marks the steps with at least one kill and counts them', () => {
        const { markers, total } = oomKills([
            series({}, [
                [10, 0],
                [20, 1.02],
                [30, 0.2],
                [40, 2.9],
            ]),
        ]);

        expect(markers).toEqual([
            { t: 20, label: 'OOM' },
            { t: 40, label: 'OOM ×3' },
        ]);
        expect(total).toBe(4);
    });
});

describe('serviceRows', () => {
    it('joins CPU, memory and limits of each service on each server', () => {
        const rows = serviceRows({
            services_cpu: [
                series({ falak_server_id: 'S1', falak_compose_service: 'web', falak_workload_name: 'shop-web-1' }, [
                    [10, 0.2],
                    [20, 0.4],
                ]),
                series({ falak_server_id: 'S1', falak_compose_service: 'db', falak_workload_name: 'shop-db-1' }, [[20, 0.1]]),
            ],
            services_memory: [series({ falak_server_id: 'S1', falak_compose_service: 'web', falak_workload_name: 'shop-web-1' }, [[20, 512]])],
            services_memory_limit: [series({ falak_server_id: 'S1', falak_compose_service: 'web', falak_workload_name: 'shop-web-1' }, [[20, 1024]])],
        });

        expect(rows).toEqual([
            { key: 's1/db/shop-db-1', name: 'db', container: 'shop-db-1', serverId: 's1', cpu: 0.1, cpuLimit: null, memory: null, memoryLimit: null },
            {
                key: 's1/web/shop-web-1',
                name: 'web',
                container: 'shop-web-1',
                serverId: 's1',
                cpu: 0.4,
                cpuLimit: null,
                memory: 512,
                memoryLimit: 1024,
            },
        ]);
    });

    it('names a site container after the site, the container kept apart', () => {
        const [row] = serviceRows({ services_memory: [series({ service_name: 'pos', falak_workload_name: 'falak-pos-green' }, [[10, 1]])] });
        expect([row.name, row.container]).toEqual(['pos', 'falak-pos-green']);
    });

    it('names a workload without a compose service after the workload', () => {
        expect(serviceRows({ services_cpu: [series({ falak_workload_name: 'falak-site_shop.slice' }, [[10, 1]])] })[0].name).toBe(
            'falak-site_shop.slice',
        );
    });
});

describe('formatCores', () => {
    it('keeps small values readable', () => {
        expect(formatCores(0)).toBe('0');
        expect(formatCores(0.004)).toBe('0.004');
        expect(formatCores(0.25)).toBe('0.25');
        expect(formatCores(1.5)).toBe('1.5');
        expect(formatCores(2)).toBe('2');
        expect(formatCores(16)).toBe('16');
    });
});

describe('quietAsZero', () => {
    it('reads a missing counter as 0 where the requests have a value', () => {
        const points = combine({
            value: [series({}, [[2, 1]])],
            seen: [
                series({}, [
                    [1, 5],
                    [2, 6],
                ]),
            ],
        });

        expect(quietAsZero(points)).toEqual([
            { t: 1, value: 0 },
            { t: 2, value: 1 },
        ]);
        expect(quietAsZero(combine({ value: [], seen: [] }))).toEqual([]);
    });
});

describe('memorySeries', () => {
    it('stacks the site and the rest of the server only, the reclaimable cache apart', () => {
        const series = memorySeries({ rest: 'Rest of the server', limit: 'Limit', capacity: 'Server memory' });

        expect(series.filter((item) => item.stack === 'memory').map((item) => item.key)).toEqual(['used', 'rest']);
        const cache = series.find((item) => item.key === 'cache');
        expect(cache?.label).toBe('Cache (reclaimable)');
        expect(cache?.mark ?? 'line').toBe('line');
        expect(cache?.stack).toBeUndefined();
    });
});

describe('unmeasuredNote', () => {
    it('says why only the server is shown', () => {
        expect(unmeasuredNote(true, true)).toBeNull();
        expect(unmeasuredNote(false, true)).toBe('No usage of this site’s own has arrived yet; the server’s is shown.');
        expect(unmeasuredNote(false, true, true)).toBe('Update this server’s agent to see this site’s own usage; the server’s is shown.');
        expect(unmeasuredNote(true, true, true)).toBeNull();
        expect(unmeasuredNote(false, false, true)).toContain('once their agent is updated');
        expect(unmeasuredNote(false, false)).toContain('once their agent is updated');
    });
});
