<?php

namespace Falak\Config\Application\Planning;

use Falak\Config\ConfigServiceProvider;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\Pointer;
use Falak\Config\Contracts\Units;

/**
 * contracts/config/risky-changes.json: flags the changes of a plan that wait for an approval (or, while the source is
 * experimental, refuse the run). The first matching rule wins.
 */
final class RiskRules
{
    /** @var ?list<array{id: string, paths: list<string>, ops: list<string>, unit_types?: list<string>, production_only?: bool, condition?: string, reason: string}> */
    private ?array $rules = null;

    /**
     * @param  array<string, string>  $unitTypes  unit key => type (from the resolved file, or today's state for removed units)
     * @param  array<string, bool>  $unitsWithVolumes  units that had volumes (for removed ones)
     */
    public function flag(ChangeSet $changes, bool $production, array $unitTypes, array $unitsWithVolumes = []): ChangeSet
    {
        $out = [];

        foreach ($changes->changes as $change) {
            $rule = $change->risky ? null : $this->match($change, $production, $unitTypes, $unitsWithVolumes);
            $out[] = $rule !== null ? $change->withRisk($rule['id'], $rule['reason']) : $change;
        }

        return new ChangeSet($out);
    }

    /**
     * @param  array<string, string>  $unitTypes
     * @param  array<string, bool>  $unitsWithVolumes
     * @return ?array{id: string, reason: string}
     */
    private function match(Change $change, bool $production, array $unitTypes, array $unitsWithVolumes): ?array
    {
        $tokens = Pointer::tokens($change->path);

        foreach ($this->rules() as $rule) {
            if (! in_array($change->op->value, $rule['ops'], true)) {
                continue;
            }

            if (($rule['production_only'] ?? false) && ! $production) {
                continue;
            }

            if (! array_filter($rule['paths'], fn (string $p) => self::pathMatches(Pointer::tokens($p), $tokens))) {
                continue;
            }

            $unit = $change->unit ?? (count($tokens) >= 2 && $tokens[0] === 'units' ? $tokens[1] : null);

            if (isset($rule['unit_types']) && ! in_array($unitTypes[(string) $unit] ?? null, $rule['unit_types'], true)) {
                continue;
            }

            if (isset($rule['condition']) && ! self::condition($rule['condition'], $change, $unitsWithVolumes[(string) $unit] ?? false)) {
                continue;
            }

            return ['id' => $rule['id'], 'reason' => $rule['reason']];
        }

        return null;
    }

    /**
     * @param  list<string>  $pattern
     * @param  list<string>  $tokens
     */
    private static function pathMatches(array $pattern, array $tokens): bool
    {
        if (count($pattern) !== count($tokens)) {
            return false;
        }

        foreach ($pattern as $i => $p) {
            if ($p !== '*' && $p !== $tokens[$i]) {
                return false;
            }
        }

        return true;
    }

    private static function condition(string $condition, Change $change, bool $hasVolumes): bool
    {
        return match ($condition) {
            'became_true' => $change->before !== true && $change->after === true,
            'became_false' => $change->before === true && $change->after !== true,
            'decrease' => ($b = self::magnitude($change->before)) !== null && ($a = self::magnitude($change->after)) !== null && $a < $b,
            // A limit where there was none (unlimited), or a lower one.
            'tightens' => ($a = self::magnitude($change->after)) !== null && (($b = self::magnitude($change->before)) === null || $a < $b),
            'removes_items' => is_array($change->before) && is_array($change->after) && array_diff(array_map('strval', $change->before), array_map('strval', $change->after)) !== [],
            'has_volumes' => $hasVolumes || (is_array($change->before) && ! empty($change->before['volumes'])),
            default => false,
        };
    }

    private static function magnitude(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return Units::bytes($value) ?? Units::seconds($value);
    }

    /**
     * @return list<array{id: string, paths: list<string>, ops: list<string>, unit_types?: list<string>, production_only?: bool, condition?: string, reason: string}>
     */
    private function rules(): array
    {
        if ($this->rules === null) {
            $data = json_decode((string) file_get_contents(ConfigServiceProvider::contractsPath().'/risky-changes.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->rules = $data['rules'];
        }

        return $this->rules;
    }
}
