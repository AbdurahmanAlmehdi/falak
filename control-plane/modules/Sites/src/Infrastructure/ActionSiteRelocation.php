<?php

namespace Falak\Sites\Infrastructure;

use Falak\Sites\Application\Actions\SetSiteTargets;
use Falak\Sites\Application\SiteRules;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Domain\Models\Site;
use Illuminate\Validation\ValidationException;

final class ActionSiteRelocation implements SiteRelocation
{
    public function __construct(
        private readonly SetSiteTargets $targets,
        private readonly SiteRules $rules,
    ) {}

    public function replaceServer(string $siteId, string $fromServerId, string $toServerId): void
    {
        $site = Site::query()->with('targets')->find($siteId) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);
        $current = $site->serverIds();

        if (! in_array($fromServerId, $current, true)) {
            return; // Already moved (a retried step).
        }

        $servers = array_values(array_unique(array_map(fn (string $id) => $id === $fromServerId ? $toServerId : $id, $current)));
        $leader = $site->leaderTarget()?->server_id;
        $leader = $leader === null || $leader === $fromServerId ? $toServerId : $leader;

        ($this->targets)($site, $servers, $leader);
    }

    public function addServer(string $siteId, string $serverId): void
    {
        $site = $this->site($siteId);
        $current = $site->serverIds();

        if (in_array($serverId, $current, true)) {
            return;
        }

        ($this->targets)($site, [...$current, $serverId], (string) ($site->leaderTarget()?->server_id ?? $current[0] ?? $serverId));
    }

    public function makeLeader(string $siteId, string $serverId): void
    {
        $site = $this->site($siteId);

        if (in_array($serverId, $site->serverIds(), true)) {
            ($this->targets)($site, $site->serverIds(), $serverId);
        }
    }

    public function removeServer(string $siteId, string $serverId): void
    {
        $site = $this->site($siteId);
        $leader = $site->leaderTarget()?->server_id;
        $rest = array_values(array_diff($site->serverIds(), [$serverId]));

        if ($leader === $serverId || $rest === [] || count($rest) === count($site->serverIds())) {
            return;
        }

        ($this->targets)($site, $rest, (string) ($leader ?? $rest[0]));
    }

    public function problemOn(string $siteId, string $serverId): ?string
    {
        $site = $this->site($siteId);

        try {
            $this->rules->targets($site->organization_id, [$serverId], $site->runtime, $site->php_version, $site->build_mode);
        } catch (ValidationException $e) {
            return (string) collect($e->errors())->flatten()->first();
        }

        return null;
    }

    private function site(string $siteId): Site
    {
        return Site::query()->with('targets')->find($siteId) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);
    }
}
