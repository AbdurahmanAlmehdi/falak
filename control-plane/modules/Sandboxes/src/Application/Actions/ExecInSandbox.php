<?php

namespace Falak\Sandboxes\Application\Actions;

use Falak\Sandboxes\Application\Actor;
use Falak\Sandboxes\Application\SandboxAgent;
use Falak\Sandboxes\Domain\Models\Sandbox;
use Falak\Sandboxes\Domain\Models\SandboxExec;

/**
 * Run a command in a running sandbox and wait for it (sandbox.exec; up to 120 s, the request waits 10 s longer). Every
 * exec is a sandboxes_execs row with the command redacted: env values, URL credentials and token-looking strings.
 */
final class ExecInSandbox
{
    /** Words whose value (KEY=value, --key value) is masked in the audit trail. */
    private const SENSITIVE = 'password|passwd|secret|token|api[_-]?key|apikey|auth|credential|private[_-]?key|access[_-]?key';

    public function __construct(private readonly SandboxAgent $agent) {}

    /**
     * @param  string|list<string>  $cmd  a string runs as `sh -lc <cmd>`
     * @param  array<string, string>  $env
     * @return array{exit_code: int, stdout: string, stderr: string, truncated: bool, timed_out: bool, duration_ms: int} stdout/stderr as bytes
     */
    public function __invoke(Sandbox $sandbox, Actor $actor, string|array $cmd, ?string $cwd = null, array $env = [], ?string $stdin = null, int $timeout = 60): array
    {
        $argv = is_string($cmd) ? ['sh', '-lc', $cmd] : array_values($cmd);
        $startedAt = now();

        $result = $this->agent->ask($sandbox, 'sandbox.exec', array_filter([
            'id' => $sandbox->id,
            'cmd' => $argv,
            'cwd' => $cwd,
            'env' => $env === [] ? null : (object) $env,
            'stdin' => $stdin !== null && $stdin !== '' ? base64_encode($stdin) : null,
            'timeout_s' => $timeout,
            'max_output' => 262144,
        ], fn ($value) => $value !== null), $timeout + 10);

        $out = [
            'exit_code' => (int) ($result['exit_code'] ?? -1),
            'stdout' => (string) base64_decode((string) ($result['stdout'] ?? ''), true),
            'stderr' => (string) base64_decode((string) ($result['stderr'] ?? ''), true),
            'truncated' => (bool) ($result['truncated'] ?? false),
            'timed_out' => (bool) ($result['timed_out'] ?? false),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
        ];

        $this->record($sandbox, $actor, is_string($cmd) ? $cmd : self::join($argv), $cwd, $env, $startedAt, $out);

        return $out;
    }

    /**
     * An audit row for an exec, a file write or delete.
     *
     * @param  array<string, string>  $env
     * @param  array{exit_code?: ?int, stdout?: string, stderr?: string, timed_out?: bool, duration_ms?: ?int}  $out
     */
    public function record(Sandbox $sandbox, Actor $actor, string $cmd, ?string $cwd, array $env, \DateTimeInterface $startedAt, array $out): SandboxExec
    {
        $sandbox->forceFill(['last_activity_at' => now()])->save();

        return SandboxExec::query()->create([
            'sandbox_id' => $sandbox->id,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'actor' => $actor->name,
            'cmd' => mb_substr(self::redact($cmd, $env), 0, 4000),
            'cwd' => $cwd,
            'exit_code' => $out['exit_code'] ?? null,
            'duration_ms' => $out['duration_ms'] ?? null,
            'stdout_bytes' => strlen($out['stdout'] ?? ''),
            'stderr_bytes' => strlen($out['stderr'] ?? ''),
            'timed_out' => $out['timed_out'] ?? false,
            'started_at' => $startedAt,
        ]);
    }

    /**
     * Mask what looks like a credential: the values of the env passed along, user:password@ in URLs, Bearer/Basic
     * tokens, KEY=value and --key value where the key names a secret, and well-known token formats.
     *
     * @param  array<string, string>  $env
     */
    public static function redact(string $cmd, array $env = []): string
    {
        foreach ($env as $value) {
            if (strlen((string) $value) >= 4) {
                $cmd = str_replace((string) $value, '***', $cmd);
            }
        }

        $patterns = [
            '#\b([a-z][a-z0-9+.-]*://)[^\s/@:]+(?::[^\s/@]*)?@#i' => '$1***@',
            '/\b(Bearer|Basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 ***',
            '/(\b[\w.-]*(?:'.self::SENSITIVE.')[\w.-]*\s*[=:]\s*)("[^"]*"|\'[^\']*\'|\S+)/i' => '$1***',
            '/(--?[\w-]*(?:'.self::SENSITIVE.')[\w-]*[\s=]+)("[^"]*"|\'[^\']*\'|\S+)/i' => '$1***',
            '/\b(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{20,}|sk-[A-Za-z0-9_-]{20,}|xox[abpr]-[A-Za-z0-9-]{10,}|AKIA[0-9A-Z]{16}|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+)\b/' => '***',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $cmd);
    }

    /**
     * @param  list<string>  $argv
     */
    private static function join(array $argv): string
    {
        return implode(' ', array_map(fn (string $arg) => preg_match('/^[A-Za-z0-9_\/.,:=@%+-]+$/', $arg) === 1 ? $arg : escapeshellarg($arg), $argv));
    }
}
