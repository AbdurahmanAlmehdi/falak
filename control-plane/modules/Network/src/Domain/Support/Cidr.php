<?php

namespace Falak\Network\Domain\Support;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 prefix for blocking (docs/plans/network/protection.md), held as packed bytes. Strict: an address or
 * a CIDR without host bits, no zones, no IPv4-mapped IPv6. Printed canonically (a single address without its length).
 */
final readonly class Cidr
{
    private function __construct(
        public string $bytes,
        public int $bits,
    ) {}

    public static function parse(string $value): self
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 43 || ! preg_match('/^[0-9a-fA-F.:]+(\/\d{1,3})?$/', $value)) {
            throw new InvalidArgumentException("\"{$value}\" is not an IP address or CIDR.");
        }

        [$address, $length] = array_pad(explode('/', $value, 2), 2, null);
        $bytes = filter_var($address, FILTER_VALIDATE_IP) !== false ? @inet_pton($address) : false;

        if ($bytes === false) {
            throw new InvalidArgumentException("\"{$value}\" is not an IP address or CIDR.");
        }

        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            throw new InvalidArgumentException("Use the IPv4 form of \"{$address}\".");
        }

        $max = strlen($bytes) * 8;
        $bits = $length === null ? $max : (int) $length;

        if ($length !== null && ((string) $bits !== $length || $bits > $max)) {
            throw new InvalidArgumentException("\"{$value}\" has an invalid prefix length.");
        }

        $cidr = new self($bytes, $bits);

        if ($cidr->mask($bits) !== $bytes) {
            throw new InvalidArgumentException("\"{$value}\" has host bits set; did you mean {$cidr->network()}?");
        }

        return $cidr;
    }

    public static function tryParse(?string $value): ?self
    {
        try {
            return $value === null ? null : self::parse($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * A single address as seen elsewhere (a request's client, a session, a server record): IPv4-mapped IPv6
     * ("::ffff:203.0.113.7", dual-stack sockets) becomes the IPv4 address it is. null when it is not an address.
     */
    public static function fromAddress(?string $address): ?self
    {
        $address = trim((string) $address);

        if ($address === '' || str_contains($address, '/') || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $bytes = @inet_pton($address);

        if ($bytes === false) {
            return null;
        }

        if (strlen($bytes) === 16 && str_starts_with($bytes, str_repeat("\0", 10)."\xff\xff")) {
            $bytes = substr($bytes, 12);
        }

        return new self($bytes, strlen($bytes) * 8);
    }

    public function isV4(): bool
    {
        return strlen($this->bytes) === 4;
    }

    public function family(): string
    {
        return $this->isV4() ? 'ipv4' : 'ipv6';
    }

    public function maxBits(): int
    {
        return strlen($this->bytes) * 8;
    }

    public function isSingle(): bool
    {
        return $this->bits === $this->maxBits();
    }

    public function __toString(): string
    {
        $address = (string) inet_ntop($this->bytes);

        return $this->isSingle() ? $address : "{$address}/{$this->bits}";
    }

    /** Whether the two prefixes share any address. */
    public function overlaps(self $other): bool
    {
        if ($this->isV4() !== $other->isV4()) {
            return false;
        }

        $bits = min($this->bits, $other->bits);

        return $this->mask($bits) === $other->mask($bits);
    }

    public function contains(self $other): bool
    {
        return $this->isV4() === $other->isV4() && $this->bits <= $other->bits && $other->mask($this->bits) === $this->bytes;
    }

    private function mask(int $bits): string
    {
        $out = '';

        foreach (str_split($this->bytes) as $i => $byte) {
            $keep = max(0, min(8, $bits - $i * 8));
            $out .= chr(ord($byte) & (0xFF << (8 - $keep)) & 0xFF);
        }

        return $out;
    }

    private function network(): string
    {
        return (string) (new self($this->mask($this->bits), $this->bits));
    }
}
