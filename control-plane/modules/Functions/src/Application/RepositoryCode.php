<?php

namespace Falak\Functions\Application;

use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Validation\ValidationException;

/**
 * The code of a falak.yaml function unit: the repository's files under the unit's `root` at a commit, read through
 * the provider's API (**[decision]** the control plane reads them, like falak.yaml itself; the builder is not involved,
 * functions have no build step on the control plane). Skipped, like `falak fn deploy` skips them: dot-files and
 * dot-folders, folders the installers create (`node_modules`, `__pycache__`, `.venv`, `venv`), names a version can't
 * hold, falak.yaml itself, and files that aren't UTF-8 text. What remains is validated as any version
 * ({@see Code::files()}: at most 50 files and 1 MB, the entrypoint present).
 */
final class RepositoryCode
{
    /** Folders never part of a function's code. */
    private const IGNORED = ['node_modules', '__pycache__', '.venv', 'venv'];

    private const CONFIG_FILES = ['falak.yaml', 'falak.yml', 'falak.json'];

    /** Commits kept in memory (a plan, then its apply, read the same commit). */
    private const CACHED = 20;

    /** @var array<string, array{files: array<string, string>, hash: string}|ValidationException> */
    private array $cache = [];

    public function __construct(private readonly SourceControlGateway $git) {}

    /**
     * The function's files at $ref (validated, sorted) and their hash.
     *
     * @return array{files: array<string, string>, hash: string}
     *
     * @throws ValidationException the files can't be a version (too many, too large, no entrypoint, unreadable)
     */
    public function read(string $connectionId, string $repository, string $ref, string $root, string $entrypoint): array
    {
        // A commit's files never change (a branch's do: never cached).
        $key = preg_match('/^[0-9a-f]{40}([0-9a-f]{24})?$/i', $ref) === 1 ? implode('|', [$connectionId, strtolower($repository), strtolower($ref), $root, $entrypoint]) : null;

        if ($key === null) {
            return $this->load($connectionId, $repository, $ref, $root, $entrypoint);
        }

        $cached = $this->cache[$key] ?? null;

        if ($cached instanceof ValidationException) {
            throw $cached;
        }

        if ($cached !== null) {
            return $cached;
        }

        if (count($this->cache) >= self::CACHED) {
            array_shift($this->cache);
        }

        try {
            return $this->cache[$key] = $this->load($connectionId, $repository, $ref, $root, $entrypoint);
        } catch (ValidationException $e) {
            throw $this->cache[$key] = $e;
        }
    }

    /**
     * @return array{files: array<string, string>, hash: string}
     *
     * @throws ValidationException
     */
    private function load(string $connectionId, string $repository, string $ref, string $root, string $entrypoint): array
    {
        $root = trim($root, '/');
        $prefix = $root === '' || $root === '.' ? '' : $root.'/';
        $max = (int) config('functions.max_files', 50);

        try {
            $paths = $this->git->tree($connectionId, $repository, $ref, $prefix === '' ? '**' : "{$prefix}**", complete: true);
        } catch (SourceControlException $e) {
            throw ValidationException::withMessages(['code' => "The code under {$root} can't be read from {$repository}: {$e->getMessage()}"]);
        }

        $files = [];

        foreach ($paths as $path) {
            if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
                continue;
            }

            $relative = substr($path, strlen($prefix));

            if (! self::included($relative)) {
                continue;
            }

            if (count($files) >= $max) {
                throw ValidationException::withMessages(['code' => "{$root} holds more than {$max} files: a function can have at most {$max} (set `root` to the function's own folder)."]);
            }

            try {
                $content = $this->git->file($connectionId, $repository, $ref, $path);
            } catch (SourceControlException $e) {
                throw ValidationException::withMessages(['code' => "{$path} can't be read from {$repository}: {$e->getMessage()}"]);
            }

            if ($content !== null && mb_check_encoding($content, 'UTF-8')) {
                $files[$relative] = $content;
            }
        }

        $files = Code::files($files, $entrypoint, 'code');

        return ['files' => $files, 'hash' => Code::hash($files, $entrypoint)];
    }

    private static function included(string $path): bool
    {
        if ($path === '' || in_array($path, self::CONFIG_FILES, true) || preg_match(Code::PATH_PATTERN, $path) !== 1 || strlen($path) > 200) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (str_starts_with($segment, '.') || in_array($segment, self::IGNORED, true)) {
                return false;
            }
        }

        return true;
    }
}
