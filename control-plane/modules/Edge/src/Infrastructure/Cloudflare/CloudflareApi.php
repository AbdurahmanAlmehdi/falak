<?php

namespace Falak\Edge\Infrastructure\Cloudflare;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Cloudflare API v4 with one API token. Every call returns Cloudflare's `result` or throws CloudflareError with the
 * API's own message, so it can be shown to the user as is.
 */
final class CloudflareApi
{
    public const BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    public static function with(#[SensitiveParameter] string $token): self
    {
        return new self($token);
    }

    /** Token status ("active") — user tokens verify at /user/tokens, account tokens at /accounts/{id}/tokens. */
    public function verify(): string
    {
        try {
            return (string) ($this->call('GET', '/user/tokens/verify')['status'] ?? '');
        } catch (CloudflareError $e) {
            if ($e->status !== 401 && $e->status !== 403) {
                throw $e;
            }
            $accounts = $this->call('GET', '/accounts', ['per_page' => 5]);
            if (($id = $accounts[0]['id'] ?? null) === null) {
                throw $e;
            }

            return (string) ($this->call('GET', "/accounts/{$id}/tokens/verify")['status'] ?? '');
        }
    }

    /**
     * Zones the token can see.
     *
     * @return list<array{id: string, name: string, status: string, plan: string, account_id: ?string}>
     */
    public function zones(): array
    {
        $zones = [];

        for ($page = 1; $page <= 20; $page++) {
            $batch = $this->call('GET', '/zones', ['per_page' => 50, 'page' => $page]);
            foreach ($batch as $zone) {
                $zones[] = ['id' => (string) $zone['id'], 'name' => (string) $zone['name'], 'status' => (string) ($zone['status'] ?? ''),
                    'plan' => (string) ($zone['plan']['name'] ?? ''), 'account_id' => isset($zone['account']['id']) ? (string) $zone['account']['id'] : null];
            }
            if (count($batch) < 50) {
                break;
            }
        }

        return $zones;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(string $zoneId, string $name): array
    {
        return array_values($this->call('GET', "/zones/{$zoneId}/dns_records", ['name.exact' => $name, 'per_page' => 100]));
    }

    /**
     * @param  array{type: string, name: string, content: string, proxied: bool, comment: string}  $record
     * @return array<string, mixed>
     */
    public function createRecord(string $zoneId, array $record): array
    {
        return $this->call('POST', "/zones/{$zoneId}/dns_records", [...$record, 'ttl' => 1]);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateRecord(string $zoneId, string $recordId, array $fields): array
    {
        return $this->call('PATCH', "/zones/{$zoneId}/dns_records/{$recordId}", $fields);
    }

    /** Deleting a record that is already gone is not an error. */
    public function deleteRecord(string $zoneId, string $recordId): void
    {
        try {
            $this->call('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");
        } catch (CloudflareError $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    public function setting(string $zoneId, string $key): mixed
    {
        return $this->call('GET', "/zones/{$zoneId}/settings/{$key}")['value'] ?? null;
    }

    public function updateSetting(string $zoneId, string $key, mixed $value): mixed
    {
        return $this->call('PATCH', "/zones/{$zoneId}/settings/{$key}", ['value' => $value])['value'] ?? null;
    }

    /**
     * The zone's plan: free | pro | business | enterprise (Cloudflare's legacy_id).
     */
    public function plan(string $zoneId): string
    {
        $zone = $this->call('GET', "/zones/{$zoneId}");

        return strtolower((string) ($zone['plan']['legacy_id'] ?? 'free')) ?: 'free';
    }

    /**
     * A zone's entry point ruleset of a phase (e.g. http_request_cache_settings); empty when there is none yet.
     *
     * @return array<string, mixed>
     */
    public function ruleset(string $zoneId, string $phase): array
    {
        try {
            return $this->call('GET', "/zones/{$zoneId}/rulesets/phases/{$phase}/entrypoint");
        } catch (CloudflareError $e) {
            if ($e->status === 404 || $e->cloudflareCode === 10003) {
                return ['rules' => []];
            }
            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public function putRuleset(string $zoneId, string $phase, array $rules): void
    {
        $this->call('PUT', "/zones/{$zoneId}/rulesets/phases/{$phase}/entrypoint", ['rules' => $rules]);
    }

    /**
     * @param  list<string>  $hosts  at most 30
     */
    public function purgeHosts(string $zoneId, array $hosts): void
    {
        $this->call('POST', "/zones/{$zoneId}/purge_cache", ['hosts' => array_values($hosts)]);
    }

    /** A remotely managed tunnel (routes configured through the API). Returns its id. */
    public function createTunnel(string $accountId, string $name): string
    {
        return (string) $this->call('POST', "/accounts/{$accountId}/cfd_tunnel", ['name' => $name, 'config_src' => 'cloudflare'])['id'];
    }

    /** The token cloudflared runs the tunnel with. */
    public function tunnelToken(string $accountId, string $tunnelId): string
    {
        $client = $this->client();
        $body = $client->get("/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/token")->json();

        if (! is_array($body) || ($body['success'] ?? false) !== true || ! is_string($body['result'] ?? null)) {
            throw new CloudflareError('Cloudflare: could not read the tunnel token.', 0);
        }

        return $body['result'];
    }

    /**
     * @param  list<array<string, mixed>>  $ingress  rules, ending with a catch-all
     */
    public function configureTunnel(string $accountId, string $tunnelId, array $ingress): void
    {
        $this->call('PUT', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/configurations", ['config' => ['ingress' => $ingress]]);
    }

    /**
     * @return array{status: string, connections: int}
     */
    public function tunnel(string $accountId, string $tunnelId): array
    {
        $tunnel = $this->call('GET', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");

        return ['status' => (string) ($tunnel['status'] ?? 'unknown'), 'connections' => count((array) ($tunnel['connections'] ?? []))];
    }

    /** Deletes a tunnel after dropping its connections; a tunnel that is already gone is not an error. */
    public function deleteTunnel(string $accountId, string $tunnelId): void
    {
        try {
            $this->call('DELETE', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}/connections");
        } catch (CloudflareError $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }

        try {
            $this->call('DELETE', "/accounts/{$accountId}/cfd_tunnel/{$tunnelId}");
        } catch (CloudflareError $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
    }

    /**
     * GraphQL Analytics API (POST /graphql): the `data` object. Errors in the body throw CloudflareGraphqlError (with
     * the partial data); transport failures and HTTP failures without a body throw CloudflareError.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        try {
            $response = $this->client()->retry(2, 400, throw: false)->post('/graphql', ['query' => $query, 'variables' => (object) $variables]);
        } catch (ConnectionException $e) {
            throw new CloudflareError('Cloudflare is unreachable: '.$e->getMessage(), 0);
        }

        $body = $response->json();
        $data = is_array($body) && is_array($body['data'] ?? null) ? $body['data'] : [];
        $errors = is_array($body) && is_array($body['errors'] ?? null) ? array_values($body['errors']) : [];

        if ($errors !== []) {
            // REST-style errors (403 {code: 10000, message}) and GraphQL errors ({message, path, extensions.code}).
            $list = array_map(fn ($e) => [
                'message' => (string) (is_array($e) ? ($e['message'] ?? '') : $e),
                'path' => is_array($e) && is_array($e['path'] ?? null) ? array_values($e['path']) : [],
                'code' => match (true) {
                    ! is_array($e) => null,
                    isset($e['extensions']['code']) => (string) $e['extensions']['code'],
                    isset($e['code']) => (string) $e['code'],
                    default => null,
                },
            ], $errors);

            throw new CloudflareGraphqlError($list, $data, $response->status());
        }

        if ($response->failed()) {
            throw new CloudflareError("Cloudflare: HTTP {$response->status()}", $response->status());
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  query (GET) or JSON body
     * @return array<int|string, mixed>
     */
    private function call(string $method, string $path, array $data = []): array
    {
        $idempotent = in_array($method, ['GET', 'DELETE'], true);

        try {
            $request = $this->client()->retry($idempotent ? 3 : 1, 400, throw: false);
            $response = $method === 'GET' ? $request->get($path, $data) : $request->send($method, $path, $data === [] ? [] : ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new CloudflareError('Cloudflare is unreachable: '.$e->getMessage(), 0);
        }

        $body = $response->json();

        if ($response->failed() || ! is_array($body) || ($body['success'] ?? false) !== true) {
            $errors = is_array($body['errors'] ?? null) ? $body['errors'] : [];
            $message = implode('; ', array_map(fn ($e) => (string) (is_array($e) ? ($e['message'] ?? '') : $e), $errors)) ?: "HTTP {$response->status()}";

            throw new CloudflareError("Cloudflare: {$message}", $response->status(), (int) ($errors[0]['code'] ?? 0));
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withToken($this->token)->acceptJson()->timeout(20)->connectTimeout(8);
    }
}
