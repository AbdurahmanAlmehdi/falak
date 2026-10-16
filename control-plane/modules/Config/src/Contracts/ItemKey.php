<?php

namespace Falak\Config\Contracts;

use Illuminate\Support\Str;

/**
 * The falak.yaml key of a named item made in the dashboard (a schedule, a daemon, a function's schedule), whose name
 * may hold characters a key can't (`^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$`): "Nightly report" reads as `Nightly-report`.
 * Reconcilers key today's items with {@see self::map()}, so an export writes keys that validate and applying it maps
 * each key back to the same row.
 */
final class ItemKey
{
    public static function of(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/', $name) === 1) {
            return $name;
        }

        $key = ltrim((string) preg_replace('/[^A-Za-z0-9_.-]+/', '-', Str::ascii($name)), '-_.');

        return rtrim(substr($key, 0, 64), '-') ?: 'item';
    }

    /**
     * Rows by key. A row the file manages keeps the key it is recorded under ($owned), so a row added later can't take
     * it; then a row whose name is already a key keeps it; another takes {@see self::of()} of its name, with `-2`,
     * `-3`… when that key is taken (in the rows' order: order them by name and id).
     *
     * @template T
     *
     * @param  iterable<T>  $rows
     * @param  callable(T): string  $name
     * @param  ?callable(T): ?string  $owned
     * @return array<string, T>
     */
    public static function map(iterable $rows, callable $name, ?callable $owned = null): array
    {
        $out = [];
        $rest = [];
        $later = [];

        foreach ($rows as $row) {
            $key = $owned !== null ? $owned($row) : null;

            if ($key !== null && ! isset($out[$key])) {
                $out[$key] = $row;
            } else {
                $rest[] = $row;
            }
        }

        foreach ($rest as $row) {
            $n = $name($row);

            if (self::of($n) === $n && ! isset($out[$n])) {
                $out[$n] = $row;
            } else {
                $later[] = [self::of($n), $row];
            }
        }

        foreach ($later as [$base, $row]) {
            $key = $base;

            for ($i = 2; isset($out[$key]); $i++) {
                $key = substr($base, 0, 60).'-'.$i;
            }

            $out[$key] = $row;
        }

        return $out;
    }
}
