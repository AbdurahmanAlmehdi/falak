<?php

namespace Falak\Config\Http\Controllers;

use Falak\Config\Application\Actions\CancelRun;
use Falak\Config\Application\Actions\RequestRun;
use Falak\Config\Application\Actions\SaveSource;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Domain\Policies\ConfigPolicy;
use Falak\Config\Http\Presenters;
use Falak\Kernel\Http\Controller;
use Falak\SourceControl\Contracts\ProviderType;
use Falak\SourceControl\Contracts\SourceControlGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Config section of a project's settings (JSON for the dashboard): the source, the run history, "Apply now".
 */
final class ConfigController extends Controller
{
    use ResolvesProjects;

    public function show(Request $request, string $project, SourceControlGateway $gateway): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $source = ConfigSource::query()->where('project_id', $data->id)->first();
        $runs = ConfigRun::query()->where('project_id', $data->id)->latest()->orderByDesc('id')->limit(30)->get();
        $connections = array_values(array_filter($gateway->connections($data->organizationId), fn ($c) => $c->provider !== ProviderType::Custom));

        return response()->json(['data' => [
            'source' => Presenters::source($source),
            'connections' => array_map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'provider' => $c->provider->value], $connections),
            'runs' => $runs->map(fn (ConfigRun $r) => Presenters::run($r))->all(),
            'can' => [
                'apply' => $this->can($request->user(), $data->organizationId, ConfigPolicy::APPLY),
                'approve' => $this->can($request->user(), $data->organizationId, ConfigPolicy::APPROVE),
            ],
        ]]);
    }

    public function update(Request $request, string $project, SaveSource $save): JsonResponse
    {
        $data = $this->project($request->user(), $project, ConfigPolicy::APPLY);
        $input = $request->validate([
            'connection_id' => ['required', 'string', 'max:26'],
            'repository' => ['required', 'string', 'max:255', 'regex:#^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)+$#'],
            'path' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ]);

        $source = $save($data, $input, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => Presenters::source($source)]);
    }

    public function apply(Request $request, string $project, RequestRun $requestRun, SourceControlGateway $gateway): JsonResponse
    {
        $data = $this->project($request->user(), $project, ConfigPolicy::APPLY);
        $input = $request->validate(['environment' => ['required', 'string', 'max:64'], 'branch' => ['required', 'string', 'max:250']]);
        $source = ConfigSource::enabledFor($data->id) ?? throw ValidationException::withMessages(['environment' => 'Config as code is not enabled for this project.']);
        $environment = $this->environment($data, $input['environment']);
        $commit = rescue(fn () => $gateway->latestCommit($source->source_connection_id, $source->repository, $input['branch']), null, false);

        if ($commit === null) {
            throw ValidationException::withMessages(['branch' => "Couldn't find the head of {$input['branch']}."]);
        }

        $run = $requestRun($source, $environment, null, null, $commit->sha, RunTrigger::Manual, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => Presenters::run($run)], 201);
    }

    public function run(Request $request, string $run): JsonResponse
    {
        return response()->json(['data' => Presenters::run($this->run($request->user(), $run), withFile: true)]);
    }

    public function cancel(Request $request, string $run, CancelRun $cancel): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPLY);

        if (! $cancel($model)) {
            throw ValidationException::withMessages(['run' => 'Only a run that is planning or awaiting approval can be cancelled.']);
        }

        return response()->json(['data' => Presenters::run($model->refresh())]);
    }
}
