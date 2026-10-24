<?php

namespace Falak\Secrets\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Network\EndpointGuard;
use Falak\Secrets\Application\Providers\ProviderFields;
use Falak\Secrets\Domain\Enums\ProviderStatus;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\SecretProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a provider, or change one (its type is fixed). Credentials left empty on edit keep their stored value,
 * except when a setting that decides where they are sent changes (endpoint, CA, region, role, header…): then
 * every credential must be entered again, so a stored credential can never be redirected to another host.
 * Fields that don't apply (another auth method) are dropped. Endpoints are checked against the endpoint guard.
 * A config change bumps config_version, which drops the cached values and logins bound to the old one.
 * The audit log gets names (of the provider and of the settings changed), never a value.
 */
final class SaveSecretProvider
{
    /** Never set by a webhook's auth header: they control the request itself (hop-by-hop, framing, routing). */
    private const FORBIDDEN_HEADERS = [
        'host', 'content-length', 'content-type', 'transfer-encoding', 'connection', 'keep-alive', 'te', 'trailer', 'upgrade',
        'proxy-authenticate', 'proxy-authorization', 'proxy-connection', 'expect', 'accept', 'accept-encoding', 'cookie',
    ];

    public function __construct(
        private readonly EndpointGuard $guard,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{name?: string, type?: string, config?: array<string, mixed>, allow_private_network?: bool, cache_ttl_seconds?: int}  $data
     */
    public function __invoke(string $organizationId, ?SecretProvider $provider, #[\SensitiveParameter] array $data, ?string $userId): SecretProvider
    {
        $type = $provider->type ?? ProviderType::from((string) $data['type']);
        $allowPrivate = (bool) ($data['allow_private_network'] ?? $provider?->allow_private_network ?? false) && $type->selfHostable();

        if ($allowPrivate && (! config('secrets.providers.allow_private_network', false) || config('controlplane.managed'))) {
            throw ValidationException::withMessages(['allow_private_network' => 'This Falak instance does not allow providers on private networks.']);
        }

        // On edit without settings (a rename, a TTL): the stored config stays as it is.
        $config = $provider !== null && ! array_key_exists('config', $data)
            ? $provider->config
            : $this->config($type, (array) ($data['config'] ?? []), $provider?->config ?? [], $allowPrivate, $allowPrivate && ! ($provider?->allow_private_network ?? false));
        $name = trim((string) ($data['name'] ?? $provider?->name));

        return DB::transaction(function () use ($organizationId, $provider, $data, $userId, $type, $allowPrivate, $config, $name) {
            if (SecretProvider::query()->where('organization_id', $organizationId)->where('name', $name)->when($provider, fn ($q) => $q->whereKeyNot($provider->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['name' => "A provider named {$name} already exists."]);
            }

            $changedSettings = $provider === null ? [] : array_keys(array_filter(
                array_flip(array_unique([...array_keys($config), ...array_keys($provider->config)])),
                fn ($_, string $key) => ($config[$key] ?? null) !== ($provider->config[$key] ?? null),
                ARRAY_FILTER_USE_BOTH,
            ));

            $model = $provider ?? new SecretProvider(['organization_id' => $organizationId, 'type' => $type, 'created_by' => $userId]);
            $model->fill([
                'name' => $name,
                'allow_private_network' => $allowPrivate,
                'cache_ttl_seconds' => (int) ($data['cache_ttl_seconds'] ?? $provider?->cache_ttl_seconds ?? 300),
            ]);

            if ($provider === null || $changedSettings !== [] || $model->isDirty('allow_private_network')) {
                $model->config = $config;
                $model->config_version = $provider !== null ? $provider->config_version + 1 : 1;
                $model->status = ProviderStatus::Untested;
                $model->last_error = null;

                // Values fetched with the old settings may come from another place: never serve them again.
                if ($provider !== null) {
                    ProviderValue::query()->where('provider_id', $provider->id)->delete();
                }
            }

            $dirty = array_keys($model->getDirty());
            $model->save();

            if ($provider === null) {
                $this->audit->record('secret_provider.created', 'secret_provider', $model->id, ['name' => $name, 'type' => $type->value], $organizationId);
            } elseif ($dirty !== []) {
                $this->audit->record('secret_provider.updated', 'secret_provider', $model->id, [
                    'name' => $name,
                    'changed' => array_values(array_diff($dirty, ['config', 'config_version', 'status', 'last_error'])),
                    'settings' => $changedSettings,
                ], $organizationId);
            }

            return $model;
        });
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $stored
     * @param  bool  $privateTurnedOn  "allow private network" is being turned on (the endpoint may now resolve elsewhere)
     * @return array<string, string>
     */
    private function config(ProviderType $type, #[\SensitiveParameter] array $incoming, #[\SensitiveParameter] array $stored, bool $allowPrivate, bool $privateTurnedOn): array
    {
        $fields = ProviderFields::for($type);
        $merged = [];

        foreach ($fields as $field) {
            $value = $incoming[$field['name']] ?? null;
            $value = is_scalar($value) ? trim((string) $value) : '';

            $merged[$field['name']] = $value !== '' || ($field['secret'] ?? false) ? $value : (string) ($field['default'] ?? '');
        }

        // Where the credentials go: if any of it changes, stored credentials are not carried over.
        $rebound = $stored !== [] && ($privateTurnedOn || collect($fields)->contains(
            fn (array $field) => ($field['binds'] ?? false) && self::normalize($field, $merged[$field['name']]) !== self::normalize($field, (string) ($stored[$field['name']] ?? '')),
        ));

        $config = [];
        $errors = [];

        foreach ($fields as $field) {
            $name = $field['name'];
            $value = $merged[$name];
            $applies = ProviderFields::applies($field, $merged, $type);

            if ($value === '' && ($field['secret'] ?? false) && $applies && ($stored[$name] ?? '') !== '') {
                if ($rebound) {
                    $errors["config.{$name}"] = "Enter the {$field['label']} again: the endpoint or the settings that decide where it is sent changed.";

                    continue;
                }

                $value = (string) $stored[$name];
            }

            if (! $applies || $value === '') {
                if ($field['required'] && $applies) {
                    $errors["config.{$name}"] = "Enter the {$field['label']}.";
                }

                continue;
            }

            $error = match (true) {
                strlen($value) > ($field['kind'] === 'textarea' ? 20000 : 8192) => 'This is too long.',
                isset($field['options']) && ! in_array($value, array_column($field['options'], 'value'), true) => 'Choose one of the options.',
                isset($field['pattern']) && preg_match($field['pattern'], $value) !== 1 => "This is not a valid {$field['label']}.",
                ($field['path'] ?? false) && preg_match('#(^|/)\.{1,2}(/|$)|//#', $value) === 1 => 'Path segments can\'t be empty, "." or "..".',
                $name === 'header_name' && in_array(strtolower($value), self::FORBIDDEN_HEADERS, true) => "{$value} can't be used as the auth header.",
                $field['kind'] === 'textarea' && ! str_contains($value, '-----BEGIN CERTIFICATE-----') => 'Paste a PEM certificate (-----BEGIN CERTIFICATE-----).',
                $field['kind'] === 'url' => $this->urlError($value, $allowPrivate),
                default => null,
            };

            if ($error !== null) {
                $errors["config.{$name}"] = $error;

                continue;
            }

            $config[$name] = self::normalize($field, $value);
        }

        // The instance profile is the control plane's own role: only ever used to assume a role of the organization's.
        if (($config['auth_method'] ?? null) === 'instance_profile' && ! isset($config['role_arn'])) {
            $errors['config.role_arn'] ??= 'With the instance profile, enter the role to assume (its trust policy requires the external ID shown in the docs).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $config;
    }

    /**
     * @param  array{kind: string}  $field
     */
    private static function normalize(array $field, string $value): string
    {
        return $field['kind'] === 'url' ? rtrim($value, '/') : $value;
    }

    private function urlError(string $url, bool $allowPrivate): ?string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_QUERY) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            return 'Enter an https:// URL (no credentials, query or fragment).';
        }

        return $this->guard->refusal($url, $allowPrivate);
    }
}
