<?php

namespace Falak\Config\Application\Planning;

use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Reconcilable;

/**
 * Who owns which part of the resolved file: prunes the file to a reconciler's sections (minus the longer sections
 * other reconcilers own inside them) and lists what no registered reconciler owns yet ("not supported in this
 * version").
 */
final class Sections
{
    /** @var array<int, list<SectionPattern>> by spl_object_id of the reconciler */
    private array $patterns = [];

    /** @var list<SectionPattern> */
    private array $all = [];

    /**
     * @param  list<Reconcilable>  $reconcilers
     */
    public function __construct(private readonly array $reconcilers)
    {
        foreach ($reconcilers as $reconciler) {
            $list = array_map(SectionPattern::parse(...), $reconciler->sections());
            $this->patterns[spl_object_id($reconciler)] = $list;
            $this->all = [...$this->all, ...$list];
        }
    }

    /**
     * @return list<SectionPattern>
     */
    public function of(Reconcilable $reconciler): array
    {
        return $this->patterns[spl_object_id($reconciler)] ?? [];
    }

    /**
     * The resolved file pruned to a reconciler's sections, from the root.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function prune(Reconcilable $reconciler, array $document): array
    {
        $out = [];

        foreach ($this->of($reconciler) as $pattern) {
            if (! $pattern->inUnit) {
                if (! array_key_exists($pattern->root, $document)) {
                    continue;
                }

                $value = self::get($document[$pattern->root], $pattern->path, $found);

                if ($found) {
                    self::set($out, [$pattern->root, ...$pattern->path], $value);
                }

                continue;
            }

            foreach ((array) ($document['units'] ?? []) as $unit => $body) {
                if (! is_array($body) || ! $pattern->selects($body['type'] ?? null)) {
                    continue;
                }

                $value = self::get($body, $pattern->path, $found);

                if (! $found) {
                    continue;
                }

                // Longer sections owned by others are not this reconciler's.
                foreach ($this->all as $other) {
                    if ($pattern->contains($other) && $other->selects($body['type'] ?? null) && ! in_array($other, $this->of($reconciler), true)) {
                        self::remove($value, array_slice($other->path, count($pattern->path)));
                    }
                }

                self::set($out, ['units', (string) $unit, ...$pattern->path], $value);
            }
        }

        return $out;
    }

    /**
     * Fields of the resolved file no registered reconciler owns: warnings, the field is ignored.
     *
     * @param  array<string, mixed>  $document
     * @return list<ConfigIssue>
     */
    public function unsupported(array $document): array
    {
        $issues = [];

        foreach ((array) ($document['units'] ?? []) as $unit => $body) {
            if (! is_array($body)) {
                continue;
            }

            $type = $body['type'] ?? null;
            $patterns = array_values(array_filter($this->all, fn (SectionPattern $p) => $p->inUnit && $p->selects($type)));

            if ($patterns === []) {
                $issues[] = ConfigIssue::warning('value.unsupported', '/units/'.Pointer::escape((string) $unit),
                    "Units of type \"{$type}\" are not supported in this version yet; this unit is ignored.");

                continue;
            }

            $this->uncovered($body, [], $patterns, '/units/'.Pointer::escape((string) $unit), $issues);
        }

        foreach (['previews'] as $root) {
            if (array_key_exists($root, $document) && ! array_filter($this->all, fn (SectionPattern $p) => ! $p->inUnit && $p->root === $root)) {
                $issues[] = ConfigIssue::warning('value.unsupported', '/'.$root, "\"{$root}\" is not supported in this version yet; it is ignored.");
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $path
     * @param  list<SectionPattern>  $patterns
     * @param  list<ConfigIssue>  $issues
     */
    private function uncovered(array $value, array $path, array $patterns, string $base, array &$issues): void
    {
        foreach ($value as $key => $child) {
            $childPath = [...$path, (string) $key];
            $covered = false;
            $deeper = false;

            foreach ($patterns as $pattern) {
                $n = count($pattern->path);

                if ($n <= count($childPath) && array_slice($childPath, 0, $n) === $pattern->path) {
                    $covered = true;
                    break;
                }

                if ($n > count($childPath) && array_slice($pattern->path, 0, count($childPath)) === $childPath) {
                    $deeper = true;
                }
            }

            if ($covered) {
                continue;
            }

            if ($deeper && is_array($child) && ! array_is_list($child)) {
                $this->uncovered($child, $childPath, $patterns, $base, $issues);

                continue;
            }

            $pointer = $base.Pointer::of($childPath);
            $issues[] = ConfigIssue::warning('value.unsupported', $pointer, '"'.implode('.', $childPath).'" is not supported in this version yet; it is ignored.');
        }
    }

    /**
     * @param  list<string>  $path
     */
    private static function get(mixed $value, array $path, ?bool &$found = null): mixed
    {
        foreach ($path as $token) {
            if (! is_array($value) || ! array_key_exists($token, $value)) {
                $found = false;

                return null;
            }

            $value = $value[$token];
        }

        $found = true;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  list<string>  $path
     */
    private static function set(array &$target, array $path, mixed $value): void
    {
        $cur = &$target;

        foreach ($path as $token) {
            if (! isset($cur[$token]) || ! is_array($cur[$token])) {
                $cur[$token] = [];
            }

            $cur = &$cur[$token];
        }

        $cur = $value;
    }

    /**
     * @param  list<string>  $path
     */
    private static function remove(mixed &$value, array $path): void
    {
        if ($path === [] || ! is_array($value)) {
            return;
        }

        $key = array_shift($path);

        if (! array_key_exists($key, $value)) {
            return;
        }

        if ($path === []) {
            unset($value[$key]);

            return;
        }

        self::remove($value[$key], $path);

        if ($value[$key] === []) {
            unset($value[$key]);
        }
    }

    /**
     * @return list<Reconcilable>
     */
    public function reconcilers(): array
    {
        return $this->reconcilers;
    }
}
