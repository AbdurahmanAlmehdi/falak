<?php

namespace Falak\Databases\Infrastructure\ObjectStorage;

use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Kernel\Network\EndpointGuard;
use Falak\Kernel\Network\EndpointRefused;
use Falak\Kernel\Support\Aws\SigV4Signer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * Minimal S3-compatible client: presigned PUT/GET URLs for agents and header-signed requests
 * issued by the control plane itself (verification probe, pruning deletes).
 */
final class ObjectStore
{
    public function __construct(
        private readonly StorageProvider $provider,
        private readonly HttpFactory $http,
        private readonly int $timeout = 30,
        private readonly EndpointGuard $guard = new EndpointGuard,
        // Private endpoints (a MinIO on the LAN): databases.allow_private_endpoints.
        private readonly bool $allowPrivate = true,
    ) {}

    /**
     * The https endpoint for a driver (without the bucket).
     */
    public static function endpointFor(StorageDriver $driver, string $region, ?string $endpoint = null, ?string $accountId = null): string
    {
        return rtrim(match ($driver) {
            StorageDriver::S3 => "https://s3.{$region}.amazonaws.com",
            StorageDriver::R2 => $accountId !== null && $accountId !== '' ? "https://{$accountId}.r2.cloudflarestorage.com" : (string) $endpoint,
            StorageDriver::B2 => "https://s3.{$region}.backblazeb2.com",
            StorageDriver::Spaces => "https://{$region}.digitaloceanspaces.com",
            StorageDriver::Minio => (string) $endpoint,
        }, '/');
    }

    public function provider(): StorageProvider
    {
        return $this->provider;
    }

    /**
     * Object key under the provider's prefix.
     */
    public function key(string ...$segments): string
    {
        $parts = array_filter([trim((string) $this->provider->prefix, '/'), ...array_map(fn (string $s) => trim($s, '/'), $segments)], fn (string $s) => $s !== '');

        return implode('/', $parts);
    }

    public function url(string $key): string
    {
        $endpoint = rtrim((string) ($this->provider->endpoint ?: self::endpointFor($this->provider->driver, $this->provider->region)), '/');
        $bucket = $this->provider->bucket;
        $path = '/'.ltrim($key, '/');

        // Dotted bucket names break TLS for virtual-hosted style (the wildcard cert covers one label).
        if ($this->provider->path_style || str_contains($bucket, '.')) {
            return "{$endpoint}/{$bucket}{$path}";
        }

        $parts = (array) parse_url($endpoint);
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return ($parts['scheme'] ?? 'https').'://'.$bucket.'.'.($parts['host'] ?? '').$port.$path;
    }

    public function presignPut(string $key, int $ttlSeconds): string
    {
        return $this->signer()->presign('PUT', $this->url($key), $ttlSeconds);
    }

    public function presignGet(string $key, int $ttlSeconds): string
    {
        return $this->signer()->presign('GET', $this->url($key), $ttlSeconds);
    }

    /**
     * @throws StorageRequestFailed
     */
    public function put(string $key, string $body, string $contentType = 'application/octet-stream'): void
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('PUT', $url, ['content-type' => $contentType], hash('sha256', $body));
        // Content-Type is signed, but withBody() sets it: passed in the headers too, it went out twice
        // ("text/plain, text/plain"), which no longer matched the signature (SignatureDoesNotMatch).
        $headers = array_filter($headers, fn (string $name) => strtolower($name) !== 'content-type', ARRAY_FILTER_USE_KEY);

        $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->timeout($this->timeout)->withBody($body, $contentType)->put($url), 'PUT', $key);
    }

    /**
     * Delete an object. A missing object counts as deleted.
     *
     * @throws StorageRequestFailed
     */
    public function delete(string $key): void
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('DELETE', $url);

        $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->timeout($this->timeout)->delete($url), 'DELETE', $key, allowNotFound: true);
    }

    /**
     * The stored size of an object (HEAD), null when there is none.
     *
     * @throws StorageRequestFailed
     */
    public function size(string $key): ?int
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('HEAD', $url);
        $response = $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->timeout($this->timeout)->head($url), 'HEAD', $key, allowNotFound: true);

        return $response->status() === 404 ? null : (int) $response->header('Content-Length');
    }

    /**
     * The SHA-256 of an object's content (GET; for small objects: it is held in memory).
     *
     * @throws StorageRequestFailed
     */
    public function sha256(string $key): string
    {
        $url = $this->url($key);
        $headers = $this->signer()->signHeaders('GET', $url);

        return hash('sha256', $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->timeout($this->timeout)->get($url), 'GET', $key)->body());
    }

    /**
     * The request goes to the address the guard checked (pinned) and follows no redirects (their target is unchecked).
     *
     * @param  callable(PendingRequest): Response  $request
     *
     * @throws StorageRequestFailed
     */
    private function send(callable $request, string $method, string $key, bool $allowNotFound = false): Response
    {
        $url = $this->url($key);

        try {
            $addresses = $this->guard->check($url, $this->allowPrivate);
        } catch (EndpointRefused $e) {
            throw new StorageRequestFailed($e->getMessage());
        }

        try {
            $response = $request($this->http->withoutRedirecting()->withOptions(['curl' => EndpointGuard::pin($url, $addresses[0])]));
        } catch (ConnectionException $e) {
            throw new StorageRequestFailed("{$method} {$key}: could not connect to the storage endpoint ({$e->getMessage()}).", 0, $e);
        }

        if ($response->successful() || ($allowNotFound && $response->status() === 404)) {
            return $response;
        }

        $code = null;

        if (preg_match('#<Code>([^<]+)</Code>#', $response->body(), $m) === 1) {
            $code = $m[1];
        }

        throw new StorageRequestFailed("{$method} {$key} failed: HTTP {$response->status()}".($code ? " ({$code})" : '').'.');
    }

    private function signer(): SigV4Signer
    {
        return new SigV4Signer(
            $this->provider->access_key_id,
            $this->provider->secret_access_key,
            $this->provider->region,
        );
    }
}
