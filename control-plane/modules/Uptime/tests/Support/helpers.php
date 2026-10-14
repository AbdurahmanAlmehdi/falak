<?php

use Falak\Alerting\Contracts\Alerts;
use Falak\Uptime\Application\Probing\Prober;
use Falak\Uptime\Domain\Enums\MonitorSource;
use Falak\Uptime\Domain\Enums\MonitorStatus;
use Falak\Uptime\Domain\Enums\MonitorType;
use Falak\Uptime\Domain\Models\Monitor;
use Falak\Uptime\Tests\Support\FakeProber;
use Falak\Uptime\Tests\Support\RecordingAlerts;

require_once __DIR__.'/Fakes.php';
require_once __DIR__.'/Pki.php';

/**
 * @return array{prober: FakeProber, alerts: RecordingAlerts}
 */
function uptime_fakes(): array
{
    config(['uptime.retry_delay_ms' => 0]);
    $prober = new FakeProber;
    $alerts = new RecordingAlerts;
    app()->instance(Prober::class, $prober);
    app()->instance(Alerts::class, $alerts);

    return ['prober' => $prober, 'alerts' => $alerts];
}

function uptime_monitor(string $organizationId, array $attributes = []): Monitor
{
    return Monitor::query()->create([
        'organization_id' => $organizationId,
        'source' => MonitorSource::User,
        'type' => MonitorType::Http,
        'name' => 'Shop',
        'url' => 'https://shop.example.com/',
        'status' => MonitorStatus::Pending,
        'next_check_at' => now(),
        ...$attributes,
    ]);
}
