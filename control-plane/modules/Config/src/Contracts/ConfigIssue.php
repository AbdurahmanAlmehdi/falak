<?php

namespace Falak\Config\Contracts;

/**
 * One error or warning about a falak.yaml (the API's `ConfigIssue`, docs/plans/CONFIG_AS_CODE.md "Validation stages
 * and issue codes"). `pointer` is an RFC 6901 JSON Pointer into the parsed file (stages 1-2) or the resolved file
 * (3-4, then `source` is the value's place in the file); line and column are 1-based, null when unknown.
 */
final readonly class ConfigIssue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function __construct(
        public string $code,
        public ?string $pointer,
        public string $message,
        public ?string $source = null,
        public ?int $line = null,
        public ?int $column = null,
        public string $severity = self::ERROR,
    ) {}

    public static function error(string $code, ?string $pointer, string $message, ?string $source = null): self
    {
        return new self($code, $pointer, $message, $source);
    }

    public static function warning(string $code, ?string $pointer, string $message, ?string $source = null): self
    {
        return new self($code, $pointer, $message, $source, severity: self::WARNING);
    }

    public function at(?int $line, ?int $column): self
    {
        return new self($this->code, $this->pointer, $this->message, $this->source, $line, $column, $this->severity);
    }

    public function asWarning(): self
    {
        return new self($this->code, $this->pointer, $this->message, $this->source, $this->line, $this->column, self::WARNING);
    }

    public function isError(): bool
    {
        return $this->severity === self::ERROR;
    }

    /** `falak.yaml:12:17: schema: /units/web/deploy/strategy: value must be …` (the CLI's format). */
    public function format(string $file = 'falak.yaml'): string
    {
        $at = $this->line !== null ? "{$file}:{$this->line}:".($this->column ?? 1).': ' : "{$file}: ";

        return $at.$this->code.': '.($this->pointer !== null && $this->pointer !== '' ? "{$this->pointer}: " : '').$this->message;
    }

    /**
     * @return array{code: string, pointer: ?string, source: ?string, line: ?int, column: ?int, message: string, severity: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'pointer' => $this->pointer,
            'source' => $this->source,
            'line' => $this->line,
            'column' => $this->column,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['code'],
            isset($data['pointer']) ? (string) $data['pointer'] : null,
            (string) ($data['message'] ?? ''),
            isset($data['source']) ? (string) $data['source'] : null,
            isset($data['line']) ? (int) $data['line'] : null,
            isset($data['column']) ? (int) $data['column'] : null,
            (string) ($data['severity'] ?? self::ERROR),
        );
    }
}
