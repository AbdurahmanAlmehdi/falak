// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TemplateIcon } from './template-icon';

vi.mock('@inertiajs/react', () => ({ usePage: () => ({ props: { icons_url: 'https://coolicon.falak.sh' } }) }));

afterEach(cleanup);

describe('TemplateIcon', () => {
    it('loads an icon key it does not bundle from Cool Icons', () => {
        const { container } = render(<TemplateIcon icon={{ type: 'brand', key: 'docker/svgl-dark' }} name="Docker" />);

        expect(container.querySelector('img')?.getAttribute('src')).toBe('https://coolicon.falak.sh/i/docker/svgl-dark.svg');
    });

    it('keeps bundled brand logos local', () => {
        const { container } = render(<TemplateIcon icon={{ type: 'brand', key: 'n8n' }} name="n8n" />);

        expect(container.querySelector('img')).toBeNull();
        expect(container.querySelector('svg path')).not.toBeNull();
    });
});
