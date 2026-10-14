<?php

namespace Falak\Uptime\Domain\Probing;

use InvalidArgumentException;

/**
 * The statuses an http monitor accepts: a comma-separated list of codes (200) and classes (2xx), e.g. "2xx,3xx".
 */
final readonly class ExpectedStatus
{
    public const DEFAULT = '2xx,3xx';

    /**
     * @param  list<string>  $parts  normalized: "2xx" or "404"
     */
    private function __construct(public array $parts) {}

    public static function parse(?string $value): self
    {
        $value = trim((string) $value);

        if ($value === '') {
            $value = self::DEFAULT;
        }

        $parts = [];

        foreach (explode(',', strtolower($value)) as $part) {
            $part = trim($part);

            if (! preg_match('/^([1-5]xx|[1-5][0-9]{2})$/', $part)) {
                throw new InvalidArgumentException("Invalid status \"{$part}\": use codes (200) or classes (2xx), separated by commas.");
            }

            $parts[] = $part;
        }

        return new self(array_values(array_unique($parts)));
    }

    public static function isValid(?string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function matches(int $code): bool
    {
        foreach ($this->parts as $part) {
            if (str_ends_with($part, 'xx') ? intdiv($code, 100) === (int) $part[0] : $code === (int) $part) {
                return true;
            }
        }

        return false;
    }

    public function toString(): string
    {
        return implode(',', $this->parts);
    }
}
