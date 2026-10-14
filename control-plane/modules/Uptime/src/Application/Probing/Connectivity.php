<?php

namespace Falak\Uptime\Application\Probing;

use Illuminate\Support\Facades\Cache;

/**
 * Whether the control plane itself can reach the internet: any of the canaries (uptime.canaries, host:port) accepts a
 * TCP connection. Asked only after a failed check; the answer is cached 30 seconds, and "offline" is remembered two
 * minutes for the UI ("monitoring paused").
 */
final class Connectivity
{
    public const OFFLINE_KEY = 'uptime:control-plane-offline';

    public function __construct(private readonly Transport $transport) {}

    public function online(): bool
    {
        $canaries = array_values((array) config('uptime.canaries', []));

        if ($canaries === []) {
            return true;
        }

        $online = (bool) Cache::remember('uptime:connectivity', 30, function () use ($canaries) {
            foreach ($canaries as $canary) {
                $port = (int) substr(strrchr((string) $canary, ':') ?: ':443', 1);
                $host = trim(substr((string) $canary, 0, (int) strrpos((string) $canary, ':')) ?: (string) $canary, '[]');

                if ($this->transport->tcp($host, $port ?: 443, 3)->ok) {
                    return true;
                }
            }

            return false;
        });

        if (! $online) {
            Cache::put(self::OFFLINE_KEY, now()->toIso8601String(), 120);
        }

        return $online;
    }

    public static function offlineSince(): ?string
    {
        $value = Cache::get(self::OFFLINE_KEY);

        return is_string($value) ? $value : null;
    }
}
