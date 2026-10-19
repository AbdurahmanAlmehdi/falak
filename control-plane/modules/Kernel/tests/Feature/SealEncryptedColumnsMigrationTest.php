<?php

use Falak\Alerting\Domain\Models\Channel;
use Falak\Kernel\Security\SealedColumns;
use Falak\Kernel\Security\Sealer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function sealMigration(): object
{
    return require dirname(__DIR__, 2).'/database/migrations/2026_10_23_000001_seal_encrypted_columns.php';
}

function legacyChannel(string $config, ?string $id = null): string
{
    $id ??= strtolower((string) Str::ulid());
    DB::table('alerting_channels')->insert([
        'id' => $id, 'organization_id' => strtolower((string) Str::ulid()), 'type' => 'webhook', 'name' => "c-{$id}",
        'config' => $config, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

it('re-encrypts APP_KEY ciphertexts with the new cast, and skips values already sealed', function () {
    $legacy = collect(range(1, 3))->map(fn ($i) => legacyChannel(Crypt::encryptString(json_encode(['url' => "https://hooks.example.com/{$i}"]))));
    $sealedId = strtolower((string) Str::ulid());
    legacyChannel(app(Sealer::class)->seal(json_encode(['url' => 'https://already.example.com']), "alerting_channels.config:{$sealedId}"), $sealedId);
    $sealedRaw = DB::table('alerting_channels')->where('id', $sealedId)->value('config');

    sealMigration()->up();

    foreach ($legacy as $i => $id) {
        expect(DB::table('alerting_channels')->where('id', $id)->value('config'))->toStartWith('fk1:')
            ->and(app(Sealer::class)->open(DB::table('alerting_channels')->where('id', $id)->value('config'), "alerting_channels.config:{$id}"))->toContain('hooks.example.com')
            ->and(Channel::query()->find($id)->config)->toBe(['url' => 'https://hooks.example.com/'.($i + 1)]);
    }

    expect(DB::table('alerting_channels')->where('id', $sealedId)->value('config'))->toBe($sealedRaw);

    // Idempotent: a second run changes nothing.
    $before = DB::table('alerting_channels')->orderBy('id')->pluck('config')->all();
    sealMigration()->up();
    expect(DB::table('alerting_channels')->orderBy('id')->pluck('config')->all())->toBe($before);
});

it('stops with a clear error when a value does not decrypt with APP_KEY', function () {
    legacyChannel('eyJpdiI6Im5vdC1yZWFsIn0=');

    expect(fn () => sealMigration()->up())->toThrow(RuntimeException::class, 'alerting_channels.config: a value could not be decrypted with APP_KEY');
});

it('can be reversed to APP_KEY ciphertexts', function () {
    $id = legacyChannel(Crypt::encryptString('{"url":"https://hooks.example.com/x"}'));

    sealMigration()->up();
    sealMigration()->down();

    expect(Crypt::decryptString(DB::table('alerting_channels')->where('id', $id)->value('config')))->toBe('{"url":"https://hooks.example.com/x"}');
});

it('covers exactly the columns sealed today', function () {
    // Columns added after the migration, sealed from the start (never APP_KEY ciphertexts).
    $bornSealed = ['secrets_providers.id.config', 'databases_instances.id.root_password', 'databases_instances.id.next_root_password', 'databases_instances.id.previous_password',
        'databases_restores.id.inspection_password', 'previews_previews.id.basic_password', 'sandboxes_sandboxes.id.env', 'sandboxes_snapshots.id.env',
        'network_tailscale_settings.organization_id.auth_key'];
    $migrated = array_map(fn (array $c) => implode('.', $c), sealMigration()::COLUMNS);
    $cast = array_values(array_diff(array_map(fn (array $c) => "{$c['table']}.{$c['primary_key']}.{$c['column']}", app(SealedColumns::class)->all()), $bornSealed));

    sort($migrated);
    sort($cast);

    expect($migrated)->toBe($cast);
});

it('finds every sealed model column of the modules', function () {
    app()->forgetInstance(SealedColumns::class);
    $columns = app(SealedColumns::class)->all();

    expect(count($columns))->toBe(39)
        ->and(collect($columns)->map(fn ($c) => "{$c['table']}.{$c['column']}")->all())
        ->toContain('fleet_commands.payload', 'telemetry_settings.otlp_token', 'deployments_site_settings.hook_token', 'identity_users.two_factor_secret')
        ->and(collect($columns)->firstWhere('column', 'hook_token')['primary_key'])->toBe('site_id');
});
