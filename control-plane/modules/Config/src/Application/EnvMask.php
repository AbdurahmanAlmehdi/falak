<?php

namespace Falak\Config\Application;

use Falak\Config\Contracts\Pointer;

/**
 * **[decision]** Env values never leave Config unmasked for someone without `sites.env.view`: stored plans, plan and
 * run responses, pull request comments and exports show `••••` for every literal env value of a unit, a worker or a
 * daemon (`${{ … }}` references are kept: they hold no value).
 */
final class EnvMask
{
    public const MASK = '••••';

    /** units/<u>/env/<K>, units/<u>/workers|daemons/<k>/env/<K> */
    public static function isEnvValue(array $tokens): bool
    {
        $n = count($tokens);

        return ($n === 4 && $tokens[0] === 'units' && $tokens[2] === 'env')
            || ($n === 6 && $tokens[0] === 'units' && in_array($tokens[2], ['workers', 'daemons'], true) && $tokens[4] === 'env');
    }

    public static function isEnvPath(string $pointer): bool
    {
        return self::isEnvValue(Pointer::tokens($pointer));
    }

    /**
     * A value found at $pointer of the file, with every env value under it masked.
     */
    public static function value(mixed $value, string $pointer): mixed
    {
        return self::walk($value, Pointer::tokens($pointer));
    }

    /**
     * @param  list<array<string, mixed>>  $changes  ChangeSet::toArray()
     * @return list<array<string, mixed>>
     */
    public static function changes(array $changes): array
    {
        return array_map(fn (array $c) => [...$c, 'before' => self::value($c['before'] ?? null, (string) $c['path']), 'after' => self::value($c['after'] ?? null, (string) $c['path'])], $changes);
    }

    /**
     * A plan's array (`{changes, summary, risky, deploy}`) with env values masked.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public static function plan(array $plan): array
    {
        return [...$plan, 'changes' => self::changes((array) ($plan['changes'] ?? []))];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public static function document(array $document): array
    {
        return (array) self::walk($document, []);
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function walk(mixed $value, array $tokens): mixed
    {
        if (self::isEnvValue($tokens)) {
            return $value === null || (is_string($value) && str_contains($value, '${{')) ? $value : self::MASK;
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = self::walk($child, [...$tokens, (string) $key]);
        }

        return $value;
    }
}
