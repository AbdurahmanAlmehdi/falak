<?php

namespace Falak\Telemetry\Infrastructure;

use Falak\Telemetry\Contracts\RequestIdLinking;
use Falak\Telemetry\Domain\Models\TelemetrySettings;

final class SettingsRequestIdLinking implements RequestIdLinking
{
    public function enabled(string $organizationId): bool
    {
        return (bool) TelemetrySettings::query()->whereKey(strtolower($organizationId))->value('request_id_linking');
    }
}
