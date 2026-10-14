<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\QueryInsights;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns query insights on for an existing instance (docs/plans/OBSERVABILITY.md phase 3): PostgreSQL preloads
 * pg_stat_statements, MariaDB turns performance_schema on. Both only take effect at start, so this is the one
 * db.instance.update that carries apply_query_insights (the agent restarts the container for it): only ever sent when
 * someone asks for it. Refused unless the agent and the image can apply it ({@see QueryInsights::refusal()}).
 */
final class EnableQueryInsights
{
    public function __construct(
        private readonly ApplyInstance $apply,
        private readonly AuditLog $audit,
        private readonly QueryInsights $queryInsights,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, ?string $actorId = null): DatabaseInstance
    {
        if (! $instance->isRunning()) {
            throw ValidationException::withMessages(['instance' => "The database is {$instance->status->value}."]);
        }

        if (($refusal = $this->queryInsights->refusal($instance)) !== null) {
            throw ValidationException::withMessages(['instance' => $refusal]);
        }

        // Already on and already applied (the report says so): nothing to restart. On but not applied (a flag waiting
        // for a restart): applying it is what the user asks for here.
        if ($instance->query_insights && (((array) $instance->observed)['query_insights']['status'] ?? null) === 'on') {
            return $instance;
        }

        DB::transaction(function () use ($instance) {
            $instance->forceFill(['query_insights' => true])->save();
            ($this->apply)($instance, applyQueryInsights: true);
        });

        $this->audit->record('databases.query_insights_enabled', 'database_instance', $instance->id, ['name' => $instance->name], $instance->organization_id);

        return $instance;
    }
}
