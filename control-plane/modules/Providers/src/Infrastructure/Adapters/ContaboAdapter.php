<?php

namespace Falak\Providers\Infrastructure\Adapters;

use Falak\Providers\Contracts\Data\Image;
use Falak\Providers\Contracts\Data\Machine;
use Falak\Providers\Contracts\Data\MachineSpec;
use Falak\Providers\Contracts\Data\Region;
use Falak\Providers\Contracts\Data\Size;
use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Contabo API v1 — https://api.contabo.com/
 *
 * Differences from the hourly clouds: OAuth2 password grant (four values, pasted as one block), a request id header
 * on every call, no product catalog API (sizes are the documented product ids), and monthly contracts: deleting a
 * server cancels it, and Contabo keeps it running until the end of the paid term.
 */
final class ContaboAdapter extends HttpProviderAdapter
{
    private const UBUNTU_VERSIONS = ['22.04', '24.04', '26.04'];

    /** Region id => name (the API's create-instance enum). */
    private const REGIONS = [
        'EU' => ['European Union (Germany)', 'DE'],
        'UK' => ['United Kingdom', 'GB'],
        'US-central' => ['United States (Central)', 'US'],
        'US-east' => ['United States (East)', 'US'],
        'US-west' => ['United States (West)', 'US'],
        'SIN' => ['Singapore', 'SG'],
        'JPN' => ['Japan', 'JP'],
        'AUS' => ['Australia', 'AU'],
        'IND' => ['India', 'IN'],
    ];

    /**
     * Product id => [name, disk GB] from the create-instance docs (Storage VPS cannot be ordered through the API).
     * ponytail: CPU/RAM/price are not exposed by the API, so they stay unknown (0 / null); update with Contabo's docs.
     */
    private const PRODUCTS = [
        'V153' => ['Cloud VPS 4', 100, 'SSD'],
        'V154' => ['Cloud VPS 6', 200, 'SSD'],
        'V155' => ['Cloud VPS 8', 300, 'SSD'],
        'V156' => ['Cloud VPS 12', 400, 'SSD'],
        'V157' => ['Cloud VPS 16', 500, 'SSD'],
        'V158' => ['Cloud VPS 18', 600, 'SSD'],
        'V159' => ['Cloud VPS Plus 4', 150, 'NVMe'],
        'V160' => ['Cloud VPS Plus 6', 300, 'NVMe'],
        'V161' => ['Cloud VPS Plus 8', 450, 'NVMe'],
        'V162' => ['Cloud VPS Plus 12', 600, 'NVMe'],
        'V163' => ['Cloud VPS Plus 16', 750, 'NVMe'],
        'V164' => ['Cloud VPS Plus 18', 900, 'NVMe'],
        'V8' => ['VDS S', 180, 'NVMe'],
        'V9' => ['VDS M', 240, 'NVMe'],
        'V10' => ['VDS L', 360, 'NVMe'],
        'V11' => ['VDS XL', 480, 'NVMe'],
        'V16' => ['VDS XXL', 720, 'NVMe'],
    ];

    /** Pasted-text key => credential (cntb flags, the Customer Control Panel labels, the docs' shell variables). */
    private const KEYS = [
        'oauth2clientid' => 'client_id', 'clientid' => 'client_id',
        'oauth2clientsecret' => 'client_secret', 'clientsecret' => 'client_secret',
        'oauth2user' => 'user', 'apiuser' => 'user', 'username' => 'user', 'user' => 'user',
        'oauth2password' => 'password', 'apipassword' => 'password', 'password' => 'password',
    ];

    private const LABELS = [
        'client_id' => 'Client ID',
        'client_secret' => 'Client Secret',
        'user' => 'API User',
        'password' => 'API Password',
    ];

    private ?string $accessToken = null;

    /**
     * @param  array{client_id: string, client_secret: string, user: string, password: string}  $credentials
     * @param  array<string, int>  $http
     */
    public function __construct(
        private readonly array $credentials,
        private readonly string $baseUrl = 'https://api.contabo.com/v1',
        private readonly string $authUrl = 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token',
        array $http = [],
    ) {
        parent::__construct($http);
    }

    /**
     * Reads the four API values from whatever the user pasted: the `cntb config set-credentials …` command from the
     * Contabo API page, the labelled values (ClientId / Client Secret / API User / API Password), a cntb YAML config,
     * KEY=value lines, or JSON.
     *
     * @return array{client_id: string, client_secret: string, user: string, password: string}
     *
     * @throws ProviderException when a value is missing or still a placeholder
     */
    public static function parseCredentials(string $details): array
    {
        $found = [];
        $decoded = json_decode($details, true);

        if (is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $name = self::KEYS[strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key))] ?? null;

                if ($name !== null && is_scalar($value) && ! isset($found[$name])) {
                    $found[$name] = trim((string) $value);
                }
            }
        } else {
            // key, then `=` / `:` / whitespace, then a quoted or bare value. Longest keys first so "API Password"
            // wins over "password", and a key must start a word (after whitespace, a quote, `-` or a line start).
            preg_match_all(
                '/(?<![A-Za-z0-9_])-{0,2}(oauth2[-_ ]?client[-_ ]?id|oauth2[-_ ]?client[-_ ]?secret|oauth2[-_ ]?user|oauth2[-_ ]?password|client[-_ ]?id|client[-_ ]?secret|api[-_ ]?user|api[-_ ]?password|username|password|user)\s*(?:[:=]\s*|\s+)(\'[^\']*\'|"[^"]*"|[^\s\'"]+)/i',
                $details,
                $matches,
                PREG_SET_ORDER,
            );

            // `Label: value` on its own line (panel labels, cntb YAML): the value is the rest of the line, so an
            // unquoted password with spaces survives.
            preg_match_all('/^[ \t]*(client[-_ ]?id|client[-_ ]?secret|api[-_ ]?user|api[-_ ]?password|oauth2[-\w]+)[ \t]*:[ \t]*(\S.*?)[ \t]*\r?$/im', $details, $lines, PREG_SET_ORDER);
            $matches = [...$lines, ...$matches];

            foreach ($matches as [, $key, $value]) {
                $name = self::KEYS[strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key))] ?? null;
                $value = trim($value);

                if ($name === null) {
                    continue;
                }

                if (strlen($value) >= 2 && ($value[0] === "'" || $value[0] === '"') && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }

                $found[$name] ??= $value;
            }
        }

        $missing = [];

        foreach (self::LABELS as $name => $label) {
            $value = $found[$name] ?? '';

            // Placeholders from the docs: <ClientId from …>, $API_USER, YOUR_PASSWORD.
            if ($value === '' || str_starts_with($value, '<') || str_starts_with($value, '$') || preg_match('/^(your|x{3,})/i', $value)) {
                $missing[] = $label;
            }
        }

        if ($missing !== []) {
            throw new ProviderException(
                'Missing '.implode(', ', $missing).'. On my.contabo.com → Account → API, set an API password, then paste the whole `cntb config set-credentials …` line (with your password filled in), or the four values.',
                ProviderType::Contabo->value,
            );
        }

        /** @var array{client_id: string, client_secret: string, user: string, password: string} $found */
        return [
            'client_id' => $found['client_id'],
            'client_secret' => $found['client_secret'],
            'user' => $found['user'],
            'password' => $found['password'],
        ];
    }

    public function type(): ProviderType
    {
        return ProviderType::Contabo;
    }

    public function verify(): void
    {
        $this->call('GET', 'compute/instances', ['page' => 1, 'size' => 1]);
    }

    public function regions(): array
    {
        return array_map(
            fn (string $id) => new Region(id: $id, name: self::REGIONS[$id][0], country: self::REGIONS[$id][1]),
            array_keys(self::REGIONS),
        );
    }

    public function sizes(?string $region = null): array
    {
        $sizes = [];

        foreach (self::PRODUCTS as $id => [$name, $disk, $kind]) {
            $sizes[] = new Size(id: $id, name: "{$name} — {$disk} GB {$kind}", cpus: 0, memoryMb: 0, diskGb: $disk);
        }

        return $sizes;
    }

    public function images(): array
    {
        $images = [];

        foreach ($this->paginate('compute/images', ['standardImage' => 'true']) as $image) {
            $name = strtolower((string) ($image['name'] ?? ''));
            $version = (string) ($image['version'] ?? '');

            if (! preg_match('/^\d+\.\d+/', $version) && preg_match('/(\d{2}\.\d{2})/', $name, $m)) {
                $version = $m[1];
            }

            if (! str_contains($name, 'ubuntu') || str_contains($name, 'gpu') || ! empty($image['gpu']) || ! in_array(substr($version, 0, 5), self::UBUNTU_VERSIONS, true)) {
                continue;
            }

            $images[] = new Image(
                id: (string) $image['imageId'],
                name: (string) ($image['description'] ?? '') !== '' ? (string) $image['description'] : (string) $image['name'],
                distribution: 'ubuntu',
                version: substr($version, 0, 5),
                arch: 'amd64',
            );
        }

        return $images;
    }

    public function createServer(MachineSpec $spec): Machine
    {
        $response = $this->call('POST', 'compute/instances', array_filter([
            'imageId' => $spec->image,
            'productId' => $spec->size,
            'region' => $spec->region,
            'sshKeys' => array_map(intval(...), $spec->sshKeyIds) ?: null,
            'userData' => $spec->userData,
            'displayName' => substr($this->hostname($spec->name), 0, 255),
            'period' => 1,
        ], fn ($value) => $value !== null));

        return $this->machine((array) $response?->json('data.0'));
    }

    public function getServer(string $id): ?Machine
    {
        $instance = $this->instance($id);

        return $instance ? $this->machine($instance) : null;
    }

    /** Cancels the contract (Contabo stops the machine at the end of the paid term); already cancelled = done. */
    public function destroyServer(string $id): void
    {
        $instance = $this->instance($id);

        if ($instance === null || ! empty($instance['cancelDate'])) {
            return;
        }

        // The cancel body must be a JSON object; call() would send an empty array as `[]`.
        try {
            $response = $this->prepare($this->client(), idempotent: false)->withBody('{}', 'application/json')->post("compute/instances/{$id}/cancel");
        } catch (ConnectionException $e) {
            throw new ProviderException("Contabo API is unreachable: {$e->getMessage()}", $this->type()->value, null, $e);
        }

        if ($response->failed()) {
            throw $this->exception($response);
        }
    }

    public function uploadSshKey(string $name, string $publicKey): string
    {
        $key = SshKey::normalize($publicKey);

        foreach ($this->paginate('secrets', ['type' => 'ssh']) as $secret) {
            if (SshKey::equals((string) ($secret['value'] ?? ''), $key)) {
                return (string) $secret['secretId'];
            }
        }

        $response = $this->call('POST', 'secrets', ['name' => $name.'-'.Str::lower(Str::random(6)), 'value' => $key, 'type' => 'ssh']);

        return (string) $response?->json('data.0.secretId');
    }

    public function deleteSshKey(string $id): void
    {
        $this->call('DELETE', "secrets/{$id}", allowNotFound: true);
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token())
            ->withHeaders(['x-request-id' => (string) Str::uuid()]);
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error_description') ?? $response->json('error');

        return is_array($message) ? implode('; ', array_map(strval(...), $message)) : (string) ($message ?? $response->reason());
    }

    /** OAuth2 password grant; tokens last minutes, adapters live for one job or request, so one per instance is enough. */
    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        try {
            $response = $this->prepare(Http::asForm(), idempotent: false)->post($this->authUrl, [
                'grant_type' => 'password',
                'client_id' => $this->credentials['client_id'],
                'client_secret' => $this->credentials['client_secret'],
                'username' => $this->credentials['user'],
                'password' => $this->credentials['password'],
            ]);
        } catch (ConnectionException $e) {
            throw new ProviderException("Contabo login is unreachable: {$e->getMessage()}", $this->type()->value, null, $e);
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            $status = $response->failed() ? $response->status() : 401;
            $reason = (string) ($response->json('error_description') ?? $response->json('error') ?? $response->reason());

            throw new ProviderException(
                "Contabo login failed: {$reason}. Check the Client ID/Secret and the API User/Password on my.contabo.com → Account → API.",
                $this->type()->value,
                in_array($status, [400, 401], true) ? 401 : $status,
            );
        }

        return $this->accessToken = $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function instance(string $id): ?array
    {
        $response = $this->call('GET', "compute/instances/{$id}", allowNotFound: true);
        $instance = $response?->json('data.0');

        return is_array($instance) ? $instance : null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, array $query = []): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->call('GET', $path, [...$query, 'page' => $page, 'size' => 100]);
            array_push($items, ...(array) $response?->json('data', []));
            $pages = (int) ($response?->json('_pagination.totalPages') ?? 1);
            $page++;
        } while ($page <= $pages && $page <= 50);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $instance
     */
    private function machine(array $instance): Machine
    {
        if (! isset($instance['instanceId'])) {
            throw new ProviderException('Contabo: unexpected response (missing instance id).', $this->type()->value);
        }

        return new Machine(
            id: (string) $instance['instanceId'],
            name: (string) ($instance['displayName'] ?? $instance['name'] ?? ''),
            status: match ($instance['status'] ?? null) {
                'provisioning', 'installing', 'manual_provisioning', 'pending_payment', 'verification_required', 'reset_password' => Machine::STATUS_PROVISIONING,
                'running' => Machine::STATUS_RUNNING,
                'stopped', 'rescue' => Machine::STATUS_STOPPED,
                default => Machine::STATUS_UNKNOWN,
            },
            ipv4: ($instance['ipConfig']['v4']['ip'] ?? null) ?: null,
            ipv6: ($instance['ipConfig']['v6']['ip'] ?? null) ?: null,
            region: isset($instance['region']) ? (string) $instance['region'] : null,
        );
    }
}
