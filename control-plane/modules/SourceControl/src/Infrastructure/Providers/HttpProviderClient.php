<?php

namespace Falak\SourceControl\Infrastructure\Providers;

use DateTimeImmutable;
use Exception;
use Falak\Kernel\Network\EndpointGuard;
use Falak\Kernel\Network\EndpointRefused;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Shared HTTP plumbing for API-backed providers.
 */
abstract class HttpProviderClient implements ProviderClient
{
    public function __construct(protected readonly AccessTokens $tokens) {}

    abstract protected function label(): string;

    abstract protected function request(Connection $connection): PendingRequest;

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    protected function send(Connection $connection, string $method, string $path, array $query = [], array $body = [], bool $nullOn404 = false): ?Response
    {
        $request = $this->request($connection)->acceptJson()->timeout(20);

        // A connection's own server (GitHub Enterprise, self-hosted GitLab) is a tenant's URL: on a managed install it
        // goes through the EndpointGuard (public addresses only, pinned, no redirects, no proxy). Absolute paths (next
        // page links) are checked as given. Self-hosted panels keep reaching servers on their LAN.
        if ($connection->base_url && config('controlplane.managed')) {
            $target = preg_match('#^https?://#i', $path) === 1 ? $path : (string) $connection->base_url;

            try {
                $addresses = app(EndpointGuard::class)->check($target, false, ['http', 'https']);
            } catch (EndpointRefused $e) {
                throw SourceControlException::provider($this->label(), $e->getMessage());
            }

            $request = $request->withoutRedirecting()->withOptions(['curl' => EndpointGuard::pin($target, $addresses[0]), 'proxy' => '']);
        }

        try {
            $response = match ($method) {
                // An empty `query` option would strip the query string of absolute "next page" URLs.
                'GET' => $query === [] ? $request->get($path) : $request->get($path, $query),
                'DELETE' => $request->delete($path, $body),
                default => $request->send($method, $path, array_filter(['query' => $query, 'json' => $body])),
            };
        } catch (ConnectionException $e) {
            throw SourceControlException::provider($this->label(), 'Could not reach the API: '.$e->getMessage());
        }

        if ($nullOn404 && $response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw $this->error($response);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    protected function json(Connection $connection, string $path, array $query = [], bool $nullOn404 = false): ?array
    {
        $response = $this->send($connection, 'GET', $path, $query, nullOn404: $nullOn404);

        return $response === null ? null : (array) $response->json();
    }

    protected function error(Response $response): SourceControlException
    {
        $body = $response->json();
        $detail = is_array($body) ? ($body['message'] ?? $body['error_description'] ?? $body['error']['message'] ?? $body['error'] ?? null) : null;
        $detail = is_array($detail) ? json_encode($detail) : $detail;

        $message = match ($response->status()) {
            401 => 'Authentication failed; reconnect the account.',
            403 => 'Permission denied'.($detail ? ": {$detail}" : '.'),
            404 => 'Repository or resource not found (or the account has no access).',
            422 => 'The provider rejected the request'.($detail ? ": {$detail}" : '.'),
            default => (string) ($detail ?: 'Unexpected API error.'),
        };

        return SourceControlException::provider($this->label(), $message, $response->status());
    }

    /** "dir/my file.yml" → "dir/my%20file.yml" (each segment encoded, slashes kept). */
    protected static function encodedPath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($path, '/'))));
    }

    protected function tooLarge(string $path, int $maxBytes): SourceControlException
    {
        return SourceControlException::provider($this->label(), "{$path} is larger than ".intdiv($maxBytes, 1024).' KB.');
    }

    protected function maxPages(): int
    {
        return max(1, (int) config('source_control.max_pages', 10));
    }

    protected static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    protected static function host(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
    }

    protected static function matches(string $name, ?string $search): bool
    {
        return $search === null || $search === '' || str_contains(strtolower($name), strtolower($search));
    }
}
