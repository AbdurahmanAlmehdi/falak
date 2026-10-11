<?php

namespace Falak\Previews\Application;

use Falak\Config\Contracts\PreviewConfig;
use Falak\Config\Contracts\PreviewFile;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Databases\Contracts\DatabaseProvisioner;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Edge\Contracts\PreviewDomains;
use Falak\Identity\Contracts\AuditLog;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Previews\Domain\Models\Preview;
use Falak\Previews\Domain\Models\PreviewSettings;
use Falak\Previews\Domain\Policies\PreviewPolicy;
use Falak\Projects\Contracts\Data\ServiceData;
use Falak\Projects\Contracts\PreviewEnvironments;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteFactory;
use Falak\SourceControl\Contracts\Data\PullRequestData;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Falak\Volumes\Contracts\ServiceVolumes;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A pull request's preview from open to close (docs/plans/V0_10_PRODUCTION.md §11):
 *
 * - opened → per project whose base environment deploys the repository: a fork's pull request waits for a member's
 *   approval; past the project's limit it queues; else the base environment is forked (only the included services,
 *   on the preview server), databases are created per strategy (empty, the newest backup of an environment, or
 *   production's restored and sanitized — the preview never starts when the sanitize script fails, and the restored
 *   data is deleted), hosts are routed under the preview domain behind basic auth, and every site deploys the head;
 * - updated → redeploy (a fork's new commits wait for a new approval);
 * - closed → everything is deleted (sites and their volumes, databases and their volumes, DNS records, the
 *   environment), and the next queued preview starts.
 *
 * Each step reports in the pull request's one Falak comment and the `falak/preview` commit status.
 *
 * Projects that follow a falak.yaml build previews from the file ({@see PreviewConfig}): the pull request head's file
 * (a fork's: the base branch's) is applied to the preview environment before each deploy, so the units' settings,
 * processes, schedules and limits are the file's, and units the base environment doesn't have are created there.
 */
final class PreviewLifecycle
{
    /** Database container size of a preview. */
    private const DB_MEMORY_MB = 256;

    private const DB_DISK_GB = 5;

    public function __construct(
        private readonly ProjectDirectory $projects,
        private readonly PreviewEnvironments $environments,
        private readonly SiteDirectory $sites,
        private readonly SiteFactory $siteFactory,
        private readonly DatabaseDirectory $databaseDirectory,
        private readonly DatabaseProvisioner $databases,
        private readonly PreviewDomains $domains,
        private readonly DeploymentTrigger $deployments,
        private readonly ServiceVolumes $volumes,
        private readonly SourceControlGateway $sourceControl,
        private readonly OrganizationAccess $access,
        private readonly PreviewNotifier $notifier,
        private readonly PreviewServers $servers,
        private readonly AuditLog $audit,
        private readonly PreviewConfig $config,
    ) {}

    // ---- pull request events -----------------------------------------------------------------

    /**
     * @return list<Preview>
     */
    public function opened(string $organizationId, string $connectionId, string $provider, PullRequestData $pr): array
    {
        $previews = [];

        foreach ($this->settingsFor($organizationId, $connectionId, $pr->repository) as $settings) {
            $preview = $this->locked($settings, $connectionId, $pr, function (?Preview $preview) use ($settings, $connectionId, $provider, $pr) {
                if ($preview !== null && $preview->status !== Preview::CLOSED) {
                    return $preview;
                }

                $preview ??= new Preview([
                    'organization_id' => $settings->organization_id,
                    'project_id' => $settings->project_id,
                    'connection_id' => $connectionId,
                    'repository' => $pr->repository,
                    'number' => $pr->number,
                ]);
                $preview->forceFill([
                    ...$this->pullRequestFields($pr),
                    'provider' => $provider,
                    'status' => $pr->isFork ? Preview::WAITING_APPROVAL : Preview::QUEUED,
                    'status_message' => null,
                    'environment_id' => null,
                    'sites' => null,
                    'databases' => null,
                    'urls' => null,
                    'deployments' => null,
                    'approved_by' => null,
                    'approved_at' => null,
                    'closed_at' => null,
                    'head_updated_at' => now(),
                    'last_activity_at' => now(),
                ]);

                try {
                    $preview->save();
                } catch (UniqueConstraintViolationException) {
                    return null;
                }

                $this->audit->record('previews.opened', 'preview', $preview->id, ['pull_request' => $preview->label(), 'fork' => $preview->is_fork], $preview->organization_id);

                if ($preview->is_fork) {
                    $this->notifier->update($preview);
                } else {
                    $this->start($preview);
                }

                return $preview;
            });

            if ($preview !== null) {
                $previews[] = $preview;
            }
        }

        return $previews;
    }

    public function updated(string $organizationId, string $connectionId, string $provider, PullRequestData $pr): void
    {
        foreach ($this->previewsOf($organizationId, $connectionId, $pr->repository, $pr->number) as $preview) {
            if ($preview->status === Preview::CLOSED) {
                continue;
            }

            $changed = $preview->head_sha !== $pr->headSha;

            if (! $changed) {
                $preview->forceFill($this->pullRequestFields($pr))->save();

                continue;
            }

            // New code from a fork is untrusted again: a member approves each new head. The head and the status change
            // in one write (never an approved preview with an unreviewed head), and a fork's pushes don't keep it alive
            // (the idle TTL still applies).
            if ($preview->is_fork) {
                $preview->forceFill([...$this->pullRequestFields($pr), 'head_updated_at' => now(),
                    'status' => Preview::WAITING_APPROVAL, 'approved_by' => null, 'approved_at' => null, 'status_message' => null])->save();
                $this->dropUnfinishedSanitized($preview);
                $this->notifier->update($preview);

                continue;
            }

            $preview->forceFill([...$this->pullRequestFields($pr), 'head_updated_at' => now(), 'last_activity_at' => now()])->save();

            match ($preview->status) {
                Preview::READY, Preview::FAILED, Preview::DEPLOYING => $preview->environment_id !== null ? $this->deploy($preview) : $this->start($preview),
                // Creating: the deploy that follows takes the new head.
                default => $this->notifier->update($preview),
            };
        }

        // Previews enabled after the pull request opened: start now.
        if ($this->previewsOf($organizationId, $connectionId, $pr->repository, $pr->number) === []) {
            $this->opened($organizationId, $connectionId, $provider, $pr);
        }
    }

    public function closed(string $organizationId, string $connectionId, PullRequestData $pr, bool $merged): void
    {
        foreach ($this->previewsOf($organizationId, $connectionId, $pr->repository, $pr->number) as $preview) {
            if ($preview->status !== Preview::CLOSED) {
                $this->destroy($preview, $merged ? 'The pull request was merged.' : 'The pull request was closed.');
            }
        }
    }

    /**
     * A `/falak preview` comment approves a fork's pull request when its author connected that provider account in
     * Falak (an OAuth or token connection of the organization) and may manage previews. Anyone else is told to ask a
     * member, or to approve in Falak.
     */
    public function commented(string $organizationId, string $connectionId, string $provider, string $repository, int $number, ?string $author, string $body, ?string $authorId = null, ?\DateTimeImmutable $commentedAt = null): void
    {
        if (preg_match('#^\s*/falak\s+preview\b#i', $body) !== 1) {
            return;
        }

        foreach ($this->previewsOf($organizationId, $connectionId, $repository, $number) as $preview) {
            if ($preview->status !== Preview::WAITING_APPROVAL) {
                continue;
            }

            // Matched on the provider's immutable account id, never a login or nickname (renamed and reused).
            $approver = $authorId !== null ? $this->memberFor($organizationId, $provider, $authorId) : null;

            if ($approver === null) {
                $this->audit->record('previews.approval_refused', 'preview', $preview->id, ['pull_request' => $preview->label(), 'commenter' => $author], $preview->organization_id);
                // At most one such reply per pull request and hour (a comment storm doesn't become one from Falak).
                if (Cache::add("previews:refusal:{$preview->id}", true, 3600)) {
                    $this->notifier->reply($preview, 'Only Falak project members who connected this '.ucfirst($provider).' account in Falak and may manage previews can approve with `/falak preview`. A member can also approve it in Falak (project → Previews).');
                }

                continue;
            }

            // The approval covers the head the commenter saw: a comment older than the current head approves nothing.
            if ($commentedAt === null || $preview->head_updated_at === null || $commentedAt->getTimestamp() < $preview->head_updated_at->getTimestamp()) {
                $this->audit->record('previews.approval_refused', 'preview', $preview->id, ['pull_request' => $preview->label(), 'commenter' => $author, 'reason' => 'stale'], $preview->organization_id);
                $this->notifier->reply($preview, 'New commits arrived after that `/falak preview`: review the current head and approve again.');

                continue;
            }

            $this->approve($preview, $approver, 'comment', $preview->head_sha);
        }
    }

    // ---- actions ------------------------------------------------------------------------------

    /**
     * A member approves a fork's pull request at the head they reviewed ($sha): refused when the pull request moved on
     * since. The preview starts, or redeploys the approved head when everything it runs is ready.
     */
    public function approve(Preview $preview, string $userId, string $via, string $sha): void
    {
        if ($preview->status !== Preview::WAITING_APPROVAL) {
            throw ValidationException::withMessages(['preview' => 'This preview is not waiting for an approval.']);
        }

        if (! hash_equals($preview->head_sha, $sha)) {
            throw ValidationException::withMessages(['sha' => 'The pull request has new commits since you reviewed it ('.substr($preview->head_sha, 0, 7).' now): review them and approve again.']);
        }

        $preview->forceFill(['approved_by' => $userId, 'approved_at' => now(), 'last_activity_at' => now()])->save();
        $this->audit->record('previews.approved', 'preview', $preview->id, ['pull_request' => $preview->label(), 'sha' => $preview->head_sha, 'via' => $via], $preview->organization_id);

        if ($preview->environment_id !== null && ! $this->databasesReady($preview)) {
            // Copies that never finished (dropped when it waited): set the preview up again.
            $this->release($preview);
        }

        $preview->environment_id !== null ? $this->deploy($preview) : $this->start($preview);
    }

    public function redeploy(Preview $preview, ?string $userId = null): void
    {
        if ($preview->environment_id === null || ! in_array($preview->status, [Preview::READY, Preview::FAILED, Preview::DEPLOYING], true)) {
            throw ValidationException::withMessages(['preview' => 'Only a running preview can be redeployed.']);
        }

        $preview->forceFill(['last_activity_at' => now()])->save();
        $this->audit->record('previews.redeployed', 'preview', $preview->id, ['pull_request' => $preview->label()], $preview->organization_id);
        $this->deploy($preview, $userId);
    }

    /**
     * Delete everything the preview holds, mark it closed and start the next queued preview of the project.
     */
    public function destroy(Preview $preview, string $reason): void
    {
        $this->release($preview);

        $preview->forceFill([
            'status' => Preview::CLOSED,
            'status_message' => $reason,
            'closed_at' => now(),
            'basic_password' => null,
        ])->save();

        $this->audit->record('previews.closed', 'preview', $preview->id, ['pull_request' => $preview->label(), 'reason' => $reason], $preview->organization_id);
        $this->notifier->update($preview);
        $this->promoteQueued($preview->project_id);
    }

    /** Delete what the preview holds (sites and volumes, databases and volumes, DNS records, the environment). */
    private function release(Preview $preview): void
    {
        $sites = $preview->sites ?? [];

        // Sites a falak.yaml created in the preview environment that a failed setup didn't record are the preview's too.
        if ($preview->environment_id !== null) {
            foreach ($this->projects->servicesIn($preview->environment_id) as $service) {
                if ($service->kind === ServiceKind::Site && ! in_array($service->refId, $sites, true)) {
                    $sites[$service->name.'#'.$service->refId] = $service->refId;
                }
            }
        }

        foreach ($sites as $service => $siteId) {
            // Only the preview's own resources: never a site placed outside its environment.
            if (($placed = $this->projects->projectOf(ServiceKind::Site, $siteId)) !== null && $placed->environmentId !== $preview->environment_id) {
                Log::warning('previews: a site outside the preview environment was not deleted', ['preview' => $preview->id, 'site' => $siteId]);

                continue;
            }

            $this->attempt($preview, "site {$service}", function () use ($siteId) {
                $this->domains->release($siteId);
                $volumes = array_values(array_map(fn ($volume) => $volume->id, array_filter($this->volumes->forSite($siteId), fn ($volume) => ! $volume->protected)));
                $this->siteFactory->delete($siteId, $volumes);
            });
        }

        foreach ($preview->databases ?? [] as $service => $database) {
            if ($database['state'] !== 'dropped') {
                $this->attempt($preview, "database {$service}", fn () => $this->databases->delete($database['database_id'], deleteVolume: true));
            }
        }

        if ($preview->environment_id !== null) {
            $environmentId = $preview->environment_id;
            $this->attempt($preview, 'config', fn () => $this->config->forget($environmentId));
            $this->attempt($preview, 'environment', fn () => $this->environments->delete($environmentId));
        }

        $preview->forceFill(['environment_id' => null, 'sites' => null, 'databases' => null, 'urls' => null, 'deployments' => null, 'config_ref' => null])->save();
    }

    /** Previews idle (no push, approval or redeploy) longer than their project's TTL are deleted. */
    public function cleanupIdle(): int
    {
        $deleted = 0;

        foreach (Preview::query()->where('status', '!=', Preview::CLOSED)->get() as $preview) {
            $ttl = PreviewSettings::query()->where('project_id', $preview->project_id)->value('idle_ttl_hours') ?? 72;

            if ($preview->last_activity_at !== null && $preview->last_activity_at->lt(now()->subHours((int) $ttl))) {
                $this->destroy($preview, "Idle for more than {$ttl} hours (push to the pull request to bring it back).");
                $deleted++;
            }
        }

        return $deleted;
    }

    // ---- outcomes -----------------------------------------------------------------------------

    public function databaseCreated(string $databaseId): void
    {
        [$preview, $service] = $this->byDatabase($databaseId);

        if ($preview === null || $preview->databases[$service]['state'] !== 'creating') {
            return;
        }

        // A preview that stopped setting up (failed, waiting for an approval) restores nothing more.
        if ($preview->status !== Preview::CREATING) {
            return;
        }

        $entry = $preview->databases[$service];

        if ($entry['strategy'] === PreviewSettings::EMPTY) {
            $this->setDatabase($preview, $service, ['state' => 'ready']);
            $this->deployWhenReady($preview);

            return;
        }

        try {
            $source = $this->sourceDatabase($preview, $service, $entry['strategy']);
            $restoreId = $this->databases->restoreLatestBackup($source, $databaseId);
            $this->setDatabase($preview, $service, ['state' => 'restoring', 'restore_id' => $restoreId]);
            $this->notifier->update($preview);
        } catch (ValidationException $e) {
            $this->fail($preview, "{$service}: ".self::message($e), $entry['strategy'] === PreviewSettings::CLONE_SANITIZE ? $service : null);
        }
    }

    public function restoreFinished(string $restoreId, bool $succeeded, ?string $error): void
    {
        foreach (Preview::query()->where('status', '!=', Preview::CLOSED)->get() as $preview) {
            foreach ($preview->databases ?? [] as $service => $entry) {
                if (($entry['restore_id'] ?? null) !== $restoreId || $entry['state'] !== 'restoring') {
                    continue;
                }

                $sanitize = $entry['strategy'] === PreviewSettings::CLONE_SANITIZE;

                // The preview left its setup meanwhile: an unsanitized copy never stays.
                if ($preview->status !== Preview::CREATING) {
                    $sanitize ? $this->dropDatabase($preview, (string) $service) : $this->setDatabase($preview, $service, ['state' => $succeeded ? 'ready' : 'failed']);

                    return;
                }

                if (! $succeeded) {
                    $this->fail($preview, "Restoring {$service} failed: ".($error ?? 'unknown error'), $sanitize ? $service : null);

                    return;
                }

                if (! $sanitize) {
                    $this->setDatabase($preview, $service, ['state' => 'ready']);
                    $this->deployWhenReady($preview);

                    return;
                }

                $config = PreviewSettings::query()->where('project_id', $preview->project_id)->first()?->databaseOf($service) ?? [];

                try {
                    $commandId = $this->databases->runScript($entry['database_id'], (string) ($config['sanitize_kind'] ?? 'sql'), (string) ($config['sanitize_script'] ?? ''), self::scriptKey($preview, $service));
                    $this->setDatabase($preview, $service, ['state' => 'sanitizing', 'command_id' => $commandId]);
                } catch (ValidationException $e) {
                    $this->fail($preview, "Sanitizing {$service}: ".self::message($e), $service);
                }

                return;
            }
        }
    }

    /** The sanitize script finished: the preview only goes on when it succeeded (fail closed). */
    public function scriptFinished(string $key, bool $succeeded, ?string $error): void
    {
        if (preg_match('/^databases\.script:preview:([0-9a-z]{26}):(.+)$/', $key, $m) !== 1) {
            return;
        }

        $preview = Preview::query()->find($m[1]);
        $service = $m[2];

        if ($preview === null || $preview->status === Preview::CLOSED || ($preview->databases[$service]['state'] ?? null) !== 'sanitizing') {
            return;
        }

        if ($preview->status !== Preview::CREATING) {
            // Sanitized or not, a copy of production whose preview left its setup goes.
            $this->dropDatabase($preview, $service);

            return;
        }

        if (! $succeeded) {
            $this->fail($preview, "The sanitize script of {$service} failed (".($error ?? 'unknown error').'): the preview does not start, and the restored data was deleted.', $service);

            return;
        }

        $this->setDatabase($preview, $service, ['state' => 'ready']);
        $this->deployWhenReady($preview);
    }

    public function deploymentFinished(string $siteId, bool $succeeded, ?string $error): void
    {
        $preview = Preview::query()->whereIn('status', [Preview::DEPLOYING, Preview::READY, Preview::FAILED])->get()
            ->first(fn (Preview $p) => in_array($siteId, $p->sites ?? [], true) && isset(($p->deployments ?? [])[$siteId]));

        if ($preview === null) {
            return;
        }

        $deployments = [...($preview->deployments ?? []), $siteId => $succeeded ? 'deployed' : 'failed'];
        $preview->forceFill(['deployments' => $deployments])->save();

        if (! $succeeded) {
            $service = array_search($siteId, $preview->sites ?? [], true);
            $preview->forceFill(['status' => Preview::FAILED, 'status_message' => Str::limit("Deploying {$service} failed: ".($error ?? 'unknown error'), 990)])->save();
            $this->notifier->update($preview);

            return;
        }

        if ($preview->status === Preview::DEPLOYING && ! in_array('deploying', $deployments, true) && ! in_array('failed', $deployments, true)) {
            $preview->forceFill(['status' => Preview::READY, 'status_message' => null, 'deployed_sha' => $preview->head_sha])->save();
            $this->notifier->update($preview);
        }
    }

    // ---- steps --------------------------------------------------------------------------------

    private function start(Preview $preview): void
    {
        $settings = PreviewSettings::query()->where('project_id', $preview->project_id)->first();

        if ($settings === null || ! $settings->enabled || $settings->base_environment_id === null) {
            $preview->forceFill(['status' => Preview::FAILED, 'status_message' => 'Previews are turned off for this project.'])->save();

            return;
        }

        // Counted and claimed under the project's lock: two pull requests opening together can't both take the last slot.
        $claimed = Cache::lock("previews:project:{$preview->project_id}", 30)->block(20, function () use ($preview, $settings) {
            $running = Preview::query()->where('project_id', $preview->project_id)->whereKeyNot($preview->id)
                ->where(fn ($q) => $q->whereNotNull('environment_id')->orWhere('status', Preview::CREATING))->where('status', '!=', Preview::CLOSED)->count();

            if ($running >= $settings->max_concurrent) {
                $preview->forceFill(['status' => Preview::QUEUED, 'status_message' => "{$running} previews are running (the project's limit is {$settings->max_concurrent})."])->save();

                return false;
            }

            $preview->forceFill(['status' => Preview::CREATING, 'status_message' => null])->save();

            return true;
        });
        $this->notifier->update($preview);

        if (! $claimed) {
            return;
        }

        try {
            $this->create($preview, $settings);
        } catch (ValidationException $e) {
            $this->fail($preview, self::message($e));

            return;
        } catch (Throwable $e) {
            Log::error('previews: setup failed', ['preview' => $preview->id, 'error' => $e->getMessage()]);
            $this->fail($preview, 'Setting up the preview failed: '.Str::limit($e->getMessage(), 300));

            return;
        }

        $this->deployWhenReady($preview);
    }

    /**
     * @throws ValidationException
     */
    private function create(Preview $preview, PreviewSettings $settings): void
    {
        $file = $this->configFile($preview, $settings);
        $omitted = $file->omitted ?? [];
        $services = $this->projects->servicesIn($settings->base_environment_id);
        $include = array_values(array_filter($services, fn (ServiceData $s) => $settings->modeOf($s->name) === PreviewSettings::INCLUDE && ! in_array($s->name, $omitted, true)));
        $shared = array_values(array_map(fn (ServiceData $s) => $s->name, array_filter($services, fn (ServiceData $s) => $settings->modeOf($s->name) === PreviewSettings::SHARE)));
        $serverId = $preview->is_fork ? $this->forkServer($settings) : ($settings->server_id ?? $this->defaultServer($services)
            ?? throw ValidationException::withMessages(['server_id' => 'Pick the server previews run on (project → Previews → Settings).']));
        $siteOverrides = [];

        foreach ($include as $service) {
            if ($service->kind === ServiceKind::Site && ($site = $this->sites->find($service->refId)) !== null) {
                if ($preview->is_fork && ! PreviewServers::forkRuntime($site->runtime)) {
                    throw ValidationException::withMessages(['runtime' => "{$service->name} runs natively ({$site->runtime->value}): pull requests from forks only run as containers (Docker or Compose)."]);
                }

                $siteOverrides[$service->name] = [
                    // A fork's branch lives in the fork: the head commit is fetched from the base repository.
                    'branch' => $preview->is_fork || $preview->head_branch === '' ? $site->branch : $preview->head_branch,
                    'server_ids' => [$serverId],
                    'name_suffix' => "pr-{$preview->number}",
                    // Only the names the project lists (plus references for its own pull requests): never the base's
                    // other literal values.
                    'only_variables' => array_values($settings->variables ?? []),
                    'references' => ! $preview->is_fork,
                ];
            }
        }

        $created = $this->environments->create($settings->base_environment_id, "PR #{$preview->number}", $preview->is_fork,
            array_map(fn (ServiceData $s) => $s->name, $include), $shared, $siteOverrides);
        $environmentId = $created['environment']->id;
        $preview->forceFill(['environment_id' => $environmentId, 'sites' => $created['sites']])->save();

        $databases = [];

        foreach ($include as $service) {
            if ($service->kind !== ServiceKind::Database || ($base = $this->databaseDirectory->find($service->refId)) === null) {
                continue;
            }

            // Redis / Valkey always start empty; a fork never gets a plain copy of real data (empty or sanitized only).
            $strategy = $base->isKeyValue() ? PreviewSettings::EMPTY : (string) $settings->databaseOf($service->name)['strategy'];
            $strategy = $preview->is_fork && $strategy === PreviewSettings::CLONE_BACKUP ? PreviewSettings::EMPTY : $strategy;
            $database = $this->databases->create($preview->organization_id, $serverId, $base->engine, Str::limit(Str::slug($service->name), 40, '')."-pr-{$preview->number}", null, [
                'version' => $base->engineVersion,
                'database' => $base->isKeyValue() ? null : $base->name,
                'memory_mb' => self::DB_MEMORY_MB,
                'disk_gb' => self::DB_DISK_GB,
                'environment_id' => $environmentId,
            ]);
            // Recorded at once: a later failure still deletes it.
            $databases[$service->name] = ['database_id' => $database->id, 'strategy' => $strategy, 'state' => $database->status === 'active' ? 'created' : 'creating'];
            $preview->forceFill(['databases' => $databases])->save();
            $this->environments->place($environmentId, ServiceKind::Database, $database->id, $service->name, $service->x, $service->y);
        }

        $preview->forceFill(['databases' => $databases])->save();

        if ($file !== null) {
            $this->applyFile($preview, $settings, $file, $serverId, $services);
        }

        $this->route($preview, $settings);

        // A database that already exists (an engine created synchronously) goes on now.
        foreach ($databases as $service => $entry) {
            if ($entry['state'] === 'created') {
                $this->setDatabase($preview, $service, ['state' => 'creating']);
                $this->databaseCreated($entry['database_id']);
            }
        }
    }

    /**
     * Hosts under the preview domain, behind basic auth unless the project made previews public ($only: the sites a
     * redeploy added, the others keep theirs).
     *
     * @param  ?array<string, string>  $only
     */
    private function route(Preview $preview, PreviewSettings $settings, ?array $only = null): void
    {
        $domain = $this->domains->settings()
            ?? throw ValidationException::withMessages(['domain' => 'This Falak has no preview domain (Settings → Previews).']);
        $project = $this->projects->find($preview->project_id);
        $urls = $only !== null ? ($preview->urls ?? []) : [];
        $basic = $settings->access === 'basic';

        if ($basic && $preview->basic_password === null) {
            $preview->forceFill(['basic_username' => 'preview', 'basic_password' => Str::password(24, symbols: false)])->save();
        }

        foreach ($only ?? $preview->sites ?? [] as $service => $siteId) {
            $host = PreviewHosts::host($settings->domain_pattern, $preview->number, $service, $project?->name ?? '', $preview->project_id, $domain->domain, fn (string $h) => $this->domains->available($h));
            $this->domains->route($siteId, $host);
            $urls[$service] = "https://{$host}";

            if ($basic) {
                $this->domains->protect($siteId, (string) $preview->basic_username, (string) $preview->basic_password);
            }
        }

        if (! $basic) {
            $preview->forceFill(['basic_username' => null, 'basic_password' => null]);
        }

        $preview->forceFill(['urls' => $urls])->save();
    }

    private function deployWhenReady(Preview $preview): void
    {
        $preview->refresh();

        if ($preview->status !== Preview::CREATING) {
            return;
        }

        foreach ($preview->databases ?? [] as $entry) {
            if ($entry['state'] !== 'ready') {
                return;
            }
        }

        $this->deploy($preview);
    }

    private function deploy(Preview $preview, ?string $userId = null): void
    {
        // Never against a database that is not ready (a production copy still being restored or sanitized).
        if (! $this->databasesReady($preview)) {
            if ($preview->status !== Preview::CREATING) {
                $this->fail($preview, 'A database of the preview is not ready.');
            }

            return;
        }

        try {
            $this->refreshFile($preview);
        } catch (ValidationException $e) {
            $this->fail($preview, self::message($e));

            return;
        }

        $deployments = [];
        $preview->forceFill(['status' => Preview::DEPLOYING, 'status_message' => null])->save();

        try {
            foreach ($preview->sites ?? [] as $siteId) {
                $this->deployments->deploy($siteId, $userId ?? $preview->approved_by, $preview->head_sha, $preview->title !== '' ? $preview->title : "Pull request #{$preview->number}", $preview->author);
                $deployments[$siteId] = 'deploying';
            }
        } catch (ValidationException $e) {
            $this->fail($preview, self::message($e));

            return;
        }

        $preview->forceFill(['deployments' => $deployments, 'status' => $deployments === [] ? Preview::READY : Preview::DEPLOYING])->save();
        $this->notifier->update($preview);
    }

    /**
     * The falak.yaml a preview is built from (null: the project follows none): the pull request head's, a fork's
     * base branch's (its head commit when the provider says, so a new base commit rebuilds it).
     *
     * @throws ValidationException
     */
    private function configFile(Preview $preview, PreviewSettings $settings): ?PreviewFile
    {
        $ref = $preview->head_sha;

        if ($preview->is_fork) {
            try {
                $ref = $this->sourceControl->latestCommit($preview->connection_id, $preview->repository, $preview->base_branch)?->sha ?? $preview->base_branch;
            } catch (SourceControlException) {
                $ref = $preview->base_branch;
            }
        }

        return $this->config->prepare($preview->project_id, (string) $settings->base_environment_id, $ref);
    }

    /**
     * Apply the file to the preview environment; units it creates join the preview's sites.
     *
     * @param  list<ServiceData>  $services  the base environment's services
     * @return array<string, string> the units created: name => site id
     *
     * @throws ValidationException
     */
    private function applyFile(Preview $preview, PreviewSettings $settings, PreviewFile $file, string $serverId, array $services): array
    {
        // Shared and omitted services are not the preview's to configure.
        $without = [...$file->omitted, ...array_map(fn (ServiceData $s) => $s->name, array_filter($services, fn (ServiceData $s) => $settings->modeOf($s->name) !== PreviewSettings::INCLUDE))];
        $branch = $preview->is_fork || $preview->head_branch === '' ? $preview->base_branch : $preview->head_branch;
        $result = $this->config->apply($file, (string) $preview->environment_id, $serverId, $branch, $preview->head_sha, $preview->is_fork, array_values(array_unique($without)), $preview->approved_by);

        $preview->forceFill(['sites' => [...($preview->sites ?? []), ...$result['sites']], 'config_ref' => $file->ref])->save();

        return $result['sites'];
    }

    /**
     * Before a redeploy: the file at the new head (a fork: the base branch's newest) is applied when it is not the one
     * the preview was built from; units it adds are routed.
     *
     * @throws ValidationException
     */
    private function refreshFile(Preview $preview): void
    {
        $settings = PreviewSettings::query()->where('project_id', $preview->project_id)->first();

        if ($settings === null || $settings->base_environment_id === null || $preview->environment_id === null) {
            return;
        }

        $file = $this->configFile($preview, $settings);

        if ($file === null || $file->ref === $preview->config_ref) {
            return;
        }

        $services = $this->projects->servicesIn($settings->base_environment_id);
        $serverId = $preview->is_fork ? $this->forkServer($settings) : ($settings->server_id ?? $this->defaultServer($services)
            ?? throw ValidationException::withMessages(['server_id' => 'Pick the server previews run on (project → Previews → Settings).']));
        $created = $this->applyFile($preview, $settings, $file, $serverId, $services);

        if ($created !== []) {
            $this->route($preview, $settings, $created);
        }
    }

    /**
     * The preview fails: what it holds stays for inspection (deleted when the pull request closes or it idles out),
     * except a database restored from production whose sanitizing did not finish ($dropDatabase): its data goes now.
     */
    private function fail(Preview $preview, string $message, ?string $dropDatabase = null): void
    {
        if ($dropDatabase !== null && isset($preview->databases[$dropDatabase])) {
            $this->dropDatabase($preview, $dropDatabase);
        }

        $this->dropUnfinishedSanitized($preview);

        $preview->forceFill(['status' => Preview::FAILED, 'status_message' => Str::limit($message, 990)])->save();
        $this->audit->record('previews.failed', 'preview', $preview->id, ['pull_request' => $preview->label(), 'reason' => Str::limit($message, 200)], $preview->organization_id);
        $this->notifier->update($preview);
    }

    private function databasesReady(Preview $preview): bool
    {
        foreach ($preview->databases ?? [] as $entry) {
            if ($entry['state'] !== 'ready') {
                return false;
            }
        }

        return true;
    }

    /** A production copy that was not sanitized yet never outlives its setup. */
    private function dropUnfinishedSanitized(Preview $preview): void
    {
        foreach ($preview->databases ?? [] as $service => $entry) {
            if ($entry['strategy'] === PreviewSettings::CLONE_SANITIZE && ! in_array($entry['state'], ['ready', 'dropped'], true)) {
                $this->dropDatabase($preview, (string) $service);
            }
        }
    }

    private function dropDatabase(Preview $preview, string $service): void
    {
        $entry = $preview->databases[$service] ?? null;

        if ($entry === null) {
            return;
        }

        $this->attempt($preview, "database {$service}", fn () => $this->databases->delete($entry['database_id'], deleteVolume: true));
        $this->setDatabase($preview, $service, ['state' => 'dropped']);
        $this->audit->record('previews.database_dropped', 'preview', $preview->id, ['pull_request' => $preview->label(), 'service' => $service, 'strategy' => $entry['strategy']], $preview->organization_id);
    }

    /**
     * The server a fork's pull request runs on: the project's fork server, which hosts nothing but previews.
     *
     * @throws ValidationException
     */
    private function forkServer(PreviewSettings $settings): string
    {
        $serverId = $settings->fork_server_id
            ?? throw ValidationException::withMessages(['fork_server_id' => 'Pull requests from forks need a dedicated fork server (project → Previews → Settings).']);

        if (($problem = $this->servers->forkProblem($serverId)) !== null) {
            throw ValidationException::withMessages(['fork_server_id' => $problem]);
        }

        return $serverId;
    }

    private function promoteQueued(string $projectId): void
    {
        $next = Preview::query()->where('project_id', $projectId)->where('status', Preview::QUEUED)->orderBy('created_at')->first();

        if ($next !== null) {
            $this->start($next);
        }
    }

    // ---- lookups ------------------------------------------------------------------------------

    /**
     * Enabled settings of the projects whose base environment deploys the repository through the connection.
     *
     * @return list<PreviewSettings>
     */
    private function settingsFor(string $organizationId, string $connectionId, string $repository): array
    {
        $environments = [];

        foreach ($this->sites->forRepository($connectionId, $repository) as $site) {
            if ($site->organizationId === $organizationId && ($placed = $this->projects->projectOf(ServiceKind::Site, $site->id)) !== null) {
                $environments[$placed->environmentId] = true;
            }
        }

        return PreviewSettings::query()
            ->where('organization_id', $organizationId)
            ->where('enabled', true)
            ->whereIn('base_environment_id', array_keys($environments))
            ->get()
            ->all();
    }

    /**
     * @return list<Preview>
     */
    private function previewsOf(string $organizationId, string $connectionId, string $repository, int $number): array
    {
        return Preview::query()
            ->where('organization_id', $organizationId)
            ->where('connection_id', $connectionId)
            ->whereRaw('lower(repository) = ?', [strtolower($repository)])
            ->where('number', $number)
            ->get()
            ->all();
    }

    /**
     * @param  callable(?Preview): ?Preview  $callback
     */
    private function locked(PreviewSettings $settings, string $connectionId, PullRequestData $pr, callable $callback): ?Preview
    {
        $key = 'previews:'.$settings->project_id.':'.$connectionId.':'.strtolower($pr->repository).':'.$pr->number;

        return Cache::lock($key, 300)->block(60, function () use ($settings, $connectionId, $pr, $callback) {
            $existing = Preview::query()->where('project_id', $settings->project_id)->where('connection_id', $connectionId)
                ->whereRaw('lower(repository) = ?', [strtolower($pr->repository)])->where('number', $pr->number)->first();

            return $callback($existing);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function pullRequestFields(PullRequestData $pr): array
    {
        return [
            'title' => Str::limit($pr->title, 250, ''),
            'url' => $pr->url,
            'author' => $pr->author,
            'head_branch' => $pr->headBranch,
            'head_sha' => $pr->headSha,
            'base_branch' => $pr->baseBranch,
            'is_fork' => $pr->isFork,
            'source_repository' => $pr->sourceRepository,
        ];
    }

    /** The Falak member who may manage previews and connected the provider account $accountId (null: nobody). */
    private function memberFor(string $organizationId, string $provider, string $accountId): ?string
    {
        foreach ($this->sourceControl->usersWithAccount($organizationId, $provider, $accountId) as $userId) {
            $user = Auth::guard('web')->getProvider()->retrieveById($userId);

            if ($user !== null && $this->access->can($user, $organizationId, PreviewPolicy::MANAGE)) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * @param  list<ServiceData>  $services
     */
    private function defaultServer(array $services): ?string
    {
        foreach ($services as $service) {
            if ($service->kind === ServiceKind::Site && ($leader = $this->sites->leader($service->refId)) !== null) {
                return $leader->serverId;
            }
        }

        return null;
    }

    /**
     * The database a clone strategy restores from: the same-named service of the chosen environment (clone_backup:
     * default the base environment; clone_sanitize: default production).
     *
     * @throws ValidationException
     */
    private function sourceDatabase(Preview $preview, string $service, string $strategy): string
    {
        $settings = PreviewSettings::query()->where('project_id', $preview->project_id)->firstOrFail();
        $environmentId = $settings->databaseOf($service)['source_environment_id'] ?? null;

        if ($environmentId === null) {
            $environments = $this->projects->environments($preview->project_id);
            $environmentId = $strategy === PreviewSettings::CLONE_SANITIZE
                ? (collect($environments)->firstWhere('isProduction', true)?->id)
                : $settings->base_environment_id;
        }

        $environment = $environmentId !== null ? $this->projects->environment($environmentId) : null;

        if ($environment === null || $environment->projectId !== $preview->project_id || $environment->isPreview) {
            throw ValidationException::withMessages(['database' => 'The environment to clone the database from is gone.']);
        }

        foreach ($this->projects->servicesIn($environment->id) as $candidate) {
            if ($candidate->kind === ServiceKind::Database && strcasecmp($candidate->name, $service) === 0) {
                return $candidate->refId;
            }
        }

        throw ValidationException::withMessages(['database' => "{$environment->name} has no database service named {$service}."]);
    }

    /**
     * @return array{0: ?Preview, 1: string}
     */
    private function byDatabase(string $databaseId): array
    {
        foreach (Preview::query()->where('status', '!=', Preview::CLOSED)->get() as $preview) {
            foreach ($preview->databases ?? [] as $service => $entry) {
                if ($entry['database_id'] === strtolower($databaseId)) {
                    return [$preview, (string) $service];
                }
            }
        }

        return [null, ''];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function setDatabase(Preview $preview, string $service, array $changes): void
    {
        $databases = $preview->databases ?? [];
        $databases[$service] = [...$databases[$service], ...$changes];
        $preview->forceFill(['databases' => $databases])->save();
    }

    public static function scriptKey(Preview $preview, string $service): string
    {
        return "preview:{$preview->id}:{$service}";
    }

    /** Teardown keeps going when one part fails (logged; the rest still goes). */
    private function attempt(Preview $preview, string $what, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            Log::warning('previews: teardown step failed', ['preview' => $preview->id, 'step' => $what, 'error' => $e->getMessage()]);
        }
    }

    private static function message(ValidationException $e): string
    {
        return implode(' ', array_merge(...array_values($e->errors())));
    }
}
