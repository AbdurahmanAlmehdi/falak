<?php

namespace Falak\Databases\Infrastructure\ObjectStorage;

use Falak\Databases\Domain\Models\StorageProvider;
use Falak\Kernel\Network\EndpointGuard;
use Illuminate\Http\Client\Factory as HttpFactory;

final class ObjectStores
{
    public function __construct(private readonly HttpFactory $http, private readonly EndpointGuard $guard) {}

    public function for(StorageProvider $provider): ObjectStore
    {
        return new ObjectStore($provider, $this->http, (int) config('databases.storage_timeout', 30), $this->guard, (bool) config('databases.allow_private_endpoints', false) && ! config('controlplane.managed'));
    }
}
