<?php

namespace Falak\Config\Contracts;

/**
 * The changes one reconciler (or a whole plan) makes, in apply order.
 */
final readonly class ChangeSet
{
    /**
     * @param  list<Change>  $changes
     */
    public function __construct(public array $changes = []) {}

    public static function of(Change ...$changes): self
    {
        return new self(array_values($changes));
    }

    public function merge(self $other): self
    {
        return new self([...$this->changes, ...$other->changes]);
    }

    /** Nothing to do: no change, or only noops. */
    public function isEmpty(): bool
    {
        return $this->actionable() === [];
    }

    /** @return list<Change> every change but the noops */
    public function actionable(): array
    {
        return array_values(array_filter($this->changes, fn (Change $c) => $c->op !== ChangeOp::Noop));
    }

    public function isRisky(): bool
    {
        return $this->risky() !== [];
    }

    /** @return list<Change> */
    public function risky(): array
    {
        return array_values(array_filter($this->changes, fn (Change $c) => $c->risky));
    }

    public function forUnit(string $unit): self
    {
        return new self(array_values(array_filter($this->changes, fn (Change $c) => $c->unit === $unit)));
    }

    /**
     * @return array{create: int, update: int, delete: int, detach: int, noop: int}
     */
    public function summary(): array
    {
        $summary = array_fill_keys(array_map(fn (ChangeOp $op) => $op->value, ChangeOp::cases()), 0);

        foreach ($this->changes as $change) {
            $summary[$change->op->value]++;
        }

        /** @var array{create: int, update: int, delete: int, detach: int, noop: int} */
        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(fn (Change $c) => $c->toArray(), $this->changes);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(fn (array $c) => Change::fromArray($c), $data)));
    }
}
