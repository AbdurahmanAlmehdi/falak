<?php

namespace Falak\Databases\Application;

use Falak\Databases\Domain\Enums\Engine;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Fleet\Contracts\AgentDirectory;

/**
 * Who may get query insights (docs/plans/OBSERVABILITY.md phase 3): PostgreSQL (pg_stat_statements) and MariaDB from
 * 1 GiB of memory (performance_schema needs it; below, it risks the instance's memory limit), on servers whose agent
 * has db.observability. The flag only ever restarts a container when the user turns it on (EnableQueryInsights sends
 * apply_query_insights); everywhere else it waits for the next restart the user causes.
 */
final class QueryInsights
{
    public const FEATURE = 'db.observability';

    /** MariaDB's performance_schema below this much memory would eat into the buffer pool and the limit. */
    public const MARIADB_MIN_MEMORY = 1024 ** 3;

    public function __construct(private readonly AgentDirectory $agents) {}

    /** The engine and memory allow it (whatever the agent). */
    public static function engineAllows(Engine $engine, int $memoryBytes): bool
    {
        return match ($engine) {
            Engine::PostgreSql => true,
            Engine::MariaDb => $memoryBytes >= self::MARIADB_MIN_MEMORY,
            default => false,
        };
    }

    public function agentSupports(string $serverId): bool
    {
        return $this->agents->forServer($serverId)?->supports(self::FEATURE) ?? false;
    }

    /** A new instance starts with it: the engine allows it and the agent passes it on. */
    public function forNewInstance(Engine $engine, int $memoryBytes, string $serverId): bool
    {
        return self::engineAllows($engine, $memoryBytes) && $this->agentSupports($serverId);
    }

    /**
     * Why an existing instance can't be switched on now, or null when it can: the engine, its agent, and its image (a
     * report from `falak-db observe` says the helper reads the flag).
     */
    public function refusal(DatabaseInstance $instance): ?string
    {
        if (! in_array($instance->engine, [Engine::PostgreSql, Engine::MariaDb], true)) {
            return "{$instance->engine->label()} needs no restart for query insights.";
        }

        if (! self::engineAllows($instance->engine, (int) $instance->memory_bytes)) {
            return 'MariaDB needs at least 1 GiB of memory for performance_schema: raise the memory first.';
        }

        if (! $this->agentSupports($instance->server_id)) {
            return "Update the agent on {$instance->server_name} first: it doesn't apply query insights yet.";
        }

        if (! is_array(((array) ($instance->observed ?? []))['query_insights'] ?? null)) {
            return 'Update the database image first (a minor upgrade): this one does not report query insights yet.';
        }

        return null;
    }
}
