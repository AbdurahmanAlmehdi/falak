<?php

namespace Falak\Config\Http\Controllers\Api;

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Actions\CancelRun;
use Falak\Config\Application\Actions\RequestRun;
use Falak\Config\Application\Exporter;
use Falak\Config\Application\Runs\FileFetcher;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Application\Runs\PlanFile;
use Falak\Config\Application\ScopeFactory;
use Falak\Config\Application\Validation\FileCheck;
use Falak\Config\Contracts\ConfigIssue;
use Falak\Config\Domain\Enums\RunTrigger;
use Falak\Config\Domain\Models\ConfigRun;
use Falak\Config\Domain\Models\ConfigSource;
use Falak\Config\Domain\Policies\ConfigPolicy;
use Falak\Config\Http\Controllers\ResolvesProjects;
use Falak\Config\Http\Presenters;
use Falak\Kernel\Http\Controller;
use Falak\Projects\Contracts\Data\ProjectData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Config as code over `/api/v1` (docs/API.md "Config as code"; the CLI's validate, plan, apply and export).
 */
final class ConfigApiController extends Controller
{
    use ResolvesProjects;

    private const FILE_RULES = ['nullable', 'string', 'max:1048576'];

    public function validateFile(Request $request, string $project, FileCheck $check, ScopeFactory $scopes, ImplicitEnvironment $implicit): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $input = $request->validate(['file' => ['present', 'nullable', 'string', 'max:1048576'], 'format' => ['nullable', 'in:yaml,json'], 'environment' => ['nullable', 'string', 'max:64']]);
        $source = ConfigSource::query()->where('project_id', $data->id)->first();
        $format = $input['format'] ?? 'yaml';

        if (($input['environment'] ?? null) !== null) {
            $environment = $this->environment($data, $input['environment']);
            $validation = $check->check((string) $input['file'], $format, $environment->slug,
                fn (array $resolved) => $scopes->make($environment, $source, $resolved, null, (string) $request->user()?->getAuthIdentifier()),
                $source !== null ? $implicit->forCheck($source, $environment) : null);
        } else {
            $validation = $check->check((string) $input['file'], $format);
        }

        return response()->json(['data' => [
            'valid' => $validation->valid(),
            'environments' => $validation->environments,
            'errors' => array_map(fn (ConfigIssue $i) => $i->toArray(), $validation->errors),
            'warnings' => array_map(fn (ConfigIssue $i) => $i->toArray(), $validation->warnings),
        ]]);
    }

    public function plan(Request $request, string $project, PlanFile $plan, FileFetcher $files): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $input = $this->runInput($request);
        $source = $this->source($data);
        $environment = $this->environment($data, $input['environment']);
        [$raw, $format] = $this->file($source, $input, $files);

        [$validation, $result] = $plan($source, $environment, $raw, $format, $input['commit'] ?? null, (string) $request->user()?->getAuthIdentifier());

        if ($result === null) {
            return response()->json($validation->toErrorResponse($source->path), 422);
        }

        return response()->json(['data' => [
            'environment' => $environment->slug,
            'commit' => $input['commit'] ?? null,
            'resolved' => $validation->resolved,
            'plan' => $result->toArray(),
            'warnings' => array_map(fn (ConfigIssue $i) => $i->toArray(), $validation->warnings),
        ]]);
    }

    public function store(Request $request, string $project, RequestRun $requestRun): JsonResponse
    {
        $data = $this->project($request->user(), $project, ConfigPolicy::APPLY);
        $input = $this->runInput($request) + $request->validate(['allow_destroy' => ['nullable', 'boolean']]);

        if ((bool) ($input['allow_destroy'] ?? false) && ! $this->can($request->user(), $data->organizationId, ConfigPolicy::DESTROY)) {
            abort(403, 'Deleting databases and volumes needs the config.destroy permission.');
        }

        $source = $this->source($data);
        $environment = $this->environment($data, $input['environment']);
        $run = $requestRun($source, $environment, $input['file'] ?? null, $input['format'] ?? 'yaml', $input['commit'] ?? null,
            RunTrigger::Cli, (string) $request->user()?->getAuthIdentifier(), (bool) ($input['allow_destroy'] ?? false));

        if ($run->status->value === 'failed' && ($run->errors ?? []) !== [] && collect($run->errors)->contains(fn ($i) => ($i['severity'] ?? 'error') === 'error')) {
            // An invalid file answers like validate: 422 with the issues (the run is kept in the history).
            $errors = array_values(array_filter(array_map(fn (array $i) => ConfigIssue::fromArray($i), $run->errors), fn (ConfigIssue $i) => $i->isError()));
            $warnings = array_values(array_filter(array_map(fn (array $i) => ConfigIssue::fromArray($i), $run->errors), fn (ConfigIssue $i) => ! $i->isError()));

            return response()->json([
                'message' => (string) $run->error,
                'errors' => ['file' => array_map(fn (ConfigIssue $i) => ($i->pointer ? "{$i->pointer}: " : '').$i->message, $errors)],
                'config_errors' => array_map(fn (ConfigIssue $i) => $i->toArray(), $errors),
                'config_warnings' => array_map(fn (ConfigIssue $i) => $i->toArray(), $warnings),
                'run' => Presenters::run($run),
            ], 422);
        }

        return response()->json(['data' => Presenters::run($run)], 201);
    }

    public function index(Request $request, string $project): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $environment = $request->query('environment');
        $runs = ConfigRun::query()->where('project_id', $data->id)
            ->when(is_string($environment) && $environment !== '', fn ($q) => $q->where('environment', $environment))
            ->latest()->orderByDesc('id')->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => array_map(fn (ConfigRun $r) => Presenters::run($r), $runs->items()),
            'links' => ['next' => $runs->nextPageUrl(), 'prev' => $runs->previousPageUrl()],
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total(), 'per_page' => $runs->perPage()],
        ]);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        return response()->json(['data' => Presenters::run($this->run($request->user(), $run))]);
    }

    public function approve(Request $request, string $run, ApproveRun $approve): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPROVE);

        if (! $approve($model, (string) $request->user()?->getAuthIdentifier())) {
            return response()->json(['message' => 'This run is not awaiting approval.'], 409);
        }

        return response()->json(['data' => Presenters::run($model->refresh())]);
    }

    public function cancel(Request $request, string $run, CancelRun $cancel): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPLY);

        if (! $cancel($model)) {
            return response()->json(['message' => 'Only a run that is planning or awaiting approval can be cancelled.'], 409);
        }

        return response()->json(['data' => Presenters::run($model->refresh())]);
    }

    public function export(Request $request, string $project, Exporter $exporter): JsonResponse
    {
        $data = $this->project($request->user(), $project);
        $input = $request->validate(['environment' => ['required', 'string', 'max:64'], 'format' => ['nullable', 'in:yaml,json']]);
        $environment = $this->environment($data, $input['environment']);
        $format = $input['format'] ?? 'yaml';
        $source = ConfigSource::query()->where('project_id', $data->id)->first();

        return response()->json(['data' => [
            'environment' => $environment->slug,
            'format' => $format,
            'file' => $exporter->render($exporter->document($environment, $source), $format),
        ]]);
    }

    /**
     * @return array{file?: ?string, format?: ?string, commit?: ?string, environment: string}
     */
    private function runInput(Request $request): array
    {
        $input = $request->validate([
            'file' => ['required_without:commit', 'prohibits:commit', ...self::FILE_RULES],
            'commit' => ['required_without:file', 'nullable', 'string', 'regex:/^[0-9a-fA-F]{7,40}$/'],
            'format' => ['nullable', 'in:yaml,json'],
            'environment' => ['required', 'string', 'max:64'],
        ]);

        /** @var array{file?: ?string, format?: ?string, commit?: ?string, environment: string} */
        return $input;
    }

    private function source(ProjectData $project): ConfigSource
    {
        $source = ConfigSource::enabledFor($project->id);

        if ($source === null) {
            abort(response()->json(['message' => 'Config as code is not enabled for this project.'], 409));
        }

        return $source;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string}
     */
    private function file(ConfigSource $source, array $input, FileFetcher $files): array
    {
        if (($input['file'] ?? null) !== null) {
            return [(string) $input['file'], ($input['format'] ?? 'yaml') === 'json' ? 'json' : 'yaml'];
        }

        [$raw, $problem] = $files->fetch($source, (string) $input['commit']);

        if ($raw === null) {
            throw ValidationException::withMessages(['commit' => $problem]);
        }

        return [$raw, $source->format()];
    }
}
