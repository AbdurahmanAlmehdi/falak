<?php

use Falak\Fleet\Contracts\CommandStatus;
use Falak\Fleet\Domain\Models\Agent;
use Falak\Fleet\Domain\Models\Certificate;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\MemberRemoved;
use Falak\Identity\Events\MemberRoleChanged;
use Falak\Servers\Domain\Models\Server;
use Falak\Terminal\Application\Actions\CloseSession;
use Falak\Terminal\Application\Jobs\SweepTerminalSessions;
use Falak\Terminal\Domain\Enums\SessionStatus;
use Falak\Terminal\Domain\Models\TerminalSession;
use Falak\Terminal\Infrastructure\RelayTokens;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\FakeAgentGateway;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    $this->agents = FakeAgentGateway::install();
    [$this->owner, $this->organization] = actingAsMember(Role::Owner);
    $this->server = terminal_server($this->organization, ['name' => 'main-1']);
    $this->agent = Agent::factory()->create(['server_id' => $this->server->id, 'organization_id' => $this->organization->id, 'facts' => ['features' => ['terminal.v2']]]);
    config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=', 'terminal.relay.enabled' => true, 'terminal.relay.internal_url' => 'http://relay:8080']);
    Http::fake(['relay:8080/*' => Http::response(null, 204)]);
});

/** Verified claims of a relay token. */
function relay_claims(string $token): array
{
    [$body, $signature] = explode('.', $token);
    expect($signature)->toBe(rtrim(strtr(base64_encode(hash_hmac('sha256', $body, app(RelayTokens::class)->key(), true)), '+/', '-_'), '='));

    return json_decode(base64_decode(strtr($body, '-_', '+/')), true);
}

/**
 * The kicks sent to the relay, as [session id, claims].
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function relay_kicks(): array
{
    return Http::recorded()
        ->filter(fn ($pair) => str_contains($pair[0]->url(), '/internal/v1/sessions/'))
        ->map(function ($pair) {
            /** @var Request $request */
            $request = $pair[0];
            preg_match('#^http://relay:8080/internal/v1/sessions/([^/]+)/kick$#', $request->url(), $match);
            expect($request->method())->toBe('POST');

            return [$match[1] ?? '?', relay_claims(substr($request->header('Authorization')[0], strlen('Bearer ')))];
        })->values()->all();
}

function relay_certificate(Agent $agent, array $attributes = []): Certificate
{
    return Certificate::query()->create([
        'agent_id' => $agent->id,
        'serial' => Str::random(20),
        'fingerprint' => hash('sha256', Str::random(32)),
        'certificate_pem' => 'pem',
        'not_before' => now()->subDay(),
        'not_after' => now()->addDays(30),
        ...$attributes,
    ]);
}

function relay_open(Server $server): TerminalSession
{
    $session = terminal_open($server);
    expect($session->relayed)->toBeTrue();

    return $session;
}

// ---- revocation -------------------------------------------------------------------------------------------------

it('drops every relay client when a session closes', function () {
    $session = relay_open($this->server);

    app(CloseSession::class)($session);

    expect(relay_kicks())->toHaveCount(1)
        ->and(relay_kicks()[0][0])->toBe($session->id)
        ->and(relay_kicks()[0][1])->toMatchArray(['v' => 1, 'sid' => $session->id, 'aud' => 'control'])
        ->and(relay_kicks()[0][1])->not->toHaveKey('keep')
        ->and(relay_kicks()[0][1]['exp'])->toBeLessThanOrEqual(now()->getTimestamp() + 60)
        ->and(relay_kicks()[0][1]['n'])->not->toBeEmpty();
});

it('keeps only the owner\'s relay clients when a session is unshared', function () {
    $session = relay_open($this->server);

    $this->patch("/terminal/sessions/{$session->id}/share", ['shared' => true])->assertRedirect();
    expect(relay_kicks())->toBe([]);

    $this->patch("/terminal/sessions/{$session->id}/share", ['shared' => false])->assertRedirect();
    expect(relay_kicks())->toHaveCount(1)
        ->and(relay_kicks()[0][1])->toMatchArray(['sid' => $session->id, 'aud' => 'control', 'keep' => $this->owner->id]);
});

it('does not call the relay for sessions without it, or when it is off', function () {
    $plain = terminal_server($this->organization, ['name' => 'old']);
    app(CloseSession::class)(terminal_open($plain)); // no terminal.v2 agent: not relayed

    $relayed = relay_open($this->server);
    config(['terminal.relay.enabled' => false]);
    app(CloseSession::class)($relayed);

    Http::assertNothingSent();
});

it('never fails the user action when the relay is down', function () {
    Log::spy();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $session = relay_open($this->server);

    $this->delete("/terminal/sessions/{$session->id}")->assertRedirect();

    expect($session->refresh()->status)->toBe(SessionStatus::Closed);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'relay') && $context['session_id'] === $session->id)->once();
});

it('drops relay clients of members who leave or change role', function () {
    [$member] = memberOf($this->organization, Role::Admin);
    $own = relay_open($this->server);
    $own->forceFill(['user_id' => $member->id])->save();
    $sharedByOwner = relay_open($this->server);
    $sharedByOwner->forceFill(['shared' => true])->save();
    $privateOfOwner = relay_open($this->server);
    [, $other] = memberOf();
    $foreign = TerminalSession::query()->create([...$privateOfOwner->only(['server_id', 'server_name', 'unix_user', 'idle_timeout_s']), 'organization_id' => $other->id, 'user_id' => $member->id, 'status' => SessionStatus::Open, 'relayed' => true]);

    MemberRemoved::dispatch($this->organization->id, $member->id);

    $kicks = collect(relay_kicks())->mapWithKeys(fn ($kick) => [$kick[0] => $kick[1]['keep'] ?? null]);
    expect($kicks->all())->toEqual([$own->id => null, $sharedByOwner->id => $this->owner->id])
        ->and($kicks->has($privateOfOwner->id))->toBeFalse()
        ->and($kicks->has($foreign->id))->toBeFalse();

    MemberRoleChanged::dispatch($this->organization->id, $member->id, 'admin', 'developer');
    expect(relay_kicks())->toHaveCount(4);
});

// ---- agent certificate binding ------------------------------------------------------------------------------------

it('binds the agent token to the agent\'s current client certificate', function () {
    relay_certificate($this->agent, ['fingerprint' => str_repeat('a', 64), 'not_before' => now()->subDays(60), 'superseded_at' => now()->subDay()]);
    relay_certificate($this->agent, ['fingerprint' => str_repeat('b', 64), 'not_before' => now()->subDays(90), 'revoked_at' => now()->subDays(30)]);
    relay_certificate($this->agent, ['fingerprint' => str_repeat('c', 64), 'not_before' => now()->subDays(100), 'not_after' => now()->subDay()]);
    relay_certificate($this->agent, ['fingerprint' => str_repeat('d', 64), 'not_before' => now()->subHour()]);

    relay_open($this->server);

    expect(relay_claims($this->agents->last('terminal.open')['payload']['relay_token']))->toMatchArray(['aud' => 'agent', 'fp' => str_repeat('d', 64)]);
});

it('leaves fp out when no usable certificate is known', function () {
    relay_certificate($this->agent, ['fingerprint' => str_repeat('a', 64), 'revoked_at' => now()]);

    relay_open($this->server);

    expect(relay_claims($this->agents->last('terminal.open')['payload']['relay_token']))->not->toHaveKey('fp');
});

// ---- idle sweep ----------------------------------------------------------------------------------------------------

it('does not close relayed sessions as idle while the agent still runs them', function () {
    $running = relay_open($this->server);
    $runningHandle = terminal_open_command($this->agents)['handle'];
    $this->agents->started($runningHandle);
    $orphan = relay_open($this->server);
    $orphanHandle = terminal_open_command($this->agents)['handle'];
    $this->agents->started($orphanHandle);
    $overlong = relay_open($this->server);
    $this->agents->started(terminal_open_command($this->agents)['handle']);

    // The agent's terminal.open of $orphan ended but the outcome never reached the session.
    $this->agents->commands[$orphanHandle->id]['status'] = CommandStatus::Succeeded;

    foreach ([$running, $orphan] as $session) {
        $session->forceFill(['last_activity_at' => now()->subSeconds(900 + 61)])->save();
    }
    $overlong->forceFill(['created_at' => now()->subSeconds(3600 + 61), 'last_activity_at' => now()])->save();

    app()->call([new SweepTerminalSessions, 'handle']);

    expect($running->refresh()->status)->toBe(SessionStatus::Open)
        ->and($orphan->refresh()->close_reason)->toBe('idle')
        ->and($overlong->refresh()->close_reason)->toBe('timeout');
});
