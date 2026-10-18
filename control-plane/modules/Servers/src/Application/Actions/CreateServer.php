<?php

namespace Falak\Servers\Application\Actions;

use Falak\ControlPlane\Contracts\Exceptions\HostRequestRefused;
use Falak\ControlPlane\Contracts\HostChannel;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Identity\Contracts\AuditLog;
use Falak\Providers\Contracts\ProviderGateway;
use Falak\Providers\Contracts\ProviderType;
use Falak\Servers\Application\Jobs\CreateProviderMachine;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Contracts\ServerType;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Models\SshKey;
use Falak\Servers\Domain\Stack\Stack;
use Falak\Servers\Events\ServerCreated;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers a server. Provider servers are created asynchronously at the provider with a cloud-init
 * that runs the agent installer; custom servers show the install command to run by hand. The control plane's own
 * machine ("This machine", control_plane_host) is custom too, but its install URL goes to the host channel: falak-ctl
 * runs the installer on the host.
 */
final class CreateServer
{
    public const RUN_UP = 'Run sudo falak-ctl up once on the control-plane machine to enable the host channel.';

    public const RUN_ALLOW = 'Run sudo falak-ctl local-server allow on the control-plane machine (it lets the panel act as root there, as on every server it manages).';

    public function __construct(
        private readonly ProviderGateway $providers,
        private readonly Enrollment $enrollment,
        private readonly AuditLog $audit,
        private readonly HostChannel $host,
    ) {}

    /**
     * @param  array{name: string, type: string, provider: string, credential_id?: ?string, region?: ?string, size?: ?string, image?: ?string, stack?: array<string, mixed>|null, ssh_key_ids?: list<string>, timezone?: ?string, control_plane_host?: bool}  $input
     */
    public function __invoke(string $organizationId, ?string $userId, array $input): Server
    {
        $type = ServerType::from($input['type']);
        $provider = ProviderType::from($input['provider']);
        $stack = isset($input['stack']) ? Stack::fromArray($input['stack']) : Stack::defaultsFor($type);

        if (($errors = $stack->errorsFor($type)) !== []) {
            throw ValidationException::withMessages($errors);
        }

        $controlPlaneHost = (bool) ($input['control_plane_host'] ?? false);

        if ($controlPlaneHost) {
            $this->ensureControlPlaneHostAllowed($provider);
        }

        $credentialId = null;

        if ($provider->hasApi()) {
            $credential = $this->providers->credential($organizationId, (string) ($input['credential_id'] ?? ''));

            if (! $credential || $credential->provider !== $provider) {
                throw ValidationException::withMessages(['credential_id' => 'Choose a credential for this provider.']);
            }

            foreach (['region', 'size', 'image'] as $field) {
                if (empty($input[$field])) {
                    throw ValidationException::withMessages([$field => "The {$field} is required for provider servers."]);
                }
            }

            $credentialId = $credential->id;
        }

        $sshKeyIds = array_values(array_unique($input['ssh_key_ids'] ?? []));

        if (SshKey::query()->where('organization_id', $organizationId)->whereIn('id', $sshKeyIds)->count() !== count($sshKeyIds)) {
            throw ValidationException::withMessages(['ssh_key_ids' => 'Unknown SSH key.']);
        }

        $server = DB::transaction(function () use ($organizationId, $userId, $input, $type, $provider, $stack, $credentialId, $sshKeyIds, $controlPlaneHost) {
            $server = Server::query()->create([
                'organization_id' => $organizationId,
                'name' => $input['name'],
                'type' => $type,
                'status' => ServerStatus::Creating,
                'provider' => $provider->value,
                'provider_credential_id' => $credentialId,
                'region' => $provider->hasApi() ? $input['region'] : null,
                'size' => $provider->hasApi() ? $input['size'] : null,
                'image' => $provider->hasApi() ? $input['image'] : null,
                'timezone' => $input['timezone'] ?? 'UTC',
                'stack' => $stack,
                'created_by' => $userId,
                'control_plane_host' => $controlPlaneHost,
            ]);

            foreach ($stack->phpVersions as $version) {
                $server->phpVersions()->create([
                    'version' => $version,
                    'status' => PhpVersionStatus::Installing,
                    'is_default' => $version === $stack->phpDefault,
                    'ini' => PhpVersion::DEFAULT_INI,
                    'fpm' => PhpVersion::defaultFpm(null),
                ]);
            }

            $server->sshKeys()->attach(array_fill_keys($sshKeyIds, ['unix_user' => (string) config('servers.unix_user', 'falak')]));

            $install = $this->enrollment->issueInstallToken($organizationId, $server->id, $provider->hasApi() ? 60 * 24 : 60 * 24 * 7);
            $server->forceFill(['install_command' => $install->command])->save();

            if ($controlPlaneHost) {
                // Last in the transaction: a refused request rolls the server and its token back.
                try {
                    $this->host->requestLocalServer($install->url, $userId);
                } catch (HostRequestRefused $e) {
                    throw ValidationException::withMessages(['control_plane_host' => $e->getMessage()]);
                }
            }

            return $server;
        });

        $this->audit->record('server.created', 'server', $server->id, [
            'name' => $server->name,
            'type' => $type->value,
            'provider' => $provider->value,
            'region' => $server->region,
            'size' => $server->size,
            'control_plane_host' => $controlPlaneHost,
        ], $organizationId, $userId);

        ServerCreated::dispatch($server->id, $organizationId, $type->value, $server->name);

        if ($provider->hasApi()) {
            CreateProviderMachine::dispatch($server->id);
        }

        return $server;
    }

    /**
     * "This machine": once per install, through the host channel (falak-ctl ≥ v0.23), with nothing else running there.
     */
    private function ensureControlPlaneHostAllowed(ProviderType $provider): void
    {
        $refuse = fn (string $message) => throw ValidationException::withMessages(['control_plane_host' => $message]);

        if ($provider !== ProviderType::Custom) {
            $refuse('The control plane\'s machine is a custom server.');
        }

        if (! $this->host->available()) {
            $refuse(self::RUN_UP);
        }

        if (! $this->host->localServerAllowed()) {
            $refuse(self::RUN_ALLOW);
        }

        if (Server::query()->where('control_plane_host', true)->exists()) {
            $refuse('The control plane\'s machine is already a server.');
        }

        if ($this->host->state()['busy']) {
            $refuse('The control plane\'s host is busy with another request; try again once it finishes.');
        }
    }
}
