// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Cloudflare from './pages/Cloudflare';

const page = vi.hoisted(() => ({ errors: {} as Record<string, string> }));

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    useForm: (data: Record<string, string>) => ({ data, errors: {}, processing: false, setData: vi.fn(), post: vi.fn(), reset: vi.fn() }),
    usePage: () => ({ props: { errors: page.errors } }),
}));
vi.mock('@/layouts/settings/layout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));

afterEach(() => {
    cleanup();
    page.errors = {};
});

const props = {
    connections: [{ id: 'c'.repeat(26), name: 'Cloudflare', verified_at: null, error: null, zones: [] }],
    zones: [],
    servers: [{ id: 's'.repeat(26), name: 'web-1', ipv4: '203.0.113.10', lock: null, tunnel: null }],
    can: { manage: true },
};

describe('Cloudflare settings', () => {
    it('shows why routing a server through a tunnel failed', () => {
        page.errors = { server: 'Cloudflare: Authentication error (the token needs Account → Cloudflare Tunnel → Edit)' };
        render(<Cloudflare {...props} />);

        expect(screen.getByRole('alert').textContent).toContain('the token needs Account → Cloudflare Tunnel → Edit');
    });

    it('shows no error when tunnel actions succeed', () => {
        render(<Cloudflare {...props} />);

        expect(screen.queryByRole('alert')).toBeNull();
    });
});
