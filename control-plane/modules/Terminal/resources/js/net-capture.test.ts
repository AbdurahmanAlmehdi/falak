import { describe, expect, it } from 'vitest';
import { EMPTY_CAPTURE, captureBody, describeFilter, describeProgress, elapsedFraction, isActive } from './net-capture';

describe('captureBody', () => {
    it('sends the interface for host captures, defaults and the acknowledgement', () => {
        expect(captureBody(EMPTY_CAPTURE, false)).toEqual({ interface: 'default', duration_s: 30, max_mb: 20, acknowledged: true });
    });

    it('drops the interface in containers and keeps only the filter fields set', () => {
        expect(
            captureBody(
                { ...EMPTY_CAPTURE, interface: 'docker', host: ' 10.0.0.0/24 ', port: '443', protocol: 'tcp', direction: 'in', full_packets: true },
                true,
            ),
        ).toEqual({
            duration_s: 30,
            max_mb: 20,
            full_packets: true,
            filter: { host: '10.0.0.0/24', port: 443, protocol: 'tcp', direction: 'in' },
            acknowledged: true,
        });
    });

    it('passes a non-numeric duration through for the server to reject', () => {
        expect(captureBody({ ...EMPTY_CAPTURE, duration_s: 'x' }, false).duration_s).toBe('x');
    });
});

describe('formatting', () => {
    it('describes progress and filters', () => {
        expect(describeProgress({ packets_captured: 142, bytes_captured: 31744, max_bytes: 20 * 1024 * 1024 })).toBe(
            '142 packets · 31.0 KiB of 20.0 MiB',
        );
        expect(describeFilter(null)).toBe('everything');
        expect(describeFilter({ protocol: 'tcp', port: 443, host: '10.0.0.5', direction: 'out' })).toBe('tcp port 443 host 10.0.0.5, out');
        expect(isActive({ status: 'uploading' })).toBe(true);
        expect(isActive({ status: 'ready' })).toBe(false);
    });

    it('measures the elapsed part of the duration', () => {
        const started = '2026-10-15T10:00:00Z';
        expect(elapsedFraction({ started_at: null, duration_s: 30 }, Date.parse(started))).toBe(0);
        expect(elapsedFraction({ started_at: started, duration_s: 30 }, Date.parse(started) + 15000)).toBe(0.5);
        expect(elapsedFraction({ started_at: started, duration_s: 30 }, Date.parse(started) + 60000)).toBe(1);
    });
});
