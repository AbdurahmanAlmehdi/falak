<?php

namespace Falak\Recovery\Application;

use Falak\Databases\Contracts\DatabaseRecovery;
use Falak\Deployments\Contracts\DeploymentDirectory;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Edge\Contracts\DnsCheck;
use Falak\Edge\Contracts\DomainRecords;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Recovery\Domain\Models\SiteMove;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Falak\Sites\Contracts\SiteRelocation;
use Falak\Sites\Contracts\SiteRuntime;
use Falak\Sites\Contracts\TargetStatus;
use Falak\Volumes\Contracts\VolumeRecovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs "Move to another server" (see {@see SiteMove} for the steps). Like ServerRecoveryRunner: advance() is
 * idempotent, starts what is pending in the current step, polls what runs (the databases', volumes' and deployments'
 * own state, settled by their command outcomes) and moves on once every item of a step is done; it runs right after a
 * start or retry and every minute (AdvanceSiteMoves), which also starts the next queued move of a "Move all sites".
 * A failed item stops the move there until it is retried or cancelled; nothing before it is redone.
 */
final class SiteMoveRunner
{
    /** How long the old server keeps serving a stateless site whose DNS still points at it. */
    private const DNS_GRACE_HOURS = 48;

    public function __construct(
        private readonly SiteMovePlanner $planner,
        private readonly ServerDirectory $servers,
        private readonly SiteDirectory $sites,
        private readonly SiteRelocation $relocation,
        private readonly DatabaseRecovery $databases,
        private readonly VolumeRecovery $volumes,
        private readonly DeploymentTrigger $deploys,
        private readonly DeploymentDirectory $deployments,
        private readonly DomainRecords $domains,
        private readonly DnsCheck $dns,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Start a move now, or queue it ($batchId: a "Move all sites", started one after another).
     *
     * @throws ValidationException
     */
    public function start(SiteData $site, ServerData $target, ?string $fromServerId = null, ?string $storageProviderId = null, ?string $actorId = null, ?string $batchId = null): SiteMove
    {
        // One move per site: the check and the insert under a per-site lock (two clicks, or a click and a "Move all").
        $move = Cache::lock(self::siteLock($site->id), 30)->get(fn () => $this->create($site, $target, $fromServerId, $storageProviderId, $actorId, $batchId));

        if (! $move instanceof SiteMove) {
            throw ValidationException::withMessages(['server_id' => "{$site->name} is being moved right now: try again in a moment."]);
        }

        $this->audit->record('recovery.site_move_'.($batchId !== null ? 'queued' : 'started'), 'site', $site->id, ['move_id' => $move->id, 'from' => $move->from_server_id, 'to' => $target->id], $site->organizationId);

        if ($batchId === null) {
            $this->advance($move);
        }

        return $move->refresh();
    }

    public static function siteLock(string $siteId): string
    {
        return "recovery:site-move-start:{$siteId}";
    }

    private function create(SiteData $site, ServerData $target, ?string $fromServerId, ?string $storageProviderId, ?string $actorId, ?string $batchId): SiteMove
    {
        if (SiteMove::query()->where('site_id', $site->id)->whereIn('status', SiteMove::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['server_id' => "{$site->name} is already being moved: finish or cancel that move first."]);
        }

        $plan = $this->planner->plan($site, $target->id, $fromServerId, $storageProviderId);

        if ($batchId === null && $plan['blocking'] !== []) {
            throw ValidationException::withMessages(['server_id' => implode(' ', $plan['blocking'])]);
        }

        return SiteMove::query()->create([
            'organization_id' => $site->organizationId,
            'site_id' => $site->id,
            'site_name' => $site->name,
            'from_server_id' => $plan['from']['id'],
            'from_server_name' => $plan['from']['name'],
            'target_server_id' => $target->id,
            'target_server_name' => $target->name,
            'storage_provider_id' => $plan['storage_provider_id'],
            'batch_id' => $batchId,
            'status' => $batchId !== null ? 'queued' : 'running',
            'current_step' => 'check',
            'steps' => self::steps($site, $target, $plan),
            'requested_by' => $actorId,
        ]);
    }

    /**
     * "Move all sites" of a server: one move per site, queued; the first starts now.
     *
     * @return list<SiteMove>
     */
    public function startAll(ServerData $from, ServerData $target, ?string $storageProviderId = null, ?string $actorId = null): array
    {
        $batch = strtolower((string) Str::ulid());
        $moves = [];

        foreach ($this->sites->forServer($from->id) as $site) {
            try {
                $moves[] = $this->start($site, $target, $from->id, $storageProviderId, $actorId, $batch);
            } catch (ValidationException) {
                // Already moving (or no longer on that server): left out.
            }
        }

        if ($moves === []) {
            throw ValidationException::withMessages(['server_id' => "No site of {$from->name} can be moved (none left, or each is already being moved)."]);
        }

        $this->kick();

        return $moves;
    }

    /**
     * Each "Move all sites" batch starts its next queued move once none of it runs (a failed one holds the batch). A
     * move only waiting in its cleanup (the old server serving until DNS moves, up to 48 h) doesn't hold it.
     */
    public function kick(): void
    {
        $batches = SiteMove::query()->where('status', 'queued')->whereNotNull('batch_id')->distinct()->pluck('batch_id');

        foreach ($batches as $batch) {
            $busy = SiteMove::query()->where('batch_id', $batch)
                ->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($q) => $q->where('status', 'running')->where(fn ($q) => $q->whereNull('current_step')->orWhere('current_step', '!=', 'cleanup'))))
                ->exists();

            if ($busy) {
                continue;
            }

            $next = SiteMove::query()->where('batch_id', $batch)->where('status', 'queued')->orderBy('created_at')->orderBy('id')->first();

            if ($next !== null) {
                $next->forceFill(['status' => 'running'])->save();
                $this->audit->record('recovery.site_move_started', 'site', $next->site_id, ['move_id' => $next->id, 'batch_id' => $batch], $next->organization_id);
                $this->advance($next);
            }
        }
    }

    /** Failed items of a step go back to pending; the move runs again from there. */
    public function retry(SiteMove $move, string $stepKey): void
    {
        Cache::lock("recovery:site-move:{$move->id}", 120)->block(30, fn () => $this->reset($move->refresh(), $stepKey));
        $this->audit->record('recovery.site_move_retried', 'site', $move->site_id, ['move_id' => $move->id, 'step' => $stepKey], $move->organization_id);
        $this->advance($move);
    }

    /**
     * Give up on a queued or failed move before its cutover. Nothing moved yet: the new server leaves the site again.
     *
     * @throws ValidationException after the cutover, or once data moved
     */
    public function cancel(SiteMove $move): void
    {
        Cache::lock("recovery:site-move:{$move->id}", 120)->block(30, function () use ($move) {
            $move->refresh();

            if (! in_array($move->status, ['queued', 'failed'], true)) {
                throw ValidationException::withMessages(['move' => 'Only a queued or failed move can be cancelled.']);
            }

            if ($move->switched()) {
                throw ValidationException::withMessages(['move' => 'The new server already serves the site: retry the failed step instead.']);
            }

            if ($move->movedData()) {
                throw ValidationException::withMessages(['move' => 'Some of its data already moved to the new server: retry the failed step instead.']);
            }

            $added = collect($move->step('add_target')['items'] ?? [])->contains(fn (array $item) => $item['added'] ?? false);

            if ($added) {
                $this->relocation->removeServer($move->site_id, $move->target_server_id);
            }

            $move->forceFill(['status' => 'cancelled', 'finished_at' => now()])->save();
        });

        $this->audit->record('recovery.site_move_cancelled', 'site', $move->site_id, ['move_id' => $move->id], $move->organization_id);
        $this->kick();
    }

    /**
     * An operator gives up on a failed move that can't be cancelled (data moved, or it cut over): it is only marked
     * abandoned, so the site can be moved again. Nothing on any server changes; what moved stays where it is.
     *
     * @throws ValidationException when the move hasn't failed
     */
    public function abandon(SiteMove $move, ?string $actorId = null): void
    {
        Cache::lock("recovery:site-move:{$move->id}", 120)->block(30, function () use ($move) {
            $move->refresh();

            if ($move->status !== 'failed') {
                throw ValidationException::withMessages(['move' => 'Only a failed move can be abandoned.']);
            }

            $move->forceFill(['status' => 'abandoned', 'finished_at' => now()])->save();
        });

        $this->audit->record('recovery.site_move_abandoned', 'site', $move->site_id, ['move_id' => $move->id, 'step' => $move->current_step, 'by' => $actorId], $move->organization_id);
        $this->kick();
    }

    private function reset(SiteMove $move, string $stepKey): void
    {
        $steps = $move->steps;

        foreach ($steps as $i => $step) {
            if ($step['key'] !== $stepKey) {
                continue;
            }

            if ($step['status'] !== 'failed') {
                throw ValidationException::withMessages(['step' => 'Only a failed step can be retried.']);
            }

            foreach ($step['items'] as $j => $item) {
                if ($item['state'] === 'failed') {
                    // An item retries the phase it failed in (a database's backup, container or restore).
                    $steps[$i]['items'][$j] = [...$item, 'state' => 'pending', 'message' => null, 'retry' => true];
                }
            }

            $steps[$i]['status'] = 'running';
            $steps[$i]['message'] = null;
        }

        $move->forceFill(['steps' => $steps, 'status' => 'running', 'finished_at' => null])->save();
    }

    public function advance(SiteMove $move): void
    {
        Cache::lock("recovery:site-move:{$move->id}", 120)->get(function () use ($move) {
            $move->refresh();

            if (! $move->isRunning()) {
                return;
            }

            $steps = $move->steps;

            foreach ($steps as $i => $step) {
                if ($step['status'] === 'succeeded') {
                    continue;
                }

                $move->current_step = $step['key'];
                $move->steps = $steps;
                $steps[$i] = $this->runStep($move, $step);
                $move->forceFill(['steps' => $steps])->save();

                if ($steps[$i]['status'] !== 'succeeded') {
                    break;
                }
            }

            $statuses = array_column($steps, 'status');
            $status = match (true) {
                in_array('failed', $statuses, true) => 'failed',
                array_unique($statuses) === ['succeeded'] => 'succeeded',
                default => 'running',
            };

            $move->forceFill(['status' => $status, 'finished_at' => $status === 'running' ? null : now()])->save();

            if ($status !== 'running') {
                $this->audit->record("recovery.site_move_{$status}", 'site', $move->site_id, ['move_id' => $move->id, 'step' => $move->current_step], $move->organization_id);
            }
        });

        if ($move->status === 'succeeded') {
            $this->kick();
        }
    }

    /**
     * @param  array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}  $step
     * @return array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}
     */
    private function runStep(SiteMove $move, array $step): array
    {
        foreach ($step['items'] as $j => $item) {
            if (in_array($item['state'], [...SiteMove::DONE, 'failed'], true)) {
                continue;
            }

            try {
                $step['items'][$j] = match ($step['key']) {
                    'check' => $this->check($move),
                    'add_target' => $this->addTarget($move, $item),
                    'databases' => $this->database($move, $item),
                    'volumes' => $this->volume($move, $item),
                    // The switch first; the domain items it adds are only re-checked (Cloudflare records syncing).
                    'cutover' => ($item['id'] ?? null) === 'switch' ? $this->cutover($move, $item) : $this->domainState($move, $item),
                    'cleanup' => $this->cleanup($move, $item),
                } + $item;
            } catch (ValidationException $e) {
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => (string) collect($e->errors())->flatten()->first()];
            } catch (AgentUnavailable) {
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => 'A server agent is not connected.'];
            } catch (Throwable $e) {
                report($e);
                Log::warning('Site move step failed.', ['move_id' => $move->id, 'step' => $step['key'], 'item' => $item['id'] ?? null]);
                $step['items'][$j] = [...$item, 'state' => 'failed', 'message' => 'Unexpected error: '.mb_substr($e->getMessage(), 0, 300)];
            }
        }

        // The cutover's domains are only known once it switched (generated names are renamed then).
        if ($step['key'] === 'cutover' && ($step['items'][0]['state'] ?? null) === 'succeeded' && count($step['items']) === 1) {
            array_push($step['items'], ...$this->domainItems($move, (array) ($step['items'][0]['renamed'] ?? [])));
        }

        $states = array_column($step['items'], 'state');
        $step['status'] = match (true) {
            in_array('failed', $states, true) => 'failed',
            array_diff($states, SiteMove::DONE) === [] => 'succeeded',
            default => 'running',
        };

        return $step;
    }

    /**
     * The target can still take the site (it may have changed since the move was planned or queued).
     *
     * @return array<string, mixed>
     */
    private function check(SiteMove $move): array
    {
        $site = $this->site($move);
        $target = $this->servers->find($move->target_server_id) ?? throw ValidationException::withMessages(['server_id' => 'The new server was deleted.']);
        $plan = $this->planner->plan($site, $target->id, $move->from_server_id, $move->storage_provider_id);

        if ($plan['blocking'] !== []) {
            return ['state' => 'failed', 'message' => implode(' ', $plan['blocking'])];
        }

        return ['state' => 'succeeded', 'message' => $plan['problems'] !== [] ? implode(' ', $plan['problems']) : null];
    }

    /**
     * The new server joins as a member (prepared for the site), then gets a deploy unless the site was never deployed
     * or its compose stack has volumes still to move (its first `up` there must find them).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function addTarget(SiteMove $move, array $item): array
    {
        if (($item['phase'] ?? 'add') === 'add') {
            if ($item['state'] === 'pending') {
                $this->relocation->addServer($move->site_id, $move->target_server_id);
                $item = [...$item, 'state' => 'running', 'added' => true];
            }

            $target = $this->site($move)->target($move->target_server_id);

            if ($target === null || $target->status === TargetStatus::Failed) {
                return [...$item, 'state' => 'failed', 'message' => $target?->statusMessage ?: "Preparing {$move->target_server_name} for it failed."];
            }

            if ($target->status !== TargetStatus::Ready) {
                return [...$item, 'state' => 'running', 'message' => "Preparing {$move->target_server_name} for it."];
            }

            if (! ($item['deploy'] ?? false)) {
                return [...$item, 'state' => 'succeeded', 'message' => $item['note'] ?? null];
            }

            $item = [...$item, 'phase' => 'deploy', 'state' => 'pending'];
        }

        return $this->deploy($move, $item, "Moving to {$move->target_server_name}");
    }

    /**
     * backup (fresh, into the move's storage) → create (the same instance relocated to the new server: name, DNS name,
     * users and links follow) → restore (from that backup).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function database(SiteMove $move, array $item): array
    {
        $id = (string) $item['id'];
        $phase = (string) ($item['phase'] ?? 'backup');

        if ($phase === 'backup') {
            if ($item['state'] === 'pending') {
                $item = [...$item, 'state' => 'running', 'backups' => $this->databases->backUp($id, (string) $move->storage_provider_id, $move->requested_by)];
            }

            $progress = $this->databases->backupProgress((array) $item['backups']);

            if ($progress['state'] !== 'succeeded') {
                return [...$item, 'state' => $progress['state'], 'message' => $progress['message']];
            }

            $item = [...$item, 'phase' => 'create', 'state' => 'pending'];
        }

        if ($phase === 'backup' || $phase === 'create') {
            if ($item['state'] === 'pending') {
                $this->databases->relocate($id, $move->target_server_id, $move->requested_by, suspendPitr: (bool) ($item['pitr'] ?? false));
                $item = [...$item, 'state' => 'running', 'phase' => 'create'];
            }

            $progress = $this->databases->progress($id);

            if ($progress['state'] !== 'ready') {
                return [...$item, 'state' => $progress['state'] === 'failed' ? 'failed' : 'running', 'message' => $progress['message'] ?? "Creating it on {$move->target_server_name}."];
            }

            $item = [...$item, 'phase' => 'restore', 'state' => 'pending'];
        }

        if ($item['state'] === 'pending') {
            // The move's own backups (taken above), never whatever is newest by then.
            $result = $this->databases->restoreBackups($id, (array) ($item['backups'] ?? []), $move->requested_by);
            $item = [...$item, 'state' => 'running', 'restores' => $result['restores'], 'skipped' => $result['skipped']];
        }

        // A moved database that isn't restored would come up empty on the new server: the move must not go on.
        if (($item['skipped'] ?? []) !== []) {
            return [...$item, 'state' => 'failed', 'message' => 'Not restored: '.implode(' ', (array) $item['skipped'])];
        }

        $notes = ($item['pitr'] ?? false) ? ['Point-in-time recovery is off after the move: turn it on again on its page.'] : [];
        $progress = ($item['restores'] ?? []) === [] ? ['state' => 'succeeded', 'message' => null] : $this->databases->progress($id, (array) $item['restores']);

        return match ($progress['state']) {
            'succeeded' => [...$item, 'state' => $notes !== [] ? 'manual' : 'succeeded', 'message' => $notes !== [] ? implode(' ', $notes) : "Restored on {$move->target_server_name}."],
            'failed' => [...$item, 'state' => 'failed', 'message' => $progress['message']],
            default => [...$item, 'state' => 'running', 'message' => 'Restoring its fresh backup.'],
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function volume(SiteMove $move, array $item): array
    {
        if ($item['state'] === 'pending') {
            $item = [...$item, 'state' => 'running', 'operation' => $this->volumes->move((string) $item['id'], $move->target_server_id, (string) $move->storage_provider_id, $move->requested_by)];
        }

        $progress = $this->volumes->progress((string) $item['operation']);

        return [...$item, 'state' => $progress['state'], 'message' => $progress['message']];
    }

    /**
     * The switch: the new server becomes the leader. The old one leaves right away when data moved (it no longer has
     * it); a stateless site whose DNS is not Falak's keeps it as a member until its DNS points at the new server (the
     * cleanup removes it). Generated names follow the IP; then a redeploy when data moved.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function cutover(SiteMove $move, array $item): array
    {
        if (($item['phase'] ?? 'switch') === 'switch') {
            $keepOld = (bool) ($item['keep_old'] ?? false);

            if ($keepOld) {
                $this->relocation->makeLeader($move->site_id, $move->target_server_id);
            } else {
                $this->relocation->replaceServer($move->site_id, $move->from_server_id, $move->target_server_id);
            }

            // Switched from here on: whatever fails next keeps that recorded (the move can't be cancelled any more).
            $item = [...$item, 'switched' => true, 'switched_at' => $item['switched_at'] ?? now()->getTimestamp()];
        }

        try {
            if (($item['phase'] ?? 'switch') === 'switch') {
                if (($item['renamed'] ?? null) === null) {
                    $from = $this->servers->find($move->from_server_id);
                    $target = $this->servers->find($move->target_server_id);
                    $item['renamed'] = $from?->ipv4 !== null && $target?->ipv4 !== null ? $this->domains->followServer($move->site_id, $from->ipv4, $target->ipv4) : [];
                }

                if (! ($item['deploy'] ?? false)) {
                    return [...$item, 'state' => 'succeeded', 'message' => "{$move->target_server_name} leads the site."];
                }

                $item = [...$item, 'phase' => 'deploy', 'state' => 'pending'];
            }

            return $this->deploy($move, $item, "Moved to {$move->target_server_name}");
        } catch (ValidationException $e) {
            return [...$item, 'state' => 'failed', 'message' => (string) collect($e->errors())->flatten()->first()];
        } catch (Throwable $e) {
            report($e);

            return [...$item, 'state' => 'failed', 'message' => 'Unexpected error: '.mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /**
     * A managed domain's record after the switch, read again: synced → done, conflict / error → failed, else running.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function domainState(SiteMove $move, array $item): array
    {
        $record = collect($this->domains->forSites([$move->site_id]))->firstWhere('name', $item['id']);

        return match (true) {
            $record === null => [...$item, 'state' => 'skipped', 'message' => 'The domain was removed.'],
            $record['status'] === 'synced' => [...$item, 'state' => 'succeeded', 'message' => 'Falak moved its DNS record to the new server.'],
            in_array($record['status'], ['conflict', 'error'], true) => [...$item, 'state' => 'failed', 'message' => "Its Cloudflare record is in {$record['status']}: see the domain's settings."],
            $record['status'] === 'pending' => [...$item, 'state' => 'running', 'message' => 'Updating its DNS record at Cloudflare.'],
            // In a managed zone but no record (yet): the user's turn, like any other domain.
            default => [...$item, 'state' => 'manual', 'message' => 'Falak keeps no DNS record for it: point it at the new server.'],
        };
    }

    /**
     * Old copies go once the site runs on the new server: the moved databases' old containers, the compose volumes'
     * sources; the old server leaves a stateless site once its DNS points at the new one (at most 48 h). Backups stay.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function cleanup(SiteMove $move, array $item): array
    {
        $notes = (array) ($item['notes'] ?? []);

        if (! ($item['removed'] ?? false)) {
            $notes = [...$notes, ...$this->removeOldCopies($move)];
            $item = [...$item, 'removed' => true, 'notes' => $notes];
        }

        $switch = $move->step('cutover')['items'][0] ?? [];

        if ($switch['keep_old'] ?? false) {
            $site = $this->site($move);

            if ($site->target($move->from_server_id) !== null) {
                $waiting = $this->dnsStillOld($move);
                $deadline = (int) ($switch['switched_at'] ?? now()->getTimestamp()) + self::DNS_GRACE_HOURS * 3600;

                if ($waiting !== [] && now()->getTimestamp() < $deadline) {
                    return [...$item, 'state' => 'running', 'message' => implode(', ', $waiting).' still '.(count($waiting) === 1 ? 'points' : 'point')." at {$move->from_server_name}, which keeps serving the site until then (at most until ".date('Y-m-d H:i', $deadline).' UTC).'];
                }

                $this->relocation->removeServer($move->site_id, $move->from_server_id);
                $notes[] = "{$move->from_server_name} no longer serves the site.";
            }
        }

        return [...$item, 'state' => 'succeeded', 'notes' => $notes, 'message' => $notes !== [] ? implode(' ', $notes) : 'Nothing was left on the old server.'];
    }

    /** @return list<string> */
    private function removeOldCopies(SiteMove $move): array
    {
        $notes = [];

        foreach (($move->step('databases')['items'] ?? []) as $database) {
            if (in_array($database['state'], ['succeeded', 'manual'], true)) {
                $notes[] = $this->databases->removeLeftover((string) $database['id'], $move->from_server_id)
                    ? "{$database['label']}: its old container on {$move->from_server_name} is removed; its data volume there is kept (delete it on the Volumes page once you checked)."
                    : "{$database['label']}: {$move->from_server_name}'s agent is offline, its old container is still there.";
            }
        }

        foreach (($move->step('volumes')['items'] ?? []) as $volume) {
            if (is_string($volume['operation'] ?? null) && ($note = $this->volumes->finishMove($volume['operation'], $move->requested_by)) !== null) {
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /** @return list<string> the domains whose DNS does not point at the new server yet */
    private function dnsStillOld(SiteMove $move): array
    {
        $waiting = [];

        foreach (($move->step('cutover')['items'] ?? []) as $domain) {
            if (($domain['kind'] ?? null) !== 'manual') {
                continue;
            }

            if ($this->dns->check($move->organization_id, (string) $domain['id'], [$move->target_server_id], $move->site_id)->matched === []) {
                $waiting[] = (string) $domain['id'];
            }
        }

        return $waiting;
    }

    /**
     * Deploy the site (once per item) and wait for it; never deployed: nothing to do.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function deploy(SiteMove $move, array $item, string $message): array
    {
        $current = $this->deployments->currentForSites([$move->site_id])[$move->site_id] ?? null;

        if ($item['state'] === 'pending') {
            if ($current === null) {
                return [...$item, 'state' => 'succeeded', 'message' => 'Never deployed: its first deploy goes to the new server.'];
            }

            $id = $this->deploys->deploy($move->site_id, $move->requested_by, message: $message);
            $item = [...$item, 'state' => 'running', 'deployment' => $id, 'since' => now()->getTimestamp()];
            $current = $this->deployments->currentForSites([$move->site_id])[$move->site_id] ?? $current;
        }

        if ($current === null) {
            return [...$item, 'state' => 'failed', 'message' => 'The deployment disappeared.'];
        }

        // A newer deployment than ours (someone deployed meanwhile) settles it too.
        $ours = $current->id === ($item['deployment'] ?? null) || $current->createdAt->getTimestamp() >= (int) ($item['since'] ?? 0);

        return match (true) {
            ! $ours => [...$item, 'state' => 'running', 'message' => 'Queued.'],
            $current->status === 'succeeded' => [...$item, 'state' => 'succeeded', 'message' => "Deployment #{$current->number} succeeded."],
            in_array($current->status, ['failed', 'cancelled'], true) => [...$item, 'state' => 'failed', 'message' => $current->error ?: "Deployment #{$current->number} {$current->status}."],
            default => [...$item, 'state' => 'running', 'message' => "Deployment #{$current->number}: {$current->status}."],
        };
    }

    /**
     * The site's domains after the switch: Falak-managed records follow on their own (verified here), generated names
     * were renamed for the new IP, the others need their DNS record changed by hand.
     *
     * @param  array<string, string>  $renamed  old name => new name
     * @return list<array<string, mixed>>
     */
    private function domainItems(SiteMove $move, array $renamed): array
    {
        $target = $this->servers->find($move->target_server_id);
        $address = implode(' / ', array_filter([$target?->ipv4, $target?->ipv6])) ?: $move->target_server_name;
        $keepOld = (bool) ($move->step('cutover')['items'][0]['keep_old'] ?? false);
        $items = [];

        foreach ($renamed as $old => $new) {
            $items[] = ['id' => $new, 'label' => $new, 'kind' => 'generated', 'state' => 'succeeded', 'message' => "Generated name for the new IP; {$old} pointed at the old server and is removed."];
        }

        foreach ($this->domains->forSites([$move->site_id]) as $record) {
            if (in_array($record['name'], $renamed, true)) {
                continue;
            }

            $items[] = match (true) {
                $record['managed'] && $record['status'] === 'synced' => ['id' => $record['name'], 'label' => $record['name'], 'kind' => 'managed', 'state' => 'succeeded', 'message' => 'Falak moved its DNS record to the new server.'],
                $record['managed'] && in_array($record['status'], ['conflict', 'error'], true) => ['id' => $record['name'], 'label' => $record['name'], 'kind' => 'managed', 'state' => 'failed', 'message' => "Its Cloudflare record is in {$record['status']}: see the domain's settings."],
                $record['managed'] && $record['status'] === 'pending' => ['id' => $record['name'], 'label' => $record['name'], 'kind' => 'managed', 'state' => 'running', 'message' => 'Updating its DNS record at Cloudflare.'],
                default => ['id' => $record['name'], 'label' => $record['name'], 'kind' => 'manual', 'state' => 'manual', 'message' => $keepOld
                    ? "Point its DNS record at {$address}: {$move->from_server_name} keeps serving it until then (at most 48 h)."
                    : "Point its DNS record at {$address} now: {$move->from_server_name} no longer serves it."],
            };
        }

        return $items;
    }

    private function site(SiteMove $move): SiteData
    {
        return $this->sites->find($move->site_id) ?? throw ValidationException::withMessages(['site' => 'The site was deleted.']);
    }

    /**
     * @param  array<string, mixed>  $plan  SiteMovePlanner::plan()
     * @return list<array{key: string, title: string, status: string, message: ?string, items: list<array<string, mixed>>}>
     */
    private static function steps(SiteData $site, ServerData $target, array $plan): array
    {
        $item = fn (string $id, string $label, string $state = 'pending', ?string $message = null, array $extra = []) => ['id' => $id, 'label' => $label, 'state' => $state, 'message' => $message, ...$extra];
        $composeVolumes = $site->runtime === SiteRuntime::Compose && $plan['volumes'] !== [];
        $manualDomains = collect($plan['domains'])->contains('kind', 'manual');

        $items = [
            'check' => [$item($target->id, $target->name)],
            'add_target' => [$item($site->id, $site->name, extra: [
                'phase' => 'add',
                'deploy' => ! $composeVolumes,
                'note' => $composeVolumes ? 'Its stack starts there at the cutover, on its moved volumes.' : null,
            ])],
            'databases' => array_map(fn (array $instance) => $instance['move']
                ? $item($instance['id'], $instance['name'], extra: ['phase' => 'backup', 'pitr' => $instance['pitr']])
                : $item($instance['id'], $instance['name'], 'skipped', "Shared ({$instance['reason']}): it stays on {$plan['from']['name']}."), $plan['databases']),
            'volumes' => array_map(fn (array $volume) => $item($volume['id'], $volume['name']), $plan['volumes']),
            'cutover' => [$item('switch', "{$target->name} serves the site", extra: [
                'phase' => 'switch',
                // Data moved: the new copies need a deploy (env, mounts, the compose stack's first `up`).
                'deploy' => $plan['stateful'],
                // ponytail: instead of a proxy on the old edge, a stateless site keeps the old server as a member
                // until its DNS moves (it serves the same release there).
                'keep_old' => ! $plan['stateful'] && $manualDomains,
            ])],
            'cleanup' => [$item('cleanup', "Old copies on {$plan['from']['name']}")],
        ];

        $steps = [];

        foreach (SiteMove::STEPS as $key => $title) {
            $steps[] = ['key' => $key, 'title' => $title, 'status' => 'pending', 'message' => null, 'items' => $items[$key]];
        }

        return $steps;
    }
}
