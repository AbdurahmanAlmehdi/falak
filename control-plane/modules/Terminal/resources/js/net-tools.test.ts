import { describe, expect, it } from 'vitest';
import { EMPTY_FORM, describeRun, netToolBody } from './net-tools';

describe('netToolBody', () => {
    it('sends only the tool’s own non-empty fields, numbers as numbers', () => {
        expect(
            netToolBody({ ...EMPTY_FORM, tool: 'tcp', target: ' db.internal ', port: '5432', count: '', url: 'https://x', insecure: true }),
        ).toEqual({
            tool: 'tcp',
            target: 'db.internal',
            port: 5432,
        });
    });

    it('keeps http fields and the timeout, insecure only when on', () => {
        expect(netToolBody({ ...EMPTY_FORM, tool: 'http', url: 'https://example.com/health', target: 'ignored', timeout_s: '10' })).toEqual({
            tool: 'http',
            url: 'https://example.com/health',
            method: 'GET',
            timeout_s: 10,
        });
        expect(netToolBody({ ...EMPTY_FORM, tool: 'http', url: 'https://a/', insecure: true, connect_to: '203.0.113.10' })).toMatchObject({
            insecure: true,
            connect_to: '203.0.113.10',
        });
    });

    it('passes a non-numeric number through for the server to reject', () => {
        expect(netToolBody({ ...EMPTY_FORM, tool: 'ping', target: 'a', count: 'x' })).toEqual({ tool: 'ping', target: 'a', count: 'x' });
    });
});

describe('describeRun', () => {
    it('summarizes runs', () => {
        expect(describeRun({ tool: 'tcp', input: { target: 'db', port: 5432 } })).toBe('tcp db:5432');
        expect(describeRun({ tool: 'http', input: { url: 'https://a/', method: 'HEAD' } })).toBe('http HEAD https://a/');
        expect(describeRun({ tool: 'dns', input: { target: 'a.com', record_type: 'MX' } })).toBe('dns MX a.com');
        expect(describeRun({ tool: 'ping', input: { target: '1.1.1.1' } })).toBe('ping 1.1.1.1');
    });
});
