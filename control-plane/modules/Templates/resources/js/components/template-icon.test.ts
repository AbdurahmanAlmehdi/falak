import { contrast, MIN_CONTRAST, SURFACES, themedBrandColor } from '@/lib/brand-color';
import { describe, expect, it } from 'vitest';
import { BRANDS } from './template-icon';

describe('template brand icons', () => {
    it.each(Object.entries(BRANDS))('%s stays visible on both themes', (_, brand) => {
        for (const theme of ['dark', 'light'] as const) {
            const color = themedBrandColor(brand.hex, theme);

            if (color !== 'currentColor') expect(contrast(color, SURFACES[theme])).toBeGreaterThanOrEqual(MIN_CONTRAST);
        }
    });
});
