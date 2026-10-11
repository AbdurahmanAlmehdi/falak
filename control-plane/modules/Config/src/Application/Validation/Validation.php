<?php

namespace Falak\Config\Application\Validation;

use Falak\Config\Application\Reading\ConfigFile;
use Falak\Config\Contracts\ConfigIssue;

/**
 * The outcome of validating a file (stages 1-3 for every or one environment, plus stage 4 with a scope).
 */
final readonly class Validation
{
    /**
     * @param  list<string>  $environments  the file's environment keys
     * @param  ?array<string, mixed>  $resolved  the resolved file (plain arrays) of the requested environment
     * @param  list<ConfigIssue>  $errors
     * @param  list<ConfigIssue>  $warnings
     */
    public function __construct(
        public ConfigFile $file,
        public array $environments,
        public ?array $resolved,
        public array $errors,
        public array $warnings,
    ) {}

    public function valid(): bool
    {
        return $this->errors === [];
    }

    /** "falak.yaml is invalid (2 errors)." */
    public function summary(string $file = 'falak.yaml'): string
    {
        $n = count($this->errors);

        return "{$file} is invalid ({$n} ".($n === 1 ? 'error' : 'errors').').';
    }

    /**
     * Laravel's 422 shape plus the issues (the contract's "Invalid files answer 422").
     *
     * @return array<string, mixed>
     */
    public function toErrorResponse(string $file = 'falak.yaml'): array
    {
        return [
            'message' => $this->summary($file),
            'errors' => ['file' => array_map(fn (ConfigIssue $i) => ($i->pointer !== null && $i->pointer !== '' ? "{$i->pointer}: " : '').$i->message, $this->errors)],
            'config_errors' => array_map(fn (ConfigIssue $i) => $i->toArray(), $this->errors),
            'config_warnings' => array_map(fn (ConfigIssue $i) => $i->toArray(), $this->warnings),
        ];
    }
}
