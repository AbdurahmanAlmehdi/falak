<?php

namespace Falak\Sandboxes;

use Falak\Fleet\Contracts\AgentRequests;
use Falak\Fleet\Events\CommandFailed;
use Falak\Fleet\Events\CommandFinished;
use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Identity\Events\OrganizationDeleted;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Limits\Contracts\CapacitySources;
use Falak\Sandboxes\Application\Actions\DeleteSnapshot;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Jobs\SweepSandboxes;
use Falak\Sandboxes\Application\Jobs\SyncSandboxes;
use Falak\Sandboxes\Application\Listeners\HandleCommandOutcome;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Falak\Sandboxes\Domain\Policies\SandboxPolicy;
use Falak\Sandboxes\Infrastructure\AgentRequests\SandboxReaped;
use Falak\Sandboxes\Infrastructure\EloquentSandboxDirectory;
use Falak\Sandboxes\Infrastructure\SandboxesCapacity;
use Falak\Servers\Events\ServerDeleted;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

class SandboxesServiceProvider extends ModuleServiceProvider
{
    /**
     * Contract => implementation bindings exposed to other modules.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        SandboxDirectory::class => EloquentSandboxDirectory::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/sandboxes.php', 'sandboxes');
    }

    protected function bootModule(): void
    {
        Gate::policy(Sandbox::class, SandboxPolicy::class);
        Gate::policy(SandboxSnapshot::class, SandboxPolicy::class);

        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(SandboxPolicy::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'View sandboxes and their exec log', 'sandboxes');
        $registry->register(SandboxPolicy::USE, [Role::Admin, Role::Developer], 'Create sandboxes, run commands, read and write their files, open consoles, kill your own', 'sandboxes');
        $registry->register(SandboxPolicy::MANAGE, [Role::Admin], "Kill anyone's sandboxes and change sandbox settings", 'sandboxes');

        $this->app->make(CapacitySources::class)->register(SandboxesCapacity::class);
        $this->app->make(AgentRequests::class)->register('sandbox.reaped', SandboxReaped::class);

        Event::listen(CommandFinished::class, [HandleCommandOutcome::class, 'handleFinished']);
        Event::listen(CommandFailed::class, [HandleCommandOutcome::class, 'handleFailed']);
        // Snapshots hold disk (and env values) on their server: sandbox.snapshot.delete for each first, best effort (a
        // deleted server's agent may be gone already; then nothing is sent).
        $removeSnapshots = function ($query) {
            $delete = $this->app->make(DeleteSnapshot::class);
            (clone $query)->where(fn ($q) => $q->where('status', '!=', SandboxSnapshot::DELETED)->orWhereNull('delete_confirmed_at'))->get()
                ->each(fn (SandboxSnapshot $snapshot) => $delete->remove($snapshot, "sandbox.snapshot.delete:{$snapshot->id}:gone"));
        };
        Event::listen(ServerDeleted::class, function (ServerDeleted $event) use ($removeSnapshots) {
            $kill = $this->app->make(KillSandbox::class);
            Sandbox::query()->where('server_id', $event->serverId)->live()->get()->each(fn (Sandbox $sandbox) => $kill->markKilled($sandbox, 'server_deleted'));
            $removeSnapshots(SandboxSnapshot::query()->where('server_id', $event->serverId));
            SandboxSnapshot::query()->where('server_id', $event->serverId)->where('status', '!=', SandboxSnapshot::DELETED)
                ->update(['status' => SandboxSnapshot::DELETED, 'deleted_at' => now(), 'delete_confirmed_at' => now()]);
        });
        Event::listen(OrganizationDeleted::class, function (OrganizationDeleted $event) use ($removeSnapshots) {
            $removeSnapshots(SandboxSnapshot::query()->where('organization_id', $event->organizationId));
            SandboxSnapshot::query()->where('organization_id', $event->organizationId)->delete();
            Sandbox::query()->where('organization_id', $event->organizationId)->delete();
        });

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(new SweepSandboxes)->everyMinute()->name('sandboxes:sweep')->withoutOverlapping();
            $schedule->job(new SyncSandboxes)->everyFiveMinutes()->name('sandboxes:sync')->withoutOverlapping();
        });
    }
}
