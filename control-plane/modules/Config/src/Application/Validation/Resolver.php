<?php

namespace Falak\Config\Application\Validation;

use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Contracts\Pointer;
use stdClass;

/**
 * Stage 3: the environment merge and its reference checks, a port of agent/internal/configspec Resolve (the
 * reference implementation; both run contracts/config/cases/resolve). Works on the JSON data model (stdClass maps).
 *
 * Merge rules: an override map merges into the unit's map key by key; any other value (list, scalar) replaces; null
 * removes the key (a null unit removes the unit from the environment); an override for a key not in `units` adds a
 * unit.
 */
final class Resolver
{
    /** Same regex as Projects' VariableReferences::PATTERN and Go's configspec.RefPattern. */
    public const REF_PATTERN = '/\$\{\{\s*([A-Za-z0-9][A-Za-z0-9 _.\-]*?)\.([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    public const SECRET_NAME = '/^[A-Z_][A-Z0-9_]*$/';

    /** Databases' DatabaseConnections::KEYS and ::REDIS_KEYS. */
    public const DATABASE_KEYS = ['DATABASE_URL', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];

    public const REDIS_KEYS = ['REDIS_URL', 'REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD'];

    /**
     * @return array{0: ?stdClass, 1: list<ConfigIssue>, 2: list<ConfigIssue>} the resolved document (null when the merge stops), errors, warnings
     */
    public function resolve(stdClass $doc, string $environment): array
    {
        $envs = $doc->environments ?? null;
        $env = $envs instanceof stdClass && property_exists($envs, $environment) ? $envs->{$environment} : null;

        if (! $env instanceof stdClass) {
            return [null, [ConfigIssue::error('environment.unknown', '/environments', "The file has no environment \"{$environment}\".", '/environments')], []];
        }

        $units = isset($doc->units) && $doc->units instanceof stdClass ? self::copy($doc->units) : new stdClass;
        $overrides = isset($env->units) && $env->units instanceof stdClass ? $env->units : new stdClass;
        $errors = [];
        $keys = array_map('strval', array_keys((array) $overrides));
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $override = $overrides->{$key};

            if ($override === null) {
                unset($units->{$key});

                continue;
            }

            $override = $override instanceof stdClass ? $override : new stdClass;
            $base = property_exists($units, $key) && $units->{$key} instanceof stdClass ? $units->{$key} : null;

            if ($base !== null) {
                if (property_exists($override, 'type') && $override->type !== ($base->type ?? null)) {
                    $pointer = '/environments/'.Pointer::escape($environment).'/units/'.Pointer::escape($key).'/type';
                    $errors[] = ConfigIssue::error('override.type', $pointer, "An override can't change a unit's type.", $pointer);

                    continue;
                }

                $units->{$key} = self::merge($base, $override);
            } else {
                $units->{$key} = self::merge(new stdClass, $override);
            }
        }

        if ($errors !== []) {
            return [null, $errors, []];
        }

        $resolved = new stdClass;

        if (property_exists($doc, '$schema')) {
            $resolved->{'$schema'} = $doc->{'$schema'};
        }

        $resolved->version = $doc->version ?? null;
        $resolved->units = $units;
        $out = new stdClass;

        foreach ((array) $env as $k => $v) {
            if ((string) $k !== 'units') {
                $out->{$k} = self::copy($v);
            }
        }

        $resolved->environments = (object) [$environment => $out];

        if (property_exists($doc, 'previews')) {
            $resolved->previews = self::copy($doc->previews);
        }

        [$errors, $warnings] = $this->references($resolved, $environment, $overrides, $doc->units ?? null);

        return [$resolved, $errors, $warnings];
    }

    private static function merge(stdClass $base, stdClass $over): stdClass
    {
        $out = self::copy($base);

        foreach ((array) $over as $k => $v) {
            $k = (string) $k;

            if ($v === null) {
                unset($out->{$k});

                continue;
            }

            if ($v instanceof stdClass) {
                $out->{$k} = self::merge(property_exists($out, $k) && $out->{$k} instanceof stdClass ? $out->{$k} : new stdClass, $v);

                continue;
            }

            $out->{$k} = self::copy($v);
        }

        return $out;
    }

    private static function copy(mixed $v): mixed
    {
        if ($v instanceof stdClass) {
            $out = new stdClass;

            foreach ((array) $v as $k => $x) {
                $out->{(string) $k} = self::copy($x);
            }

            return $out;
        }

        if (is_array($v)) {
            return array_map(self::copy(...), $v);
        }

        return $v;
    }

    /**
     * @return array{0: list<ConfigIssue>, 1: list<ConfigIssue>}
     */
    private function references(stdClass $resolved, string $environment, stdClass $overrides, mixed $baseUnits): array
    {
        $units = $resolved->units;
        $errors = [];
        $warnings = [];
        $source = function (array $tokens) use ($environment, $overrides, $baseUnits): string {
            if (count($tokens) < 2 || $tokens[0] !== 'units') {
                return Pointer::of($tokens);
            }

            $envPointer = '/environments/'.Pointer::escape($environment).Pointer::of($tokens);

            if (! property_exists($overrides, $tokens[1]) || $overrides->{$tokens[1]} === null) {
                return Pointer::of($tokens);
            }

            if (! $baseUnits instanceof stdClass || ! property_exists($baseUnits, $tokens[1]) || $baseUnits->{$tokens[1]} === null) {
                return $envPointer;
            }

            $cur = $overrides->{$tokens[1]};

            foreach (array_slice($tokens, 2) as $token) {
                if (! $cur instanceof stdClass) {
                    return $envPointer;
                }

                if (! property_exists($cur, $token)) {
                    return Pointer::of($tokens);
                }

                $cur = $cur->{$token};
            }

            return $envPointer;
        };
        $add = function (array &$list, string $code, array $tokens, string $message, bool $warning = false) use ($source): void {
            $pointer = Pointer::of($tokens);

            foreach ($list as $issue) {
                if ($issue->code === $code && $issue->pointer === $pointer) {
                    return;
                }
            }

            $list[] = $warning ? ConfigIssue::warning($code, $pointer, $message, $source($tokens)) : ConfigIssue::error($code, $pointer, $message, $source($tokens));
        };

        $walk = function (mixed $v, array $tokens) use (&$walk, &$errors, &$warnings, $add, $units): void {
            if ($v instanceof stdClass) {
                $keys = array_map('strval', array_keys((array) $v));
                sort($keys, SORT_STRING);

                foreach ($keys as $k) {
                    $walk($v->{$k}, [...$tokens, $k]);
                }

                return;
            }

            if (is_array($v)) {
                foreach ($v as $i => $x) {
                    $walk($x, [...$tokens, (string) $i]);
                }

                return;
            }

            if (! is_string($v) || ! str_contains($v, '${{')) {
                return;
            }

            if (! (count($tokens) === 4 && $tokens[0] === 'units' && $tokens[2] === 'env')) {
                $add($errors, 'reference.not_allowed', $tokens, 'References are only resolved in a unit\'s env values.');

                return;
            }

            preg_match_all(self::REF_PATTERN, $v, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            $starts = array_flip(array_map(fn ($m) => $m[0][1], $matches));

            for ($i = 0; ($j = strpos($v, '${{', $i)) !== false; $i = $j + 3) {
                if (! isset($starts[$j])) {
                    $add($errors, 'reference.malformed', $tokens, 'Use ${{ secrets.NAME }} or ${{ <unit>.KEY }}.');

                    return;
                }
            }

            foreach ($matches as $m) {
                $name = trim($m[1][0]);
                $key = $m[2][0];

                if (strtolower($name) === 'secrets') {
                    if (! preg_match(self::SECRET_NAME, $key)) {
                        $add($errors, 'reference.secret_name', $tokens, "Secret names are upper case: \"{$key}\".");
                    }

                    continue;
                }

                $target = property_exists($units, $name) ? $units->{$name} : null;

                if (! $target instanceof stdClass) {
                    $add($warnings, 'reference.external', $tokens, "\"{$name}\" is not a unit of this file; it must be a service of the environment.", true);

                    continue;
                }

                if (! self::exposes($target, $key)) {
                    $add($errors, 'reference.unknown_key', $tokens, "Unit \"{$name}\" has no variable {$key}.");
                }
            }
        };
        $walk($resolved, []);

        return [$errors, $warnings];
    }

    private static function exposes(stdClass $unit, string $key): bool
    {
        return match ($unit->type ?? null) {
            'database' => in_array($key, self::DATABASE_KEYS, true),
            'redis' => in_array($key, self::REDIS_KEYS, true),
            default => isset($unit->env) && $unit->env instanceof stdClass && property_exists($unit->env, $key),
        };
    }
}
