<?php

namespace Falak\Telemetry\Tests\Support;

/**
 * Writes tiny MaxMind DB files for tests (an IPv6 tree, IPv4 under ::/96, as DB-IP's files are): networks map to
 * records encoded with the format's map / string / uint types. Not for production use.
 */
final class MmdbWriter
{
    /** @var list<array{0: list<int>, 1: mixed}> bits of the network, record */
    private array $networks = [];

    public function __construct(private readonly int $recordSize = 24) {}

    public function add(string $cidr, mixed $record): self
    {
        [$ip, $prefix] = explode('/', $cidr);
        $packed = inet_pton($ip);
        $offset = strlen($packed) === 4 ? 96 : 0;
        $bits = array_fill(0, $offset, 0);

        for ($i = 0; $i < (int) $prefix; $i++) {
            $bits[] = (ord($packed[$i >> 3]) >> (7 - ($i & 7))) & 1;
        }

        $this->networks[] = [$bits, $record];

        return $this;
    }

    public function write(string $path, string $type = 'test'): void
    {
        // Tree: node => [left, right], children as ['node', n] or ['data', i] or null.
        $tree = [[null, null]];
        $data = '';
        $offsets = [];

        foreach ($this->networks as $i => [$bits, $record]) {
            $offsets[$i] = strlen($data);
            $data .= self::encode($record);
            $node = 0;

            foreach ($bits as $depth => $bit) {
                if ($depth === count($bits) - 1) {
                    $tree[$node][$bit] = ['data', $i];
                    break;
                }

                if (! is_array($tree[$node][$bit]) || $tree[$node][$bit][0] !== 'node') {
                    $tree[] = [null, null];
                    $tree[$node][$bit] = ['node', count($tree) - 1];
                }

                $node = $tree[$node][$bit][1];
            }
        }

        $count = count($tree);
        $value = fn (?array $child) => match ($child[0] ?? null) {
            'node' => $child[1],
            'data' => $count + 16 + $offsets[$child[1]],
            default => $count,
        };
        $out = '';

        foreach ($tree as [$left, $right]) {
            $l = $value($left);
            $r = $value($right);
            $out .= match ($this->recordSize) {
                24 => substr(pack('N', $l), 1).substr(pack('N', $r), 1),
                28 => substr(pack('N', $l), 1).chr((($l >> 24) & 0x0F) << 4 | (($r >> 24) & 0x0F)).substr(pack('N', $r), 1),
                default => pack('N', $l).pack('N', $r),
            };
        }

        $metadata = self::encode([
            'node_count' => ['uint32', $count],
            'record_size' => ['uint16', $this->recordSize],
            'ip_version' => ['uint16', 6],
            'database_type' => $type,
            'binary_format_major_version' => ['uint16', 2],
            'binary_format_minor_version' => ['uint16', 0],
            'build_epoch' => ['uint64', 1_760_000_000],
        ]);

        file_put_contents($path, $out.str_repeat("\0", 16).$data."\xAB\xCD\xEFMaxMind.com".$metadata);
    }

    /** Values: string, int (uint32), ['uint16'|'uint32'|'uint64', n], bool, list (array), map (assoc array). */
    private static function encode(mixed $value): string
    {
        if (is_array($value) && count($value) === 2 && in_array($value[0] ?? null, ['uint16', 'uint32', 'uint64'], true) && is_int($value[1])) {
            [$kind, $n] = $value;
            $bytes = ltrim(pack('J', $n), "\0");

            return match ($kind) {
                'uint16' => self::control(5, strlen($bytes)).$bytes,
                'uint32' => self::control(6, strlen($bytes)).$bytes,
                default => self::control(9, strlen($bytes)).$bytes,
            };
        }

        return match (true) {
            is_string($value) => self::control(2, strlen($value)).$value,
            is_int($value) => self::encode(['uint32', $value]),
            is_bool($value) => self::control(14, $value ? 1 : 0),
            is_array($value) && array_is_list($value) => self::control(11, count($value)).implode('', array_map(self::encode(...), $value)),
            is_array($value) => self::control(7, count($value)).implode('', array_map(fn ($k, $v) => self::encode((string) $k).self::encode($v), array_keys($value), $value)),
        };
    }

    private static function control(int $type, int $size): string
    {
        $prefix = '';

        if ($size >= 29) {
            [$size, $prefix] = $size < 285 ? [29, chr($size - 29)] : [30, pack('n', $size - 285)];
        }

        return $type <= 7 ? chr($type << 5 | $size).$prefix : chr($size).chr($type - 7).$prefix;
    }
}
