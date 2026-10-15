import { describe, expect, it } from 'vitest';
import { brandFillProps, contrast, MIN_CONTRAST, SURFACES, themedBrandColor } from './brand-color';

describe('themedBrandColor', () => {
    it('keeps a brand color that stands out from the surface', () => {
        expect(themedBrandColor('336791', 'light')).toBe('#336791');
        expect(themedBrandColor('#F46800', 'dark')).toBe('#f46800');
    });

    it("lightens Odoo's dark purple on the dark theme just enough, keeping it on the light one", () => {
        const dark = themedBrandColor('714B67', 'dark');

        expect(dark).not.toBe('#714b67');
        expect(dark).not.toBe('currentColor');
        expect(contrast(dark, SURFACES.dark)).toBeGreaterThanOrEqual(MIN_CONTRAST);
        expect(contrast(dark, SURFACES.dark)).toBeLessThan(MIN_CONTRAST + 1);
        expect(themedBrandColor('714B67', 'light')).toBe('#714b67');
    });

    it('darkens a pale color on the light theme', () => {
        const light = themedBrandColor('5CDD8B', 'light');

        expect(light).not.toBe('currentColor');
        expect(contrast(light, SURFACES.light)).toBeGreaterThanOrEqual(MIN_CONTRAST);
        expect(themedBrandColor('5CDD8B', 'dark')).toBe('#5cdd8b');
    });

    it('draws grey, black and white marks (GitHub, Ollama) in the text color where they would vanish', () => {
        expect(themedBrandColor('181717', 'dark')).toBe('currentColor');
        expect(themedBrandColor('181717', 'light')).toBe('#181717');
        expect(themedBrandColor('FFFFFF', 'light')).toBe('currentColor');
        expect(themedBrandColor('FFFFFF', 'dark')).toBe('#ffffff');
    });
});

describe('brandFillProps', () => {
    it('switches the fill with the theme class', () => {
        const props = brandFillProps('714B67');

        expect(props.className).toContain('dark:fill-(--brand-dark)');
        expect(props.style).toMatchObject({ '--brand-light': '#714b67' });
    });
});
