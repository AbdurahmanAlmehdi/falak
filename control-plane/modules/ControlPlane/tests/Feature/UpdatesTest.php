<?php

use Falak\ControlPlane\Application\ReleaseCheck;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Domain\Models\AuditEntry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function () {
    config(['controlplane.version' => 'v0.23.0', 'controlplane.update_check' => true]);
    Cache::forget(ReleaseCheck::CACHE_KEY);
    $this->dir = host_channel();
    [$this->owner, $this->organization] = actingAsMember(Role::Owner);
});

afterEach(fn () => exec('rm -rf '.escapeshellarg($this->dir)));

function falak_update_prop(object $test): mixed
{
    $props = null;
    $test->get('/settings/profile')->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props']['falakUpdate'] ?? null;
    });

    return $props;
}

it('shows "update available" to the operator organization\'s owners and admins only', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release())]);
    app(ReleaseCheck::class)->check();

    expect(falak_update_prop($this))->toBe(['current' => 'v0.23.0', 'latest' => 'v0.24.0', 'update_available' => true, 'settings_url' => '/settings/falak-updates']);

    [$admin] = memberOf($this->organization, Role::Admin);
    $this->actingAs($admin);
    expect(falak_update_prop($this))->toMatchArray(['update_available' => true]);

    [$developer] = memberOf($this->organization, Role::Developer);
    $this->actingAs($developer);
    expect(falak_update_prop($this))->toBeNull();
    $this->get('/settings/falak-updates')->assertForbidden();
    $this->post('/settings/falak-updates', ['version' => 'v0.24.0'])->assertForbidden();

    // Another organization of a shared install never sees the control plane.
    config(['recovery.operator_organization' => $this->organization->id]);
    [$other] = memberOf(null, Role::Owner);
    $this->actingAs($other);
    expect(falak_update_prop($this))->toBeNull();
    $this->get('/settings/falak-updates')->assertNotFound();
    $this->getJson('/settings/falak-updates/status')->assertNotFound();
});

it('renders the settings page with the release notes as data', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release())]);
    app(ReleaseCheck::class)->check();

    $this->get('/settings/falak-updates')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('ControlPlane/Updates', false)
        ->where('current', 'v0.23.0')
        ->where('release.tag', 'v0.24.0')
        ->where('release.notes', "## What's new\n<script>alert(1)</script>")
        ->where('update_available', true)
        ->where('channel.available', true)
        ->where('channel.busy', false));
});

it('checks now on request', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release(['tag_name' => 'v0.23.0']))]);

    $this->post('/settings/falak-updates/check')->assertRedirect()->assertSessionHas('success', 'Falak is up to date.');
    expect(app(ReleaseCheck::class)->latest()['tag'])->toBe('v0.23.0');

    config(['controlplane.update_check' => false]);
    $this->post('/settings/falak-updates/check')->assertSessionHasErrors('check');
});

it('hands the update to the host, audits it and refuses a second one', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release())]);
    app(ReleaseCheck::class)->check();

    // Only the release the page showed (the latest, newer than this panel).
    $this->post('/settings/falak-updates', ['version' => 'v0.25.0'])->assertSessionHasErrors('version');

    $this->post('/settings/falak-updates', ['version' => 'v0.24.0'])->assertSessionHasNoErrors()->assertRedirect();

    $request = json_decode((string) file_get_contents("{$this->dir}/request.json"), true);
    expect($request)->toMatchArray(['action' => 'update', 'version' => 'v0.24.0', 'requested_by' => $this->owner->id]);

    $entry = AuditEntry::query()->where('action', 'controlplane.update_requested')->sole();
    expect($entry->context)->toMatchArray(['request_id' => $request['id'], 'from' => 'v0.23.0', 'to' => 'v0.24.0']);

    $this->post('/settings/falak-updates', ['version' => 'v0.24.0'])->assertSessionHasErrors(['version' => 'The host has not picked up the previous request (update) yet.']);

    // The host picked it up and is running it: the page polls status + log.
    unlink("{$this->dir}/request.json");
    host_status($this->dir, ['id' => $request['id'], 'log' => "{$request['id']}.log"]);
    file_put_contents("{$this->dir}/{$request['id']}.log", "==> backup\n==> pulling v0.24.0\n");

    $this->getJson('/settings/falak-updates/status')->assertOk()->assertJson([
        'current' => 'v0.23.0',
        'channel' => ['busy' => true, 'pending' => null, 'status' => ['id' => $request['id'], 'state' => 'running']],
        'log' => "==> backup\n==> pulling v0.24.0\n",
    ]);
});

it('refuses the update without the host channel', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release())]);
    app(ReleaseCheck::class)->check();
    config(['controlplane.host_channel_dir' => $this->dir.'/missing']);

    $this->post('/settings/falak-updates', ['version' => 'v0.24.0'])->assertSessionHasErrors(['version' => 'The host channel is not installed on this control plane: run sudo falak-ctl update on the host (v0.23 installs it).']);
    expect(AuditEntry::query()->where('action', 'controlplane.update_requested')->exists())->toBeFalse();
});

it('offers no update when the panel is current', function () {
    Http::fake(['api.github.com/*' => Http::response(github_release(['tag_name' => 'v0.23.0']))]);
    app(ReleaseCheck::class)->check();

    expect(falak_update_prop($this))->toMatchArray(['update_available' => false]);
    $this->post('/settings/falak-updates', ['version' => 'v0.23.0'])->assertSessionHasErrors('version');
    expect(file_exists("{$this->dir}/request.json"))->toBeFalse();
});
