<?php

namespace Falak\Databases\Application\Actions;

use Falak\Databases\Application\AgentCommands;
use Falak\Databases\Application\InstanceCertificates;
use Falak\Databases\Application\InstanceNetwork;
use Falak\Databases\Domain\Models\DatabaseInstance;
use Falak\Databases\Infrastructure\CommandPayloads;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Converges an instance's container with its row (db.instance.update): limits, settings and a new certificate apply
 * in place (a restart), the firewall's allowed sources live. New published addresses need a new container (Docker binds
 * ports at creation): they are only sent when someone applies them ($applyNetwork), never as a side effect.
 */
final class ApplyInstance
{
    public function __construct(
        private readonly AgentCommands $commands,
        private readonly InstanceNetwork $network,
        private readonly InstanceCertificates $certificates,
    ) {}

    /**
     * @param  bool  $background  record "agent not connected" on the instance instead of throwing
     * @param  bool  $applyNetwork  send the pending published addresses (the container is recreated)
     * @param  bool  $renewCertificate  issue a new certificate (renewal, a new DNS name or address) and restart with it
     * @param  bool  $applyQueryInsights  the user turned query insights on: the agent restarts the engine for it. Every
     *                                    other update leaves a query insights change for the next restart.
     *
     * @throws ValidationException
     */
    public function __invoke(DatabaseInstance $instance, bool $background = false, bool $applyNetwork = false, bool $renewCertificate = false, bool $applyQueryInsights = false): void
    {
        $instance->forceFill(['firewall_sources' => $this->network->allowedSources($instance) ?: null])->save();
        $addresses = $applyNetwork && $instance->pending_published_addresses !== null ? array_values($instance->pending_published_addresses) : null;
        $payload = CommandPayloads::instance($instance, $renewCertificate ? $this->certificates->issue($instance) : null, $addresses);

        if ($applyQueryInsights && $instance->query_insights) {
            $payload['apply_query_insights'] = true;
        }
        $key = "db.instance.update:{$instance->id}:".Str::ulid();
        $timeout = (int) config('databases.timeouts.instance', 1800);

        $handle = $background
            ? $this->commands->tryDispatch($instance->server_id, 'db.instance.update', $payload, $timeout, $key)
            : $this->commands->dispatch($instance->server_id, 'db.instance.update', $payload, $timeout, $key, 'instance');

        $instance->forceFill($handle !== null
            ? ['command_id' => $handle->id, 'status_message' => null, ...($addresses !== null ? ['network_command_id' => $handle->id] : [])]
            : ['status_message' => 'Not applied: '.AgentCommands::NOT_CONNECTED])->save();
    }
}
