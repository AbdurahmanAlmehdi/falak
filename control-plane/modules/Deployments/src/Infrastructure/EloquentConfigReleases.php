<?php

namespace Falak\Deployments\Infrastructure;

use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Application\Orchestration\Orchestrator;
use Falak\Deployments\Contracts\ConfigReleases;
use Falak\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Falak\Deployments\Domain\Enums\DeploymentStatus;
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
        private readonly Orchestrator $orchestrator,
    ) {}

    public function deploy(string $siteId, ?string $branch, ?string $commit, ?string $message = null, ?string $author = null, ?string $requestedBy = null, ?string $after = null): string
    {
        $site = $this->sites->find(strtolower($siteId)) ?? throw ValidationException::withMessages(['site' => 'Unknown site.']);
        $replaces = null;

        // The same commit already on its way (push to deploy got the push first): that deployment is the unit's.
        if ($commit !== null && ($active = Deployment::activeFor($site->id, $commit)) !== null) {
            if ($after === null || strcmp($active->id, strtolower($after)) > 0) {
                return $active->id;
            }

            // Queued before the site's config changed (push to deploy got the push before Config's phase A): it would
            // release the old config. A queued or waiting one is cancelled and a building one stops at its next step;
            // one already deploying can't be stopped and finishes. Either way the new one queues behind it.
            $this->orchestrator->cancel($active->id);
            // The replacement is the same deploy: who asked for it, how, and its variables.
            $replaces = $active;
        }

        $trigger = fn () => ($this->trigger)($site, $replaces->trigger ?? ($requestedBy === null ? Trigger::Push : Trigger::Manual), branch: $branch, commit: $commit,
            message: $message ?? $replaces?->commit_message, author: $author ?? $replaces?->commit_author, variables: (array) ($replaces?->variables ?? []),
            requestedBy: $requestedBy ?? $replaces?->requested_by);

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

    public function ended(string $deploymentId): ?array
    {
        $deployment = Deployment::query()->find(strtolower($deploymentId));

        if ($deployment === null || ! in_array($deployment->status, [DeploymentStatus::Failed, DeploymentStatus::Cancelled], true)) {
            return null;
        }

        return ['status' => $deployment->status->value, 'number' => $deployment->number, 'error' => $deployment->error];
    }

    public function released(string $siteId): bool
    {
        return Release::query()->where('site_id', strtolower($siteId))->whereNotNull('activated_at')->exists();
    }
}
