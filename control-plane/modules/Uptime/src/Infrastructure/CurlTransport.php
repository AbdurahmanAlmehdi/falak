<?php

namespace Falak\Uptime\Infrastructure;

use Falak\Uptime\Application\Probing\ConnectOutcome;
use Falak\Uptime\Application\Probing\GuardedProber;
use Falak\Uptime\Application\Probing\HttpExchange;
use Falak\Uptime\Application\Probing\TlsHandshake;
use Falak\Uptime\Application\Probing\Transport;
use Falak\Uptime\Domain\Enums\ErrorKind;
use OpenSSLCertificate;

/**
 * curl for HTTP (pinned with CURLOPT_RESOLVE, no proxy, no redirects, body capped by the write callback) and PHP
 * streams for TCP connects and TLS handshakes.
 */
final class CurlTransport implements Transport
{
    public const USER_AGENT = 'Falak-Uptime/1.0 (+https://falak.sh)';

    private const TLS_ERRORS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(private readonly ?string $caFile = null) {}

    public function http(string $url, string $ip, int $timeoutSeconds, int $bodyBytes): HttpExchange
    {
        $parts = GuardedProber::parts($url);

        if ($parts === null) {
            return new HttpExchange(errorKind: ErrorKind::Http, error: 'Invalid URL');
        }

        [, , $port] = $parts;
        $body = '';
        $location = null;
        $capped = false;
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            // Name-agnostic pin: whatever host curl derives from the URL (it decodes %xx, the guard saw the raw form), the
            // connection goes to the checked address. A literal IP needs no lookup.
            CURLOPT_CONNECT_TO => ['::'.(str_contains($ip, ':') ? "[{$ip}]" : $ip).":{$port}"],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Cache-Control: no-cache'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$location): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$capped, $bodyBytes): int {
                $room = $bodyBytes - strlen($body);

                if ($room <= 0) {
                    $capped = true;

                    return 0;
                }

                $body .= substr($chunk, 0, $room);

                if (strlen($chunk) > $room) {
                    $capped = true;

                    return 0;
                }

                return strlen($chunk);
            },
        ]);

        if ($this->caFile !== null) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caFile);
        }

        curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $ms = fn (int $option): ?int => ($value = curl_getinfo($handle, $option)) > 0 ? (int) round($value / 1000) : null;
        $connect = $ms(CURLINFO_CONNECT_TIME_T);
        $appConnect = $ms(CURLINFO_APPCONNECT_TIME_T);
        $timings = [
            'connectMs' => $connect,
            'tlsMs' => $appConnect !== null && $connect !== null ? max(0, $appConnect - $connect) : null,
            'ttfbMs' => $ms(CURLINFO_STARTTRANSFER_TIME_T),
            'totalMs' => $ms(CURLINFO_TOTAL_TIME_T),
        ];
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $primaryIp = (string) curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $timings['primaryIp'] = $primaryIp !== '' ? $primaryIp : null;
        curl_close($handle);

        // 23: our write callback stopped the transfer at the body limit: the response is complete enough.
        if ($errno !== 0 && ! ($errno === CURLE_WRITE_ERROR && $capped)) {
            return new HttpExchange(...$timings, errorKind: self::kind($errno), error: $error !== '' ? $error : "curl error {$errno}");
        }

        return new HttpExchange($status ?: null, $location, $body, ...$timings);
    }

    public function tcp(string $ip, int $port, int $timeoutSeconds): ConnectOutcome
    {
        $started = hrtime(true);
        $socket = @stream_socket_client(self::address($ip, $port), $errno, $error, $timeoutSeconds);
        $elapsed = (int) round((hrtime(true) - $started) / 1e6);

        if ($socket === false) {
            return new ConnectOutcome(false, $elapsed, self::connectKind($error, $elapsed, $timeoutSeconds), $error !== '' ? $error : 'Connection failed');
        }

        fclose($socket);

        return new ConnectOutcome(true, $elapsed);
    }

    public function tls(string $ip, int $port, string $serverName, int $timeoutSeconds): TlsHandshake
    {
        $context = stream_context_create(['ssl' => [
            'peer_name' => $serverName,
            'SNI_enabled' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert_chain' => true,
        ]]);

        $started = hrtime(true);
        $socket = @stream_socket_client(self::address($ip, $port), $errno, $error, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);
        $connectMs = (int) round((hrtime(true) - $started) / 1e6);

        if ($socket === false) {
            return new TlsHandshake(false, $connectMs, errorKind: self::connectKind($error, $connectMs, $timeoutSeconds), error: $error !== '' ? $error : 'Connection failed');
        }

        // The handshake gets what is left of the timeout (the socket timeout bounds the blocking handshake).
        $leftMs = $timeoutSeconds * 1000 - $connectMs;

        if ($leftMs <= 0) {
            fclose($socket);

            return new TlsHandshake(false, $connectMs, 0, errorKind: ErrorKind::Timeout, error: "No TLS handshake within {$timeoutSeconds} s");
        }

        stream_set_timeout($socket, intdiv($leftMs, 1000), ($leftMs % 1000) * 1000);
        $started = hrtime(true);
        $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        $handshakeMs = (int) round((hrtime(true) - $started) / 1e6);

        if ($crypto !== true) {
            $timedOut = (stream_get_meta_data($socket)['timed_out'] ?? false) || $handshakeMs >= $leftMs - 50;
            fclose($socket);
            $message = error_get_last()['message'] ?? 'TLS handshake failed';

            return $timedOut
                ? new TlsHandshake(false, $connectMs, $handshakeMs, errorKind: ErrorKind::Timeout, error: "No TLS handshake within {$timeoutSeconds} s")
                : new TlsHandshake(false, $connectMs, $handshakeMs, errorKind: ErrorKind::Tls, error: mb_substr($message, 0, 300));
        }

        $chain = [];

        foreach (stream_context_get_params($socket)['options']['ssl']['peer_certificate_chain'] ?? [] as $certificate) {
            if ($certificate instanceof OpenSSLCertificate && openssl_x509_export($certificate, $pem)) {
                $chain[] = $pem;
            }
        }

        fclose($socket);

        return new TlsHandshake(true, $connectMs, $handshakeMs, $chain);
    }

    private static function address(string $ip, int $port): string
    {
        return 'tcp://'.(str_contains($ip, ':') ? "[{$ip}]" : $ip).":{$port}";
    }

    private static function kind(int $errno): ErrorKind
    {
        return match (true) {
            $errno === 28 => ErrorKind::Timeout,
            $errno === 6 => ErrorKind::Dns,
            $errno === 7 => ErrorKind::Connect,
            $errno === 47 => ErrorKind::Redirects,
            in_array($errno, self::TLS_ERRORS, true) => ErrorKind::Tls,
            default => ErrorKind::Http,
        };
    }

    private static function connectKind(string $error, int $elapsedMs, int $timeoutSeconds): ErrorKind
    {
        return str_contains(strtolower($error), 'timed out') || $elapsedMs >= $timeoutSeconds * 1000 ? ErrorKind::Timeout : ErrorKind::Connect;
    }
}
