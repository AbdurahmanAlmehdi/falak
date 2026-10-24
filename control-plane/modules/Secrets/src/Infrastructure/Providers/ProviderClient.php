<?php

namespace Falak\Secrets\Infrastructure\Providers;

use Falak\Kernel\Network\EndpointGuard;
use Falak\Kernel\Network\EndpointRefused;
use Falak\Secrets\Domain\Models\SecretProvider;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The one way drivers talk to providers: https only, through the {@see EndpointGuard} (and pinned to the
 * addresses it checked), TLS verification always on (a provider's own CA when it has one), no redirects,
 * 5 s to connect and 10 s in total, one retry on connection errors, 429 and 5xx.
 */
class ProviderClient
{
    /** The largest answer read from a provider (bytes). */
    public const MAX_BODY = 1048576;

    public function __construct(private readonly EndpointGuard $guard) {}

    /**
     * `unguarded` is for a fixed address of our own (the EC2 instance metadata service), never a user's URL.
     *
     * @param  array{headers?: array<string, string>, query?: array<string, scalar>, json?: array<mixed>, body?: string, content_type?: string, unguarded?: bool}  $options
     *
     * @throws ProviderFailure when the endpoint is refused or unreachable (any HTTP answer is returned)
     */
    public function send(SecretProvider $provider, string $method, string $url, array $options = []): Response
    {
        if (($options['query'] ?? []) !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
        }

        $request = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->acceptJson()
            ->withHeaders($options['headers'] ?? [])
            ->retry(2, max(0, (int) config('secrets.providers.retry_delay_ms', 200)), fn (Throwable $e) => self::transient($e), throw: false);

        $curl = [];
        $caFile = null;

        if (! ($options['unguarded'] ?? false)) {
            // Both the provider and the instance must allow private networks, at request time (the instance may have
            // turned it off since the provider was saved); never on a managed install.
            $allowPrivate = $provider->allow_private_network && $provider->type->selfHostable() && (bool) config('secrets.providers.allow_private_network', false) && ! config('controlplane.managed');
            try {
                $addresses = $this->guard->check($url, $allowPrivate);
            } catch (EndpointRefused $e) {
                throw new ProviderFailure($e->getMessage());
            }
            $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');

            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $port = (int) (parse_url($url, PHP_URL_PORT) ?: 443);
                $curl[CURLOPT_RESOLVE] = array_map(fn (string $ip) => "{$host}:{$port}:".(str_contains($ip, ':') ? "[{$ip}]" : $ip), $addresses);
            }

            $ca = $provider->setting('ca_pem');

            if ($ca !== null) {
                $caFile = tempnam(sys_get_temp_dir(), 'falak-ca-');
                file_put_contents((string) $caFile, $ca);
                $request = $request->withOptions(['verify' => $caFile]);
            }
        }

        // Answers are small JSON documents: anything bigger than MAX_BODY is cut off while it downloads.
        $curl[CURLOPT_MAXFILESIZE] = self::MAX_BODY;
        $request = $request->withOptions([
            'curl' => $curl,
            'progress' => function (int $total, int $downloaded): void {
                if ($downloaded > self::MAX_BODY || $total > self::MAX_BODY) {
                    throw new ProviderFailure('The provider\'s answer is larger than 1 MiB.');
                }
            },
        ]);

        if (isset($options['json'])) {
            $request = $request->asJson()->withBody((string) json_encode($options['json'], JSON_UNESCAPED_SLASHES), 'application/json');
        } elseif (isset($options['body'])) {
            $request = $request->withBody($options['body'], $options['content_type'] ?? 'application/octet-stream');
        }

        try {
            $response = $request->send($method, $url);
        } catch (ConnectionException|TransferException) {
            // Not the exception's message: it may carry the full URL.
            throw new ProviderFailure("{$provider->type->label()} at ".parse_url($url, PHP_URL_HOST).' is unreachable (or its answer was too large).');
        } finally {
            if ($caFile !== null) {
                @unlink($caFile);
            }
        }

        if (strlen($response->body()) > self::MAX_BODY) {
            throw new ProviderFailure('The provider\'s answer is larger than 1 MiB.');
        }

        return $response;
    }

    /**
     * Why a provider answered with an error, without echoing its body (it may quote a credential or a value).
     */
    public static function failure(SecretProvider $provider, Response $response, string $what): ProviderFailure
    {
        $label = $provider->type->label();
        $status = $response->status();

        return new ProviderFailure(match (true) {
            $status === 401 || $status === 403 => "{$label} refused access to {$what} (HTTP {$status}): check the credentials and their permissions.",
            $status === 404 => "{$what} was not found at {$label} (HTTP 404).",
            $status === 429 => "{$label} is rate limiting requests (HTTP 429).",
            $status >= 500 => "{$label} is unavailable (HTTP {$status}).",
            default => "{$label} answered HTTP {$status} for {$what}.",
        });
    }

    /** Retry connection errors, 429 and 5xx once; anything else fails the same way again. */
    public static function transient(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
    }
}
