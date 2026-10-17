<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxSnapshot;
use Falak\Secrets\Contracts\Data\ScopeChain;
use Falak\Secrets\Contracts\Data\SecretAccessor;
use Falak\Secrets\Contracts\Secrets;
use Falak\Servers\Contracts\Data\ServerData;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Servers\Contracts\ServerType;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create a sandbox (docs/plans/SANDBOXES.md "Contracts"): defaults, quotas, placement, then sandbox.create. The row
 * starts `creating`; HandleCommandOutcome moves it to running or failed.
 */
final class CreateSandbox
{
    private const ADJECTIVES = ['brave', 'calm', 'eager', 'fancy', 'gentle', 'happy', 'jolly', 'kind', 'lively', 'merry', 'nimble', 'proud',
        'quick', 'quiet', 'sharp', 'shy', 'swift', 'tidy', 'witty', 'zesty', 'bold', 'clever', 'bright', 'sunny'];

    private const ANIMALS = ['otter', 'falcon', 'badger', 'heron', 'lynx', 'panda', 'koala', 'gecko', 'puffin', 'marmot', 'bison', 'ibex',
        'raven', 'wombat', 'beaver', 'dingo', 'ferret', 'mole', 'newt', 'orca', 'quokka', 'seal', 'tapir', 'yak'];

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentDirectory $agentDirectory,
        private readonly AgentGateway $agents,
        private readonly LimitValidator $limits,
        private readonly Secrets $secrets,
        private readonly AuditLog $audit,
        private readonly SandboxAgent $agent,
    ) {}

    /** A fork makes at most this many sandboxes. */
    public const MAX_FORK_COUNT = 10;

    /** idle_pause: at least this many seconds. */
    public const MIN_IDLE_PAUSE = 60;

    /**
     * @param  array{image?: ?string, name?: ?string, server?: ?string, cpus?: int|float|string|null, memory_mb?: ?int, disk_mb?: ?int,
     *     ttl?: int|string|null, network?: ?string, allow_hosts?: ?list<string>, env?: ?array<string, string>,
     *     repo?: ?array{url: string, ref?: ?string, secret?: ?string}, idle_pause?: int|string|null,
     *     db_import?: ?array{instance: string, instance_name: string, engine: string, database: string, server_id: string}}  $input  validated
     */
    public function __invoke(string $organizationId, Actor $actor, array $input): Sandbox
    {
        $defaults = (array) config('sandboxes.defaults');
        $cpus = round((float) ($input['cpus'] ?? $defaults['cpus']), 2);
        $memory = (int) ($input['memory_mb'] ?? $defaults['memory_mb']);
        $disk = (int) ($input['disk_mb'] ?? $defaults['disk_mb']);
        $ttl = self::seconds($input['ttl'] ?? (int) config('sandboxes.default_ttl'), 'ttl');
        $network = (string) ($input['network'] ?? $defaults['network']);
        $allowHosts = array_values(array_unique((array) ($input['allow_hosts'] ?? [])));
        $idlePause = self::idlePause($input['idle_pause'] ?? null);
        $db = $input['db_import'] ?? null;

        $this->checkSizes($cpus, $memory, $disk, $ttl);

        if ($network === 'allowlist' && $allowHosts === []) {
            throw ValidationException::withMessages(['allow_hosts' => 'List the hosts an allowlist sandbox may reach.']);
        }

        // A database copy comes from the sandbox's own server: without a server, the instance's.
        $server = $this->place($organizationId, ($input['server'] ?? null) ?: ($db['server_id'] ?? null), $memory);
        $this->checkV2($server, $idlePause, $db);
        $this->checkServerLimits($server, $cpus, $memory);
        $repo = isset($input['repo']['url']) ? array_filter(['url' => $input['repo']['url'], 'ref' => $input['repo']['ref'] ?? null]) : null;
        $secret = isset($input['repo']['secret']) ? (string) $input['repo']['secret'] : null;

        return $this->locked($organizationId, function () use ($organizationId, $actor, $input, $defaults, $cpus, $memory, $disk, $ttl, $network, $allowHosts, $server, $repo, $secret, $idlePause, $db) {
            $this->checkQuotas($organizationId, $memory, $disk);
            $token = $secret !== null ? $this->secret($organizationId, $secret, $actor) : null;

            return $this->start($organizationId, $actor, $server, [
                'name' => $this->name($organizationId, $input['name'] ?? null),
                'image' => (string) ($input['image'] ?? $defaults['image']),
                'cpus' => $cpus,
                'memory_mb' => $memory,
                'disk_mb' => $disk,
                'network' => $network,
                'allow_hosts' => $allowHosts,
                'env' => $input['env'] ?? [],
                'repo' => $repo,
                'ttl_seconds' => $ttl,
                'idle_pause_seconds' => $idlePause,
                'db_import' => self::dbImport($db),
            ], $token);
        });
    }

    /**
     * Fork: $count new sandboxes from a ready snapshot, on its server, with its CPUs, memory, disk and network (sandbox.create
     * from_snapshot, sandbox.v2). Their name, TTL, idle pause and env additions are their own; with a name and a count
     * over 1 they are name-1…name-N. Quotas count all of them together, under the organization's create lock.
     *
     * @param  array{name?: ?string, ttl?: int|string|null, env?: ?array<string, string>, idle_pause?: int|string|null,
     *     db_import?: ?array{instance: string, instance_name: string, engine: string, database: string, server_id: string}}  $input  validated
     * @return list<Sandbox>
     */
    public function fork(SandboxSnapshot $snapshot, Actor $actor, array $input, int $count = 1): array
    {
        abort_unless($snapshot->status === SandboxSnapshot::READY, 409, $snapshot->status === SandboxSnapshot::CREATING ? 'The snapshot is still being taken.' : "The snapshot is {$snapshot->status}.");

        if ($count < 1 || $count > self::MAX_FORK_COUNT) {
            throw ValidationException::withMessages(['count' => 'A fork makes 1 to '.self::MAX_FORK_COUNT.' sandboxes.']);
        }

        $organizationId = $snapshot->organization_id;
        $ttl = self::seconds($input['ttl'] ?? (int) config('sandboxes.default_ttl'), 'ttl');
        $idlePause = self::idlePause($input['idle_pause'] ?? null);
        $db = $input['db_import'] ?? null;
        $this->checkSizes($snapshot->cpus, $snapshot->memory_mb, $snapshot->disk_mb, $ttl);

        $server = $this->servers->find($snapshot->server_id);

        if ($server === null) {
            throw ValidationException::withMessages(['server' => "The snapshot's server is gone."]);
        }

        if ($reason = $this->unavailable($server)) {
            throw ValidationException::withMessages(['server' => $reason]);
        }

        $this->agent->requireV2($server->id);

        if ($db !== null && $db['server_id'] !== $server->id) {
            throw ValidationException::withMessages(['db.instance' => "{$db['instance_name']} is not on {$server->name}, the snapshot's server: a sandbox gets a copy only of a database on its own server."]);
        }

        $this->checkServerLimits($server, $snapshot->cpus, $snapshot->memory_mb);

        $prefix = ($input['name'] ?? null) ?: null;
        $names = $prefix === null ? array_fill(0, $count, null) : ($count === 1 ? [$prefix] : array_map(fn (int $i) => "{$prefix}-{$i}", range(1, $count)));

        foreach ($names as $name) {
            if ($name !== null && preg_match(Sandbox::NAME, $name) !== 1) {
                throw ValidationException::withMessages(['name' => "{$name} is not a valid sandbox name (lower-case letters, digits and dashes, at most 63)."]);
            }
        }

        $env = $input['env'] ?? [];

        return $this->locked($organizationId, function () use ($snapshot, $organizationId, $actor, $server, $names, $count, $ttl, $idlePause, $env, $db) {
            $this->checkQuotas($organizationId, $snapshot->memory_mb, $snapshot->disk_mb, $count);
            $sandboxes = [];

            foreach ($names as $name) {
                $sandboxes[] = $this->start($organizationId, $actor, $server, [
                    'name' => $this->name($organizationId, $name),
                    'image' => $snapshot->image,
                    'image_digest' => $snapshot->image_digest,
                    'cpus' => $snapshot->cpus,
                    'memory_mb' => $snapshot->memory_mb,
                    'disk_mb' => $snapshot->disk_mb,
                    'network' => $snapshot->network,
                    'allow_hosts' => $snapshot->allow_hosts ?? [],
                    'env' => [...($snapshot->env ?? []), ...$env],
                    'ttl_seconds' => $ttl,
                    'idle_pause_seconds' => $idlePause,
                    'from_snapshot_id' => $snapshot->id,
                    'db_import' => self::dbImport($db),
                ], null, $env);
            }

            return $sandboxes;
        });
    }

    /**
     * idle_pause: seconds or a duration (at least a minute), or null (never).
     *
     * @throws ValidationException
     */
    public static function idlePause(int|string|null $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        $seconds = self::seconds($value, 'idle_pause');

        if ($seconds < self::MIN_IDLE_PAUSE || $seconds > (int) config('sandboxes.max_ttl')) {
            throw ValidationException::withMessages(['idle_pause' => 'Idle pause is at least 1 minute (and at most the longest time to live).']);
        }

        return $seconds;
    }

    /**
     * The organization's quotas and names, checked and taken under one lock (two creates at once can't both pass).
     *
     * @template T
     *
     * @param  \Closure(): T  $insert
     * @return T
     */
    private function locked(string $organizationId, \Closure $insert): mixed
    {
        try {
            return Cache::lock("sandboxes:create:{$organizationId}", 60)->block((int) config('sandboxes.create_lock_wait', 10), fn () => DB::transaction($insert));
        } catch (LockTimeoutException) {
            abort(429, 'Another sandbox of your organization is being created; try again.');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A sandbox of that name was created meanwhile; try again.']);
        }
    }

    /** idle_pause and database copies need sandbox.v2 on the server. */
    private function checkV2(ServerData $server, ?int $idlePause, ?array $db): void
    {
        if ($idlePause !== null || $db !== null) {
            $this->agent->requireV2($server->id);
        }

        if ($db !== null && $db['server_id'] !== $server->id) {
            throw ValidationException::withMessages(['db.instance' => "{$db['instance_name']} is not on {$server->name}: a sandbox gets a copy only of a database on its own server."]);
        }
    }

    /**
     * @param  ?array{instance: string, instance_name: string, engine: string, database: string, server_id?: string}  $db
     * @return ?array{instance: string, instance_name: string, engine: string, database: string}
     */
    private static function dbImport(?array $db): ?array
    {
        return $db === null ? null : ['instance' => $db['instance'], 'instance_name' => $db['instance_name'], 'engine' => $db['engine'], 'database' => $db['database']];
    }

    /**
     * Insert the sandbox (`creating`) and send sandbox.create.
     *
     * @param  array<string, mixed>  $row
     * @param  ?array<string, string>  $agentEnv  a fork's env additions (the agent restores the snapshot's own)
     */
    private function start(string $organizationId, Actor $actor, ServerData $server, array $row, ?string $token, ?array $agentEnv = null): Sandbox
    {
        $sandbox = Sandbox::query()->create([
            ...$row,
            'organization_id' => $organizationId,
            'server_id' => $server->id,
            'status' => Sandbox::CREATING,
            'expires_at' => $expires = now()->addSeconds($row['ttl_seconds']),
            'agent_expires_at' => $expires,
            'created_by_type' => $actor->type,
            'created_by_id' => $actor->id,
            'created_by_name' => $actor->name,
            'last_activity_at' => now(),
        ]);

        try {
            $handle = $this->agents->dispatch($server->id, 'sandbox.create', self::payload($sandbox, $token, $agentEnv), (int) config('sandboxes.create_timeout', 300) + 300, "sandbox.create:{$sandbox->id}");
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['server' => "The agent on {$server->name} is not connected."]);
        }

        $sandbox->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('sandbox.created', 'sandbox', $sandbox->id, array_filter([
            'name' => $sandbox->name,
            'image' => $sandbox->image,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'cpus' => $sandbox->cpus,
            'memory_mb' => $sandbox->memory_mb,
            'disk_mb' => $sandbox->disk_mb,
            'network' => $sandbox->network,
            'ttl_seconds' => $sandbox->ttl_seconds,
            'repo' => $sandbox->repo['url'] ?? null,
            'from_snapshot' => $sandbox->from_snapshot_id,
            'database' => isset($sandbox->db_import) ? $sandbox->db_import['instance_name'].'/'.$sandbox->db_import['database'] : null,
            'via_token' => $actor->type === 'token' ? $actor->name : null,
        ], fn ($value) => $value !== null), $organizationId);

        return $sandbox;
    }

    /**
     * sandbox.create: the sandbox's spec; env and the repository token are forgotten once the command settled. A
     * sandbox made from a snapshot sends from_snapshot and only its env additions ($env), never a repository.
     *
     * @param  ?array<string, string>  $env
     * @return array<string, mixed>
     */
    public static function payload(Sandbox $sandbox, ?string $token = null, ?array $env = null): array
    {
        $payload = [
            'id' => $sandbox->id,
            'image' => $sandbox->image,
            'cpus' => $sandbox->cpus,
            'memory_mb' => $sandbox->memory_mb,
            'disk_mb' => $sandbox->disk_mb,
            'pids' => (int) config('sandboxes.pids', 1024),
            'network' => $sandbox->network,
            'allow_hosts' => $sandbox->allow_hosts ?? [],
            'expires_at' => $sandbox->expires_at->getTimestamp(),
        ];

        $env ??= $sandbox->env ?? [];

        if ($env !== []) {
            $payload['env'] = $env;
        }

        if ($sandbox->from_snapshot_id !== null) {
            $payload['from_snapshot'] = $sandbox->from_snapshot_id;
        } elseif ($sandbox->repo !== null) {
            $payload['repo'] = array_filter([...$sandbox->repo, 'token' => $token]);
        }

        return $payload;
    }

    /**
     * A duration: seconds, or "30m", "2h", "1h30m", "45s".
     *
     * @throws ValidationException
     */
    public static function seconds(int|string $value, string $field): int
    {
        if (is_int($value) || ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', strtolower(trim($value)), $m) === 1 && trim($value) !== '') {
            return (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        }

        throw ValidationException::withMessages([$field => 'Give a number of seconds or a duration like 30m, 2h or 1h30m.']);
    }

    /** One sandbox's size and time to live. */
    private function checkSizes(float $cpus, int $memory, int $disk, int $ttl): void
    {
        $maxTtl = (int) config('sandboxes.max_ttl');
        $errors = array_filter([
            'cpus' => $cpus < 0.1 || $cpus > (float) config('sandboxes.max_cpus') ? 'CPUs must be between 0.1 and '.(float) config('sandboxes.max_cpus').'.' : null,
            'memory_mb' => $memory < 64 || $memory > (int) config('sandboxes.max_memory_mb') ? 'Memory must be between 64 and '.(int) config('sandboxes.max_memory_mb').' MB.' : null,
            'disk_mb' => $disk < 256 || $disk > (int) config('sandboxes.max_disk_mb') ? 'Disk must be between 256 and '.(int) config('sandboxes.max_disk_mb').' MB.' : null,
            'ttl' => $ttl < 60 || $ttl > $maxTtl ? 'The time to live must be between 1 minute and '.self::human($maxTtl).'.' : null,
        ]);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The organization's live sandboxes, their memory and their disk, with $count more of this size (under the create lock). */
    private function checkQuotas(string $organizationId, int $memory, int $disk, int $count = 1): void
    {
        $live = Sandbox::query()->where('organization_id', $organizationId)->live();
        $running = (clone $live)->count();
        $max = (int) config('sandboxes.max_running');

        if ($running + $count > $max) {
            throw ValidationException::withMessages(['sandbox' => $count === 1
                ? "Your organization already runs {$running} of its {$max} sandboxes. Kill one first."
                : "Your organization runs {$running} of its {$max} sandboxes: {$count} more don't fit."]);
        }

        [$memory, $disk] = [$memory * $count, $disk * $count];
        $used = (int) (clone $live)->sum('memory_mb');
        $total = (int) config('sandboxes.max_total_memory_mb');

        if ($used + $memory > $total) {
            throw ValidationException::withMessages(['memory_mb' => "Your organization's sandboxes may use {$total} MB of memory in all; {$used} MB are in use."]);
        }

        $usedDisk = (int) (clone $live)->sum('disk_mb');
        $totalDisk = (int) config('sandboxes.max_total_disk_mb');

        if ($usedDisk + $disk > $totalDisk) {
            throw ValidationException::withMessages(['disk_mb' => "Your organization's sandboxes may use {$totalDisk} MB of disk in all; {$usedDisk} MB are in use."]);
        }
    }

    /** The given server (id or name), else the active app server with the sandbox.v1 agent and the most free memory. */
    private function place(string $organizationId, ?string $ref, int $memory): ServerData
    {
        $servers = $this->servers->forOrganization($organizationId);

        if ($ref !== null && $ref !== '') {
            $server = collect($servers)->first(fn (ServerData $s) => $s->id === strtolower($ref) || $s->name === $ref);

            if ($server === null) {
                throw ValidationException::withMessages(['server' => 'No server of your organization is called '.$ref.'.']);
            }

            if ($reason = $this->unavailable($server)) {
                throw ValidationException::withMessages(['server' => $reason]);
            }

            return $server;
        }

        $agents = $this->agentDirectory->forServers(array_map(fn (ServerData $s) => $s->id, $servers));
        $best = null;
        $bestFree = PHP_INT_MIN;

        foreach ($servers as $server) {
            if ($server->type !== ServerType::App || $this->unavailable($server) !== null) {
                continue;
            }

            $agent = $agents[$server->id] ?? null;
            $total = (int) ($agent->facts['memory_bytes'] ?? 0);
            $free = $total - (int) ($agent->metrics['memory_used_bytes'] ?? 0);

            if ($free > $bestFree) {
                [$best, $bestFree] = [$server, $free];
            }
        }

        if ($best === null) {
            throw ValidationException::withMessages(['server' => 'No server can run sandboxes: they need an active app server with sandboxes enabled and an up-to-date agent.']);
        }

        return $best;
    }

    private function unavailable(ServerData $server): ?string
    {
        return match (true) {
            ! $server->isActive() => "{$server->name} is not active.",
            ! $server->sandboxesEnabled => "Sandboxes are turned off on {$server->name}.",
            ! ($this->agentDirectory->forServer($server->id)?->supports(SandboxDirectory::FEATURE) ?? false) => "The agent on {$server->name} is too old for sandboxes; update it first.",
            default => null,
        };
    }

    /** CPUs and memory within the server's (agent facts), as for any service. */
    private function checkServerLimits(ServerData $server, float $cpus, int $memory): void
    {
        try {
            $this->limits->validate(['memory_limit' => $memory, 'cpus' => $cpus], [$server->id]);
        } catch (ValidationException $e) {
            $errors = [];

            foreach ($e->errors() as $key => $messages) {
                $errors[str_ends_with($key, 'cpus') ? 'cpus' : 'memory_mb'] = $messages[0];
            }

            throw ValidationException::withMessages($errors);
        }
    }

    private function name(string $organizationId, ?string $name): string
    {
        $taken = fn (string $candidate) => Sandbox::query()->where('organization_id', $organizationId)->where('status', '!=', Sandbox::KILLED)->where('name', $candidate)->exists();

        if ($name !== null && $name !== '') {
            if (in_array($name, Sandbox::RESERVED_NAMES, true)) {
                throw ValidationException::withMessages(['name' => str_replace(':name', $name, Sandbox::RESERVED_NAME_MESSAGE)]);
            }

            if ($taken($name)) {
                throw ValidationException::withMessages(['name' => "A sandbox called {$name} already exists."]);
            }

            return $name;
        }

        for ($i = 0; $i < 20; $i++) {
            $candidate = self::ADJECTIVES[array_rand(self::ADJECTIVES)].'-'.self::ANIMALS[array_rand(self::ANIMALS)].($i >= 10 ? '-'.random_int(2, 999) : '');

            if (! $taken($candidate)) {
                return $candidate;
            }
        }

        return 'sandbox-'.strtolower(bin2hex(random_bytes(3)));
    }

    /**
     * repo.secret: an organization secret's value, sent to the agent as the clone token and never stored. Whoever asks
     * may reveal it (the API checks secrets.reveal); a sensitive (write-only) secret is refused.
     */
    private function secret(string $organizationId, string $name, Actor $actor): string
    {
        if ($this->secrets->check(new ScopeChain($organizationId), [$name])->isSensitive($name)) {
            throw ValidationException::withMessages(['repo.secret' => "{$name} is a sensitive secret: its value is write-only and never handed to a sandbox. Use a secret created with Sensitive off for the clone token."]);
        }

        $accessor = $actor->tokenId !== null
            ? SecretAccessor::apiToken($actor->tokenId, $actor->id, 'Sandbox repository clone')
            : ($actor->id !== null ? SecretAccessor::user($actor->id, 'Sandbox repository clone') : SecretAccessor::system('Sandbox repository clone'));
        $resolved = $this->secrets->resolve(new ScopeChain($organizationId), [$name], $accessor);

        if ($resolved->isSensitive($name)) {
            throw ValidationException::withMessages(['repo.secret' => "{$name} is a sensitive secret: its value is write-only and never handed to a sandbox. Use a secret created with Sensitive off for the clone token."]);
        }

        if (! isset($resolved->values[$name])) {
            throw ValidationException::withMessages(['repo.secret' => $resolved->errors[$name] ?? "No organization secret is called {$name}."]);
        }

        return $resolved->values[$name];
    }

    private static function human(int $seconds): string
    {
        return $seconds % 3600 === 0 ? ($seconds / 3600).' h' : intdiv($seconds, 60).' min';
    }
}
