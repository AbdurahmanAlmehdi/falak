<?php

namespace Falak\Edge\Infrastructure;

/**
 * Falak's Caddy builds for servers' edges (Caddy + caddy-ratelimit + caddy-dns/cloudflare), baked into the control
 * plane image next to the agent binaries (falak-caddy-linux-<arch> with a .sha256 sidecar) and served at
 * /install/edge/caddy-linux-<arch>. edge.caddy.apply carries the URL and checksum when a server needs rate limits.
 */
final class EdgeCaddyBuilds
{
    public const ARCHES = ['amd64', 'arm64'];

    public function file(string $arch): ?string
    {
        if (! in_array($arch, self::ARCHES, true)) {
            return null;
        }

        $file = rtrim((string) (config('edge.caddy_builds_path') ?: config('fleet.agent.binaries_path')), '/')."/falak-caddy-linux-{$arch}";

        return is_file($file) ? $file : null;
    }

    public function checksum(string $arch): ?string
    {
        $file = $this->file($arch);

        if ($file === null) {
            return null;
        }

        $sidecar = "{$file}.sha256";

        if (is_file($sidecar) && filemtime($sidecar) >= filemtime($file)) {
            $sum = strtolower(trim(explode(' ', (string) file_get_contents($sidecar))[0]));

            if (preg_match('/^[a-f0-9]{64}$/', $sum) === 1) {
                return $sum;
            }
        }

        return hash_file('sha256', $file) ?: null;
    }

    /**
     * The payload's caddy_build for a server architecture ("amd64", "x86_64", "arm64", "aarch64"), or null when no build
     * is published for it.
     *
     * @return ?array{url: string, sha256: string}
     */
    public function forArch(?string $arch): ?array
    {
        $arch = match ($arch) {
            'x86_64', 'amd64' => 'amd64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };

        if ($arch === null || ($sum = $this->checksum($arch)) === null) {
            return null;
        }

        $base = rtrim((string) (config('fleet.panel_url') ?: config('app.url')), '/');

        return ['url' => "{$base}/install/edge/caddy-linux-{$arch}", 'sha256' => $sum];
    }
}
