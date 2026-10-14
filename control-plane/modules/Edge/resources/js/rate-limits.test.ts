import { describe, expect, it } from 'vitest';
import { describeLimit } from './rate-limits';

describe('edge rate limits', () => {
    it('describes a limit', () => {
        expect(describeLimit(10, 60)).toBe('10 requests / minute per IP');
        expect(describeLimit(1, 3600)).toBe('1 request / hour per IP');
        expect(describeLimit(5, 45)).toBe('5 requests / 45 seconds per IP');
    });
});
