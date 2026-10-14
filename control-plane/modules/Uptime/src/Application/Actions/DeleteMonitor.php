<?php

namespace Falak\Uptime\Application\Actions;

use Falak\Uptime\Domain\Models\Check;
use Falak\Uptime\Domain\Models\Incident;
use Falak\Uptime\Domain\Models\Monitor;
use Illuminate\Support\Facades\DB;

/**
 * Removes a monitor with its history; its open alerts and issues are cleared.
 */
final class DeleteMonitor
{
    public function __construct(private readonly RecordCheck $record) {}

    public function __invoke(Monitor $monitor): void
    {
        $this->record->stop($monitor, 'deleted');

        DB::transaction(function () use ($monitor) {
            Check::query()->where('monitor_id', $monitor->id)->delete();
            Incident::query()->where('monitor_id', $monitor->id)->delete();
            DB::table('uptime_rollups')->where('monitor_id', $monitor->id)->delete();
            DB::table('uptime_status_page_monitors')->where('monitor_id', $monitor->id)->delete();
            $monitor->delete();
        });
    }
}
