<?php

namespace Falak\Sandboxes\Http\Controllers\Api;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sandboxes\Application\Actions\CreateSandbox;
use Falak\Sandboxes\Application\Actions\ExecInSandbox;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\Listeners\HandleCommandOutcome;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
use Falak\Sandboxes\Domain\Policies\SandboxPolicy;
use Falak\Sandboxes\Infrastructure\EloquentSandboxDirectory;
use Falak\Secrets\Contracts\Secrets;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sandboxes API v1 (docs/plans/SANDBOXES.md "HTTP API"), used by `falak sandbox`, `falak mcp` and the dashboard (the
 * same routes under the session). Sandboxes of other organizations are 404; actions on a sandbox that isn't running
 * are 409.
 */
final class SandboxApiController extends Controller
{
    private const HOST = '/^(\*\.)?[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    private const IMAGE = '/^[a-zA-Z0-9][a-zA-Z0-9._\/:@-]*$/';

    private const ENV_KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        private readonly CurrentOrganization $organization,
        private readonly OrganizationAccess $access,
        private readonly ServerDirectory $servers,
        private readonly SandboxAgent $agent,
    ) {}

    /** GET /sandboxes ?status=: {data: [sandbox]} (without a status: live ones and those killed in the last day) */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SandboxPolicy::VIEW);
        $status = $request->validate(['status' => ['nullable', Rule::in([Sandbox::CREATING, Sandbox::RUNNING, Sandbox::FAILED, Sandbox::KILLED])]])['status'] ?? null;

        $query = Sandbox::query()->where('organization_id', $organizationId)->latest('created_at')->latest('id')->limit(500);
        $status !== null
            ? $query->where('status', $status)
            : $query->where(fn ($q) => $q->where('status', '!=', Sandbox::KILLED)->orWhere('killed_at', '>', now()->subDay()));

        return response()->json(['data' => $this->present($query->get()->all(), $organizationId)]);
    }

    /** POST /sandboxes [?wait=1]: 201 {data: sandbox} */
    public function store(Request $request, CreateSandbox $create, AgentGateway $agents): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SandboxPolicy::USE);
        $input = $request->validate([
            'image' => ['nullable', 'string', 'max:255', 'regex:'.self::IMAGE],
            'name' => ['nullable', 'string', 'max:63', 'regex:'.Sandbox::NAME],
            'server' => ['nullable', 'string', 'max:255'],
            'cpus' => ['nullable', 'numeric'],
            'memory_mb' => ['nullable', 'integer'],
            'disk_mb' => ['nullable', 'integer'],
            'ttl' => ['nullable'],
            'network' => ['nullable', Rule::in(Sandbox::NETWORKS)],
            'allow_hosts' => ['nullable', 'array', 'max:100'],
            'allow_hosts.*' => ['string', 'max:253', 'regex:'.self::HOST],
            'env' => ['nullable', 'array', 'max:100'],
            'env.*' => ['nullable', 'string', 'max:32768'],
            'repo' => ['nullable', 'array'],
            'repo.url' => ['required_with:repo', 'string', 'max:2048', 'regex:/^(https:\/\/|git@|ssh:\/\/)\S+$/'],
            'repo.ref' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9._\/@^~-]+$/'],
            'repo.secret' => ['nullable', 'string', 'max:255', 'regex:'.Secrets::NAME_PATTERN],
        ]);

        $input['env'] = $this->env($input['env'] ?? []);

        if (isset($input['ttl']) && ! is_int($input['ttl']) && ! is_string($input['ttl'])) {
            throw ValidationException::withMessages(['ttl' => 'Give a number of seconds or a duration like 30m, 2h or 1h30m.']);
        }

        $sandbox = $create($organizationId, Actor::of($request->user()), $input);

        if ($request->boolean('wait') && $sandbox->command_id !== null) {
            $result = $agents->await($sandbox->command_id, 60);
            $sandbox->refresh();

            if ($result->isFinished() && $sandbox->status === Sandbox::CREATING) {
                $result->isSuccessful()
                    ? HandleCommandOutcome::started($sandbox, $result->result ?? [])
                    : HandleCommandOutcome::failed($sandbox, (string) ($result->error ?: 'The sandbox could not be created.'));
            }
        }

        return response()->json(['data' => $this->present([$sandbox], $organizationId)[0]], 201);
    }

    /** GET /sandboxes/{id}: {data: sandbox} */
    public function show(Request $request, string $sandbox): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'view');

        return response()->json(['data' => $this->present([$model], $model->organization_id)[0]]);
    }

    /** PATCH /sandboxes/{id} {name?, extend?}: {data: sandbox} */
    public function update(Request $request, string $sandbox): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'use');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:63', 'regex:'.Sandbox::NAME],
            'extend' => ['nullable'],
        ]);

        if (isset($data['name']) && $data['name'] !== $model->name) {
            $taken = Sandbox::query()->where('organization_id', $model->organization_id)->where('status', '!=', Sandbox::KILLED)
                ->where('name', $data['name'])->whereKeyNot($model->id)->exists();

            if ($taken) {
                throw ValidationException::withMessages(['name' => "A sandbox called {$data['name']} already exists."]);
            }

            $model->forceFill(['name' => $data['name']])->save();
        }

        if (isset($data['extend'])) {
            if (! is_int($data['extend']) && ! is_string($data['extend'])) {
                throw ValidationException::withMessages(['extend' => 'Give a number of seconds or a duration like 30m.']);
            }

            $this->extend($model, $data['extend']);
        }

        return response()->json(['data' => $this->present([$model], $model->organization_id)[0]]);
    }

    /** DELETE /sandboxes/{id}: 204 (killing a killed sandbox too) */
    public function destroy(Request $request, string $sandbox, KillSandbox $kill): Response
    {
        $model = $this->resolve($request, $sandbox, 'kill');
        $kill($model);

        return response()->noContent();
    }

    /** POST /sandboxes/{id}/exec {cmd, cwd?, env?, stdin?, timeout?} [?raw=1]: {data: {exit_code, stdout, stderr, truncated, timed_out, duration_ms}} */
    public function exec(Request $request, string $sandbox, ExecInSandbox $exec): JsonResponse
    {
        $model = $this->running($request, $sandbox);
        $data = $request->validate([
            'cmd' => ['required'],
            'cmd.*' => ['string', 'max:131072'],
            'cwd' => ['nullable', 'string', 'max:4096', 'regex:/^\//'],
            'env' => ['nullable', 'array', 'max:100'],
            'env.*' => ['nullable', 'string', 'max:32768'],
            'stdin' => ['nullable', 'string', 'max:1048576'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);
        $cmd = $data['cmd'];

        if (! (is_string($cmd) && trim($cmd) !== '') && ! (is_array($cmd) && $cmd !== [] && array_is_list($cmd))) {
            throw ValidationException::withMessages(['cmd' => 'Give the command as a string or a list of arguments.']);
        }

        $out = $exec($model, Actor::of($request->user()), $cmd, $data['cwd'] ?? null, $this->env($data['env'] ?? []), $data['stdin'] ?? null, (int) ($data['timeout'] ?? 60));
        $raw = $request->boolean('raw');

        return response()->json(['data' => [
            ...$out,
            'stdout' => $raw ? base64_encode($out['stdout']) : mb_scrub($out['stdout'], 'UTF-8'),
            'stderr' => $raw ? base64_encode($out['stderr']) : mb_scrub($out['stderr'], 'UTF-8'),
        ]]);
    }

    /** GET /sandboxes/{id}/files ?path= [&list=1]: the file's bytes, or {data: {entries, truncated}} */
    public function files(Request $request, string $sandbox): Response|JsonResponse
    {
        $model = $this->running($request, $sandbox);
        $path = $this->path($request);

        if ($request->boolean('list')) {
            $result = $this->agent->ask($model, 'sandbox.files.list', ['id' => $model->id, 'path' => $path], 30);

            return response()->json(['data' => ['entries' => array_values((array) ($result['entries'] ?? [])), 'truncated' => (bool) ($result['truncated'] ?? false)]]);
        }

        $result = $this->agent->ask($model, 'sandbox.files.read', ['id' => $model->id, 'path' => $path, 'max_bytes' => (int) config('sandboxes.max_file_bytes')], 60);

        return response((string) base64_decode((string) ($result['content'] ?? ''), true), 200, array_filter([
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes(basename($path) ?: 'file', '"\\').'"',
            'X-Falak-File-Size' => (string) (int) ($result['size'] ?? 0),
            'X-Falak-File-Mode' => isset($result['mode']) ? (string) $result['mode'] : null,
            'X-Falak-Truncated' => ($result['truncated'] ?? false) ? '1' : '0',
            'Cache-Control' => 'no-store',
        ]));
    }

    /** PUT /sandboxes/{id}/files ?path=&mode=, body = bytes (≤ 5 MB): {data: {size}} */
    public function write(Request $request, string $sandbox, ExecInSandbox $exec): JsonResponse
    {
        $model = $this->running($request, $sandbox);
        $path = $this->path($request);
        $mode = $request->validate(['mode' => ['nullable', 'string', 'regex:/^0?[0-7]{3,4}$/']])['mode'] ?? null;
        $content = $request->getContent();
        abort_if(strlen($content) > (int) config('sandboxes.max_file_bytes'), 413, 'Files are limited to 5 MB.');

        $startedAt = now();
        $result = $this->agent->ask($model, 'sandbox.files.write', array_filter(['id' => $model->id, 'path' => $path, 'content' => base64_encode($content), 'mode' => $mode], fn ($v) => $v !== null), 60);
        $exec->record($model, Actor::of($request->user()), 'files.write '.$path.' ('.strlen($content).' bytes)', null, [], $startedAt, ['exit_code' => 0]);

        return response()->json(['data' => ['size' => (int) ($result['size'] ?? strlen($content))]]);
    }

    /** DELETE /sandboxes/{id}/files ?path=&recursive=1: 204 */
    public function deleteFile(Request $request, string $sandbox, ExecInSandbox $exec): Response
    {
        $model = $this->running($request, $sandbox);
        $path = $this->path($request);
        $recursive = $request->boolean('recursive');

        $startedAt = now();
        $this->agent->ask($model, 'sandbox.files.delete', ['id' => $model->id, 'path' => $path, 'recursive' => $recursive], 60);
        $exec->record($model, Actor::of($request->user()), 'files.delete '.($recursive ? '-r ' : '').$path, null, [], $startedAt, ['exit_code' => 0]);

        return response()->noContent();
    }

    /** GET /sandboxes/{id}/execs: {data: [{id, cmd, exit_code, duration_ms, actor, started_at}]} (newest first) */
    public function execs(Request $request, string $sandbox): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'view');

        return response()->json(['data' => SandboxExec::query()->where('sandbox_id', $model->id)->latest('started_at')->latest('id')->limit(500)->get()
            ->map(fn (SandboxExec $exec) => $exec->present())->values()]);
    }

    /**
     * Extend the time to live: the sandbox's whole lifetime stays within `sandboxes.max_ttl`. The agent learns the new
     * expires_at from sandbox.create sent again (idempotent by id: an existing sandbox only takes the new expiry).
     */
    private function extend(Sandbox $sandbox, int|string $by): void
    {
        abort_unless($sandbox->isLive(), 409, 'The sandbox is not running.');

        $seconds = CreateSandbox::seconds($by, 'extend');
        $expires = $sandbox->expires_at->copy()->addSeconds($seconds);
        $max = (int) config('sandboxes.max_ttl');

        if ($seconds < 1 || $sandbox->created_at->diffInSeconds($expires) > $max) {
            throw ValidationException::withMessages(['extend' => 'A sandbox lives at most '.intdiv($max, 60).' minutes in all.']);
        }

        $sandbox->forceFill(['expires_at' => $expires, 'ttl_seconds' => (int) $sandbox->created_at->diffInSeconds($expires)])->save();

        if ($sandbox->isRunning()) {
            $this->agent->tryDispatch($sandbox, 'sandbox.create', CreateSandbox::payload($sandbox), "sandbox.create:{$sandbox->id}:expires:{$expires->getTimestamp()}");
        }
    }

    /**
     * @param  array<array-key, mixed>  $env
     * @return array<string, string>
     */
    private function env(array $env): array
    {
        foreach (array_keys($env) as $key) {
            if (! is_string($key) || preg_match(self::ENV_KEY, $key) !== 1) {
                throw ValidationException::withMessages(['env' => 'Variable names are letters, digits and underscores, not starting with a digit.']);
            }
        }

        return array_map(fn ($value) => (string) $value, $env);
    }

    private function path(Request $request): string
    {
        return $request->validate(['path' => ['required', 'string', 'max:4096', 'regex:/^\/[^\x00]*$/']])['path'];
    }

    private function resolve(Request $request, string $ref, string $ability): Sandbox
    {
        $organizationId = $this->organization->requireId();
        $sandbox = EloquentSandboxDirectory::lookup($organizationId, $ref);
        abort_if($sandbox === null, 404, 'Sandbox not found.');
        $this->authorize($ability, $sandbox);

        return $sandbox;
    }

    private function running(Request $request, string $ref): Sandbox
    {
        $sandbox = $this->resolve($request, $ref, 'use');
        abort_unless($sandbox->isRunning(), 409, $sandbox->status === Sandbox::CREATING ? 'The sandbox is still starting.' : "The sandbox is {$sandbox->status}.");

        return $sandbox;
    }

    /**
     * @param  list<Sandbox>  $sandboxes
     * @return list<array<string, mixed>>
     */
    private function present(array $sandboxes, string $organizationId): array
    {
        $names = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            /** @var ServerData $server */
            $names[$server->id] = $server->name;
        }

        return array_map(fn (Sandbox $sandbox) => $sandbox->present($names[$sandbox->server_id] ?? null), $sandboxes);
    }
}
