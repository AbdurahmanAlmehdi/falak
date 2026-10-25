// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { VariablesList } from './project-parts';

afterEach(cleanup);

describe('VariablesList', () => {
    it('shows the note when the stack reads no variables', () => {
        // Extracting the only service that reads a variable empties the list; the create request can still fail on it.
        render(<VariablesList variables={[]} note={<p>The compose project needs POSTGRES_PASSWORD.</p>} />);

        expect(screen.getByText('The compose project needs POSTGRES_PASSWORD.')).toBeTruthy();
        expect(screen.getByText('The stack reads no variables.')).toBeTruthy();
    });

    it('shows the note above the variables', () => {
        render(
            <VariablesList
                variables={[{ name: 'APP_KEY', default: null, required: true, services: ['app'], source: 'compose' }]}
                values={{}}
                onChange={() => {}}
                note={<p>The compose project needs APP_KEY.</p>}
            />,
        );

        expect(screen.getByText('The compose project needs APP_KEY.')).toBeTruthy();
        expect(screen.getByLabelText('APP_KEY')).toBeTruthy();
    });
});
