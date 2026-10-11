<?php

namespace Falak\Functions\Http\Controllers;

use Falak\Config\Contracts\ManagedResources;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Functions\Application\Actions\SaveSchedule;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionSchedule;
use Falak\Functions\FunctionsServiceProvider as Permissions;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\SiteDirectory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The function panel's Schedules tab (JSON): schedules, and "Run now" with its live output.
 */
final class ScheduleController extends Controller
{
    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly SiteDirectory $sites,
        private readonly FunctionStore $functions,
        private readonly Cache $cache,
    ) {}

    public function index(Request $request, string $site): JsonResponse
    {
        [$data, $function] = $this->resolve($request, $site, Permissions::VIEW);
        $schedules = FunctionSchedule::query()->where('function_id', $function->id)->orderBy('created_at')->get();

        return response()->json(['data' => [
            'schedules' => $schedules->map(fn (FunctionSchedule $s) => $s->present($data->slug))->values(),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'can' => ['manage' => $this->canDeploy($request, $data)],
        ]]);
    }

    public function store(Request $request, string $site, SaveSchedule $save): JsonResponse
    {
        [$data, $function] = $this->resolve($request, $site, Permissions::DEPLOY);
        $input = $request->validate(SaveSchedule::rules());

        // A name the file uses is the file's: a schedule created here would be adopted by the next push.
        if (FunctionSchedule::query()->where('function_id', $function->id)->where('name', trim((string) $input['name']))->get()->contains(fn (FunctionSchedule $s) => app(ManagedResources::class)->managed('function_schedule', $s->id)->any())) {
            throw ValidationException::withMessages(['name' => 'falak.yaml manages a schedule with this name: edit the file to change it.']);
        }

        $schedule = $save($data, $function, null, $input);

        return response()->json(['data' => $schedule->present($data->slug)], 201);
    }

    public function update(Request $request, string $site, string $schedule, SaveSchedule $save): JsonResponse
    {
        [$data, $function] = $this->resolve($request, $site, Permissions::DEPLOY);
        $model = $this->schedule($function, $schedule);
        app(ManagedResources::class)->managed('function_schedule', $model->id)->guardWhole('name', '/schedules/'.$model->name);
        $model = $save($data, $function, $model, $request->validate(SaveSchedule::rules()));

        return response()->json(['data' => $model->present($data->slug)]);
    }

    public function destroy(Request $request, string $site, string $schedule, SaveSchedule $save): JsonResponse
    {
        [$data, $function] = $this->resolve($request, $site, Permissions::DEPLOY);
        $model = $this->schedule($function, $schedule);
        app(ManagedResources::class)->managed('function_schedule', $model->id)->guardWhole('name', '/schedules/'.$model->name);
        $save->delete($data, $model);

        return response()->json(['data' => null]);
    }

    /** Run now: the schedule runs once on the leader server (fn.run); poll runStatus for the output. */
    public function run(Request $request, string $site, string $schedule, AgentGateway $agents): JsonResponse
    {
        [$data, $function] = $this->resolve($request, $site, Permissions::DEPLOY);
        $model = $this->schedule($function, $schedule);
        $leader = $this->sites->leader($data->id) ?? throw ValidationException::withMessages(['schedule' => 'The function has no server.']);

        try {
            $handle = $agents->dispatch($leader->serverId, 'fn.run', [
                'site' => $data->slug,
                'schedule' => $model->key(),
                'name' => mb_substr($model->name, 0, 128),
                'cron' => $model->expression,
                'timeout_s' => $model->timeout_s,
            ], $model->timeout_s + 60);
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['schedule' => 'The function’s server is not connected.']);
        }

        $this->cache->put($this->runKey($data, $handle->id), $model->id, now()->addDay());

        return response()->json(['data' => ['run_id' => $handle->id]], 202);
    }

    public function runStatus(Request $request, string $site, string $run, AgentGateway $agents): JsonResponse
    {
        [$data] = $this->resolve($request, $site, Permissions::VIEW);
        abort_unless($this->cache->has($this->runKey($data, $run)), 404, 'Run not found.');

        $status = $agents->status($run);
        $output = $agents->output($run);

        return response()->json(['data' => [
            'status' => $status->status->value,
            'finished' => $status->isFinished(),
            'exit_code' => $status->result['exit_code'] ?? $status->exitCode,
            'duration_ms' => $status->result['duration_ms'] ?? null,
            'error' => $status->error,
            'output' => $output->text(),
        ]])->header('Cache-Control', 'no-store');
    }

    private function runKey(SiteData $site, string $run): string
    {
        return "functions:run:{$site->id}:".strtolower($run);
    }

    private function schedule(CloudFunction $function, string $id): FunctionSchedule
    {
        return FunctionSchedule::query()->where('function_id', $function->id)->findOrFail(strtolower($id));
    }

    private function canDeploy(Request $request, SiteData $site): bool
    {
        return $this->access->can($request->user(), $site->organizationId, Permissions::DEPLOY);
    }

    /**
     * @return array{0: SiteData, 1: CloudFunction}
     */
    private function resolve(Request $request, string $siteId, string $permission): array
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, Permissions::VIEW);
        $site = $this->sites->find(strtolower($siteId));
        abort_if($site === null || $site->organizationId !== $organizationId || ! $site->runtime->isFunction(), 404, 'Function not found.');
        $this->access->authorize($request->user(), $organizationId, $permission);

        return [$site, $this->functions->ensure($site)];
    }
}
