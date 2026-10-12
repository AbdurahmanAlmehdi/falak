<?php

namespace Falak\Config\Application\Runs;

use Falak\Config\Contracts\Change;

/**
 * The risky changes of a plan as an approval sees them: `{op, path, rule}` (an approved detach never covers a delete),
 * and their `plan_hash` (docs/plans/CONFIG_AS_CODE.md, Phase 4).
 */
final class RiskSet
{
    /**
     * The canonical risky set of a stored plan's changes (Change::toArray() shapes, or Change objects).
     *
     * @param  list<array<string, mixed>|Change>  $changes
     * @return list<array{op: string, path: string, rule: string}>
     */
    public static function of(array $changes): array
    {
        $set = [];

        foreach ($changes as $c) {
            $c = $c instanceof Change ? $c->toArray() : $c;

            if ((bool) ($c['risky'] ?? false)) {
                $set[] = ['op' => (string) $c['op'], 'path' => (string) $c['path'], 'rule' => (string) ($c['rule'] ?? '')];
            }
        }

        usort($set, fn (array $a, array $b) => [$a['path'], $a['rule'], $a['op']] <=> [$b['path'], $b['rule'], $b['op']]);

        return array_values(array_unique($set, SORT_REGULAR));
    }

    /** @param  list<array<string, mixed>|Change>  $changes */
    public static function hash(array $changes): string
    {
        return hash('sha256', (string) json_encode(self::of($changes), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param  array<string, mixed>|Change  $change */
    public static function key(array|Change $change): string
    {
        $c = $change instanceof Change ? $change->toArray() : $change;

        return ($c['op'] ?? '')."\0".($c['path'] ?? '')."\0".($c['rule'] ?? '');
    }

    /**
     * The risky changes an approved set doesn't cover.
     *
     * @param  list<Change>  $changes
     * @param  list<array<string, mixed>>  $approved
     * @return list<Change>
     */
    public static function beyond(array $changes, array $approved): array
    {
        $seen = array_flip(array_map(self::key(...), $approved));

        return array_values(array_filter($changes, fn (Change $c) => $c->risky && ! isset($seen[self::key($c)])));
    }
}
