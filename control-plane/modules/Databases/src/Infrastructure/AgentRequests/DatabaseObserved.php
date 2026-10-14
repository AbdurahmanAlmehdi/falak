<?php

namespace Falak\Databases\Infrastructure\AgentRequests;

use Falak\Databases\Application\Actions\RecordObservation;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;

/**
 * db.observed: what `falak-db observe` found in the server's database containers in the last minute
 * (docs/plans/OBSERVABILITY.md phase 3). Only instances of the agent's own server and organization are recorded, and
 * only while they run (active or upgrading); anything else in the report is ignored, silently, like an instance that
 * is being deleted. The engine must match the instance's: a report never re-labels one.
 */
final class DatabaseObserved implements AgentRequestHandler
{
    public function __construct(private readonly RecordObservation $record) {}

    public function handle(AgentCaller $caller, array $body): array
    {
        $reports = [];

        foreach ((array) $body['instances'] as $item) {
            if (is_array($item)) {
                $reports[strtolower((string) $item['id'])] = $item;
            }
        }

        if ($reports === []) {
            return ['accepted' => 0];
        }

        $instances = DatabaseInstance::query()
            ->whereIn('id', array_keys($reports))
            ->where('server_id', $caller->serverId)
            ->where('organization_id', $caller->organizationId)
            ->whereIn('status', [InstanceStatus::Active, InstanceStatus::Upgrading])
            ->get();
        $accepted = 0;

        foreach ($instances as $instance) {
            $report = $reports[$instance->id];

            if ($report['engine'] !== $instance->engine->protocol()) {
                continue;
            }

            ($this->record)($instance, $report);
            $accepted++;
        }

        return ['accepted' => $accepted];
    }
}
