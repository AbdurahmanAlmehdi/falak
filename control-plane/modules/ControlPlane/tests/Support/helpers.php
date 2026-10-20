<?php

/**
 * A fresh temporary host channel directory, set as controlplane.host_channel_dir (as falak-ctl mounts /falak/host),
 * with the channel.json falak-ctl writes once its units are installed.
 */
function host_channel(): string
{
    $dir = sys_get_temp_dir().'/falak-host-'.getmypid().'-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    config(['controlplane.host_channel_dir' => $dir]);

    file_put_contents("{$dir}/channel.json", json_encode(['version' => 'v0.23.0', 'units' => true, 'local_server_allowed' => true, 'installed_at' => now()->toIso8601String()]));

    return $dir;
}

/**
 * Write status.json as the host does.
 *
 * @param  array<string, mixed>  $status
 */
function host_status(string $dir, array $status): void
{
    file_put_contents("{$dir}/status.json", json_encode([
        'id' => '01K7A0000000000000000000AA',
        'action' => 'update',
        'state' => 'running',
        'started_at' => now()->toIso8601String(),
        'finished_at' => null,
        'exit_code' => null,
        'log' => '01K7A0000000000000000000AA.log',
        ...$status,
    ]));
}

/**
 * @return array<string, mixed>
 */
function github_release(array $overrides = []): array
{
    return [
        'tag_name' => 'v0.24.0',
        'html_url' => 'https://github.com/falak-sh/falak/releases/tag/v0.24.0',
        'published_at' => '2026-10-17T10:00:00Z',
        'draft' => false,
        'prerelease' => false,
        'body' => "  ## What's new\n<script>alert(1)</script>\n  ",
        ...$overrides,
    ];
}
