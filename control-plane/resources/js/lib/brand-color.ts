import { type CSSProperties } from 'react';

/** The surfaces a brand mark sits on (app.css): the lightest of the dark theme, the darkest of the light theme. */
export const SURFACES = { dark: '#1d2a31', light: '#e4eef0' } as const;

/** WCAG 2 non-text contrast: a mark below 3:1 against its background is hard to make out. */
export const MIN_CONTRAST = 3;

function rgb(hex: string): [number, number, number] {
    const h = hex.replace('#', '');

    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)) as [number, number, number];
}

function luminance([r, g, b]: [number, number, number]): number {
    const [lr, lg, lb] = [r, g, b].map((c) => (c / 255 <= 0.04045 ? c / 255 / 12.92 : ((c / 255 + 0.055) / 1.055) ** 2.4));

    return 0.2126 * lr + 0.7152 * lg + 0.0722 * lb;
}

/** WCAG contrast ratio of two #rrggbb colors (1 to 21). */
export function contrast(a: string, b: string): number {
    const [la, lb] = [luminance(rgb(a)), luminance(rgb(b))];

    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const toHex = (channels: number[]) => `#${channels.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`;

/**
 * A brand color as drawn on one theme: as is when it stands out from the surface (3:1); a grey or black/white mark
 * (GitHub, Ollama) in the text color; a colored one (Odoo's dark purple on the dark theme) lightened, or darkened on
 * the light theme, just enough to reach 3:1, so it keeps its hue.
 */
export function themedBrandColor(hex: string, theme: keyof typeof SURFACES): string {
    const color = `#${hex.replace('#', '').toLowerCase()}`;
    const surface = SURFACES[theme];

    if (contrast(color, surface) >= MIN_CONTRAST) return color;

    const channels = rgb(color);
    if (Math.max(...channels) - Math.min(...channels) < 0.1 * 255) return 'currentColor';

    const target = theme === 'dark' ? 255 : 0;
    for (let step = 1; step < 20; step++) {
        const mixed = toHex(channels.map((c) => c + (target - c) * (step / 20)));
        if (contrast(mixed, surface) >= MIN_CONTRAST) return mixed;
    }

    return 'currentColor';
}

/**
 * Props for a brand mark's <svg>: the fill per theme (CSS variables switched by the theme class), `text-fg` for the
 * marks drawn in the text color.
 */
export function brandFillProps(hex: string): { className: string; style: CSSProperties } {
    return {
        className: 'text-fg fill-(--brand-light) dark:fill-(--brand-dark)',
        style: { '--brand-light': themedBrandColor(hex, 'light'), '--brand-dark': themedBrandColor(hex, 'dark') } as CSSProperties,
    };
}
