<?php

namespace Falak\Terminal\Infrastructure\AgentRequests;

use Falak\Fleet\Contracts\AgentRequestHandler;
use Falak\Fleet\Contracts\Data\AgentCaller;
use Falak\Terminal\Domain\Models\NetCapture;

/**
 * net.capture.progress (docs/plans/network/capture.md): a running capture's counters every few seconds. The reply tells
 * the agent to stop when the user cancelled, or when the capture is unknown to this server or no longer in progress.
 */
final class NetCaptureProgress implements AgentRequestHandler
{
    public function handle(AgentCaller $caller, array $body): array
    {
        $capture = NetCapture::query()
            ->where('organization_id', $caller->organizationId)
            ->where('server_id', $caller->serverId)
            ->find(strtolower((string) $body['capture_id']));

        if ($capture === null || ! $capture->isActive() || $capture->cancel_requested_at !== null) {
            return ['cancel' => true];
        }

        $capture->forceFill([
            'status' => $capture->status === NetCapture::QUEUED ? NetCapture::CAPTURING : $capture->status,
            'started_at' => $capture->started_at ?? now(),
            'bytes_captured' => min((int) $body['bytes'], $capture->max_bytes),
            'packets_captured' => (int) $body['packets'],
        ])->save();

        return ['cancel' => false];
    }
}
