<?php

use Falak\Network\Infrastructure\PtrResolver;

it('builds reverse names and PTR queries', function () {
    expect(PtrResolver::reverseName('8.8.4.4'))->toBe('4.4.8.8.in-addr.arpa')
        ->and(PtrResolver::reverseName('2001:db8::1'))->toBe('1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa')
        ->and(PtrResolver::reverseName('nope'))->toBeNull();

    $query = PtrResolver::query(0x1234, '4.4.8.8.in-addr.arpa');
    expect(bin2hex(substr($query, 0, 12)))->toBe('123401000001000000000000')
        ->and(substr($query, 12))->toBe("\x014\x014\x018\x018\x07in-addr\x04arpa\x00\x00\x0c\x00\x01");
});

it('reads the PTR answer, compressed names included', function () {
    $question = substr(PtrResolver::query(7, '4.4.8.8.in-addr.arpa'), 12);
    // Answer: name = pointer to the question (offset 12), type PTR, class IN, ttl, rdata "dns.google" with "google" as
    // a plain label.
    $rdata = "\x03dns\x06google\x00";
    $answer = "\xc0\x0c".pack('nnNn', 12, 1, 300, strlen($rdata)).$rdata;
    $packet = pack('nnnnnn', 7, 0x8180, 1, 1, 0, 0).$question.$answer;

    expect(PtrResolver::parse($packet, 7))->toBe('dns.google')
        ->and(PtrResolver::parse($packet, 8))->toBeNull()
        // NXDOMAIN
        ->and(PtrResolver::parse(pack('nnnnnn', 7, 0x8183, 1, 0, 0, 0).$question, 7))->toBeNull()
        ->and(PtrResolver::parse('short', 7))->toBeNull();
});

it('gives up after its timeout when the nameserver does not answer', function () {
    $silent = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
    $port = (int) substr((string) strrchr(stream_socket_get_name($silent, false), ':'), 1);

    $started = microtime(true);
    expect((new PtrResolver('127.0.0.1', 0.3, $port))->resolve('8.8.8.8'))->toBeNull()
        ->and(microtime(true) - $started)->toBeLessThan(2.0);
    fclose($silent);
});
