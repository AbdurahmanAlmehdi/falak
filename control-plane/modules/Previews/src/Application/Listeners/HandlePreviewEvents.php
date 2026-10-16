<?php

namespace Falak\Previews\Application\Listeners;

use Falak\Databases\Events\DatabaseCreated;
use Falak\Databases\Events\RestoreFinished;
use Falak\Deployments\Events\DeploymentFailed;
use Falak\Deployments\Events\DeploymentSucceeded;
use Falak\Fleet\Contracts\SkipsSandboxCommands;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Previews\Application\PreviewLifecycle;
use Falak\SourceControl\Events\PullRequestClosed;
use Falak\SourceControl\Events\PullRequestCommented;
use Falak\SourceControl\Events\PullRequestOpened;
use Falak\SourceControl\Events\PullRequestUpdated;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Pull request events and the outcomes a preview waits for (databases created, restored and sanitized, deployments),
 * handled on the queue: a webhook delivery answers at once, and the provider API calls happen here.
 */
final class HandlePreviewEvents implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use SkipsSandboxCommands;

    public function __construct(private readonly PreviewLifecycle $lifecycle) {}

    public function opened(PullRequestOpened $event): void
    {
        $this->lifecycle->opened($event->organizationId, $event->connectionId, $event->provider, $event->pullRequest);
    }

    public function updated(PullRequestUpdated $event): void
    {
        $this->lifecycle->updated($event->organizationId, $event->connectionId, $event->provider, $event->pullRequest);
    }

    public function closed(PullRequestClosed $event): void
    {
        $this->lifecycle->closed($event->organizationId, $event->connectionId, $event->pullRequest, $event->merged);
    }

    public function commented(PullRequestCommented $event): void
    {
        $this->lifecycle->commented($event->organizationId, $event->connectionId, $event->provider, $event->repository, $event->number, $event->author, $event->body, $event->authorId, $event->createdAt);
    }

    public function databaseCreated(DatabaseCreated $event): void
    {
        $this->lifecycle->databaseCreated($event->databaseId);
    }

    public function restoreFinished(RestoreFinished $event): void
    {
        $this->lifecycle->restoreFinished($event->restoreId, $event->succeeded, $event->error);
    }

    public function commandFinished(CommandFinished $event): void
    {
        if ($event->type === 'system.exec') {
            $this->lifecycle->scriptFinished($event->idempotencyKey, true, null);
        }
    }

    public function commandFailed(CommandFailed $event): void
    {
        if ($event->type === 'system.exec') {
            $this->lifecycle->scriptFinished($event->idempotencyKey, false, $event->error ?: "exit code {$event->exitCode}");
        }
    }

    public function deploymentSucceeded(DeploymentSucceeded $event): void
    {
        $this->lifecycle->deploymentFinished($event->siteId, true, null);
    }

    public function deploymentFailed(DeploymentFailed $event): void
    {
        $this->lifecycle->deploymentFinished($event->siteId, false, $event->error);
    }
}
