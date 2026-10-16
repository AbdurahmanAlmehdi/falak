<?php

namespace Falak\Sandboxes\Http\Controllers\Api;

use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\CommandTimedOut;
use Falak\Identity\Contracts\CurrentOrganization;
use Falak\Identity\Contracts\OrganizationAccess;
use Falak\Kernel\Http\Controller;
use Falak\Sandboxes\Application\Actions\CreateSandbox;
use Falak\Sandboxes\Application\Actions\ExecInSandbox;
use Falak\Databases\Contracts\DatabaseDirectory;
use Falak\Sandboxes\Application\Actions\DeleteSnapshot;
use Falak\Sandboxes\Application\Actions\ImportDatabase;
use Falak\Sandboxes\Application\Actions\KillSandbox;
use Falak\Sandboxes\Application\Actions\PauseSandbox;
use Falak\Sandboxes\Application\Actions\TakeSnapshot;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\Listeners\HandleCommandOutcome;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Falak\Sandboxes\Domain\Policies\SandboxPolicy;
use Falak\Sandboxes\Infrastructure\EloquentSandboxDirectory;
use Falak\Secrets\Contracts\Secrets;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Sandboxes API v1 (docs/plans/SANDBOXES.md "HTTP API"), used by `falak sandbox`, `falak mcp` and the dashboard (the
 * same routes under the session). Sandboxes of other organizations are 404; actions on a sandbox that isn't running
 * are 409.
 */
final class SandboxApiController extends Controller
{
    private const HOST = '/^(\*\.)?[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    /** An image reference: no "..", no trailing dot (the schema's pattern too). */
    private const IMAGE = '/^[a-zA-Z0-9](\.?[a-zA-Z0-9_\/:@-])*$/';

    private const ENV_KEY = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** What revealing a secret needs; repo.secret hands its value to the sandbox. */
    private const SECRETS_REVEAL = 'secrets.reveal';

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
        $status = $request->validate(['status' => ['nullable', Rule::in([Sandbox::CREATING, Sandbox::RUNNING, Sandbox::PAUSED, Sandbox::FAILED, Sandbox::KILLED])]])['status'] ?? null;

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
            'image' => ['nullable', 'string', 'max:255', 'regex:'.self::IMAGE, function (string $attribute, mixed $value, \Closure $fail) {
                if (is_string($value) && self::privateRegistry($value)) {
                    $fail('Images come from a public registry, not localhost or a private or loopback address.');
                }
            }],
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
            'snapshot' => ['nullable', 'string', 'max:255'],
            'idle_pause' => ['nullable'],
            'db' => ['nullable', 'array'],
            'db.instance' => ['required_with:db', 'string', 'max:255'],
            'db.database' => ['required_with:db', 'string', 'max:64'],
        ]);

        $input['env'] = $this->env($input['env'] ?? []);

        foreach (['ttl', 'idle_pause'] as $field) {
            if (isset($input[$field]) && ! is_int($input[$field]) && ! is_string($input[$field])) {
                throw ValidationException::withMessages([$field => 'Give a number of seconds or a duration like 30m, 2h or 1h30m.']);
            }
        }

        if (isset($input['repo']['secret'])) {
            $this->authorizeSecretRead($request, $organizationId);
        }

        $actor = Actor::of($request->user());
        $input['db_import'] = isset($input['db']) ? $this->database($request, $organizationId, $input['db']['instance'], $input['db']['database'], null, 'db.') : null;

        if (($input['snapshot'] ?? null) !== null) {
            // = a fork with count 1: everything the snapshot fixes is not the request's to choose.
            $fixed = array_values(array_filter(['image', 'cpus', 'memory_mb', 'disk_mb', 'network', 'allow_hosts', 'server', 'repo'], fn (string $key) => isset($input[$key])));

            if ($fixed !== []) {
                throw ValidationException::withMessages(array_fill_keys($fixed, 'A sandbox from a snapshot has the snapshot\'s image, size, network and server; leave this out.'));
            }

            $snapshot = SandboxSnapshot::lookup($organizationId, $input['snapshot']);

            if ($snapshot === null) {
                throw ValidationException::withMessages(['snapshot' => "No snapshot of your organization is called {$input['snapshot']}."]);
            }

            $this->authorize('operate', $snapshot);
            $sandboxes = $create->fork($snapshot, $actor, $input);
        } else {
            $sandboxes = [$create($organizationId, $actor, $input)];
        }

        if ($request->boolean('wait')) {
            $this->wait($sandboxes, $agents);
        }

        return response()->json(['data' => $this->present($sandboxes, $organizationId)[0]], 201);
    }

    /** POST /sandboxes/snapshots/{snapshot}/fork {count?, name?, ttl?, env?, idle_pause?} [?wait=1]: 201 {data: [sandbox]} */
    public function fork(Request $request, string $snapshot, CreateSandbox $create, AgentGateway $agents): JsonResponse
    {
        $model = $this->snapshotOf($snapshot, 'operate');
        $input = $request->validate([
            'count' => ['nullable', 'integer', 'min:1', 'max:'.CreateSandbox::MAX_FORK_COUNT],
            'name' => ['nullable', 'string', 'max:60', 'regex:'.Sandbox::NAME],
            'ttl' => ['nullable'],
            'env' => ['nullable', 'array', 'max:100'],
            'env.*' => ['nullable', 'string', 'max:32768'],
            'idle_pause' => ['nullable'],
        ]);
        $input['env'] = $this->env($input['env'] ?? []);

        foreach (['ttl', 'idle_pause'] as $field) {
            if (isset($input[$field]) && ! is_int($input[$field]) && ! is_string($input[$field])) {
                throw ValidationException::withMessages([$field => 'Give a number of seconds or a duration like 30m, 2h or 1h30m.']);
            }
        }

        $sandboxes = $create->fork($model, Actor::of($request->user()), $input, (int) ($input['count'] ?? 1));

        if ($request->boolean('wait')) {
            $this->wait($sandboxes, $agents);
        }

        return response()->json(['data' => $this->present($sandboxes, $model->organization_id)], 201);
    }

    /** GET /sandboxes/snapshots ?sandbox=: {data: [snapshot]} (not deleted, newest first) */
    public function snapshots(Request $request): JsonResponse
    {
        $organizationId = $this->organization->requireId();
        $this->access->authorize($request->user(), $organizationId, SandboxPolicy::VIEW);
        $ref = $request->validate(['sandbox' => ['nullable', 'string', 'max:255']])['sandbox'] ?? null;
        $query = SandboxSnapshot::query()->with('sandbox')->where('organization_id', $organizationId)->where('status', '!=', SandboxSnapshot::DELETED)
            ->latest('created_at')->latest('id')->limit(500);

        if ($ref !== null) {
            $query->where('sandbox_id', EloquentSandboxDirectory::lookup($organizationId, $ref)->id ?? '');
        }

        return response()->json(['data' => $this->presentSnapshots($query->get()->all(), $organizationId)]);
    }

    /** GET /sandboxes/snapshots/{snapshot}: {data: snapshot} */
    public function showSnapshot(string $snapshot): JsonResponse
    {
        $model = $this->snapshotOf($snapshot, 'view');

        return response()->json(['data' => $this->presentSnapshots([$model], $model->organization_id)[0]]);
    }

    /** POST /sandboxes/{sandbox}/snapshots {name?, stop?} [?wait=1]: 201 {data: snapshot} */
    public function snapshot(Request $request, string $sandbox, TakeSnapshot $take, AgentGateway $agents, HandleCommandOutcome $outcomes): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'operate');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:63', 'regex:'.Sandbox::NAME],
            'stop' => ['nullable', 'boolean'],
        ]);
        $stop = (bool) ($data['stop'] ?? false);
        $snapshot = $take($model, Actor::of($request->user()), $data['name'] ?? null, $stop);

        if ($request->boolean('wait') && $snapshot->command_id !== null) {
            try {
                $result = $agents->await($snapshot->command_id, 60);

                if ($result->isFinished() && $result->isSuccessful()) {
                    $outcomes->snapshotted($snapshot, $result->result ?? [], $stop);
                } elseif ($result->isFinished()) {
                    SandboxSnapshot::query()->whereKey($snapshot->id)->where('status', SandboxSnapshot::CREATING)
                        ->update(['status' => SandboxSnapshot::FAILED, 'error' => mb_substr((string) ($result->error ?: 'The snapshot could not be taken.'), 0, 1000)]);
                }
            } catch (CommandTimedOut) {
                // Still being taken: the answer is the snapshot as it is (HandleCommandOutcome settles it).
            }

            $snapshot->refresh();
        }

        return response()->json(['data' => $this->presentSnapshots([$snapshot], $model->organization_id)[0]], 201);
    }

    /** PATCH /sandboxes/snapshots/{snapshot} {name}: {data: snapshot} */
    public function updateSnapshot(Request $request, string $snapshot): JsonResponse
    {
        $model = $this->snapshotOf($snapshot, 'operate');
        $name = $request->validate(['name' => ['required', 'string', 'max:63', 'regex:'.Sandbox::NAME]])['name'];
        abort_if($model->status === SandboxSnapshot::DELETED, 409, 'The snapshot is deleted.');

        if ($name !== $model->name) {
            $taken = SandboxSnapshot::query()->where('organization_id', $model->organization_id)->where('status', '!=', SandboxSnapshot::DELETED)
                ->where('name', $name)->whereKeyNot($model->id)->exists();

            try {
                $taken || $model->forceFill(['name' => $name])->save();
            } catch (UniqueConstraintViolationException) {
                $taken = true;
            }

            if ($taken) {
                throw ValidationException::withMessages(['name' => "A snapshot called {$name} already exists."]);
            }
        }

        return response()->json(['data' => $this->presentSnapshots([$model], $model->organization_id)[0]]);
    }

    /** DELETE /sandboxes/snapshots/{snapshot}: 204 (deleting a deleted snapshot too) */
    public function destroySnapshot(string $snapshot, DeleteSnapshot $delete): Response
    {
        $delete($this->snapshotOf($snapshot, 'operate'));

        return response()->noContent();
    }

    /** POST /sandboxes/{sandbox}/pause: {data: sandbox} */
    public function pause(Request $request, string $sandbox, PauseSandbox $pauses): JsonResponse
    {
        $model = $pauses->pause($this->resolve($request, $sandbox, 'operate'));

        return response()->json(['data' => $this->present([$model], $model->organization_id)[0]]);
    }

    /** POST /sandboxes/{sandbox}/resume: {data: sandbox} */
    public function resume(Request $request, string $sandbox, PauseSandbox $pauses): JsonResponse
    {
        $model = $pauses->resume($this->resolve($request, $sandbox, 'operate'));
        $model->forceFill(['last_activity_at' => now()])->save();

        return response()->json(['data' => $this->present([$model], $model->organization_id)[0]]);
    }

    /** POST /sandboxes/{sandbox}/db {instance, database}: {data: {path, size_bytes}} (waits, up to 10 minutes) */
    public function db(Request $request, string $sandbox, ImportDatabase $import): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'operate');
        $data = $request->validate([
            'instance' => ['required', 'string', 'max:255'],
            'database' => ['required', 'string', 'max:64'],
        ]);
        $db = $this->database($request, $model->organization_id, $data['instance'], $data['database'], $model->server_id);
        $model = $this->running($request, $sandbox);

        return response()->json(['data' => $import($model, Actor::of($request->user()), $db)]);
    }

    /**
     * ?wait=1: up to 60 s in all for the sandboxes' creates; those that finished are settled here (the listener too).
     *
     * @param  list<Sandbox>  $sandboxes
     */
    private function wait(array $sandboxes, AgentGateway $agents): void
    {
        $deadline = time() + 60;

        foreach ($sandboxes as $sandbox) {
            if ($sandbox->command_id === null) {
                continue;
            }

            try {
                $result = $agents->await($sandbox->command_id, max(1, $deadline - time()));
            } catch (CommandTimedOut) {
                // Still creating: the answer is the sandbox as it is (HandleCommandOutcome settles it).
                $sandbox->refresh();

                continue;
            }

            $sandbox->refresh();

            if ($result->isFinished() && $sandbox->status === Sandbox::CREATING) {
                $result->isSuccessful()
                    ? HandleCommandOutcome::started($sandbox, $result->result ?? [])
                    : HandleCommandOutcome::failed($sandbox, (string) ($result->error ?: 'The sandbox could not be created.'));
            }
        }
    }

    /**
     * A database copy: an instance of the organization (id or name) with that database, on $serverId when given. It hands
     * out the data, so it needs what downloading a backup needs (a token created with that ability by name; `*` is not
     * enough).
     *
     * @return array{instance: string, instance_name: string, engine: string, database: string, server_id: string}
     */
    private function database(Request $request, string $organizationId, string $instance, string $database, ?string $serverId, string $prefix = ''): array
    {
        $permission = DatabaseDirectory::BACKUP_DOWNLOAD_PERMISSION;
        $token = $request->user()?->currentAccessToken();

        if (($token instanceof PersonalAccessToken && ! in_array($permission, (array) $token->abilities, true))
            || ! $this->access->can($request->user(), $organizationId, $permission)) {
            throw new AuthorizationException("A database copy hands out the database's data: it needs the {$permission} permission, as downloading a backup (a token created with the {$permission} ability itself).");
        }

        return app(ImportDatabase::class)->resolve($organizationId, $instance, $database, $serverId, $prefix);
    }

    private function snapshotOf(string $ref, string $ability): SandboxSnapshot
    {
        $snapshot = SandboxSnapshot::lookup($this->organization->requireId(), $ref);
        abort_if($snapshot === null, 404, 'Snapshot not found.');
        $this->authorize($ability, $snapshot);

        return $snapshot;
    }

    /**
     * @param  list<SandboxSnapshot>  $snapshots
     * @return list<array<string, mixed>>
     */
    private function presentSnapshots(array $snapshots, string $organizationId): array
    {
        $names = $this->serverNames($organizationId);

        return array_map(fn (SandboxSnapshot $snapshot) => $snapshot->present($names[$snapshot->server_id] ?? null), $snapshots);
    }

    /** GET /sandboxes/{id}: {data: sandbox} */
    public function show(Request $request, string $sandbox): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'view');

        return response()->json(['data' => $this->present([$model], $model->organization_id)[0]]);
    }

    /** PATCH /sandboxes/{id} {name?, extend?}: {data: sandbox} */
    public function update(Request $request, string $sandbox, HandleCommandOutcome $outcomes): JsonResponse
    {
        $model = $this->resolve($request, $sandbox, 'update');

        if ($model->isLive()) {
            $model->forceFill(['last_activity_at' => now()])->save();
        }

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

            try {
                $model->forceFill(['name' => $data['name']])->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['name' => "A sandbox called {$data['name']} already exists."]);
            }
        }

        if (isset($data['extend'])) {
            if (! is_int($data['extend']) && ! is_string($data['extend'])) {
                throw ValidationException::withMessages(['extend' => 'Give a number of seconds or a duration like 30m.']);
            }

            $this->extend($model, $data['extend'], $outcomes);
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
        $cmd = 'files.write '.$path.' ('.strlen($content).' bytes)';

        try {
            $result = $this->agent->ask($model, 'sandbox.files.write', array_filter(['id' => $model->id, 'path' => $path, 'content' => base64_encode($content), 'mode' => $mode], fn ($v) => $v !== null), 60);
        } catch (Throwable $e) {
            $exec->recordFailure($model, Actor::of($request->user()), $cmd, null, [], $startedAt, $e);

            throw $e;
        }

        $exec->record($model, Actor::of($request->user()), $cmd, null, [], $startedAt, ['exit_code' => 0]);

        return response()->json(['data' => ['size' => (int) ($result['size'] ?? strlen($content))]]);
    }

    /** DELETE /sandboxes/{id}/files ?path=&recursive=1: 204 */
    public function deleteFile(Request $request, string $sandbox, ExecInSandbox $exec): Response
    {
        $model = $this->running($request, $sandbox);
        $path = $this->path($request);
        $recursive = $request->boolean('recursive');

        $startedAt = now();
        $cmd = 'files.delete '.($recursive ? '-r ' : '').$path;

        try {
            $this->agent->ask($model, 'sandbox.files.delete', ['id' => $model->id, 'path' => $path, 'recursive' => $recursive], 60);
        } catch (Throwable $e) {
            $exec->recordFailure($model, Actor::of($request->user()), $cmd, null, [], $startedAt, $e);

            throw $e;
        }

        $exec->record($model, Actor::of($request->user()), $cmd, null, [], $startedAt, ['exit_code' => 0]);

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
     * Extend the time to live: the sandbox's whole lifetime stays within `sandboxes.max_ttl`. A running sandbox's agent
     * takes the new expiry first (sandbox.extend; when it can't, nothing changes); one still being created takes it
     * once the create succeeded (HandleCommandOutcome).
     */
    private function extend(Sandbox $sandbox, int|string $by, HandleCommandOutcome $outcomes): void
    {
        abort_unless($sandbox->isLive(), 409, 'The sandbox is not running.');

        $seconds = CreateSandbox::seconds($by, 'extend');
        $expires = $sandbox->expires_at->copy()->addSeconds($seconds);
        $max = (int) config('sandboxes.max_ttl');

        if ($seconds < 1 || $sandbox->created_at->diffInSeconds($expires) > $max) {
            throw ValidationException::withMessages(['extend' => 'A sandbox lives at most '.intdiv($max, 60).' minutes in all.']);
        }

        // Asked outside the transaction (the agent's answer is written by another request).
        $told = $sandbox->isStarted();

        if ($told) {
            $this->agent->ask($sandbox, 'sandbox.extend', ['id' => $sandbox->id, 'expires_at' => $expires->getTimestamp()], 15);
        }

        // Under the row's lock, as the create's outcome: a create that finished meanwhile either saw this expiry (and
        // sent it) or is seen running here (and the expiry is sent here). SyncSandboxes catches anything else.
        $fresh = DB::transaction(function () use ($sandbox, $expires, $told, $outcomes): Sandbox {
            $fresh = Sandbox::query()->whereKey($sandbox->id)->lockForUpdate()->firstOrFail();
            abort_unless($fresh->isLive(), 409, 'The sandbox is not running.');
            $fresh->forceFill(['expires_at' => $expires, 'ttl_seconds' => (int) $fresh->created_at->diffInSeconds($expires)]);

            if ($told) {
                $fresh->agent_expires_at = $expires;
            }

            $fresh->save();

            if (! $told && $fresh->isStarted()) {
                $outcomes->sendExpiry($fresh);
            }

            return $fresh;
        });

        $sandbox->setRawAttributes($fresh->getAttributes(), true);
    }

    /**
     * repo.secret reads a secret's value into the sandbox: what revealing it takes (secrets.reveal; a token must have
     * been created with that ability itself, `*` is not enough, as POST /api/v1/secrets/{secret}/reveal).
     */
    private function authorizeSecretRead(Request $request, string $organizationId): void
    {
        $token = $request->user()?->currentAccessToken();

        if (($token instanceof PersonalAccessToken && ! in_array(self::SECRETS_REVEAL, (array) $token->abilities, true))
            || ! $this->access->can($request->user(), $organizationId, self::SECRETS_REVEAL)) {
            throw new AuthorizationException('repo.secret hands the secret to the sandbox: it needs the secrets.reveal permission (a token created with the secrets.reveal ability itself).');
        }
    }

    /** A registry host that is localhost, or a loopback, private, link-local or reserved address (also in short forms). */
    private static function privateRegistry(string $image): bool
    {
        $first = explode('/', $image, 2)[0];

        // Only a first component with a dot or a port, or localhost, names a registry (else: docker.io/library).
        if (! str_contains($image, '/') || (! str_contains($first, '.') && ! str_contains($first, ':') && strtolower($first) !== 'localhost')) {
            return false;
        }

        $host = strtolower((string) preg_replace('/:\d*$/', '', $first));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // 127.1, 0x7f.0.0.1, 2130706433: what inet_aton would take as an address.
        if (preg_match('/^(0x[0-9a-f]+|\d+)(\.(0x[0-9a-f]+|\d+)){0,3}$/', $host) === 1) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
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

    /** Exec, files and database copies: a running sandbox (a paused one is resumed first), whose use is activity. */
    private function running(Request $request, string $ref): Sandbox
    {
        $sandbox = $this->resolve($request, $ref, 'operate');

        if ($sandbox->status === Sandbox::PAUSED) {
            $sandbox = app(PauseSandbox::class)->resume($sandbox);
        }

        abort_unless($sandbox->isRunning(), 409, $sandbox->status === Sandbox::CREATING ? 'The sandbox is still starting.' : "The sandbox is {$sandbox->status}.");
        $sandbox->forceFill(['last_activity_at' => now()])->save();

        return $sandbox;
    }

    /**
     * @param  list<Sandbox>  $sandboxes
     * @return list<array<string, mixed>>
     */
    private function present(array $sandboxes, string $organizationId): array
    {
        $names = $this->serverNames($organizationId);
        (new EloquentCollection($sandboxes))->loadMissing('fromSnapshot');

        return array_map(fn (Sandbox $sandbox) => $sandbox->present($names[$sandbox->server_id] ?? null), $sandboxes);
    }

    /** @return array<string, string> */
    private function serverNames(string $organizationId): array
    {
        $names = [];

        foreach ($this->servers->forOrganization($organizationId) as $server) {
            /** @var ServerData $server */
            $names[$server->id] = $server->name;
        }

        return $names;
    }
}
