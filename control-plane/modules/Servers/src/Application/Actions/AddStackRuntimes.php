<?php

namespace Falak\Servers\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\Servers\Contracts\ServerStatus;
use Falak\Servers\Domain\Enums\PhpVersionStatus;
use Falak\Servers\Domain\Models\PhpVersion;
use Falak\Servers\Domain\Models\Server;
use Falak\Servers\Domain\Stack\Stack;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds PHP and/or Node.js to a server created without them, then re-provisions it to install them. Add-only: a
 * runtime the server already has is kept as is (more PHP versions: Servers → PHP; switching FrankenPHP ⇄ PHP-FPM
 * swaps the edge, which this does not do).
 */
final class AddStackRuntimes
{
    public function __construct(
        private readonly ProvisionServer $provision,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array{php?: array{runtime?: string, version?: string}|null, node?: string|null}  $input
     * @return string the provision command id ('' when the agent is not enrolled)
     */
    public function __invoke(Server $server, array $input, ?string $actorId = null): string
    {
        $stack = $server->stack;
        $php = $input['php'] ?? null;
        $node = $input['node'] ?? null;
        $errors = [];

        if ($php === null && $node === null) {
            $errors['stack'] = 'Name a runtime to add: php, node or both.';
        }

        if ($php !== null && $stack->phpRuntime !== null) {
            $errors['stack.php'] = "This server already runs PHP ({$stack->phpRuntime}); install more versions under Servers → PHP.";
        }

        if ($node !== null && $stack->node !== null) {
            $errors['stack.node'] = "This server already has Node.js {$stack->node}.";
        }

        if (! in_array($server->status, [ServerStatus::Active, ServerStatus::Error, ServerStatus::NeedsAttention], true)) {
            $errors['stack'] = 'Runtimes can be added once the server is provisioned.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $version = $php !== null ? (string) ($php['version'] ?? config('servers.default_php', '8.4')) : null;
        $runtime = $php !== null ? (string) ($php['runtime'] ?? '') : null;
        $new = new Stack(
            $php !== null ? $runtime : $stack->phpRuntime,
            $php !== null ? [$version] : $stack->phpVersions,
            $php !== null ? $version : $stack->phpDefault,
            $node ?? $stack->node,
        );

        if ($php !== null && $version !== null && ! in_array($version, $server->installablePhpVersions(), true)) {
            $errors['stack.php.version'] = "PHP {$version} cannot be installed on {$server->osLabel()}.";
        }

        if (($errors = [...$errors, ...$new->errorsFor($server->type)]) !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($server, $new, $version, $php) {
            $server->forceFill(['stack' => $new])->save();

            // Provisioning marks it installed (HandleCommandOutcome), as for a server created with PHP.
            if ($php !== null) {
                $server->phpVersions()->updateOrCreate(['version' => $version], [
                    'status' => PhpVersionStatus::Installing,
                    'command_id' => null,
                    'is_default' => true,
                    'ini' => PhpVersion::DEFAULT_INI,
                    'fpm' => PhpVersion::defaultFpm($server->memory_bytes),
                ]);
            }
        });

        $this->audit->record('server.stack_runtimes_added', 'server', $server->id, array_filter([
            'php' => $php !== null ? ['runtime' => $new->phpRuntime, 'version' => $version] : null,
            'node' => $node,
        ]), $server->organization_id);

        return ($this->provision)($server->refresh(), $actorId);
    }
}
