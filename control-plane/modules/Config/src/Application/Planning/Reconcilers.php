<?php

namespace Falak\Config\Application\Planning;

use Falak\Config\Contracts\ApplyPhase;
use Falak\Config\Contracts\Change;
use Falak\Config\Contracts\ChangeSet;
use Falak\Config\Contracts\Reconcilable;
use Illuminate\Contracts\Container\Container;

/**
 * The reconcilers modules registered ({@see Reconcilable::TAG}), in apply order: phase A before phase B, and within a
 * phase the order of the contract's section table (units exist before what hangs off them).
 */
final class Reconcilers
{
    /**
     * Owner order of the phase 0 contract's section table, except Volumes after Sites **[decision]**: a volume is
     * attached to its unit's site, which Sites creates for a unit the file adds.
     */
    public const ORDER = ['Projects', 'Databases', 'Sites', 'Volumes', 'Deployments', 'Limits', 'Functions', 'Previews', 'Processes', 'Edge'];

    /** @var ?list<Reconcilable> */
    private ?array $all = null;

    private ?Sections $sections = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<Reconcilable>
     */
    public function all(): array
    {
        if ($this->all === null) {
            $list = [];

            foreach ($this->container->tagged(Reconcilable::TAG) as $reconciler) {
                $list[] = $reconciler;
            }

            usort($list, fn (Reconcilable $a, Reconcilable $b) => [$a->phase()->value, self::rank($a)] <=> [$b->phase()->value, self::rank($b)]);
            $this->all = $list;
        }

        return $this->all;
    }

    /**
     * @return list<Reconcilable>
     */
    public function forPhase(ApplyPhase $phase): array
    {
        return array_values(array_filter($this->all(), fn (Reconcilable $r) => $r->phase() === $phase));
    }

    public function sections(): Sections
    {
        return $this->sections ??= new Sections($this->all());
    }

    /** The changes a reconciler planned (by their section). */
    public function changesOf(Reconcilable $reconciler, ChangeSet $changes): ChangeSet
    {
        $sections = $reconciler->sections();

        return new ChangeSet(array_values(array_filter($changes->changes, fn (Change $c) => in_array($c->section, $sections, true))));
    }

    /** The module a reconciler belongs to (Falak\<Module>\…). */
    public static function module(Reconcilable $reconciler): string
    {
        return explode('\\', $reconciler::class)[1] ?? '';
    }

    private static function rank(Reconcilable $reconciler): int
    {
        $index = array_search(self::module($reconciler), self::ORDER, true);

        return $index === false ? count(self::ORDER) : (int) $index;
    }
}
