<?php

namespace Falak\Telemetry\Infrastructure\Geo;

use RuntimeException;

/**
 * A minimal reader of the MaxMind DB format (https://maxmind.github.io/MaxMind-DB/), enough for DB-IP's Lite country
 * and ASN databases: no extension, no package. The file is read into memory once (the Lite files are 5–10 MB).
 */
final class MaxMindReader
{
    private const METADATA_MARKER = "\xAB\xCD\xEFMaxMind.com";

    private string $buf;

    private int $nodeCount;

    private int $recordSize;

    private int $nodeBytes;

    private int $ipVersion;

    private int $dataStart;

    private ?int $ipv4Start = null;

    /** @var array<string, mixed> */
    private array $metadata;

    public function __construct(string $path)
    {
        $buf = @file_get_contents($path);

        if ($buf === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        $this->buf = $buf;
        $marker = strrpos($buf, self::METADATA_MARKER);

        if ($marker === false) {
            throw new RuntimeException('Not a MaxMind DB file (no metadata).');
        }

        $this->dataStart = $marker + strlen(self::METADATA_MARKER);
        $metadata = $this->decode($this->dataStart)[0];

        if (! is_array($metadata) || ! isset($metadata['node_count'], $metadata['record_size'], $metadata['ip_version'])) {
            throw new RuntimeException('Invalid MaxMind DB metadata.');
        }

        $this->metadata = $metadata;
        $this->nodeCount = (int) $metadata['node_count'];
        $this->recordSize = (int) $metadata['record_size'];
        $this->ipVersion = (int) $metadata['ip_version'];

        if (! in_array($this->recordSize, [24, 28, 32], true)) {
            throw new RuntimeException("Unsupported record size {$this->recordSize}.");
        }

        $this->nodeBytes = $this->recordSize / 4;
        $this->dataStart = $this->nodeCount * $this->nodeBytes + 16;

        if ($this->dataStart > $marker) {
            throw new RuntimeException('Invalid MaxMind DB search tree.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * The record of the network holding $ip, or null (not in the database, or not an IP address).
     */
    public function get(string $ip): mixed
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        $bits = strlen($packed) * 8;

        if ($bits === 128 && $this->ipVersion === 4) {
            return null;
        }

        $node = $bits === 32 && $this->ipVersion === 6 ? $this->ipv4Start() : 0;

        for ($i = 0; $i < $bits && $node < $this->nodeCount; $i++) {
            $bit = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
            $node = $this->record($node, $bit);
        }

        if ($node <= $this->nodeCount) {
            return null; // nodeCount: no data; below: the address is longer than the tree (never in valid files)
        }

        $offset = $node - $this->nodeCount - 16 + $this->dataStart;

        if ($offset >= strlen($this->buf)) {
            throw new RuntimeException('Invalid MaxMind DB pointer.');
        }

        return $this->decode($offset)[0];
    }

    /** IPv4 addresses live under ::/96 of an IPv6 tree. */
    private function ipv4Start(): int
    {
        if ($this->ipv4Start === null) {
            $node = 0;

            for ($i = 0; $i < 96 && $node < $this->nodeCount; $i++) {
                $node = $this->record($node, 0);
            }

            $this->ipv4Start = $node;
        }

        return $this->ipv4Start;
    }

    private function record(int $node, int $bit): int
    {
        $base = $node * $this->nodeBytes;
        $b = substr($this->buf, $base, $this->nodeBytes);

        if (strlen($b) !== $this->nodeBytes) {
            throw new RuntimeException('Invalid MaxMind DB search tree.');
        }

        return match ($this->recordSize) {
            24 => $bit === 0 ? self::uint(substr($b, 0, 3)) : self::uint(substr($b, 3, 3)),
            28 => $bit === 0
                ? ((ord($b[3]) & 0xF0) << 20) | self::uint(substr($b, 0, 3))
                : ((ord($b[3]) & 0x0F) << 24) | self::uint(substr($b, 4, 3)),
            default => $bit === 0 ? self::uint(substr($b, 0, 4)) : self::uint(substr($b, 4, 4)),
        };
    }

    private static function uint(string $bytes): int
    {
        $n = 0;

        foreach (str_split($bytes) as $c) {
            $n = ($n << 8) | ord($c);
        }

        return $n;
    }

    /**
     * Decodes the value at $offset (absolute); returns it and the offset after it.
     *
     * @return array{0: mixed, 1: int}
     */
    private function decode(int $offset, int $depth = 0): array
    {
        if ($depth > 64 || $offset >= strlen($this->buf)) {
            throw new RuntimeException('Invalid MaxMind DB data.');
        }

        $ctrl = ord($this->buf[$offset++]);
        $type = $ctrl >> 5;

        if ($type === 1) {
            $ss = ($ctrl >> 3) & 3;
            $v = $ctrl & 7;
            $p = match ($ss) {
                0 => ($v << 8) | ord($this->buf[$offset]),
                1 => (($v << 16) | self::uint(substr($this->buf, $offset, 2))) + 2048,
                2 => (($v << 24) | self::uint(substr($this->buf, $offset, 3))) + 526336,
                default => self::uint(substr($this->buf, $offset, 4)),
            };

            return [$this->decode($this->dataStart + $p, $depth + 1)[0], $offset + $ss + 1];
        }

        if ($type === 0) {
            $type = 7 + ord($this->buf[$offset++]);
        }

        $size = $ctrl & 0x1F;

        if ($size >= 29) {
            $extra = $size - 28;
            $size = [29 => 29, 30 => 285, 31 => 65821][$size] + self::uint(substr($this->buf, $offset, $extra));
            $offset += $extra;
        }

        switch ($type) {
            case 2: // utf8 string
            case 4: // bytes
                return [substr($this->buf, $offset, $size), $offset + $size];
            case 3: // double
                return [unpack('E', substr($this->buf, $offset, 8))[1], $offset + 8];
            case 15: // float
                return [unpack('G', substr($this->buf, $offset, 4))[1], $offset + 4];
            case 5: case 6: case 9: case 10: // uint16/32/64/128 (128 only as far as PHP ints go)
                return [self::uint(substr($this->buf, $offset, $size)), $offset + $size];
            case 8: // int32
                $n = self::uint(substr($this->buf, $offset, $size));

                return [$size === 4 && $n >= 0x80000000 ? $n - 0x100000000 : $n, $offset + $size];
            case 7: // map
                $map = [];

                for ($i = 0; $i < $size; $i++) {
                    [$key, $offset] = $this->decode($offset, $depth + 1);
                    [$map[(string) $key], $offset] = $this->decode($offset, $depth + 1);
                }

                return [$map, $offset];
            case 11: // array
                $list = [];

                for ($i = 0; $i < $size; $i++) {
                    [$list[], $offset] = $this->decode($offset, $depth + 1);
                }

                return [$list, $offset];
            case 14: // boolean
                return [$size !== 0, $offset];
            default:
                throw new RuntimeException("Unsupported MaxMind DB type {$type}.");
        }
    }
}
