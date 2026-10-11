<?php

namespace Falak\Processes\Infrastructure\Config;

use Falak\Config\Contracts\ConfigScope;
use Falak\Config\Contracts\Units;
use Falak\Limits\Contracts\ResourceLimits;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\Worker;

/**
 * Workers, daemons and schedules in falak.yaml's shape and back (the Processes table of the phase 0 contract's field
 * mapping). File values are normalized (durations and sizes in their shortest form, queues as a list, env values as
 * text) so a file and today's rows compare field by field.
 */
final class ProcessShapes
{
    public const DURATIONS = [
        'worker' => ['timeout', 'sleep', 'backoff', 'max_time'],
        'daemon' => ['stop_timeout'],
        'schedule' => ['timeout'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function worker(Worker $worker, ConfigScope $scope): array
    {
        return self::compact([
            'connection' => $worker->connection,
            'queue' => $worker->queues() ?: null,
            'command' => $worker->command,
            'processes' => $worker->processes,
            'timeout' => Units::duration((int) $worker->timeout),
            'sleep' => Units::duration((int) $worker->sleep),
            'tries' => $worker->tries,
            'backoff' => $worker->backoff !== null ? Units::duration($worker->backoff) : null,
            'max_jobs' => $worker->max_jobs,
            'max_time' => $worker->max_time !== null ? Units::duration($worker->max_time) : null,
            'memory' => Units::size((int) $worker->memory),
            'env' => $worker->env ?: null,
            'limits' => self::limitsToFile($worker->limits),
            'servers' => self::serverNames($worker->server_ids, $scope),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function daemon(Daemon $daemon, ConfigScope $scope): array
    {
        return self::compact([
            'command' => $daemon->command,
            'directory' => $daemon->directory,
            'user' => $daemon->user,
            'instances' => $daemon->instances,
            'restart' => $daemon->restart,
            'stop_signal' => $daemon->stop_signal,
            'stop_timeout' => Units::duration((int) $daemon->stop_timeout),
            'env' => $daemon->env ?: null,
            'limits' => self::limitsToFile($daemon->limits),
            'servers' => self::serverNames($daemon->server_ids, $scope),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function schedule(Schedule $schedule): array
    {
        return self::compact([
            'command' => $schedule->command,
            'cron' => $schedule->expression,
            'timezone' => $schedule->timezone,
            'user' => $schedule->user,
            'overlap' => $schedule->overlap,
            'timeout' => Units::duration((int) $schedule->timeout),
            'heartbeat' => $schedule->heartbeat,
            'enabled' => $schedule->enabled,
            'all_servers' => $schedule->all_servers,
        ]);
    }

    /**
     * A file item, normalized for comparison.
     *
     * @param  'worker'|'daemon'|'schedule'  $kind
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalize(string $kind, array $item): array
    {
        foreach (self::DURATIONS[$kind] as $key) {
            if (array_key_exists($key, $item) && ($seconds = Units::seconds($item[$key])) !== null) {
                $item[$key] = Units::duration($seconds);
            }
        }

        if ($kind === 'worker') {
            if (isset($item['queue']) && is_string($item['queue'])) {
                $item['queue'] = array_values(array_filter(array_map('trim', explode(',', $item['queue'])), fn (string $q) => $q !== ''));
            }

            if (isset($item['memory']) && ($mb = Units::mebibytes($item['memory'])) !== null) {
                $item['memory'] = Units::size($mb);
            }
        }

        if (isset($item['env']) && is_array($item['env'])) {
            $item['env'] = array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, $item['env']);
            ksort($item['env']);
        }

        if (isset($item['limits']) && is_array($item['limits'])) {
            $item['limits'] = self::limitsToFile(self::limitsFromFile($item['limits'])) ?? [];
        }

        return $item;
    }

    /**
     * File limits → ResourceLimits JSON (memory_limit MB, …).
     *
     * @param  array<string, mixed>  $limits
     * @return array<string, mixed>
     */
    public static function limitsFromFile(array $limits): array
    {
        return array_filter([
            'memory_limit' => isset($limits['memory']) ? Units::mebibytes($limits['memory']) : null,
            'memory_reservation' => isset($limits['memory_reservation']) ? Units::mebibytes($limits['memory_reservation']) : null,
            'cpus' => isset($limits['cpus']) ? (float) $limits['cpus'] : null,
            'pids_limit' => $limits['pids'] ?? null,
            'restart_policy' => $limits['restart'] ?? null,
            'max_restarts' => $limits['max_restarts'] ?? null,
            'log_max_size' => isset($limits['log']['max_size']) ? Units::mebibytes($limits['log']['max_size']) : null,
            'log_max_files' => $limits['log']['max_files'] ?? null,
            'oom' => $limits['oom'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * ResourceLimits JSON → the file's shape (null when empty).
     *
     * @param  ?array<string, mixed>  $limits
     * @return ?array<string, mixed>
     */
    public static function limitsToFile(?array $limits): ?array
    {
        $l = ResourceLimits::fromArray($limits);
        $log = array_filter([
            'max_size' => $l->logMaxSize !== null ? Units::size($l->logMaxSize) : null,
            'max_files' => $l->logMaxFiles,
        ], fn ($v) => $v !== null);
        $out = array_filter([
            'memory' => $l->memoryLimit !== null ? Units::size($l->memoryLimit) : null,
            'memory_reservation' => $l->memoryReservation !== null ? Units::size($l->memoryReservation) : null,
            'cpus' => $l->cpus,
            'pids' => $l->pidsLimit,
            'restart' => $l->restartPolicy,
            'max_restarts' => $l->maxRestarts,
            'log' => $log ?: null,
            'oom' => $l->oom,
        ], fn ($v) => $v !== null);

        return $out ?: null;
    }

    /**
     * @param  ?list<string>  $ids
     * @return ?list<string>
     */
    private static function serverNames(?array $ids, ConfigScope $scope): ?array
    {
        if (! $ids) {
            return null;
        }

        return array_values(array_map(fn (string $id) => $scope->serverName($id) ?? $id, $ids));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, fn ($v) => $v !== null);
    }

    /**
     * Env values as keys only (`{"KEY": "••••"}`): plans never show process env values.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function masked(array $item): array
    {
        if (isset($item['env']) && is_array($item['env'])) {
            $item['env'] = array_map(fn () => '••••', $item['env']);
        }

        return $item;
    }
}
