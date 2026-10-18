<?php

namespace Falak\ControlPlane\Infrastructure;

use Falak\ControlPlane\Contracts\Exceptions\HostRequestRefused;
use Falak\ControlPlane\Contracts\HostChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The host channel as files in config('controlplane.host_channel_dir'): request.json is ours (written to a temporary
 * name in the same directory, then renamed, so the host's path unit never sees half a file), channel.json (written once
 * falak-ctl installed its units), status.json and <id>.log are the host's. The host validates every request again
 * (allow-list, version, install URL = FALAK_URL/install/<token>); the checks here only keep the panel honest.
 */
final class FileHostChannel implements HostChannel
{
    private const VERSION = '/^v\d+\.\d+\.\d+$/';

    private const TOKEN = '/^[A-Za-z0-9_-]+$/';

    private const ID = '/^[0-9A-Za-z]{26}$/';

    private const NOT_SET_UP = 'Run sudo falak-ctl up once on the control-plane machine to enable updates from the panel.';

    /**
     * @param  string  $panelUrl  FALAK_URL: install URLs must be exactly <panelUrl>/install/<token>
     */
    public function __construct(private readonly string $directory, private readonly int $staleAfterMinutes, private readonly string $panelUrl) {}

    public function available(): bool
    {
        return $this->mounted() && is_file($this->path('channel.json'));
    }

    private function mounted(): bool
    {
        return $this->directory !== '' && is_dir($this->directory);
    }

    public function requestUpdate(string $version, ?string $requestedBy): string
    {
        if (preg_match(self::VERSION, $version) !== 1) {
            throw new HostRequestRefused("Not a release version: {$version}.");
        }

        return $this->write(self::UPDATE, ['version' => $version], $requestedBy);
    }

    public function requestLocalServer(string $installUrl, ?string $requestedBy): string
    {
        $prefix = rtrim($this->panelUrl, '/').'/install/';

        if ($this->panelUrl === '' || ! str_starts_with($installUrl, $prefix) || preg_match(self::TOKEN, substr($installUrl, strlen($prefix))) !== 1) {
            throw new HostRequestRefused('Not an install URL.');
        }

        return $this->write(self::LOCAL_SERVER, ['install_url' => $installUrl], $requestedBy);
    }

    public function state(): array
    {
        $mounted = $this->mounted();
        $pending = null;
        $status = null;

        if ($mounted) {
            $request = $this->json('request.json');

            if ($request !== null) {
                $requestedAt = is_string($request['requested_at'] ?? null) ? $request['requested_at'] : null;
                $since = $this->time($requestedAt) ?? Carbon::createFromTimestamp((int) @filemtime($this->path('request.json')));
                $pending = [
                    'id' => (string) ($request['id'] ?? ''),
                    'action' => (string) ($request['action'] ?? ''),
                    'requested_at' => $requestedAt,
                    'stale' => $since->lt(now()->subMinutes($this->staleAfterMinutes)),
                ];
            }

            $raw = $this->json('status.json');

            if ($raw !== null && in_array($raw['state'] ?? null, ['running', 'succeeded', 'failed'], true)) {
                $status = [
                    'id' => (string) ($raw['id'] ?? ''),
                    'action' => (string) ($raw['action'] ?? ''),
                    'state' => (string) $raw['state'],
                    'started_at' => is_string($raw['started_at'] ?? null) ? $raw['started_at'] : null,
                    'finished_at' => is_string($raw['finished_at'] ?? null) ? $raw['finished_at'] : null,
                    'exit_code' => is_int($raw['exit_code'] ?? null) ? $raw['exit_code'] : null,
                    // Why the host refused a request (id null when the id itself was invalid).
                    'error' => is_string($raw['error'] ?? null) ? mb_substr($raw['error'], 0, 500) : null,
                ];
            }
        }

        // ponytail: a host that died mid-run leaves "running" for good (deleting status.json on the host clears it).
        // Add a running timeout here if that turns out to happen.
        $busy = ($status['state'] ?? null) === 'running' || ($pending !== null && ! $pending['stale']);

        return ['mounted' => $mounted, 'available' => $mounted && $this->available(), 'busy' => $busy, 'pending' => $pending, 'status' => $status];
    }

    public function log(string $id, int $bytes = 65536): ?string
    {
        if (preg_match(self::ID, $id) !== 1 || ! is_file($path = $this->path("{$id}.log"))) {
            return null;
        }

        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            if ($size > $bytes) {
                fseek($handle, -$bytes, SEEK_END);
            }

            $tail = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        // Cut at a line start when truncated, and never hand invalid UTF-8 to json_encode.
        if ($size > $bytes && ($newline = strpos($tail, "\n")) !== false) {
            $tail = substr($tail, $newline + 1);
        }

        return mb_scrub($tail, 'UTF-8');
    }

    /**
     * @param  array<string, string>  $params
     */
    private function write(string $action, array $params, ?string $requestedBy): string
    {
        if (! $this->available()) {
            throw new HostRequestRefused($this->mounted()
                ? self::NOT_SET_UP
                : 'The host channel is not installed on this control plane: run sudo falak-ctl update on the host (v0.23 installs it).');
        }

        // Check-then-write under a lock: two panel workers must not both decide the channel is free.
        return Cache::lock('controlplane:host-request', 10)->block(5, function () use ($action, $params, $requestedBy) {
            $state = $this->state();

            if ($state['busy']) {
                throw new HostRequestRefused(($state['status']['state'] ?? null) === 'running'
                    ? 'The host is running another request ('.$state['status']['action'].'); wait until it finishes.'
                    : 'The host has not picked up the previous request ('.($state['pending']['action'] ?? '?').') yet.');
            }

            $id = (string) Str::ulid();
            $payload = json_encode([
                'id' => $id,
                'action' => $action,
                ...$params,
                'requested_by' => $requestedBy,
                'requested_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $tmp = $this->path('.request.'.bin2hex(random_bytes(6)).'.tmp');

            if (@file_put_contents($tmp, $payload, LOCK_EX) === false || ! @rename($tmp, $this->path('request.json'))) {
                @unlink($tmp);

                throw new HostRequestRefused('Could not write to the host channel ('.$this->directory.'): check that it is mounted read-write.');
            }

            return $id;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(string $file): ?array
    {
        $path = $this->path($file);
        $raw = is_file($path) ? @file_get_contents($path) : false;

        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    private function time(?string $value): ?Carbon
    {
        try {
            return $value !== null ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function path(string $file): string
    {
        return rtrim($this->directory, '/').'/'.$file;
    }
}
