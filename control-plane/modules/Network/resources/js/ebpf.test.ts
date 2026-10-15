import { describe, expect, it } from 'vitest';
import { formatRate, formatSeconds, mergeCharts, serverNotice, type EbpfServer } from './ebpf';

const server = (overrides: Partial<EbpfServer>): EbpfServer => ({
    server_id: '01j',
    name: 'app-1',
    enabled: true,
    delivery: 'applied',
    error: null,
    agent_outdated: false,
    probe: { supported: true, enabled: true, state: 'running', programs: ['dns', 'runq', 'tcp'] },
    can_manage: true,
    ...overrides,
});

describe('serverNotice', () => {
    it('is silent while the probe runs whole', () => {
        expect(serverNotice(server({}))).toBeNull();
    });

    it('asks for an agent update first, then says what the kernel lacks, then that the setting is off', () => {
        expect(serverNotice(server({ agent_outdated: true, enabled: false }))?.title).toBe('Update the agent');
        expect(
            serverNotice(
                server({
                    enabled: false,
                    probe: { supported: false, reason: 'kernel 5.4.0 is older than 5.8', enabled: false, state: 'off', programs: [] },
                }),
            )?.body,
        ).toBe('app-1 cannot run the eBPF probe: kernel 5.4.0 is older than 5.8.');
        expect(serverNotice(server({ enabled: false }))?.title).toBe('TCP and DNS insights are off');
    });

    it('explains failures, shedding and the budget', () => {
        expect(serverNotice(server({ delivery: 'failed', error: 'agent busy' }))).toEqual({
            tone: 'danger',
            title: 'The setting did not reach the agent',
            body: 'agent busy',
        });
        expect(
            serverNotice(
                server({ probe: { supported: true, enabled: true, state: 'degraded', detail: 'run-queue programs shed', programs: ['dns', 'tcp'] } }),
            )?.tone,
        ).toBe('warning');
        expect(
            serverNotice(server({ probe: { supported: true, enabled: true, state: 'over_budget', detail: '1.40 % of CPU', programs: [] } }))?.body,
        ).toBe('1.40 % of CPU Turn it off and on again to retry.');
        expect(serverNotice(server({ probe: null }))?.title).toBe('Starting');
    });
});

describe('formatting', () => {
    it('formats durations from microseconds to seconds', () => {
        expect(formatSeconds(0.00082)).toBe('820 µs');
        expect(formatSeconds(0.0124)).toBe('12.4 ms');
        expect(formatSeconds(0.25)).toBe('250 ms');
        expect(formatSeconds(1.234)).toBe('1.2 s');
        expect(formatSeconds(null)).toBe('—');
        expect(formatSeconds(Number.NaN)).toBe('—');
    });

    it('formats rates', () => {
        expect(formatRate(0.123)).toBe('0.12/s');
        expect(formatRate(3.21)).toBe('3.2/s');
        expect(formatRate(42.4)).toBe('42/s');
    });
});

describe('mergeCharts', () => {
    it('puts single-series charts side by side per time', () => {
        expect(
            mergeCharts({
                p50: [
                    {
                        labels: {},
                        points: [
                            [10, 0.001],
                            [20, 0.002],
                        ],
                    },
                ],
                p95: [
                    {
                        labels: {},
                        points: [
                            [20, 0.01],
                            [30, null],
                        ],
                    },
                ],
                p99: undefined,
            }),
        ).toEqual([{ t: 10, p50: 0.001 }, { t: 20, p50: 0.002, p95: 0.01 }, { t: 30 }]);
    });
});
