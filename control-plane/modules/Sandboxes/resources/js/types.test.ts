import { describe, expect, it } from 'vitest';
import { formatMemory, formatTimeLeft, sandboxBadge, ttlLabel } from './types';

describe('sandbox helpers', () => {
    it('formats the time left', () => {
        expect(formatTimeLeft(0)).toBe('expired');
        expect(formatTimeLeft(65)).toBe('1m 05s');
        expect(formatTimeLeft(3600 + 5 * 60)).toBe('1h 05m');
    });

    it('labels sizes, durations and statuses', () => {
        expect(formatMemory(2048)).toBe('2 GB');
        expect(formatMemory(1536)).toBe('1536 MB');
        expect(ttlLabel(1800)).toBe('30 minutes');
        expect(ttlLabel(3600)).toBe('1 hour');
        expect(ttlLabel(7200)).toBe('2 hours');
        expect(sandboxBadge({ status: 'running' })).toEqual({ status: 'active', label: 'Running' });
        expect(sandboxBadge({ status: 'killed' }).label).toBe('Killed');
    });
});
