<?php

namespace Falak\Network\Infrastructure;

/**
 * Reverse DNS (PTR) with a hard timeout: one UDP query to the system's first nameserver (/etc/resolv.conf), no retries.
 * gethostbyaddr() has no timeout and a slow resolver would hold a queue worker for as long as it likes.
 */
final class PtrResolver
{
    public function __construct(
        private readonly ?string $nameserver = null,
        private readonly float $timeout = 1.0,
        private readonly int $port = 53,
    ) {}

    /** The PTR name of an address, or null (no record, an error, a timeout). */
    public function resolve(string $ip): ?string
    {
        $server = $this->nameserver ?? self::systemNameserver();
        $name = self::reverseName($ip);

        if ($server === null || $name === null) {
            return null;
        }

        $id = random_int(0, 0xFFFF);
        $target = (str_contains($server, ':') ? "[{$server}]" : $server).":{$this->port}";
        $socket = @stream_socket_client("udp://{$target}", $errno, $error, $this->timeout);

        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) $this->timeout, (int) (fmod($this->timeout, 1) * 1_000_000));

            if (@fwrite($socket, self::query($id, $name)) === false) {
                return null;
            }

            $answer = @fread($socket, 4096);

            if ($answer === false || $answer === '' || stream_get_meta_data($socket)['timed_out']) {
                return null;
            }

            return self::parse($answer, $id);
        } finally {
            fclose($socket);
        }
    }

    /** 4.3.2.1.in-addr.arpa / the IPv6 nibbles reversed .ip6.arpa; null for an invalid address. */
    public static function reverseName(string $ip): ?string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }

        return implode('.', array_reverse(str_split(bin2hex($packed)))).'.ip6.arpa';
    }

    /** A recursive PTR query. */
    public static function query(int $id, string $name): string
    {
        $qname = '';

        foreach (explode('.', $name) as $label) {
            $qname .= chr(strlen($label)).$label;
        }

        return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$qname."\0".pack('nn', 12, 1);
    }

    /** The first PTR answer of a response to query $id (null: another id, an error code, no PTR). */
    public static function parse(string $packet, int $id): ?string
    {
        if (strlen($packet) < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqd/nan', substr($packet, 0, 8));

        if ($header === false || $header['id'] !== $id || ($header['flags'] & 0x8000) === 0 || ($header['flags'] & 0x000F) !== 0) {
            return null;
        }

        $offset = 12;

        for ($i = 0; $i < $header['qd']; $i++) {
            if (self::name($packet, $offset) === null) {
                return null;
            }

            $offset += 4;
        }

        for ($i = 0; $i < $header['an']; $i++) {
            if (self::name($packet, $offset) === null || strlen($packet) < $offset + 10) {
                return null;
            }

            $rr = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;

            if ($rr === false) {
                return null;
            }

            if ($rr['type'] === 12) {
                $at = $offset;
                $name = self::name($packet, $at);

                return $name !== null && $name !== '' ? $name : null;
            }

            $offset += $rr['length'];
        }

        return null;
    }

    /**
     * Reads a (possibly compressed) domain name at $offset, advancing it past the name; null when malformed.
     */
    private static function name(string $packet, int &$offset): ?string
    {
        $labels = [];
        $at = $offset;
        $jumped = false;

        for ($hops = 0; $hops < 64; $hops++) {
            if ($at >= strlen($packet)) {
                return null;
            }

            $length = ord($packet[$at]);

            if ($length === 0) {
                if (! $jumped) {
                    $offset = $at + 1;
                }

                return implode('.', $labels);
            }

            if (($length & 0xC0) === 0xC0) {
                if ($at + 1 >= strlen($packet)) {
                    return null;
                }

                if (! $jumped) {
                    $offset = $at + 2;
                }

                $at = (($length & 0x3F) << 8) | ord($packet[$at + 1]);
                $jumped = true;

                continue;
            }

            $labels[] = substr($packet, $at + 1, $length);
            $at += $length + 1;
        }

        return null;
    }

    private static function systemNameserver(): ?string
    {
        $conf = @file_get_contents('/etc/resolv.conf');

        if ($conf !== false && preg_match('/^\s*nameserver\s+(\S+)/m', $conf, $m) === 1 && filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
            return $m[1];
        }

        return null;
    }
}
