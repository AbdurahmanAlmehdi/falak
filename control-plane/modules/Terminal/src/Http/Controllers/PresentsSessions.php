<?php

namespace Falak\Terminal\Http\Controllers;

use Falak\Identity\Contracts\OrganizationDirectory;
use Falak\Terminal\Domain\Models\TerminalSession;

trait PresentsSessions
{
    /**
     * @return array<string, mixed>
     */
    protected function present(TerminalSession $session, OrganizationDirectory $directory): array
    {
        $owner = $directory->findUser($session->user_id);

        return [
            'id' => $session->id,
            'server_id' => $session->server_id,
            'server_name' => $session->server_name,
            'unix_user' => $session->unix_user,
            'target_type' => $session->targetType(),
            'target_id' => $session->target_id,
            'target_name' => $session->target_name,
            'container' => $session->container,
            'relayed' => $session->isRelayed(),
            'status' => $session->status->value,
            'close_reason' => $session->close_reason,
            'exit_code' => $session->exit_code,
            'error' => $session->error,
            'cols' => $session->cols,
            'rows' => $session->rows,
            'shared' => $session->shared,
            'channel_epoch' => $session->channel_epoch,
            'owner' => ['id' => $session->user_id, 'name' => $owner->name ?? 'Unknown'],
            'recording_bytes' => $session->recording_bytes,
            'created_at' => $session->created_at->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'duration_s' => $session->closed_at ? (int) $session->created_at->diffInSeconds($session->closed_at, true) : null,
        ];
    }
}
