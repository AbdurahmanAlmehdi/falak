<?php

namespace Falak\Processes\Http\Controllers;

use DateTimeZone;
use Falak\Config\Contracts\ManagedResources;
use Falak\Kernel\Http\Controller;
use Falak\Processes\Domain\Models\Daemon;
use Falak\Processes\Domain\Models\Schedule;
use Falak\Processes\Domain\Models\ServerState;
use Falak\Processes\Domain\Models\Worker;
use Falak\Processes\Infrastructure\ProgramNames;
use Falak\Processes\Infrastructure\StateCompiler;
use Falak\Projects\Contracts\ProjectDirectory;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The service panel's Processes tab (UI_DESIGN §5.1): one list of everything that runs for a site — web process,
 * Horizon, Octane, queue workers, daemons, the Laravel scheduler and cron jobs — with the last known status.
 */
final class ProcessesController extends Controller
{
    use PresentsProcesses;
    use ResolvesSite;

    public function index(Request $request, string $site): JsonResponse|RedirectResponse
    {
        $site = $this->site($request, $site);

        if (! ($request->wantsJson() && $request->header('X-Inertia') === null)) {
            return self::toPanel($site->id);
        }

        $laravel = $site->framework->isLaravel() && $site->runtime->isPhp();
        $leader = $site->leader();
        $jobs = $leader ? (ServerState::query()->find($leader->serverId)?->jobs ?? []) : [];
        $shared = $this->shared($request, $site);
        $managed = app(ManagedResources::class);
        $items = [];

        $start = match ($site->runtime) {
            SiteRuntime::Node => 'npm run start',
            SiteRuntime::Bun => 'bun run start',
            SiteRuntime::Deno => 'deno task start',
            default => null,
        };

        if ($start !== null) {
            $items[] = ['kind' => 'web', 'id' => null, 'program' => ProgramNames::app($site->slug), 'label' => 'Web process', 'command' => $start, 'detail' => $site->appPort !== null ? "PORT={$site->appPort}" : null, 'instances' => 1, 'server_ids' => []];
        }

        if ($laravel && $site->laravel->horizon) {
            $items[] = ['kind' => 'horizon', 'id' => null, 'program' => ProgramNames::horizon($site->slug), 'label' => 'Horizon', 'command' => $site->phpBinary().' artisan horizon', 'detail' => null, 'instances' => 1, 'server_ids' => []];
        }

        if ($laravel && $site->laravel->servesOctane()) {
            $server = $site->laravel->octaneServer?->value ?? 'swoole';
            $items[] = ['kind' => 'octane', 'id' => null, 'program' => ProgramNames::octane($site->slug), 'label' => 'Octane', 'command' => $site->phpBinary()." artisan octane:start --server={$server}", 'detail' => "127.0.0.1:{$site->laravel->octanePort}", 'instances' => 1, 'server_ids' => []];
        }

        foreach (Worker::query()->where('site_id', $site->id)->orderBy('created_at')->orderBy('id')->get() as $worker) {
            $items[] = [
                'kind' => 'worker',
                'id' => $worker->id,
                'program' => ProgramNames::worker($site->slug, $worker->id),
                'label' => StateCompiler::workerLabel($worker),
                'command' => $worker->command,
                'detail' => null,
                'instances' => $worker->processes,
                'server_ids' => $worker->server_ids ?? [],
                // What the project's falak.yaml manages of it (read-only in the panel), null when nothing.
                'managed' => $managed->managed('worker', $worker->id)->toArray(),
                'config' => [
                    'connection' => $worker->connection,
                    'queue' => $worker->queue,
                    'command' => $worker->command,
                    'processes' => $worker->processes,
                    'timeout' => $worker->timeout,
                    'sleep' => $worker->sleep,
                    'tries' => $worker->tries,
                    'backoff' => $worker->backoff,
                    'max_jobs' => $worker->max_jobs,
                    'max_time' => $worker->max_time,
                    'memory' => $worker->memory,
                    'limits' => (object) $worker->resourceLimits()->toArray(),
                    'env' => $this->envKeys($worker->env),
                    'server_ids' => $worker->server_ids ?? [],
                ],
            ];
        }

        foreach (Daemon::query()->where('site_id', $site->id)->orderBy('name')->get() as $daemon) {
            $items[] = [
                'kind' => 'daemon',
                'id' => $daemon->id,
                'program' => ProgramNames::daemon($site->slug, $daemon->id),
                'label' => $daemon->name,
                'command' => $daemon->command,
                'detail' => null,
                'instances' => $daemon->instances,
                'server_ids' => $daemon->server_ids ?? [],
                // What the project's falak.yaml manages of it (read-only in the panel), null when nothing.
                'managed' => $managed->managed('daemon', $daemon->id)->toArray(),
                'config' => [
                    'name' => $daemon->name,
                    'command' => $daemon->command,
                    'directory' => $daemon->directory,
                    'user' => $daemon->user,
                    'instances' => $daemon->instances,
                    'restart' => $daemon->restart,
                    'stop_signal' => $daemon->stop_signal,
                    'stop_timeout' => $daemon->stop_timeout,
                    'limits' => (object) $daemon->resourceLimits()->toArray(),
                    'env' => $this->envKeys($daemon->env),
                    'server_ids' => $daemon->server_ids ?? [],
                ],
            ];
        }

        if ($laravel && $site->laravel->scheduler) {
            $job = ProgramNames::scheduler($site->slug);
            $items[] = ['kind' => 'scheduler', 'id' => null, 'program' => $job, 'label' => 'Laravel scheduler', 'command' => $site->phpBinary().' artisan schedule:run', 'detail' => '* * * * * on the leader', 'instances' => 1, 'server_ids' => [], 'deployed' => isset($jobs[$job])];
        }

        foreach (Schedule::query()->where('site_id', $site->id)->orderBy('name')->get() as $schedule) {
            $job = ProgramNames::cron($site->slug, $schedule->id);
            $items[] = [
                'kind' => 'cron',
                'id' => $schedule->id,
                'program' => $job,
                'label' => $schedule->name,
                'command' => $schedule->command,
                'detail' => $schedule->expression.($schedule->timezone ? " ({$schedule->timezone})" : ''),
                'instances' => 1,
                'server_ids' => [],
                'deployed' => isset($jobs[$job]),
                // What the project's falak.yaml manages of it (read-only in the panel), null when nothing.
                'managed' => $managed->managed('schedule', $schedule->id)->toArray(),
                'config' => [
                    'name' => $schedule->name,
                    'command' => $schedule->command,
                    'expression' => $schedule->expression,
                    'timezone' => $schedule->timezone,
                    'user' => $schedule->user,
                    'overlap' => $schedule->overlap,
                    'timeout' => $schedule->timeout,
                    'heartbeat' => $schedule->heartbeat,
                    'enabled' => $schedule->enabled,
                    'all_servers' => $schedule->all_servers,
                ],
            ];
        }

        return response()->json(['data' => [
            'site' => ['id' => $site->id, 'name' => $site->name, 'runtime' => $site->runtime->value, 'runtime_label' => $site->runtime->label()],
            'servers' => $shared['servers'],
            'programs' => $shared['programs'],
            'items' => $items,
            'leader' => $leader?->serverId,
            'laravel' => [
                'available' => $laravel,
                'horizon' => $laravel && $site->laravel->horizon,
                'octane' => $laravel && $site->laravel->octane,
                'scheduler' => $laravel && $site->laravel->scheduler,
            ],
            'defaults' => ['directory' => $site->currentPath(), 'user' => $site->unixUser, 'php' => $site->runtime->isPhp() ? $site->phpBinary() : null],
            'options' => [
                'restart' => Daemon::RESTART_POLICIES,
                'stop_signals' => Daemon::STOP_SIGNALS,
                'presets' => [
                    ['label' => 'Every minute', 'value' => '* * * * *'],
                    ['label' => 'Every 5 minutes', 'value' => '*/5 * * * *'],
                    ['label' => 'Every 15 minutes', 'value' => '*/15 * * * *'],
                    ['label' => 'Hourly', 'value' => '@hourly'],
                    ['label' => 'Nightly (midnight)', 'value' => '@daily'],
                    ['label' => 'Weekly', 'value' => '@weekly'],
                    ['label' => 'Monthly', 'value' => '@monthly'],
                ],
                'timezones' => DateTimeZone::listIdentifiers(),
            ],
            'heartbeats_url' => '/observability/heartbeats',
            'logs_url' => $shared['logsUrl'],
            'container_runtime' => $site->runtime->usesDocker(),
            'can' => $shared['can'],
        ]]);
    }

    /** Legacy /sites/{site}/queues|daemons|scheduler pages open the Processes tab of the canvas panel. */
    public static function toPanel(string $siteId): RedirectResponse
    {
        return redirect(app(ProjectDirectory::class)->serviceUrl(ServiceKind::Site, $siteId, 'processes') ?? '/projects');
    }
}
