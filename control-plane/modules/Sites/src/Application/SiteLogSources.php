<?php

namespace Falak\Sites\Application;

use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

/**
 * Site → Logs → Sources (docs/plans/OBSERVABILITY.md phase 2): the files inside a Docker or compose site's containers
 * the agent tails, and a PHP-FPM site's slow log threshold. Turned into telemetry.configure `log_sources` here: the
 * container sources (`container: true`, read through the container's /proc/<pid>/root, never on the host), and for
 * PHP-FPM sites their own master's error log and their pool's slow log.
 */
final class SiteLogSources
{
    public const MAX_SOURCES = 10;

    public const DEFAULT_SLOWLOG_TIMEOUT = 5;

    public const MAX_SLOWLOG_TIMEOUT = 600;

    public const MAX_PATTERN_BYTES = 256;

    /** Start of a PHP-FPM slow log entry: "[14-Oct-2026 10:11:12]  [pool falak-shop] pid 123" (RE2). */
    public const FPM_SLOWLOG_PATTERN = '^\[\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2}\]';

    /** The agent's paths (agent/internal/runtime/fpm.go). */
    public const FPM_ERROR_LOG = '/var/log/falak/fpm-%s.log';

    public const FPM_SLOW_LOG = '/var/log/falak/php-fpm-slow/%s.log';

    /** Docker and compose sites have containers to read files in (functions are Falak's own). */
    public static function supportsContainerSources(Site $site): bool
    {
        return $site->runtime === SiteRuntime::Docker || $site->runtime === SiteRuntime::Compose;
    }

    /**
     * The PHP-FPM slow log threshold in seconds the site's pool runs with (0 = off; not a PHP-FPM site: 0). Null is off:
     * sites that existed before the slow log keep their pool file as it was (the agent upgrade reloads no master); new
     * PHP-FPM sites start with DEFAULT_SLOWLOG_TIMEOUT (Site::booted).
     */
    public static function slowlogTimeout(Site $site): int
    {
        if ($site->runtime !== SiteRuntime::PhpFpm) {
            return 0;
        }

        return $site->fpm_slowlog_timeout ?? 0;
    }

    /**
     * Validates and normalizes the sources a user saves.
     *
     * @param  list<string>  $composeServices  the compose project's services (compose sites)
     * @return list<array{path: string, format: 'plain'|'json', multiline?: 'laravel'|'pattern', multiline_pattern?: string, compose_service?: string}>
     *
     * @throws ValidationException
     */
    public static function validate(Site $site, mixed $input, array $composeServices): array
    {
        if (! is_array($input) || ! array_is_list($input)) {
            throw ValidationException::withMessages(['sources' => 'The sources must be a list.']);
        }

        if ($input !== [] && ! self::supportsContainerSources($site)) {
            throw ValidationException::withMessages(['sources' => 'Only Docker and compose sites have containers to read log files in.']);
        }

        if (count($input) > self::MAX_SOURCES) {
            throw ValidationException::withMessages(['sources' => 'A site has at most '.self::MAX_SOURCES.' log sources.']);
        }

        $out = [];
        $seen = [];

        foreach ($input as $i => $source) {
            $error = fn (string $field, string $message) => ValidationException::withMessages(["sources.{$i}.{$field}" => $message]);

            if (! is_array($source)) {
                throw $error('path', 'Each source is an object.');
            }

            $path = $source['path'] ?? null;

            if (! is_string($path) || ($pathError = self::pathError($path)) !== null) {
                throw $error('path', $pathError ?? 'The path is required.');
            }

            $format = $source['format'] ?? 'plain';

            if (! in_array($format, ['plain', 'json'], true)) {
                throw $error('format', 'The format is plain or json.');
            }

            $entry = ['path' => $path, 'format' => $format];
            $multiline = $source['multiline'] ?? null;

            if ($multiline === 'laravel') {
                $entry['multiline'] = 'laravel';
            } elseif ($multiline === 'pattern') {
                $pattern = $source['multiline_pattern'] ?? null;

                if (! is_string($pattern) || $pattern === '' || ($patternError = self::patternError($pattern)) !== null) {
                    throw $error('multiline_pattern', $patternError ?? 'Enter the pattern a record\'s first line matches.');
                }

                $entry['multiline'] = 'pattern';
                $entry['multiline_pattern'] = $pattern;
            } elseif ($multiline !== null && $multiline !== '') {
                throw $error('multiline', 'Multi-line is laravel or pattern.');
            }

            $service = $source['compose_service'] ?? null;

            if ($service !== null && $service !== '') {
                if ($site->runtime !== SiteRuntime::Compose) {
                    throw $error('compose_service', 'Only compose sites have services.');
                }

                if (! is_string($service) || ! in_array($service, $composeServices, true)) {
                    throw $error('compose_service', 'The compose project has no such service.');
                }

                $entry['compose_service'] = $service;
            }

            $key = ($entry['compose_service'] ?? '')."\n".$path;

            if (isset($seen[$key])) {
                throw $error('path', 'This path is already a source'.(isset($entry['compose_service']) ? ' of this service.' : '.'));
            }

            $seen[$key] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * telemetry.configure `log_sources` entries of the site (without `site`, added by the payload builder).
     *
     * @return list<array<string, mixed>>
     */
    public static function telemetrySources(Site $site): array
    {
        if ($site->runtime === SiteRuntime::PhpFpm) {
            // The own master's error log exists while the site has limits (its own PHP-FPM master); the shared
            // master's log mixes every pool and is not the site's.
            $sources = [['path' => sprintf(self::FPM_ERROR_LOG, $site->slug), 'kind' => 'app', 'origin' => 'php_fpm']];

            if (self::slowlogTimeout($site) > 0) {
                $sources[] = ['path' => sprintf(self::FPM_SLOW_LOG, $site->slug), 'kind' => 'app', 'origin' => 'php_fpm_slow', 'multiline_pattern' => self::FPM_SLOWLOG_PATTERN];
            }

            return $sources;
        }

        if (! self::supportsContainerSources($site)) {
            return [];
        }

        return array_map(fn (array $source) => array_filter([
            'path' => $source['path'],
            'kind' => 'app',
            'container' => true,
            'compose_service' => $source['compose_service'] ?? null,
            'format' => $source['format'] ?? null,
            'multiline' => ($source['multiline'] ?? null) === 'laravel' ? 'laravel' : null,
            'multiline_pattern' => ($source['multiline'] ?? null) === 'pattern' ? ($source['multiline_pattern'] ?? null) : null,
        ], fn ($v) => $v !== null && $v !== ''), $site->log_sources ?? []);
    }

    /** Why a path inside a container is refused (null: fine). */
    public static function pathError(string $path): ?string
    {
        return match (true) {
            $path === '' => 'The path is required.',
            strlen($path) > 255 => 'The path is longer than 255 characters.',
            ! str_starts_with($path, '/') => 'The path must be absolute (start with /).',
            preg_match('/[\x00-\x1f\x7f\\\\]/', $path) === 1 => 'The path contains control characters or a backslash.',
            in_array('..', explode('/', $path), true) => 'The path cannot contain "..".',
            trim($path, '/') === '' => 'Name a file or a glob, not the root.',
            str_ends_with($path, '/') => 'Name a file or a glob, not a directory.',
            ! self::bracketsBalanced($path) => 'A [ in the path has no closing ].',
            self::pseudoPath($path) => '/proc, /sys and /dev hold kernel state, not logs.',
            default => null,
        };
    }

    /** Whether the path's first segment names (or its glob matches) /proc, /sys or /dev (the agent refuses them too). */
    private static function pseudoPath(string $path): bool
    {
        $first = explode('/', ltrim($path, '/'))[0];

        foreach (['proc', 'sys', 'dev'] as $pseudo) {
            if ($first === $pseudo || fnmatch($first, $pseudo)) {
                return true;
            }
        }

        return false;
    }

    private static function bracketsBalanced(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            $open = false;

            foreach (str_split($segment) as $char) {
                if ($char === '[') {
                    if ($open) {
                        return false;
                    }

                    $open = true;
                } elseif ($char === ']') {
                    $open = false;
                }
            }

            if ($open) {
                return false;
            }
        }

        return true;
    }

    /** RE2's largest repetition count ({n}, {n,m}). */
    private const MAX_REPEAT = 1000;

    /**
     * Why a start-of-record pattern is refused (null: fine). The agent compiles it with Go's RE2 (and skips a source
     * whose pattern does not compile), so only syntax PCRE and RE2 read the same way is accepted: no lookaround, named or
     * atomic group, back-reference, possessive or nested quantifier, {,m} or a count over 1000; escapes only those RE2
     * knows (\x with its hex digits, \p with its class). The pattern is read token by token (escapes, character
     * classes), so an escaped backslash or a quantifier character inside [...] is not taken for something else.
     * contracts/agent-protocol/testdata/multiline-patterns.json is the corpus both sides are tested against.
     */
    public static function patternError(string $pattern): ?string
    {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return 'The pattern is longer than '.self::MAX_PATTERN_BYTES.' characters.';
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $pattern) === 1) {
            return 'The pattern contains control characters.';
        }

        if (@preg_match('~'.str_replace('~', '\~', $pattern).'~u', '') === false) {
            return 'The pattern is not a valid regular expression.';
        }

        $n = strlen($pattern);
        $inClass = false;
        // 0: the last token is no quantifier; 1: a quantifier; 2: a lazy quantifier (nothing may follow it).
        $quantified = 0;

        for ($i = 0; $i < $n;) {
            $c = $pattern[$i];

            if ($c === '\\') {
                [$length, $error] = self::escape($pattern, $i, $inClass);

                if ($error !== null) {
                    return $error;
                }

                $i += $length;
                $quantified = 0;

                continue;
            }

            if ($inClass) {
                if ($c === '[' && ($pattern[$i + 1] ?? '') === ':' && ($end = strpos($pattern, ':]', $i + 2)) !== false) {
                    $i = $end + 2; // [:alpha:]

                    continue;
                }

                $inClass = $c !== ']';
                $i++;

                continue;
            }

            if ($c === '[') {
                $inClass = true;
                $i++;
                $i += ($pattern[$i] ?? '') === '^' ? 1 : 0;
                $i += ($pattern[$i] ?? '') === ']' ? 1 : 0; // a leading ] is a member

                continue;
            }

            if ($c === '(' && ($pattern[$i + 1] ?? '') === '?') {
                if (preg_match('/\G\(\?[imsU-]*[:)]/', $pattern, $m, 0, $i) !== 1) {
                    return 'Lookarounds, named and atomic groups are not supported: use (?: or inline flags (?i).';
                }

                $i += strlen($m[0]);
                $quantified = 0;

                continue;
            }

            $repeat = null;

            if ($c === '{') {
                if (preg_match('/\G\{,\d*\}/', $pattern, $m, 0, $i) === 1) {
                    return '{,n} is read differently by the agent: write {0,n}.';
                }

                if (preg_match('/\G\{(\d+)(?:,(\d*))?\}/', $pattern, $m, 0, $i) === 1) {
                    $min = (int) $m[1];
                    $max = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null;

                    if ($min > self::MAX_REPEAT || ($max !== null && ($max > self::MAX_REPEAT || $max < $min))) {
                        return 'Repetition counts go up to '.self::MAX_REPEAT.'.';
                    }

                    $repeat = strlen($m[0]);
                }
            } elseif ($c === '*' || $c === '+' || $c === '?') {
                $repeat = 1;
            }

            if ($repeat === null) {
                $quantified = 0;
                $i++;

                continue;
            }

            if ($quantified === 1 && $c === '?') {
                $quantified = 2; // lazy
            } elseif ($quantified === 1 && $c === '+') {
                return 'Possessive quantifiers are not supported.';
            } elseif ($quantified !== 0) {
                return 'A quantifier cannot follow another one.';
            } else {
                $quantified = 1;
            }

            $i += $repeat;
        }

        return null;
    }

    /**
     * An escape at $i: its length, or why it is refused. RE2 knows \d \D \s \S \w \W \b \B \A \z (not in a class),
     * \pX / \p{Name}, \x## / \x{hex}, \t \n \r \f \v and escaped punctuation.
     *
     * @return array{0: int, 1: ?string}
     */
    private static function escape(string $pattern, int $i, bool $inClass): array
    {
        $next = $pattern[$i + 1] ?? '';

        if ($next === '') {
            return [1, 'The pattern ends with a backslash.'];
        }

        if (! ctype_alnum($next)) {
            return [2, null];
        }

        if ($next === 'x') {
            if (preg_match('/\G\\\\x(?:[0-9A-Fa-f]{2}|\{[0-9A-Fa-f]{1,6}\})/', $pattern, $m, 0, $i) === 1) {
                return [strlen($m[0]), null];
            }

            return [2, '\x takes two hex digits or {hex}.'];
        }

        if ($next === 'p' || $next === 'P') {
            if (preg_match('/\G\\\\[pP](?:[A-Za-z]|\{\^?[A-Za-z_]+\})/', $pattern, $m, 0, $i) === 1) {
                return [strlen($m[0]), null];
            }

            return [2, "\\{$next} takes a class: \\pL or \\p{Greek}."];
        }

        $known = $inClass ? 'dDsSwWtnrfv' : 'dDsSwWbBAztnrfv';

        return [2, str_contains($known, $next) ? null : "\\{$next} is not supported in patterns".($inClass ? ' classes.' : '.')];
    }
}
