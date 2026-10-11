<?php

namespace Falak\Config\Contracts;

/**
 * One planned change. `path` is a JSON Pointer (RFC 6901) into the resolved file: list items with an identity are
 * addressed by it, not by index (/units/web/domains/shop.example.com, /units/web/redirects/~1old), see the phase 0
 * contract. `before` and `after` are values in the file's shape (null: absent), never secret values.
 */
final readonly class Change
{
    /**
     * @param  string  $section  the reconciler section that planned it, e.g. "units.*.workers"
     * @param  ?string  $unit  the unit key (null for environment and previews changes)
     * @param  ?string  $rule  the risky-change rule id (contracts/config/risky-changes.json) when risky
     */
    public function __construct(
        public ChangeOp $op,
        public string $path,
        public mixed $before = null,
        public mixed $after = null,
        public string $section = '',
        public ?string $unit = null,
        public bool $risky = false,
        public ?string $reason = null,
        public ?string $rule = null,
    ) {}

    /** This change, flagged risky by $rule. */
    public function withRisk(string $rule, string $reason): self
    {
        return new self($this->op, $this->path, $this->before, $this->after, $this->section, $this->unit, true, $reason, $rule);
    }

    /**
     * @return array{op: string, path: string, before: mixed, after: mixed, section: string, unit: ?string, risky: bool, reason: ?string, rule: ?string}
     */
    public function toArray(): array
    {
        return [
            'op' => $this->op->value,
            'path' => $this->path,
            'before' => $this->before,
            'after' => $this->after,
            'section' => $this->section,
            'unit' => $this->unit,
            'risky' => $this->risky,
            'reason' => $this->reason,
            'rule' => $this->rule,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  toArray()'s shape
     */
    public static function fromArray(array $data): self
    {
        return new self(
            op: ChangeOp::from((string) $data['op']),
            path: (string) $data['path'],
            before: $data['before'] ?? null,
            after: $data['after'] ?? null,
            section: (string) ($data['section'] ?? ''),
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            risky: (bool) ($data['risky'] ?? false),
            reason: isset($data['reason']) ? (string) $data['reason'] : null,
            rule: isset($data['rule']) ? (string) $data['rule'] : null,
        );
    }
}
