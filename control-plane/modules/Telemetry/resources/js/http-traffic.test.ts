import { ipActionsFor, registerIpActions } from '@/lib/registry';
import { describe, expect, it } from 'vitest';
import { bytePoints, describeRules, flag, protocolLabel, shares, statusPoints, tlsLabel } from './http-traffic';

describe('HTTP traffic charts', () => {
    it('splits requests by status class', () => {
        const points = statusPoints([
            {
                labels: { http_response_status_class: '2xx' },
                points: [
                    [1, 4],
                    [2, 5],
                ],
            },
            { labels: { http_response_status_class: '5xx' }, points: [[2, 1]] },
        ]);

        expect(points).toEqual([
            { t: 1, '2xx': 4 },
            { t: 2, '2xx': 5, '5xx': 1 },
        ]);
    });

    it('takes bytes without a direction as bytes out', () => {
        expect(
            bytePoints([
                { labels: { network_io_direction: 'receive' }, points: [[1, 10]] },
                { labels: { network_io_direction: 'transmit' }, points: [[1, 30]] },
                { labels: {}, points: [[2, 7]] },
            ]),
        ).toEqual([
            { t: 1, in: 10, out: 30 },
            { t: 2, out: 7 },
        ]);
    });

    it('computes shares over the range, largest first', () => {
        const result = shares(
            [
                {
                    labels: { network_protocol_version: '2' },
                    points: [
                        [1, 3],
                        [2, 3],
                    ],
                },
                {
                    labels: { network_protocol_version: '1.1' },
                    points: [
                        [1, 2],
                        [2, null],
                    ],
                },
            ],
            'network_protocol_version',
        );

        expect(result.map((s) => [s.key, s.share])).toEqual([
            ['2', 0.75],
            ['1.1', 0.25],
        ]);
        expect(shares([], 'x')).toEqual([]);
    });

    it('labels protocols, TLS versions, countries and threats', () => {
        expect(protocolLabel('2')).toBe('HTTP/2');
        expect(tlsLabel('none')).toBe('No TLS');
        expect(tlsLabel('1.3')).toBe('TLS 1.3');
        expect(flag('de')).toBe('🇩🇪');
        expect(flag(null)).toBe('');
        expect(describeRules({ git: 3, env: 40 }, 120)).toBe('.env files ×40, version control (/.git) ×3, 404 ×120');
    });
});

describe('network.ip-actions slot', () => {
    it('lists the registered actions the user may use, in order', () => {
        registerIpActions({ id: 'b', order: 20, component: () => null }, { id: 'a', order: 10, permission: 'network.manage', component: () => null });

        expect(ipActionsFor({ can: () => true }).map((a) => a.id)).toEqual(['a', 'b']);
        expect(ipActionsFor({ can: () => false }).map((a) => a.id)).toEqual(['b']);
    });
});
