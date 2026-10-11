<?php

namespace Falak\Config\Contracts;

/**
 * Helpers reconcilers share to turn section documents into changes.
 */
final class Diff
{
    /**
     * One change per field the file sets: update when it differs from today's value, noop when equal. Fields the
     * file doesn't set are not managed and produce nothing.
     *
     * @param  array<string, mixed>  $desired  the file's fields (in the file's shape), keyed by pointer relative to $base
     * @param  array<string, mixed>  $current  today's fields, same keys
     * @return list<Change>
     */
    public static function fields(string $section, string $unit, string $base, array $desired, array $current, bool $creating = false): array
    {
        $out = [];

        foreach ($desired as $key => $value) {
            $path = $base.$key;
            $before = $current[$key] ?? null;

            if ($creating) {
                $out[] = new Change(ChangeOp::Create, $path, null, $value, $section, $unit);
            } elseif (self::same($value, $before)) {
                $out[] = new Change(ChangeOp::Noop, $path, $before, $value, $section, $unit);
            } else {
                $out[] = new Change($before === null ? ChangeOp::Create : ChangeOp::Update, $path, $before, $value, $section, $unit);
            }
        }

        return $out;
    }

    /**
     * Flatten a nested map into pointer-keyed leaves (lists and scalars are leaves): ['health' => ['path' => '/up']]
     * → ['/health/path' => '/up'].
     *
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    public static function leaves(array $value, string $prefix = ''): array
    {
        $out = [];

        foreach ($value as $key => $child) {
            $path = $prefix.'/'.Pointer::escape((string) $key);

            if (is_array($child) && $child !== [] && ! array_is_list($child)) {
                $out = [...$out, ...self::leaves($child, $path)];
            } else {
                $out[$path] = $child;
            }
        }

        return $out;
    }

    /** Equal in the file's data model (maps compare by key, lists by order, 1 == 1.0). */
    public static function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) && is_array($b)) {
            if (array_is_list($a) !== array_is_list($b) && $a !== [] && $b !== []) {
                return false;
            }

            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $k => $v) {
                if (! array_key_exists($k, $b) || ! self::same($v, $b[$k])) {
                    return false;
                }
            }

            return true;
        }

        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }

    /**
     * The value at a pointer of a section document (null when absent).
     *
     * @param  array<string, mixed>  $document
     */
    public static function at(array $document, string $pointer): mixed
    {
        $cur = $document;

        foreach (Pointer::tokens($pointer) as $token) {
            if (! is_array($cur) || ! array_key_exists($token, $cur)) {
                return null;
            }

            $cur = $cur[$token];
        }

        return $cur;
    }

    /**
     * The changes of a ChangeSet under a pointer prefix.
     *
     * @return list<Change>
     */
    public static function under(ChangeSet $changes, string $prefix): array
    {
        return array_values(array_filter($changes->changes, fn (Change $c) => $c->path === $prefix || str_starts_with($c->path, $prefix.'/')));
    }
}
