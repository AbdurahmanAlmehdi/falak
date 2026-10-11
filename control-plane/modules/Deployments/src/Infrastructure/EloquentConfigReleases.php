<?php

namespace Falak\Deployments\Infrastructure;

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Deployments\Domain\Models\Release;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Validation\ValidationException;

final class EloquentConfigReleases implements ConfigReleases
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
    ) {}

    public function deploy(string $siteId, ?string $branch, ?string $commit, ?string $message = null, ?string $author = null, ?string $requestedBy = null): string
    {
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);

        // The same commit already on its way (push to deploy got the push first): that deployment is the unit's.
        if ($commit !== null && ($active = Deployment::activeFor($site->id, $commit)) !== null) {
            return $active->id;
        }

        $trigger = fn () => ($this->trigger)($site, $requestedBy === null ? Trigger::Push : Trigger::Manual, branch: $branch, commit: $commit, message: $message, author: $author, requestedBy: $requestedBy);

        try {
            return $trigger()->id;
        } catch (DeploymentTriggerBusy) {
            return $trigger()->id; // another trigger held the site's lock: one more wait
        }
    }

    public function store(string $releaseId, array $config): void
    {
        Release::query()->whereKey(strtolower($releaseId))->update(['config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), 'updated_at' => now()]);
    }

    public function config(string $releaseId): ?array
    {
        $config = Release::query()->whereKey(strtolower($releaseId))->first()?->config;

        return is_array($config) ? $config : null;
    }

    public function releaseOf(string $deploymentId): ?string
    {
        return Deployment::query()->whereKey(strtolower($deploymentId))->value('release_id');
    }
}
