<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Domain\Enums\StorageDriver;
use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Databases\Infrastructure\ObjectStorage\ObjectStore;
use Falak\Identity\Contracts\AuditLog;
use Falak\Kernel\Network\EndpointGuard;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a storage provider. Blank credentials on update keep the stored ones.
 */
final class SaveStorageProvider
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly EndpointGuard $guard,
    ) {}

    /**
     * @param  array{name: string, driver: string, region?: ?string, bucket: string, prefix?: ?string, endpoint?: ?string, account_id?: ?string, path_style?: ?bool, access_key_id?: ?string, secret_access_key?: ?string}  $data
     */
    public function __invoke(string $organizationId, array $data, ?StorageProvider $provider = null, ?string $actorId = null): StorageProvider
    {
        $driver = StorageDriver::from($data['driver']);
        $region = trim((string) (($data['region'] ?? null) ?: $driver->defaultRegion()));

        if ($region === '') {
            throw ValidationException::withMessages(['region' => 'The region is required for '.$driver->label().'.']);
        }

        $endpoint = ObjectStore::endpointFor($driver, $region, $data['endpoint'] ?? $provider?->endpoint, $data['account_id'] ?? null);

        if ($endpoint === '' || ! str_starts_with($endpoint, 'https://') || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            // Agents only accept https presigned URLs (db.backup / db.restore schemas).
            throw ValidationException::withMessages([$driver === StorageDriver::R2 ? 'account_id' : 'endpoint' => 'An https endpoint is required.']);
        }

        if ($driver === StorageDriver::Minio && ($refusal = $this->guard->refusal($endpoint, (bool) config('databases.allow_private_endpoints', false) && ! config('controlplane.managed')))) {
            throw ValidationException::withMessages(['endpoint' => $refusal]);
        }

        $accessKey = ($data['access_key_id'] ?? null) ?: $provider?->access_key_id;
        $secretKey = ($data['secret_access_key'] ?? null) ?: $provider?->secret_access_key;

        if (! $accessKey || ! $secretKey) {
            throw ValidationException::withMessages(['access_key_id' => 'Access key ID and secret are required.']);
        }

        $credentialsChanged = ! $provider
            || $accessKey !== $provider->access_key_id
            || $secretKey !== $provider->secret_access_key
            || $endpoint !== $provider->endpoint
            || $data['bucket'] !== $provider->bucket
            || $region !== $provider->region;

        $provider ??= new StorageProvider(['organization_id' => $organizationId, 'created_by' => $actorId]);
        $provider->fill([
            'name' => $data['name'],
            'driver' => $driver,
            'endpoint' => $endpoint,
            'region' => $region,
            'bucket' => $data['bucket'],
            'prefix' => trim((string) ($data['prefix'] ?? ''), '/') ?: null,
            'path_style' => $data['path_style'] ?? in_array($driver, [StorageDriver::R2, StorageDriver::Minio], true),
            'access_key_id' => $accessKey,
            'secret_access_key' => $secretKey,
        ]);

        if ($credentialsChanged) {
            $provider->verified_at = null;
        }

        $created = ! $provider->exists;
        $provider->save();

        $this->audit->record($created ? 'databases.storage_provider_created' : 'databases.storage_provider_updated', 'storage_provider', $provider->id, [
            'name' => $provider->name,
            'driver' => $driver->value,
            'bucket' => $provider->bucket,
            'credentials_changed' => $credentialsChanged,
        ], $organizationId);

        return $provider;
    }
}
