<?php

namespace Falak\Deployments\Application\Listeners;

use Falak\Config\Contracts\ConfigSources;
use Falak\Deployments\Application\Actions\TriggerDeployment;
use Falak\Deployments\Contracts\Exceptions\DeploymentTriggerBusy;
use Falak\Deployments\Domain\Enums\Trigger;
use Falak\Deployments\Domain\Models\Deployment;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\SourceControl\Events\PushReceived;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push-to-deploy: a verified push to a site's branch deploys every site with push-to-deploy enabled. Sites of a
 * project that follows a falak.yaml are left to Config, which deploys the units the push changed.
 */
final class DeployOnPush implements ShouldQueue
{
    public function __construct(
        private readonly SiteDirectory $sites,
        private readonly TriggerDeployment $trigger,
        private readonly ConfigSources $config,
    ) {}

    public function handle(PushReceived $event): void
    {
        foreach ($this->sites->forRepository($event->connectionId, $event->repository, $event->branch) as $site) {
            if (! $site->pushToDeploy || $site->organizationId !== $event->organizationId || $site->branch !== $event->branch) {
                continue;
            }

            if ($this->config->managesSite($site->id) || Deployment::activeFor($site->id, $event->commit->sha) !== null) {
                continue; // Config deploys it, or it already deploys this commit (Config got the push first)
            }

            $trigger = fn () => ($this->trigger)(
                $site,
                Trigger::Push,
                branch: $event->branch,
                commit: $event->commit->sha,
                message: $event->commit->message,
                author: $event->commit->authorName ?? $event->pusher,
            );

            try {
                try {
                    $trigger();
                } catch (DeploymentTriggerBusy) {
                    $trigger(); // another trigger held the site's lock: one more wait before giving up on this push
                }
            } catch (Throwable $e) {
                Log::warning('deployments: push-to-deploy failed', ['site_id' => $site->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
