<?php

namespace Falak\Edge\Infrastructure;

use Falak\Edge\Contracts\CloudflareRanges;
use Falak\Edge\Domain\Models\CloudflareTunnel;
use Falak\Edge\Domain\Models\OriginLock;
use Falak\Network\Contracts\WebOriginPolicy;

/**
 * Web ports of servers locked down behind Cloudflare. `closed` only holds while the server's tunnel is running:
 * otherwise its sites would be unreachable, so the ports fall back to Cloudflare's ranges.
 */
final class CloudflareOriginPolicy implements WebOriginPolicy
{
    public function for(string $serverId): ?array
    {
        $lock = OriginLock::query()->find(strtolower($serverId));

        if ($lock === null) {
            return null;
        }

        if ($lock->mode === OriginLock::CLOSED && CloudflareTunnel::query()->where('server_id', $lock->server_id)->where('status', CloudflareTunnel::ACTIVE)->exists()) {
            return ['mode' => 'closed'];
        }

        return ['mode' => 'only', 'sources' => CloudflareRanges::RANGES];
    }
}
