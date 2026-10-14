// @vitest-environment jsdom
import { ipActionsFor } from '@/lib/registry';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { BlockIpAction } from './components/block-ip-action';
import { formatCount, scopesFor } from './protection';
import './register';

function reply(status: number, body: unknown) {
    return Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
}

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

describe('protection helpers', () => {
    it('offers the scopes the row knows about', () => {
        expect(scopesFor({}).map((s) => s.value)).toEqual(['organization']);
        expect(scopesFor({ serverId: 's', siteId: 'x' }).map((s) => s.value)).toEqual(['server', 'site', 'organization']);
    });

    it('formats counters', () => {
        expect(formatCount(999)).toBe('999');
        expect(formatCount(1234)).toBe('1.2k');
        expect(formatCount(5_300_000, 'B')).toBe('5.3 MB');
        expect(formatCount(42, 'B')).toBe('42 B');
    });
});

describe('network.ip-actions: Block IP', () => {
    it('is registered in the slot for users who may protect only', () => {
        expect(ipActionsFor({ can: (p) => p === 'network.protect' }).map((a) => a.id)).toContain('network.block');
        expect(ipActionsFor({ can: () => false }).map((a) => a.id)).not.toContain('network.block');
    });

    it('blocks on the row server and asks to confirm a private range', async () => {
        const fetch = vi
            .fn()
            .mockReturnValueOnce(
                reply(422, { message: 'x', errors: { confirm: ['10.1.2.3 is in the private range 10.0.0.0/8. Confirm to block it anyway.'] } }),
            )
            .mockReturnValueOnce(reply(201, { blocks: [{ id: 'b1', cidr: '10.1.2.3', server_id: 's1' }] }));
        vi.stubGlobal('fetch', fetch);

        render(<BlockIpAction ip="10.1.2.3" serverId="s1" siteId="site1" source="http.clients" />);
        fireEvent.click(screen.getByRole('button', { name: 'Block 10.1.2.3' }));
        fireEvent.change(await screen.findByPlaceholderText('e.g. credential stuffing'), { target: { value: 'stuffing' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Block' }).at(-1)!);

        const checkbox = await screen.findByRole('checkbox');
        expect(screen.getByText(/private range 10\.0\.0\.0\/8\. Block it anyway\./)).toBeTruthy();
        const submit = screen.getAllByRole('button', { name: 'Block' }).at(-1)! as HTMLButtonElement;
        expect(submit.disabled).toBe(true);

        fireEvent.click(checkbox);
        fireEvent.click(submit);
        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));

        const [url, init] = fetch.mock.calls[0];
        expect(url).toBe('/network/blocks');
        expect(JSON.parse(init.body)).toEqual({
            address: '10.1.2.3',
            scope: 'server',
            server_id: 's1',
            duration: '24h',
            reason: 'stuffing',
            confirm: false,
        });
        expect(JSON.parse(fetch.mock.calls[1][1].body)).toMatchObject({ confirm: true, scope: 'server' });
    });

    it('shows a refusal from the guards', async () => {
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockReturnValue(
                    reply(422, { errors: { address: ['203.0.113.7 contains your own address (203.0.113.7): you would lock yourself out.'] } }),
                ),
        );

        render(<BlockIpAction ip="203.0.113.7" source="http.threats" />);
        fireEvent.click(screen.getByRole('button', { name: 'Block 203.0.113.7' }));
        fireEvent.click((await screen.findAllByRole('button', { name: 'Block' })).at(-1)!);

        expect((await screen.findByRole('alert')).textContent).toContain('lock yourself out');
    });
});
