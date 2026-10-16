<?php

namespace Falak\Projects\Contracts\Data;

use Falak\Projects\Contracts\ServiceKind;
use Illuminate\Support\Str;

/**
 * A site or database placed in a project environment (one environment per site/database).
 */
final readonly class ServiceData
{
    /**
     * @param  string  $name  service name inside the environment, used by variable references (`${{ name.KEY }}`)
     */
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $projectId,
        public string $environmentId,
        public ServiceKind $kind,
        public string $refId,
        public string $name,
        public int $x,
        public int $y,
    ) {}

    /**
     * Normalised form of a service name, unique in an environment: references match it ("Shop API", "shop-api" and
     * "shop_api" are the same service) and falak.yaml uses it as the service's unit key.
     */
    public static function handle(string $name): string
    {
        return Str::slug(str_replace(['_', '.'], '-', trim($name)));
    }
}
