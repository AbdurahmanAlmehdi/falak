<?php

namespace Falak\Terminal\Application\Actions;

use Falak\Fleet\Contracts\AgentDirectory;
use Falak\Fleet\Contracts\AgentGateway;
use Falak\Fleet\Contracts\Exceptions\AgentUnavailable;
use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerDirectory;
use Falak\Terminal\Domain\Data\ConsoleTarget;
use Falak\Terminal\Domain\Data\NetCaptureSpec;
use Falak\Terminal\Domain\Models\NetCapture;
use Falak\Terminal\Infrastructure\NetCaptureStore;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Start a packet capture (docs/plans/network/capture.md) where a network tool would run: a server's interface, or a
 * site's or database's container network namespace. Only agents with feature `net.capture` get the command; an
 * organization runs at most terminal.captures.max_active at once and starts at most max_per_hour an hour.
 */
final class StartNetCapture
{
    public const FEATURE = 'net.capture';

    public const AGENT_TOO_OLD = 'The Falak agent on this server is too old for packet capture; update it first.';

    public const BUSY = 'Wait for the organization’s other captures to finish.';

    public const HOURLY = 'At most :n captures an hour per organization.';

    /** Captures older than this no longer count as in progress (the sweep fails them). */
    public const STALE_MINUTES = 15;

    /** Beyond the capture's duration: tcpdump's install on first use and the upload. */
    public const COMMAND_GRACE_SECONDS = 600;

    public static function lockKey(string $organizationId): string
    {
        return "terminal:net-captures:{$organizationId}";
    }

    public function __construct(
        private readonly ServerDirectory $servers,
        private readonly AgentGateway $agents,
        private readonly AgentDirectory $agentDirectory,
        private readonly AuditLog $audit,
        private readonly NetCaptureStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $input  interface?, duration_s?, max_mb?, full_packets?, filter?, acknowledged
     */
    public function __invoke(string $organizationId, string $userId, ConsoleTarget $target, array $input): NetCapture
    {
        if (! filter_var($input['acknowledged'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['acknowledged' => 'Confirm that captures may contain sensitive data.']);
        }

        $payload = NetCaptureSpec::payload($input, $target->container !== null);
        $server = $this->servers->find($target->serverId);

        if (! $server || $server->organizationId !== $organizationId) {
            abort(404);
        }

        if (! $server->isActive()) {
            throw ValidationException::withMessages(['server' => 'Packet capture runs once the server is active.']);
        }

        $agent = $this->agentDirectory->forServers([$server->id])[$server->id] ?? null;

        if (! $agent?->supports(self::FEATURE)) {
            throw ValidationException::withMessages(['server' => self::AGENT_TOO_OLD]);
        }

        if ($target->container !== null) {
            $payload['container'] = array_intersect_key($target->container, ['name' => true, 'labels' => true]);
        }

        // Count and insert under one lock per organization: two requests at once can't both pass the caps.
        try {
            $capture = Cache::lock(self::lockKey($organizationId), 10)->block(3, function () use ($organizationId, $userId, $server, $target, $payload) {
                $recent = NetCapture::query()->where('organization_id', $organizationId);
                $active = (clone $recent)->whereIn('status', NetCapture::ACTIVE)->where('created_at', '>=', now()->subMinutes(self::STALE_MINUTES))->count();
                abort_if($active >= (int) config('terminal.captures.max_active', 3), 429, self::BUSY);
                $hourly = (int) config('terminal.captures.max_per_hour', 10);
                abort_if((clone $recent)->where('created_at', '>=', now()->subHour())->count() >= $hourly, 429, str_replace(':n', (string) $hourly, self::HOURLY));

                $id = strtolower((string) Str::ulid());

                return NetCapture::query()->create([
                    'id' => $id,
                    'organization_id' => $organizationId,
                    'user_id' => $userId,
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'target_type' => $target->type,
                    'target_id' => $target->id,
                    'target_name' => $target->name ?? $server->name,
                    'container' => $target->containerLabel,
                    'interface' => $payload['interface'] ?? null,
                    'filter' => $payload['filter'] ?? null,
                    'snaplen' => $payload['snaplen'],
                    'duration_s' => $payload['duration_s'],
                    'max_bytes' => $payload['max_bytes'],
                    'status' => NetCapture::QUEUED,
                    'data_key' => $this->store->newKey($organizationId, $id),
                    'expires_at' => now()->addHours((int) config('terminal.captures.ttl_hours', 24)),
                ]);
            });
        } catch (LockTimeoutException) {
            abort(429, self::BUSY);
        }

        try {
            $handle = $this->agents->dispatch($server->id, 'net.capture', ['capture_id' => $capture->id, ...$payload],
                $payload['duration_s'] + self::COMMAND_GRACE_SECONDS, "net.capture:{$capture->id}");
        } catch (AgentUnavailable) {
            $capture->delete();

            throw ValidationException::withMessages(['server' => 'The server agent is not connected.']);
        }

        $capture->forceFill(['command_id' => $handle->id])->save();

        $this->audit->record('network.capture_started', 'net_capture', $capture->id, [
            'target_type' => $capture->target_type,
            'target_name' => $capture->target_name,
            'server_name' => $capture->server_name,
            'container' => $capture->container,
            'interface' => $capture->interface,
            'filter' => $capture->filter,
            'duration_s' => $capture->duration_s,
            'max_bytes' => $capture->max_bytes,
            'snaplen' => $capture->snaplen,
        ], $organizationId);

        return $capture;
    }
}
