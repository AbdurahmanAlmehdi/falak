<?php

use Falak\Databases\Application\DatabaseMetricQueries;
use Falak\Databases\Application\Recommendations;
use Falak\Databases\Domain\Enums\Engine;

const RECO_MB = 1024 ** 2;

function reco_keys(array $recommendations): array
{
    return array_column($recommendations, 'key');
}

it('recommends an index for large tables read by sequential scans, never for small or index-served ones', function () {
    $table = ['database' => 'shop', 'schema' => 'public', 'name' => 'orders', 'size_bytes' => 500 * RECO_MB, 'seq_scan' => 2000, 'seq_tup_read' => 2000 * 50000, 'idx_scan' => 10];

    expect(reco_keys(Recommendations::for(Engine::PostgreSql, ['tables' => [$table]])))->toBe(['seq_scan:shop.orders'])
        ->and(reco_keys(Recommendations::for(Engine::PostgreSql, ['tables' => [[...$table, 'size_bytes' => 50 * RECO_MB]]])))->toBe([])
        ->and(reco_keys(Recommendations::for(Engine::PostgreSql, ['tables' => [[...$table, 'idx_scan' => 1_000_000]]])))->toBe([])
        ->and(reco_keys(Recommendations::for(Engine::PostgreSql, ['tables' => [[...$table, 'schema' => 'billing']]])))->toBe(['seq_scan:shop.billing.orders']);
});

it('recommends dropping unused indexes only once the statistics are a week old', function () {
    $index = ['database' => 'shop', 'schema' => 'public', 'table' => 'orders', 'name' => 'orders_note_idx', 'size_bytes' => 64 * RECO_MB, 'scans' => 0];

    expect(reco_keys(Recommendations::for(Engine::PostgreSql, ['indexes' => [$index], 'settings' => ['stats_age_s' => 8 * 86400]])))->toBe(['unused_index:shop.orders.orders_note_idx'])
        ->and(Recommendations::for(Engine::PostgreSql, ['indexes' => [$index], 'settings' => ['stats_age_s' => 86400]]))->toBe([])
        ->and(Recommendations::for(Engine::PostgreSql, ['indexes' => [[...$index, 'size_bytes' => RECO_MB]], 'settings' => ['stats_age_s' => 8 * 86400]]))->toBe([]);
});

it('suggests more memory when the cache hit ratio is low, per engine', function () {
    $low = ['deltas' => ['cache_hits' => 90000, 'cache_misses' => 10000], 'settings' => ['shared_buffers_bytes' => 128 * RECO_MB, 'innodb_buffer_pool_bytes' => 256 * RECO_MB]];
    $pg = Recommendations::for(Engine::PostgreSql, $low);

    expect($pg[0]['key'])->toBe('cache_hit')
        ->and($pg[0]['title'])->toContain('90%')
        ->and($pg[0]['detail'])->toContain('shared_buffers (128 MB)')
        ->and(Recommendations::for(Engine::MySql, $low)[0]['detail'])->toContain('innodb_buffer_pool_size (256 MB)')
        // Too few reads to judge, or Redis (its hit rate is the application's business).
        ->and(Recommendations::for(Engine::PostgreSql, ['deltas' => ['cache_hits' => 90, 'cache_misses' => 10]]))->toBe([])
        ->and(Recommendations::for(Engine::Redis, $low))->toBe([]);
});

it('warns about Redis near maxmemory without eviction, and connections near the limit', function () {
    $near = ['gauges' => ['used_memory' => 95, 'maxmemory' => 100], 'labels' => ['maxmemory_policy' => 'noeviction']];

    expect(Recommendations::for(Engine::Valkey, $near)[0])->toMatchArray(['key' => 'maxmemory', 'severity' => 'critical'])
        ->and(Recommendations::for(Engine::Valkey, [...$near, 'labels' => ['maxmemory_policy' => 'allkeys-lru']]))->toBe([])
        ->and(reco_keys(Recommendations::for(Engine::MySql, ['gauges' => ['connections' => 130, 'max_connections' => 151]])))->toBe(['connections']);
});

it('forecasts a full disk from the size samples', function () {
    $sizes = ['2026-10-01' => 50 * 1024 ** 3, '2026-10-02' => 51 * 1024 ** 3, '2026-10-03' => 52 * 1024 ** 3, '2026-10-04' => 53 * 1024 ** 3];

    expect(Recommendations::daysUntilFull($sizes, 60 * 1024 ** 3))->toBe(7)
        ->and(Recommendations::daysUntilFull(array_slice($sizes, 0, 2), 60 * 1024 ** 3))->toBeNull()
        ->and(Recommendations::daysUntilFull(array_fill_keys(array_keys($sizes), 10), 100))->toBeNull()
        ->and(Recommendations::daysUntilFull($sizes, 50 * 1024 ** 3))->toBe(0);

    $recommendation = Recommendations::for(Engine::PostgreSql, [], $sizes, 60 * 1024 ** 3)[0];
    expect($recommendation['key'])->toBe('disk_full')->and($recommendation['severity'])->toBe('critical');
    expect(Recommendations::for(Engine::PostgreSql, [], $sizes, 1024 ** 4))->toBe([]);
});

it('orders recommendations by severity and offers query insights where it is off', function () {
    $report = [
        'query_insights' => ['status' => 'off'],
        'gauges' => ['connections' => 90, 'max_connections' => 100],
        'no_index_statements' => [['query' => 'SELECT * FROM t WHERE a = ?', 'no_index' => 5, 'calls' => 5]],
    ];

    expect(reco_keys(Recommendations::for(Engine::MariaDb, $report)))->toBe(['no_index', 'connections', 'query_insights'])
        ->and(reco_keys(Recommendations::for(Engine::MySql, ['query_insights' => ['status' => 'off']])))->toBe([])
        // MariaDB below 1 GiB: raise the memory first, never "enable" (performance_schema would not fit).
        ->and(reco_keys(Recommendations::for(Engine::MariaDb, ['query_insights' => ['status' => 'off']], memoryBytes: 512 * 1024 ** 2)))->toBe(['query_insights_memory'])
        ->and(reco_keys(Recommendations::for(Engine::MariaDb, ['query_insights' => ['status' => 'off']], memoryBytes: 1024 ** 3)))->toBe(['query_insights'])
        ->and(reco_keys(Recommendations::for(Engine::PostgreSql, ['query_insights' => ['status' => 'off']], memoryBytes: 256 * 1024 ** 2)))->toBe(['query_insights']);
});

it('matches database series by the lowercase instance id and the uppercase server id', function () {
    $queries = DatabaseMetricQueries::for('01HZYINST00000000000000001', '01hzysrv000000000000000001', Engine::PostgreSql, 120);

    expect($queries['connections'])->toContain('falak_db_instance_id="01hzyinst00000000000000001"')
        ->and($queries['cpu_rest'])->toContain('falak_server_id="01HZYSRV000000000000000001"')
        ->and($queries['deadlocks'])->toContain('[120s]')
        ->and($queries['cache_hit'])->toContain('[300s]')
        ->and($queries)->toHaveKeys(['transactions', 'locks_waiting', 'sizes', 'memory_limit'])
        ->and($queries)->not->toHaveKey('ops')
        ->and(DatabaseMetricQueries::for('x', 'y', Engine::Redis))->toHaveKeys(['ops', 'used_memory', 'maxmemory', 'evicted', 'keys'])
        ->and(DatabaseMetricQueries::for('x', 'y', Engine::MySql))->toHaveKeys(['queries', 'operations', 'lock_waits', 'transactions']);
});
