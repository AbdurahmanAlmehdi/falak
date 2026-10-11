<?php

namespace Falak\Config\Contracts;

/**
 * RFC 6901 JSON Pointers, the paths of changes, issues and `config_ownership.path`.
 */
final class Pointer
{
    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    public static function unescape(string $token): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $token);
    }

    /**
     * @param  list<string|int>  $tokens
     */
    public static function of(array $tokens): string
    {
        return implode('', array_map(fn ($t) => '/'.self::escape((string) $t), $tokens));
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $pointer): array
    {
        if ($pointer === '' || $pointer === '/') {
            return $pointer === '/' ? [''] : [];
        }

        return array_map(self::unescape(...), array_slice(explode('/', $pointer), 1));
    }

    /** /units/<unit>/x/y → /x/y (the path relative to the unit, as config_ownership stores it); null when not in a unit. */
    public static function relativeToUnit(string $pointer): ?string
    {
        $tokens = self::tokens($pointer);

        if (count($tokens) < 2 || $tokens[0] !== 'units') {
            return null;
        }

        return self::of(array_slice($tokens, 2));
    }

    /** The unit key of a pointer into the file (/units/<unit>/…), or null. */
    public static function unit(string $pointer): ?string
    {
        $tokens = self::tokens($pointer);

        return count($tokens) >= 2 && $tokens[0] === 'units' ? $tokens[1] : null;
    }
}
