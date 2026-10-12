<?php

namespace Falak\Config\Http\Controllers;

use Falak\Config\Application\Actions\ApproveRun;
use Falak\Config\Application\Actions\CancelRun;
use Falak\Config\Application\Actions\RejectRun;
use Falak\Config\Application\Actions\RequestRun;
use Falak\Config\Application\Actions\SaveSource;
use Falak\Config\Application\Runs\ImplicitEnvironment;
use Falak\Config\Domain\Enums\RunStatus;
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
            'runs' => $runs->map(fn (ConfigRun $r) => Presenters::run($r, user: $request->user()))->all(),
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
            'separate_approver' => ['sometimes', 'boolean'],
            'allowed_credentials' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_credentials.*' => ['string', 'max:255'],
        ]);

        if (array_key_exists('separate_approver', $input) && ! $this->can($request->user(), $data->organizationId, ConfigPolicy::APPROVE)
            && (bool) $input['separate_approver'] !== (bool) ConfigSource::query()->where('project_id', $data->id)->value('separate_approver')) {
            abort(403, 'Only admins can change who may approve runs.');
        }

        // Letting a project's file use an organization certificate, DNS credential or storage provider is an admin's call.
        if (array_key_exists('allowed_credentials', $input) && ! $this->can($request->user(), $data->organizationId, ConfigPolicy::APPROVE)) {
            $current = ConfigSource::query()->where('project_id', $data->id)->value('allowed_credentials');
            $current = is_string($current) ? (array) json_decode($current, true) : (array) $current;

            if (array_values((array) $input['allowed_credentials']) !== array_values($current)) {
                abort(403, 'Only admins can change the certificates, DNS credentials and storage providers the file may use.');
            }
        }

        $source = $save($data, $input, (string) $request->user()?->getAuthIdentifier());

        return response()->json(['data' => Presenters::source($source)]);
    }

    /**
     * "Apply now": the file at the head of the branch the environment's last applied file binds (the default branch
     * for production when the file has no environments). **[decision]** the run is labelled with that branch and commit.
     */
    public function apply(Request $request, string $project, RequestRun $requestRun, SourceControlGateway $gateway, ImplicitEnvironment $implicit): JsonResponse
    {
        $data = $this->project($request->user(), $project, ConfigPolicy::APPLY);
        $input = $request->validate(['environment' => ['required', 'string', 'max:64']]);
        $source = ConfigSource::enabledFor($data->id) ?? throw ValidationException::withMessages(['environment' => 'Config as code is not enabled for this project.']);
        $environment = $this->environment($data, $input['environment']);
        $applied = ConfigRun::query()->where('environment_id', $environment->id)->where('status', RunStatus::Applied)->whereNotNull('resolved')->latest('finished_at')->orderByDesc('id')->first();
        $branch = $applied?->resolved['environments'][$environment->slug]['branch'] ?? ($environment->isProduction ? $implicit->defaultBranch($source) : null);

        if ($branch === null) {
            throw ValidationException::withMessages(['environment' => "No branch is bound to {$environment->slug}: push the file to the branch its environments name first."]);
        }

        $commit = rescue(fn () => $gateway->latestCommit($source->source_connection_id, $source->repository, $branch), null, false);

        if ($commit === null) {
            throw ValidationException::withMessages(['environment' => "Couldn't find the head of {$branch}."]);
        }

        $run = $requestRun($source, $environment, null, null, $commit->sha, RunTrigger::Manual, (string) $request->user()?->getAuthIdentifier(), branch: $branch);

        return response()->json(['data' => Presenters::run($run, user: $request->user())], 201);
    }

    public function run(Request $request, string $run): JsonResponse
    {
        $model = $this->run($request->user(), $run);

        // The raw file holds the env values the file writes: only for those who may read site environments.
        return response()->json(['data' => Presenters::run($model, withFile: $this->can($request->user(), $model->organization_id, 'sites.env.view'), user: $request->user())]);
    }

    public function cancel(Request $request, string $run, CancelRun $cancel): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPLY);

        if (! $cancel($model)) {
            throw ValidationException::withMessages(['run' => 'Only a run that is planning or awaiting approval can be cancelled.']);
        }

        return response()->json(['data' => Presenters::run($model->refresh(), user: $request->user())]);
    }

    public function approve(Request $request, string $run, ApproveRun $approve): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPROVE);
        $input = $request->validate(['plan_hash' => ['required', 'string', 'size:64'], 'comment' => ['nullable', 'string', 'max:2000']]);
        $outcome = $approve($model, (string) $request->user()?->getAuthIdentifier(), $input['plan_hash'], $input['comment'] ?? null);

        if ($outcome === ApproveRun::BLOCKED) {
            abort(403, (string) ApproveRun::blockedReason($model, (string) $request->user()?->getAuthIdentifier()));
        }

        if ($outcome !== ApproveRun::APPROVED) {
            throw ValidationException::withMessages(['run' => $outcome === ApproveRun::PLAN_CHANGED ? 'The plan changed since it was shown; review it again.' : 'This run is not awaiting approval.']);
        }

        return response()->json(['data' => Presenters::run($model->refresh(), user: $request->user())]);
    }

    public function reject(Request $request, string $run, RejectRun $reject): JsonResponse
    {
        $model = $this->run($request->user(), $run, ConfigPolicy::APPROVE);
        $input = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        if (! $reject($model, (string) $request->user()?->getAuthIdentifier(), $input['comment'] ?? null)) {
            throw ValidationException::withMessages(['run' => 'This run is not awaiting approval.']);
        }

        return response()->json(['data' => Presenters::run($model->refresh(), user: $request->user())]);
    }
}
