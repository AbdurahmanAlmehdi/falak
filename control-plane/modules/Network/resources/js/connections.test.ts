import { describe, expect, it } from 'vitest';
import { actionableIp, bucketLabel, conntrackPercent, formatAge, latest, peerLabel, pointsBy, type PeerRow } from './connections';

const row = (overrides: Partial<PeerRow>): PeerRow => ({
    direction: 'outbound',
    address: '203.0.113.9',
    port: 443,
    peer: null,
    rdns: null,
    workload: 'Server',
    connections: 1,
    ...overrides,
});

describe('pointsBy', () => {
    it('keys points by a label, sums series of the same key and orders by time', () => {
        const { points, keys } = pointsBy(
            [
                {
                    labels: { falak_net_state: 'established' },
                    points: [
                        [20, 3],
                        [10, 1],
                    ],
                },
                { labels: { falak_net_state: 'established' }, points: [[10, 2]] },
                { labels: { falak_net_state: 'time_wait' }, points: [[10, null]] },
            ],
            'falak_net_state',
        );
        expect(keys).toEqual(['established', 'time_wait']);
        expect(points).toEqual([
            { t: 10, established: 3 },
            { t: 20, established: 3 },
        ]);
        expect(latest(points, 'established')).toBe(3);
        expect(latest(points, 'time_wait')).toBeNull();
    });
});

describe('peerLabel', () => {
    it('prefers the Falak name, then reverse DNS, then the address with its port', () => {
        expect(peerLabel(row({ peer: { label: 'app-2', url: '/servers/x' } }))).toBe('app-2');
        expect(peerLabel(row({ rdns: 'dns.google' }))).toBe('dns.google:443');
        expect(peerLabel(row({}))).toBe('203.0.113.9:443');
        expect(peerLabel(row({ direction: 'inbound', port: 0 }))).toBe('203.0.113.9');
        expect(peerLabel(row({ other: true, peers: 3, address: null }))).toBe('3 other peers');
    });
});

describe('bucketLabel', () => {
    it('names the metric buckets', () => {
        expect(bucketLabel('external:443')).toBe('Internet :443');
        expect(bucketLabel('external')).toBe('Internet');
        expect(bucketLabel('private:5432')).toBe('Private network :5432');
        expect(bucketLabel('other')).toBe('Other');
        expect(bucketLabel('host:caddy')).toBe('caddy');
        expect(bucketLabel('site:shop/web')).toBe('shop/web');
    });
});

describe('actionableIp', () => {
    it('offers actions on remote addresses only', () => {
        expect(actionableIp(row({}))).toBe('203.0.113.9');
        expect(actionableIp(row({ address: '127.0.0.1' }))).toBeNull();
        expect(actionableIp(row({ other: true }))).toBeNull();
        expect(actionableIp(row({ address: '10.90.0.2' }))).toBeNull();
        expect(actionableIp(row({ address: '172.18.0.3' }))).toBeNull();
        expect(actionableIp(row({ address: '192.168.1.4' }))).toBeNull();
        expect(actionableIp(row({ address: '100.64.0.1' }))).toBeNull();
        expect(actionableIp(row({ address: 'fd00::1' }))).toBeNull();
        expect(actionableIp(row({ address: 'fe80::1' }))).toBeNull();
        expect(actionableIp(row({ address: '172.32.0.1' }))).toBe('172.32.0.1');
        expect(actionableIp(row({ address: '2001:db8::1' }))).toBe('2001:db8::1');
        expect(actionableIp(row({ address: '203.0.113.11', peer: { label: 'app-2', url: '/servers/x' } }))).toBeNull();
        expect(actionableIp(row({ address: '172.70.1.2', unblockable: 'Cloudflare' }))).toBeNull();
    });
});

describe('conntrack and ages', () => {
    it('formats the fill and handshake ages', () => {
        expect(conntrackPercent({ entries: 870, limit: 1000, accounting: 'off' })).toBe(87);
        expect(conntrackPercent({ accounting: 'unavailable' })).toBeNull();
        expect(formatAge(null)).toBe('never');
        expect(formatAge(42)).toBe('42 s');
        expect(formatAge(600)).toBe('10 min');
        expect(formatAge(10800)).toBe('3 h');
    });
});
