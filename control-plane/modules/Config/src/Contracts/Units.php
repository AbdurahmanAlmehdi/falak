<?php

namespace Falak\Config\Contracts;

/**
 * The file's units of measure ("Units of measure" in the phase 0 contract): durations (`1h30m`) and sizes (`512m`,
 * `1.5g`, `512MiB`), to and from the stored numbers.
 */
final class Units
{
    public const DURATION = '/^([0-9]+d)?([0-9]+h)?([0-9]+m)?([0-9]+s)?$/';

    public const SIZE = '/^([0-9]+(?:\.[0-9]+)?)([kKmMgGtT])(?:[iI]?[bB])?$/';

    private const MULTIPLIERS = ['k' => 1024, 'm' => 1024 ** 2, 'g' => 1024 ** 3, 't' => 1024 ** 4];

    /** Seconds of a duration, null when it isn't one. */
    public static function seconds(mixed $duration): ?int
    {
        if (! is_string($duration) || strlen($duration) < 2 || ! preg_match(self::DURATION, $duration, $m)) {
            return null;
        }

        return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) ($m[4] ?? 0);
    }

    /** A number of seconds as the shortest duration (`90` → `1m30s`, `0` → `0s`). */
    public static function duration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '0s';
        }

        $out = '';

        foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($seconds >= $size) {
                $out .= intdiv($seconds, $size).$unit;
                $seconds %= $size;
            }
        }

        return $out;
    }

    /** Bytes of a size (float when not whole), null when it isn't one. */
    public static function bytes(mixed $size): int|float|null
    {
        if (! is_string($size) || ! preg_match(self::SIZE, $size, $m)) {
            return null;
        }

        $bytes = (float) $m[1] * self::MULTIPLIERS[strtolower($m[2])];

        return floor($bytes) === $bytes ? (int) $bytes : $bytes;
    }

    /** Whole MiB of a size; null when it isn't a size or not a whole number of MiB. */
    public static function mebibytes(mixed $size): ?int
    {
        $bytes = self::bytes($size);

        return is_int($bytes) && $bytes % (1024 ** 2) === 0 ? intdiv($bytes, 1024 ** 2) : null;
    }

    /** A number of MiB as the shortest size (`1024` → `1g`, `1536` → `1536m`). */
    public static function size(int $mebibytes): string
    {
        foreach (['t' => 1024 ** 2, 'g' => 1024] as $unit => $per) {
            if ($mebibytes >= $per && $mebibytes % $per === 0) {
                return intdiv($mebibytes, $per).$unit;
            }
        }

        return $mebibytes.'m';
    }
}
