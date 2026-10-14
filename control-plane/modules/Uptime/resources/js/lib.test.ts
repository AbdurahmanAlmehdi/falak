import { describe, expect, it } from 'vitest';
import { formatDurationSeconds, formatUptime, intervalLabel, isValidExpectedStatus, monitorStatus, uptimeTone } from './lib';

describe('uptime helpers', () => {
    it('formats percentages', () => {
        expect(formatUptime(100)).toBe('100%');
        expect(formatUptime(99.951)).toBe('99.95%');
        expect(formatUptime(66.667)).toBe('66.67%');
        expect(formatUptime(null)).toBe('—');
    });

    it('picks tones', () => {
        expect(uptimeTone(99.95)).toBe('success');
        expect(uptimeTone(99.5)).toBe('warning');
        expect(uptimeTone(90)).toBe('danger');
        expect(uptimeTone(null)).toBe('faint');
    });

    it('formats durations and intervals', () => {
        expect(formatDurationSeconds(42)).toBe('42 s');
        expect(formatDurationSeconds(180)).toBe('3 min');
        expect(formatDurationSeconds(3720)).toBe('1 h 2 min');
        expect(formatDurationSeconds(90000)).toBe('1 d 1 h');
        expect(intervalLabel(60)).toBe('every 1 min');
        expect(intervalLabel(30)).toBe('every 30 s');
    });

    it('validates expected statuses like the server', () => {
        expect(isValidExpectedStatus('')).toBe(true);
        expect(isValidExpectedStatus('2xx, 301,404')).toBe(true);
        expect(isValidExpectedStatus('2xx,9xx')).toBe(false);
        expect(isValidExpectedStatus('ok')).toBe(false);
    });

    it('labels monitor states', () => {
        expect(monitorStatus({ paused: true, status: 'down', source: 'user', armed: true }).label).toBe('Paused');
        expect(monitorStatus({ paused: false, status: 'down', source: 'user', armed: true }).status).toBe('failed');
        expect(monitorStatus({ paused: false, status: 'pending', source: 'auto', armed: false }).label).toBe('Waiting for first success');
    });
});
