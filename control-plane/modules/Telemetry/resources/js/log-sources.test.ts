import { describe, expect, it } from 'vitest';
import { cleanSources, rowErrors, sourceFilters, sourceOptions } from './log-sources';

const values = (runtime: string | undefined) => sourceOptions(runtime).map((option) => option.value);

describe('sourceOptions', () => {
    it('offers what applies to the runtime', () => {
        expect(values('php-fpm')).toEqual(['all', 'output', 'file', 'php_fpm', 'php_fpm_slow', 'access']);
        expect(values('frankenphp')).toEqual(['all', 'output', 'file', 'access']);
        expect(values('compose')).toEqual(['all', 'output', 'container_file', 'access']);
        expect(values('docker')).toEqual(['all', 'output', 'container_file', 'access']);
        expect(values('node')).toEqual(['all', 'output', 'access']);
        expect(values('function')).toEqual(['all', 'output']);
    });

    it('offers everything while the runtime is unknown', () => {
        expect(values(undefined)).toEqual(['all', 'output', 'file', 'container_file', 'php_fpm', 'php_fpm_slow', 'access']);
    });
});

describe('sourceFilters', () => {
    it('maps a choice to the query filters', () => {
        expect(sourceFilters('all')).toEqual({ kind: 'app' });
        expect(sourceFilters('access')).toEqual({ kind: 'access' });
        expect(sourceFilters('php_fpm_slow')).toEqual({ kind: 'app', origin: 'php_fpm_slow' });
    });
});

describe('cleanSources', () => {
    it('keeps every row in place and drops fields that do not apply', () => {
        expect(
            cleanSources([
                { path: '  ', format: 'plain' },
                { path: ' /var/log/app.log ', format: 'json', multiline_pattern: 'stale', compose_service: '' },
                { path: '/a/*.log', format: 'plain', multiline: 'pattern', multiline_pattern: '^\\d{4} ', compose_service: 'app' },
                { path: '/b.log', format: 'plain', multiline: 'laravel', multiline_pattern: 'stale' },
            ]),
        ).toEqual([
            { path: '', format: 'plain' },
            { path: '/var/log/app.log', format: 'json' },
            { path: '/a/*.log', format: 'plain', multiline: 'pattern', multiline_pattern: '^\\d{4} ', compose_service: 'app' },
            { path: '/b.log', format: 'plain', multiline: 'laravel' },
        ]);
    });
});

describe('rowErrors', () => {
    it("picks one row's validation errors", () => {
        expect(rowErrors({ 'sources.1.path': 'bad', 'sources.10.path': 'other', sources: 'too many' }, 1)).toEqual({ path: 'bad' });
    });
});
