<?php

namespace Falak\Terminal\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class TerminalSessionOpened
{
    use Dispatchable;

    public function __construct(
        public string $sessionId,
        public string $organizationId,
        public string $serverId,
        public string $userId,
        /** Empty for container consoles */
        public string $unixUser,
        /** server | site | database */
        public string $targetType = 'server',
        public ?string $targetId = null,
        public ?string $targetName = null,
    ) {}
}
