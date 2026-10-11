<?php

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Domain\Enums\RunStatus;
use Falak\Config\Domain\Models\ConfigDetached;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Databases\Domain\Enums\InstanceStatus;
use Falak\Databases\Domain\Models\BackupSchedule;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Identity\Application\Actions\CreateApiToken;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\VariableReferences;
use Falak\Projects\Domain\Models\Service;
use Falak\Servers\Domain\Models\Server;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\Volumes\Domain\Models\Volume;

require_once __DIR__.'/support.php';

// The data lane through a whole run: a push creates database, Redis and volume units in phase A, the app's
// `${{ db.DATABASE_URL }}` resolves to them, no password ever leaves the Databases module through Config, the dashboard
// refuses edits of managed fields, and export → plan is empty.

function data_e2e_file(string $memory = '1g', string $extra = ''): string
{
    return <<<YAML
        version: 1
        units:
          web:
            type: app
            runtime: frankenphp
            php: "8.4"
            env:
              DATABASE_URL: \${{ db.DATABASE_URL }}
              DB_PASSWORD: \${{ db.DB_PASSWORD }}
              REDIS_URL: \${{ cache.REDIS_URL }}
          db:
            type: database
            engine: postgresql
            version: "17"
            memory: {$memory}
            databases: [shop, analytics]
          cache:
            type: redis
            memory: 128m
        {$extra}environments:
          production: { branch: main, servers: [web-1] }
        YAML;
}

/** Every password the Databases module generated (superusers and users). */
function data_passwords(): array
{
    $out = [];

    foreach (DatabaseInstance::query()->with('users')->get() as $instance) {
        $out[] = $instance->root_password;

        foreach ($instance->users as $user) {
            $out[] = (string) $user->password;
        }
    }

    return array_values(array_unique(array_filter($out)));
}

it('creates database and redis units in phase A; the app\'s references resolve to them', function () {
    $world = config_world();
    $world->file(data_e2e_file());

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applying)
        ->and(collect($run->errors)->where('code', 'value.unsupported')->all())->toBe([])
        ->and(Service::query()->where('environment_id', $world->environment->id)->pluck('name')->sort()->values()->all())->toBe(['cache', 'db', 'db-analytics', 'web']); // the unit's second database stays in its environment

    $world->settle();
    $db = DatabaseInstance::query()->where('name', 'db')->sole();

    expect($run->refresh()->status)->toBe(RunStatus::Applied)
        ->and($db->status)->toBe(InstanceStatus::Active)
        ->and(collect($run->deploy)->firstWhere('unit', 'db')['status'])->toBe('skipped');

    $resolved = app(VariableReferences::class)->resolveForSite($world->deploy->site->id, [
        'DATABASE_URL' => '${{ db.DATABASE_URL }}', 'DB_PASSWORD' => '${{ db.DB_PASSWORD }}', 'REDIS_URL' => '${{ cache.REDIS_URL }}',
    ]);
    $user = $db->users()->sole();

    expect($resolved->errors)->toBe([])
        ->and($resolved->variables['DATABASE_URL'])->toStartWith('postgresql://shop:')->toContain('/shop')
        ->and($resolved->variables['DB_PASSWORD'])->toBe((string) $user->password)
        ->and($resolved->variables['REDIS_URL'])->toStartWith('redis://');
});

it('never puts a database password in the run, its plan, the export, the API or a pull request comment', function () {
    $world = config_world(actingAs: false);
    $world->file(data_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();
    $world->file(data_e2e_file('2g'));
    $world->push(config_sha('c'));
    $world->settle();
    $passwords = data_passwords();

    $project = $world->environment->project_id;
    $token = app(CreateApiToken::class)($world->deploy->user, $world->deploy->organization->id, 'cli', ['config.view', 'sites.env.view'])->plainTextToken;
    $export = $this->withToken($token)->getJson("/api/v1/projects/{$project}/config/export?environment=production")->assertOk();
    $plan = $this->withToken($token)->postJson("/api/v1/projects/{$project}/config/plan", ['file' => data_e2e_file('4g'), 'environment' => 'production'])->assertOk();
    $runs = $this->withToken($token)->getJson("/api/v1/projects/{$project}/config/runs")->assertOk();

    $world->file(data_e2e_file('3g'), config_sha('f'));
    PullRequestOpened::dispatch($world->deploy->organization->id, $world->source->source_connection_id, 'github',
        new PullRequestData('acme/shop', 3, 'x', null, 'feature', config_sha('f'), 'main', 'ada', false, 'acme/shop'));
    $comment = array_values($world->deploy->sourceControl->comments)[0]['body'];

    $everything = json_encode([ConfigRun::query()->get()->toArray(), $export->json(), $plan->json(), $runs->json(), $comment]);

    expect($passwords)->not->toBeEmpty()
        ->and($comment)->toContain('/units/db/memory')->toContain('Restarts the database container')
        ->and($export->json('data.file'))->toContain('type: database');

    foreach ($passwords as $password) {
        expect($everything)->not->toContain($password);
    }
});

it('exports database, redis and volume units; planning the export changes nothing', function () {
    $world = config_world(['runtime' => 'docker', 'framework' => 'docker', 'build_mode' => 'docker', 'php_version' => null, 'dockerfile' => 'Dockerfile', 'app_port' => 30001, 'container_port' => 8080]);
    $provider = databases_provider($world->deploy->organization, ['name' => 'offsite']);
    $world->source->forceFill(['allowed_credentials' => ['offsite']])->save();
    $world->file(<<<'YAML'
        version: 1
        units:
          web:
            type: app
            runtime: docker
            port: 8080
            env: { DATABASE_URL: "${{ db.DATABASE_URL }}" }
            volumes:
              uploads: { mount: /srv/uploads, backups: { cron: "0 4 * * *", storage: offsite, keep: 7 } }
              cache: { kind: sized, size: 512m, mount: /cache, read_only: true, protected: true }
          db:
            type: database
            engine: postgresql
            version: "17"
            memory: 768m
            disk: 20g
            settings: { max_connections: 120 }
            databases: [shop, reports]
            backups: { cron: "0 3 * * *", storage: offsite, keep: 14, databases: [shop], drill: monthly }
            pitr: { storage: offsite, window_days: 7, base_interval_days: 1 }
          cache:
            type: redis
            engine: valkey
            settings: { eviction: allkeys-lru }
        environments:
          production: { branch: main, servers: [web-1] }
        YAML);

    $world->push(config_sha('b'));
    $world->settle();
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Applied)
        ->and(Volume::query()->whereIn('name', ['uploads', 'cache'])->count())->toBe(2)
        ->and(BackupSchedule::query()->where('name', 'falak.yaml')->sole()->storage_provider_id)->toBe($provider->id);

    $environment = app(ProjectDirectory::class)->environment($world->environment->id);
    $exporter = app(Exporter::class);
    $document = $exporter->document($environment, $world->source);
    $yaml = $exporter->render($document, 'yaml');
    [$validation, $plan] = app(PlanFile::class)($world->source, $environment, $yaml, 'yaml');

    expect($document['units']['db'])->toMatchArray(['type' => 'database', 'engine' => 'postgresql', 'memory' => '768m', 'disk' => '20g', 'databases' => ['shop', 'reports']])
        ->and($document['units']['cache']['engine'])->toBe('valkey')
        ->and($document['units']['web']['volumes']['cache'])->toBe(['kind' => 'sized', 'size' => '512m', 'mount' => '/cache', 'read_only' => true, 'protected' => true, 'server' => 'web-1'])
        ->and($validation->errors)->toBe([])
        ->and(array_map(fn ($c) => [$c->op->value, $c->path], $plan?->changes->actionable() ?? ['no plan']))->toBe([]);
});

it('a run that drops a database unit waits for an approval; nothing is deleted', function () {
    $world = config_world();
    $world->file(data_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();

    $world->file(str_replace("  cache:\n    type: redis\n    memory: 128m\n", '', data_e2e_file()));
    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect($run->status)->toBe(RunStatus::AwaitingApproval)
        ->and(collect($run->plan['changes'])->pluck('rule')->filter()->all())->toContain('unit.remove_with_data')
        ->and(DatabaseInstance::query()->where('name', 'cache')->sole()->status)->toBe(InstanceStatus::Active);
});

it('answers 422 to dashboard edits of managed fields and shows the badge; other fields stay editable', function () {
    $world = config_world();
    $provider = databases_provider($world->deploy->organization, ['name' => 'offsite']);
    $world->source->forceFill(['allowed_credentials' => ['offsite']])->save();
    $world->file(data_e2e_file('1g', "  extra:\n    type: database\n    engine: mysql\n    backups: { cron: \"0 3 * * *\", storage: offsite }\n    pitr: { storage: offsite }\n"));
    $world->push(config_sha('b'));
    $world->settle();
    $db = DatabaseInstance::query()->where('name', 'db')->sole();
    $extra = DatabaseInstance::query()->where('name', 'extra')->sole();
    $schedule = BackupSchedule::query()->where('name', 'falak.yaml')->sole();
    $shop = $db->databases()->where('name', 'shop')->sole();

    $this->putJson("/databases/instances/{$db->id}", ['memory_mb' => 2048])->assertStatus(422)->assertJsonValidationErrors('memory_mb');
    $this->putJson("/databases/instances/{$db->id}", ['memory_mb' => 1024, 'require_tls' => true])->assertOk(); // unchanged managed value, unmanaged field
    $this->postJson("/databases/instances/{$db->id}/upgrade", ['version' => '18'])->assertStatus(422);
    $this->deleteJson("/databases/instances/{$db->id}", ['confirm' => 'db'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->deleteJson("/databases/databases/{$shop->id}", ['confirm' => 'shop'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    $this->putJson("/databases/instances/{$extra->id}/pitr", ['enabled' => false])->assertStatus(422)->assertJsonValidationErrors('enabled');
    $this->putJson("/databases/schedules/{$schedule->id}", ['name' => 'falak.yaml', 'storage_provider_id' => $provider->id, 'database_ids' => $schedule->databases()->pluck('databases_databases.id')->all(), 'cron' => '0 9 * * *'])
        ->assertStatus(422)->assertJsonValidationErrors('cron');
    $this->deleteJson("/databases/schedules/{$schedule->id}")->assertStatus(422);
    $this->postJson("/databases/instances/{$db->id}/schedules", ['name' => 'falak.yaml', 'storage_provider_id' => $provider->id, 'database_ids' => [$shop->id], 'cron' => '0 1 * * *'])
        ->assertStatus(422)->assertJsonValidationErrors('name');

    $this->get("/databases/instances/{$db->id}")->assertInertia(fn ($page) => $page
        ->where('instance.managed.badge', 'Managed by falak.yaml @ bbbbbbb')
        ->where('instance.managed.paths', fn ($paths) => in_array('/memory', $paths->all(), true)));

    // Disabling the source makes everything editable again.
    $world->source->forceFill(['enabled' => false])->save();
    $this->putJson("/databases/instances/{$db->id}", ['memory_mb' => 2048])->assertOk();
});

it('maps a unit to its container by resource, not by the canvas name', function () {
    $world = config_world();
    $world->file(data_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();
    $service = Service::query()->where('environment_id', $world->environment->id)->where('name', 'db')->sole();
    $service->forceFill(['name' => 'renamed'])->save();

    $world->file(data_e2e_file());
    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect(collect($run->plan['changes'])->where('unit', 'db')->where('op', '!=', 'noop')->values()->all())->toBe([])
        ->and(DatabaseInstance::query()->where('engine', 'postgresql')->count())->toBe(1);
});

it('validates names against the organization: a server the project doesn\'t have is name.server', function () {
    $world = config_world();
    Server::factory()->create(['organization_id' => $world->deploy->organization->id, 'name' => 'elsewhere']);
    $world->file(str_replace("    memory: 1g\n", "    memory: 1g\n    server: elsewhere\n", data_e2e_file()));

    $world->push(config_sha('b'));
    $run = ConfigRun::query()->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and(collect($run->errors)->firstWhere('code', 'name.server')['pointer'])->toBe('/units/db/server')
        ->and(DatabaseInstance::query()->count())->toBe(0);
});

it('an approved run that drops a database unit detaches it and lists it as unmanaged', function () {
    $world = config_world();
    $world->file(data_e2e_file());
    $world->push(config_sha('b'));
    $world->settle();

    $world->file(str_replace("  cache:\n    type: redis\n    memory: 128m\n", '', data_e2e_file()));
    $world->push(config_sha('c'));
    $run = ConfigRun::query()->where('commit', config_sha('c'))->sole();

    expect(app(ApproveRun::class)($run, $world->deploy->user->id))->toBe('approved');
    $world->settle();

    $cache = DatabaseInstance::query()->where('name', 'cache')->sole();
    expect($cache->status)->toBe(InstanceStatus::Active)
        ->and(ConfigDetached::query()->where('resource_id', $cache->id)->sole()->reason)->toBe('removed_from_file');
});
