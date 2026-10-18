<?php

namespace Falak\Providers\Infrastructure;

use Falak\Providers\Contracts\Exceptions\ProviderException;
use Falak\Providers\Contracts\ProviderAdapter;
use Falak\Providers\Contracts\ProviderType;
use Falak\Providers\Infrastructure\Adapters\ContaboAdapter;
use Falak\Providers\Infrastructure\Adapters\CustomAdapter;
use Falak\Providers\Infrastructure\Adapters\DigitalOceanAdapter;
use Falak\Providers\Infrastructure\Adapters\HetznerAdapter;
use Falak\Providers\Infrastructure\Adapters\LightsailAdapter;
use Falak\Providers\Infrastructure\Adapters\LinodeAdapter;
use Falak\Providers\Infrastructure\Adapters\VultrAdapter;
use Illuminate\Contracts\Config\Repository as Config;

final class AdapterFactory
{
    public function __construct(private readonly Config $config) {}

    /**
     * @param  array<string, mixed>  $credentials  decrypted credential fields
     */
    public function make(ProviderType $type, array $credentials): ProviderAdapter
    {
        /** @var array<string, int> $http */
        $http = (array) $this->config->get('providers.http', []);
        // Demo / test credentials may pin an unroutable `.invalid` endpoint (RFC 6761: never resolves), so Verify and
        // catalog calls fail fast without reaching the real provider. Any other override is ignored.
        $pinned = is_string($credentials['endpoint'] ?? null) && str_ends_with((string) parse_url($credentials['endpoint'], PHP_URL_HOST), '.invalid')
            ? $credentials['endpoint']
            : null;
        $endpoint = fn (string $default) => $pinned ?? (string) $this->config->get("providers.endpoints.{$type->value}", $default);
        $field = function (string $name) use ($credentials, $type): string {
            $value = $credentials[$name] ?? null;

            if (! is_string($value) || $value === '') {
                throw new ProviderException("Missing credential field [{$name}] for {$type->label()}.", $type->value);
            }

            return $value;
        };

        return match ($type) {
            ProviderType::Hetzner => new HetznerAdapter($field('token'), $endpoint('https://api.hetzner.cloud/v1'), $http),
            ProviderType::DigitalOcean => new DigitalOceanAdapter($field('token'), $endpoint('https://api.digitalocean.com/v2'), $http),
            ProviderType::Vultr => new VultrAdapter($field('api_key'), $endpoint('https://api.vultr.com/v2'), $http),
            ProviderType::Linode => new LinodeAdapter($field('token'), $endpoint('https://api.linode.com/v4'), $http),
            ProviderType::Aws => new LightsailAdapter(
                $field('access_key_id'),
                $field('secret_access_key'),
                $field('region'),
                $endpoint('https://lightsail.{region}.amazonaws.com'),
                $http,
            ),
            ProviderType::Contabo => new ContaboAdapter(
                ContaboAdapter::parseCredentials($field('api_details')),
                $endpoint('https://api.contabo.com/v1'),
                $pinned !== null ? rtrim($pinned, '/').'/token' : (string) $this->config->get('providers.contabo_auth_url', 'https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token'),
                $http,
            ),
            ProviderType::Custom => new CustomAdapter,
        };
    }
}
