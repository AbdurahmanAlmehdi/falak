<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\UpdateSiteLimits;
use Falak\Sites\Contracts\SiteLimits;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

final class ActionSiteLimits implements SiteLimits
{
    public function __construct(private readonly UpdateSiteLimits $update) {}

    public function set(string $siteId, ?array $limits, ?string $userId = null): string
    {
        $site = Site::query()->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        return ($this->update)($site, $limits, null, $userId);
    }

    public function setService(string $siteId, string $service, ?array $limits, ?string $userId = null): string
    {
        $site = Site::query()->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        return ($this->update)($site, $limits, $service, $userId);
    }
}
