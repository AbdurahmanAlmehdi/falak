<?php

namespace Falak\Config\Application\Planning;

use Falak\Config\Contracts\ChangeSet;

/**
 * A plan: the changes (risk flagged) and the units whose config changed.
 */
final readonly class Plan
{
    /**
     * @param  list<string>  $deploy
     */
    public function __construct(public ChangeSet $changes, public array $deploy) {}

    /**
     * The API's `plan` (`{changes, summary, risky, deploy}`).
     *
     * @return array{changes: list<array<string, mixed>>, summary: array<string, int>, risky: bool, deploy: list<string>}
     */
    public function toArray(): array
    {
        return [
            'changes' => $this->changes->toArray(),
            'summary' => $this->changes->summary(),
            'risky' => $this->changes->isRisky(),
            'deploy' => $this->deploy,
        ];
    }
}
