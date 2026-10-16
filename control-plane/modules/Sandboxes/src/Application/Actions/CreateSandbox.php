<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Limits\Contracts\LimitValidator;
use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Contracts\SandboxDirectory;
use Falak\Sandboxes\Domain\Models\Sandbox;
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
    ) {}

    /**
     * @param  array{image?: ?string, name?: ?string, server?: ?string, cpus?: int|float|string|null, memory_mb?: ?int, disk_mb?: ?int,
     *     ttl?: int|string|null, network?: ?string, allow_hosts?: ?list<string>, env?: ?array<string, string>,
     *     repo?: ?array{url: string, ref?: ?string, secret?: ?string}}  $input  validated
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

        $this->checkSizes($cpus, $memory, $disk, $ttl);

        if ($network === 'allowlist' && $allowHosts === []) {
            throw ValidationException::withMessages(['allow_hosts' => 'List the hosts an allowlist sandbox may reach.']);
        }

        $server = $this->place($organizationId, $input['server'] ?? null, $memory);
        $this->checkServerLimits($server, $cpus, $memory);
        $repo = isset($input['repo']['url']) ? array_filter(['url' => $input['repo']['url'], 'ref' => $input['repo']['ref'] ?? null]) : null;
        $secret = isset($input['repo']['secret']) ? (string) $input['repo']['secret'] : null;

        // The organization's quotas and names, checked and taken under one lock (two creates at once can't both pass).
        try {
            return Cache::lock("sandboxes:create:{$organizationId}", 60)->block((int) config('sandboxes.create_lock_wait', 10), fn () => DB::transaction(
                fn () => $this->insert($organizationId, $actor, $input, $defaults, $cpus, $memory, $disk, $ttl, $network, $allowHosts, $server, $repo, $secret),
            ));
        } catch (LockTimeoutException) {
            abort(429, 'Another sandbox of your organization is being created; try again.');
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'A sandbox of that name was created meanwhile; try again.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $defaults
     * @param  list<string>  $allowHosts
     * @param  ?array<string, string>  $repo
     */
    private function insert(string $organizationId, Actor $actor, array $input, array $defaults, float $cpus, int $memory, int $disk, int $ttl, string $network, array $allowHosts, ServerData $server, ?array $repo, ?string $secret): Sandbox
    {
        $this->checkQuotas($organizationId, $memory, $disk);
        $name = $this->name($organizationId, $input['name'] ?? null);
        $token = $secret !== null ? $this->secret($organizationId, $secret, $actor) : null;

        $sandbox = Sandbox::query()->create([
            'organization_id' => $organizationId,
            'server_id' => $server->id,
            'name' => $name,
            'image' => (string) ($input['image'] ?? $defaults['image']),
            'status' => Sandbox::CREATING,
            'cpus' => $cpus,
            'memory_mb' => $memory,
            'disk_mb' => $disk,
            'network' => $network,
            'allow_hosts' => $allowHosts,
            'env' => $input['env'] ?? [],
            'repo' => $repo,
            'ttl_seconds' => $ttl,
            'expires_at' => $expires = now()->addSeconds($ttl),
            'agent_expires_at' => $expires,
            'created_by_type' => $actor->type,
            'created_by_id' => $actor->id,
            'created_by_name' => $actor->name,
            'last_activity_at' => now(),
        ]);

        try {
            $handle = $this->agents->dispatch($server->id, 'sandbox.create', self::payload($sandbox, $token), (int) config('sandboxes.create_timeout', 300) + 300, "sandbox.create:{$sandbox->id}");
        } catch (AgentUnavailable) {
            throw ValidationException::withMessages(['server' => "The agent on {$server->name} is not connected."]);
        }

        $sandbox->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('sandbox.created', 'sandbox', $sandbox->id, array_filter([
            'name' => $name,
            'image' => $sandbox->image,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'cpus' => $cpus,
            'memory_mb' => $memory,
            'disk_mb' => $disk,
            'network' => $network,
            'ttl_seconds' => $ttl,
            'repo' => $repo['url'] ?? null,
            'via_token' => $actor->type === 'token' ? $actor->name : null,
        ], fn ($value) => $value !== null), $organizationId);

        return $sandbox;
    }

    /**
     * sandbox.create: the sandbox's spec; env and the repository token are forgotten once the command settled.
     *
     * @return array<string, mixed>
     */
    public static function payload(Sandbox $sandbox, ?string $token = null): array
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

        if (($sandbox->env ?? []) !== []) {
            $payload['env'] = $sandbox->env;
        }

        if ($sandbox->repo !== null) {
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

    /** The organization's running sandboxes, their memory and their disk (under the create lock). */
    private function checkQuotas(string $organizationId, int $memory, int $disk): void
    {
        $live = Sandbox::query()->where('organization_id', $organizationId)->live();
        $running = (clone $live)->count();
        $max = (int) config('sandboxes.max_running');

        if ($running >= $max) {
            throw ValidationException::withMessages(['sandbox' => "Your organization already runs {$running} of its {$max} sandboxes. Kill one first."]);
        }

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
