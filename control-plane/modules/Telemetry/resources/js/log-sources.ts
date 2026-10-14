/**
 * The site Logs tab's source filter and the Sources editor (docs/plans/OBSERVABILITY.md phase 2). Pure helpers, so
 * the options per runtime and the saved shape are tested without rendering.
 */

/** Where a site's records come from: Falak\Telemetry\Application\Queries\LogQueryBuilder::ORIGINS, plus the edge's requests. */
export type LogOrigin = 'output' | 'file' | 'container_file' | 'php_fpm' | 'php_fpm_slow';

export type SourceChoice = 'all' | LogOrigin | 'access';

export interface SourceOption {
    value: SourceChoice;
    label: string;
}

const CONTAINER_RUNTIMES = ['docker', 'compose'];
const PHP_RUNTIMES = ['php-fpm', 'frankenphp'];

/** The source filter's options that apply to a site of this runtime (unknown runtime: every option). */
export function sourceOptions(runtime: string | undefined): SourceOption[] {
    const any = runtime === undefined;
    const options: SourceOption[] = [
        { value: 'all', label: 'All sources' },
        { value: 'output', label: 'Output (stdout / stderr)' },
    ];
    if (any || PHP_RUNTIMES.includes(runtime)) options.push({ value: 'file', label: 'App log files' });
    if (any || CONTAINER_RUNTIMES.includes(runtime)) options.push({ value: 'container_file', label: 'Container files' });
    if (any || runtime === 'php-fpm') {
        options.push({ value: 'php_fpm', label: 'PHP-FPM' }, { value: 'php_fpm_slow', label: 'Slow requests' });
    }
    if (runtime !== 'function') options.push({ value: 'access', label: 'Access (edge)' });

    return options;
}

/** The log query filters of a choice: the edge's requests are their own kind, the rest narrow the app records. */
export function sourceFilters(choice: SourceChoice): { kind: 'app' | 'access'; origin?: LogOrigin } {
    if (choice === 'access') return { kind: 'access' };
    if (choice === 'all') return { kind: 'app' };

    return { kind: 'app', origin: choice };
}

/** One source as GET/PUT /sites/{site}/log-sources has it. */
export interface LogSource {
    path: string;
    format: 'plain' | 'json';
    multiline?: 'laravel' | 'pattern';
    multiline_pattern?: string;
    compose_service?: string;
}

export function emptySource(): LogSource {
    return { path: '', format: 'plain' };
}

/**
 * The list to save: fields that do not apply dropped (a pattern without pattern mode). Rows keep their positions, so
 * the server's `sources.<i>.*` errors land on the row they are about (a blank path is one).
 */
export function cleanSources(sources: LogSource[]): LogSource[] {
    return sources.map((source) => {
        const out: LogSource = { path: source.path.trim(), format: source.format };
        if (source.multiline === 'laravel') out.multiline = 'laravel';
        if (source.multiline === 'pattern') {
            out.multiline = 'pattern';
            out.multiline_pattern = source.multiline_pattern ?? '';
        }
        if (source.compose_service) out.compose_service = source.compose_service;

        return out;
    });
}

/** Validation errors of PUT (`sources.<i>.<field>`) for row i. */
export function rowErrors(errors: Record<string, string>, index: number): Record<string, string> {
    const prefix = `sources.${index}.`;

    return Object.fromEntries(
        Object.entries(errors)
            .filter(([key]) => key.startsWith(prefix))
            .map(([key, message]) => [key.slice(prefix.length), message]),
    );
}
