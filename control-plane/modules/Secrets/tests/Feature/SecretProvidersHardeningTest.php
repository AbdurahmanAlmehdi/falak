<?php

use Falak\Identity\Contracts\Role;
use Falak\Secrets\Application\Actions\DeleteSecret;
use Falak\Secrets\Application\Actions\RollBackSecret;
use Falak\Secrets\Application\Actions\SaveSecretProvider;
use Falak\Secrets\Application\Actions\SetSecretValue;
use Falak\Secrets\Application\Jobs\PollLinkedSecret;
use Falak\Secrets\Application\Jobs\PollLinkedSecrets;
use Falak\Secrets\Application\LinkedSecretWatch;
use Falak\Secrets\Application\Providers\ProviderValueCache;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Exceptions\SecretProviderUnavailable;
use Falak\Secrets\Contracts\SecretProviders;
use Falak\Secrets\Contracts\Secrets;
use Falak\Secrets\Domain\Enums\ProviderType;
use Falak\Secrets\Domain\Models\ProviderValue;
use Falak\Secrets\Domain\Models\SecretVersion;
use Falak\Secrets\Events\ProviderUnreachable;
use Falak\Secrets\Infrastructure\ExternalSecretProviders;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    [$this->user, $this->organization] = memberOf();
    secrets_guard();
    Http::preventStrayRequests();
});

it('asks for every credential again when the endpoint or where it is sent changes', function () {
    Http::fake(['*' => Http::response(['data' => []])]);
    $provider = secrets_vault($this->organization, ['auth_method' => 'approle', 'role_id' => 'r', 'secret_id' => 'STORED-SECRET-ID', 'token' => '']);
    $save = app(SaveSecretProvider::class);
    $settings = ['address' => 'https://vault.example.com', 'auth_method' => 'approle', 'role_id' => 'r'];

    // Same endpoint: the stored secret id stays.
    $save($this->organization->id, $provider, ['config' => [...$settings, 'role_id' => 'r2']], null);
    expect($provider->refresh()->config['secret_id'])->toBe('STORED-SECRET-ID');

    // Another endpoint (or CA, namespace) without the credential: refused, nothing changes.
    foreach ([['address' => 'https://attacker.example.net'], ['namespace' => 'other'], ['ca_pem' => "-----BEGIN CERTIFICATE-----\nX\n-----END CERTIFICATE-----"]] as $change) {
        expect(fn () => $save($this->organization->id, $provider->refresh(), ['config' => [...$settings, ...$change]], null))
            ->toThrow(ValidationException::class, 'Enter the Secret ID again');
    }
    expect($provider->refresh()->config['address'])->toBe('https://vault.example.com');

    // With the credential entered again: saved.
    $save($this->organization->id, $provider->refresh(), ['config' => [...$settings, 'address' => 'https://vault2.example.com', 'secret_id' => 'NEW-SECRET-ID']], null);
    expect($provider->refresh()->config['secret_id'])->toBe('NEW-SECRET-ID');

    // Renaming or changing the TTL (no settings sent) keeps everything.
    $save($this->organization->id, $provider->refresh(), ['name' => 'Renamed', 'cache_ttl_seconds' => 60], null);
    expect($provider->refresh()->config['secret_id'])->toBe('NEW-SECRET-ID')->and($provider->name)->toBe('Renamed');
});

it('applies the same rule to AWS regions and roles, and webhook headers', function () {
    $aws = secrets_provider($this->organization, ProviderType::AwsSecretsManager, ['region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLEKEY12', 'secret_access_key' => 'STORED-KEY']);
    $hook = secrets_provider($this->organization, ProviderType::Http, ['base_url' => 'https://hook.example.com', 'header_name' => 'X-Api-Key', 'header_value' => 'STORED-HEADER']);
    $save = app(SaveSecretProvider::class);

    expect(fn () => $save($this->organization->id, $aws, ['config' => ['region' => 'us-east-1', 'access_key_id' => 'AKIAEXAMPLEKEY12']], null))->toThrow(ValidationException::class, 'again')
        ->and(fn () => $save($this->organization->id, $aws, ['config' => ['region' => 'eu-west-1', 'access_key_id' => 'AKIAEXAMPLEKEY12', 'role_arn' => 'arn:aws:iam::123456789012:role/x']], null))->toThrow(ValidationException::class, 'again')
        ->and(fn () => $save($this->organization->id, $hook, ['config' => ['base_url' => 'https://hook.example.com', 'header_name' => 'X-Other']], null))->toThrow(ValidationException::class, 'again');
});

it('lets only admins manage providers, while developers keep managing secrets', function () {
    $provider = secrets_vault($this->organization);
    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);

    $this->postJson('/secrets/providers', ['name' => 'X', 'type' => 'doppler', 'config' => ['token' => 't']])->assertForbidden();
    $this->patchJson("/secrets/providers/{$provider->id}", ['config' => ['address' => 'https://attacker.example.net', 'auth_method' => 'token']])->assertForbidden();
    $this->postJson("/secrets/providers/{$provider->id}/test")->assertForbidden();
    $this->deleteJson("/secrets/providers/{$provider->id}")->assertForbidden();
    $this->postJson('/secrets', ['name' => 'K', 'scope' => 'organization', 'scope_id' => $this->organization->id, 'kind' => 'linked', 'reference' => 'vault://kv/data/app#K', 'provider_id' => $provider->id])->assertCreated();

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin);
    $this->patchJson("/secrets/providers/{$provider->id}", ['name' => 'Admin rename'])->assertOk();
});

it('refuses private endpoints at request time unless both the instance and the provider allow them', function () {
    secrets_guard(['vault.lan' => ['10.0.0.9']]);
    expect(config('secrets.providers.allow_private_network'))->toBeFalse();

    config(['secrets.providers.allow_private_network' => true]);
    $provider = secrets_vault($this->organization, ['address' => 'https://vault.lan'], ['allow_private_network' => true]);
    Http::fake(['*' => Http::response(['data' => ['data' => ['K' => 'lan-value']]])]);
    expect(app(ExternalSecretProviders::class)->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))->toBe('lan-value');

    // The operator turns private networks off: the saved provider stops reaching them.
    config(['secrets.providers.allow_private_network' => false]);
    expect(fn () => app(ExternalSecretProviders::class)->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))
        ->toThrow(SecretProviderUnavailable::class, 'private or reserved');
    Http::assertSentCount(1);
});

it('ignores the private-network setting on a managed install', function () {
    secrets_guard(['vault.lan' => ['10.0.0.9']]);
    config(['secrets.providers.allow_private_network' => true]);
    $provider = secrets_vault($this->organization, ['address' => 'https://vault.lan'], ['allow_private_network' => true]);
    Http::fake(['*' => Http::response(['data' => ['data' => ['K' => 'lan-value']]])]);

    config(['controlplane.managed' => true]);
    expect(fn () => app(ExternalSecretProviders::class)->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))
        ->toThrow(SecretProviderUnavailable::class, 'private or reserved')
        ->and(fn () => secrets_vault($this->organization, ['address' => 'https://vault.lan'], ['allow_private_network' => true]))
        ->toThrow(ValidationException::class, 'private networks');
    Http::assertNothingSent();
});

it('drops a poll whose secret changed while the provider answered', function () {
    $provider = secrets_vault($this->organization);
    $secret = secrets_linked($this->organization, 'DB_PASS', 'vault://kv/data/app#DB_PASS', $provider, ['watch_minutes' => 5]);
    $GLOBALS['answer'] = 'first';
    Http::fake(function () {
        if (isset($GLOBALS['during_poll'])) {
            ($GLOBALS['during_poll'])();
            unset($GLOBALS['during_poll']);
        }

        return Http::response(['data' => ['data' => ['DB_PASS' => $GLOBALS['answer']]]]);
    });
    $watch = app(LinkedSecretWatch::class);
    $watch->poll($secret);
    $GLOBALS['answer'] = 'second';
    $watch->poll($secret->refresh()); // v2 (changed upstream)

    // A rollback lands while the next poll waits for the provider: the poll must not override it.
    $GLOBALS['answer'] = 'third';
    $GLOBALS['during_poll'] = fn () => app(RollBackSecret::class)($secret->refresh(), 1, null);
    app()->forgetScopedInstances();
    expect($watch->poll($secret->refresh()))->toBeFalse()
        ->and($secret->refresh()->current_version)->toBe(3)
        ->and(SecretVersion::query()->where('secret_id', $secret->id)->where('version', 3)->sole()->pinned())->toBeTrue();

    // The same for a stale version number handed to the write directly.
    expect(app(SetSecretValue::class)->upstreamChange($secret->refresh(), 2, 'vault://kv/data/app#DB_PASS', 'x', 'k:h'))->toBeNull();
});

it('caps provider answers at 1 MiB and values at the environment variable limit', function () {
    $provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 0]);
    Http::fake([
        'https://vault.example.com/v1/kv/data/huge' => Http::response(str_repeat('x', 1_048_577)),
        'https://vault.example.com/v1/kv/data/big' => Http::response(['data' => ['data' => ['K' => str_repeat('v', 65_536)]]]),
    ]);
    $providers = app(ExternalSecretProviders::class);

    expect(fn () => $providers->refresh('vault://kv/data/huge#K', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'larger than 1 MiB')
        ->and(fn () => $providers->refresh('vault://kv/data/big#K', $provider->id, $this->organization->id))->toThrow(SecretProviderUnavailable::class, 'larger than 65535 bytes')
        ->and(ProviderValue::query()->count())->toBe(0);
});

it('stops using the last good value after the maximum stale age, with an alert', function () {
    Event::fake([ProviderUnreachable::class]);
    $provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 60]);
    $GLOBALS['vault_up'] = true;
    Http::fake(fn () => $GLOBALS['vault_up'] ? Http::response(['data' => ['data' => ['K' => 'old']]]) : throw new ConnectionException('down'));
    $resolve = function () use ($provider) {
        app()->forgetScopedInstances();

        return app(SecretProviders::class)->resolve('vault://kv/data/app#K', $provider->id, $this->organization->id);
    };

    $resolve();
    $GLOBALS['vault_up'] = false;
    $this->travel(23)->hours();
    expect($resolve())->toBe('old');

    $this->travel(2)->hours();
    expect(fn () => $resolve())->toThrow(SecretProviderUnavailable::class, 'older than the maximum stale age');
    Event::assertDispatched(ProviderUnreachable::class, fn (ProviderUnreachable $e) => ! $e->usedStale);
});

it('drops cached values and logins when the provider settings change', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['K' => 'from-old-vault']]])]);
    $provider = secrets_vault($this->organization);
    app(SecretProviders::class)->resolve('vault://kv/data/app#K', $provider->id, $this->organization->id);
    $row = ProviderValue::query()->sole();

    app(SaveSecretProvider::class)($this->organization->id, $provider, ['config' => ['address' => 'https://vault2.example.com', 'auth_method' => 'token', 'token' => 'hvs.NEW']], null);

    expect($provider->refresh()->config_version)->toBe(2)
        ->and(ProviderValue::query()->count())->toBe(0);

    // A row sealed for the old config version does not open under the new one.
    ProviderValue::query()->create($row->only(['organization_id', 'provider_id', 'reference_hash', 'ciphertext', 'fetched_at']));
    expect(app(ProviderValueCache::class)->get($provider, 'vault://kv/data/app#K'))->toBeNull();
});

it('queues one poll per due secret, a bounded number per organization', function () {
    Queue::fake();
    $provider = secrets_vault($this->organization);
    $due = collect(range(1, 30))->map(fn (int $i) => secrets_linked($this->organization, "K{$i}", 'vault://kv/data/app#K', $provider, ['watch_minutes' => 5]));
    [, $other] = memberOf();
    $theirs = secrets_linked($other, 'THEIRS', 'vault://kv/data/app#K', secrets_vault($other), ['watch_minutes' => 5]);

    (new PollLinkedSecrets)->handle();

    Queue::assertPushed(PollLinkedSecret::class, 26);
    Queue::assertPushed(PollLinkedSecret::class, fn (PollLinkedSecret $job) => $job->secretId === $theirs->id && $job->uniqueId() === $theirs->id);
    expect(Queue::pushed(PollLinkedSecret::class)->filter(fn ($job) => $job->organizationId === $this->organization->id))->toHaveCount(25)
        ->and($due->first()->refresh()->next_poll_at->isFuture())->toBeTrue()
        ->and((new PollLinkedSecret('x', 'y'))->uniqueFor)->toBeGreaterThan((new PollLinkedSecret('x', 'y'))->timeout);

    // Queued secrets are not queued again by the next run.
    Queue::fake();
    (new PollLinkedSecrets)->handle();
    Queue::assertPushed(PollLinkedSecret::class, 5);
});

it('runs at most N polls of one organization at once', function () {
    config(['secrets.providers.poll_concurrency_per_organization' => 2]);
    $held = [PollLinkedSecret::acquireSlot('org-1', 60), PollLinkedSecret::acquireSlot('org-1', 60)];

    expect($held)->each->not->toBeNull()
        ->and(PollLinkedSecret::acquireSlot('org-1', 60))->toBeNull()
        ->and(PollLinkedSecret::acquireSlot('org-2', 60))->not->toBeNull();

    $held[0]->release();
    expect(PollLinkedSecret::acquireSlot('org-1', 60))->not->toBeNull();
    Cache::flush();
});

it('refuses hop-by-hop auth headers and dot segments in Vault paths', function () {
    foreach (['Host', 'content-length', 'Transfer-Encoding', 'Connection', 'Upgrade'] as $header) {
        expect(fn () => secrets_provider($this->organization, ProviderType::Http, ['base_url' => 'https://hook.example.com', 'header_name' => $header, 'header_value' => 'v']))
            ->toThrow(ValidationException::class, 'can\'t be used as the auth header');
    }

    foreach (['../sys', 'approle/..', 'a//b', '.'] as $mount) {
        expect(fn () => secrets_vault($this->organization, ['auth_method' => 'approle', 'role_id' => 'r', 'secret_id' => 's', 'auth_mount' => $mount]))
            ->toThrow(ValidationException::class);
    }
});

it('purges the cached values of a deleted linked secret unless another secret uses the reference', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['A' => 'a', 'B' => 'b']]])]);
    $provider = secrets_vault($this->organization);
    $a = secrets_linked($this->organization, 'A', 'vault://kv/data/app#A', $provider);
    $shared = secrets_linked($this->organization, 'B', 'vault://kv/data/app#B', $provider);
    $twin = secrets_linked($this->organization, 'B_TWIN', 'vault://kv/data/app#B', $provider);
    app(Secrets::class)->resolve(new ScopeChain($this->organization->id), ['A', 'B']);
    expect(ProviderValue::query()->count())->toBe(2);

    app(DeleteSecret::class)($a);
    app(DeleteSecret::class)($shared);

    expect(ProviderValue::query()->pluck('reference_hash')->all())->toBe([ProviderValueCache::hash('vault://kv/data/app#B')]);
    app(DeleteSecret::class)($twin);
    expect(ProviderValue::query()->count())->toBe(0);
});

it('finds providers by id whatever its case', function () {
    Http::fake(['*' => Http::response(['data' => ['data' => ['K' => 'v']]])]);
    $provider = secrets_vault($this->organization);

    expect(app(SecretProviders::class)->resolve('vault://kv/data/app#K', strtoupper($provider->id), $this->organization->id))->toBe('v')
        ->and(app(SecretProviders::class)->exists(strtoupper($provider->id), $this->organization->id))->toBeTrue();
});

it('asks a failing provider once per deployment, then uses the last good values', function () {
    $provider = secrets_vault($this->organization, attributes: ['cache_ttl_seconds' => 60]);
    secrets_linked($this->organization, 'A', 'vault://kv/data/app#A', $provider);
    secrets_linked($this->organization, 'B', 'vault://kv/data/app#B', $provider);
    $GLOBALS['vault_up'] = true;
    $GLOBALS['vault_calls'] = 0;
    Http::fake(function () {
        $GLOBALS['vault_calls']++;

        return $GLOBALS['vault_up'] ? Http::response(['data' => ['data' => ['A' => 'a', 'B' => 'b']]]) : throw new ConnectionException('timeout');
    });
    $chain = new ScopeChain($this->organization->id);
    app(Secrets::class)->resolve($chain, ['A', 'B']);

    $GLOBALS['vault_up'] = false;
    $this->travel(2)->minutes();
    app()->forgetScopedInstances();
    $GLOBALS['vault_calls'] = 0;

    // One failed request (retried once by the client), not one per secret.
    expect(app(Secrets::class)->resolve($chain, ['A', 'B'])->values)->toBe(['A' => 'a', 'B' => 'b'])
        ->and($GLOBALS['vault_calls'])->toBe(2);
});

it('refuses provider endpoints whose IPv6 addresses embed metadata or private IPv4 ones', function () {
    config(['secrets.providers.allow_private_network' => true]);

    foreach (['::ffff:a9fe:a9fe', '64:ff9b::a9fe:a9fe', '2002:a9fe:a9fe::1', 'fec0::1'] as $address) {
        secrets_guard(['vault.example.com' => [$address]]);
        expect(fn () => secrets_vault($this->organization, attributes: ['allow_private_network' => true]))->toThrow(ValidationException::class, 'never allowed');
    }

    // Saved while public; rebound to a 6to4 address embedding a private one before the request.
    secrets_guard();
    $provider = secrets_vault($this->organization);
    secrets_guard(['vault.example.com' => ['2002:c0a8:0101::1']]);
    Http::fake();

    expect(fn () => app(ExternalSecretProviders::class)->refresh('vault://kv/data/app#K', $provider->id, $this->organization->id))
        ->toThrow(SecretProviderUnavailable::class, 'private or reserved');
    Http::assertNothingSent();
});
