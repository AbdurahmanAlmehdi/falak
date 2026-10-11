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

                $value = self::pick($body, $pattern->path, $found);

                if (! $found) {
                    continue;
                }

                // Longer sections owned by others are not this reconciler's.
                foreach ($this->all as $other) {
                    if ($pattern->contains($other) && $other->selects($body['type'] ?? null) && ! in_array($other, $this->of($reconciler), true)) {
                        self::remove($value, $other->path);
                    }
                }

                if ($value !== []) {
                    $out['units'][(string) $unit] = self::merge($out['units'][(string) $unit] ?? [], $value);
                }
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

                if ($n <= count($childPath) && SectionPattern::matches($pattern->path, array_slice($childPath, 0, $n))) {
                    $covered = true;
                    break;
                }

                if ($n > count($childPath) && SectionPattern::matches(array_slice($pattern->path, 0, count($childPath)), $childPath)) {
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
     * The parts of $value at $path (`*` tokens match every key), from the root of $value; $found says whether any was.
     *
     * @param  array<string, mixed>  $value
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private static function pick(array $value, array $path, ?bool &$found = null): array
    {
        $found = false;
        $token = array_shift($path);
        $out = [];
        $keys = $token === '*' ? (array_is_list($value) ? [] : array_keys($value)) : (array_key_exists((string) $token, $value) ? [(string) $token] : []);

        foreach ($keys as $key) {
            if ($path === []) {
                $out[(string) $key] = $value[$key];
                $found = true;

                continue;
            }

            if (! is_array($value[$key])) {
                continue;
            }

            $child = self::pick($value[$key], $path, $childFound);

            if ($childFound) {
                $out[(string) $key] = $child;
                $found = true;
            }
        }

        return $out;
    }

    /**
     * Two picked documents of one unit merged (maps merge key by key, anything else is replaced).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $a[$key] = is_array($value) && ! array_is_list($value) && is_array($a[$key] ?? null) && ! array_is_list($a[$key])
                ? self::merge($a[$key], $value)
                : $value;
        }

        return $a;
    }

    /**
     * Remove $path (`*` tokens match every key) from $value, dropping maps it leaves empty.
     *
     * @param  list<string>  $path
     */
    private static function remove(mixed &$value, array $path): void
    {
        if ($path === [] || ! is_array($value)) {
            return;
        }

        $key = array_shift($path);
        $keys = $key === '*' ? array_keys($value) : (array_key_exists($key, $value) ? [$key] : []);

        foreach ($keys as $k) {
            if ($path === []) {
                unset($value[$k]);

                continue;
            }

            self::remove($value[$k], $path);

            if ($value[$k] === []) {
                unset($value[$k]);
            }
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
