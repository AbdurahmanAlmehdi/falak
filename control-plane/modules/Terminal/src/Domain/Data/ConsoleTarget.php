<?php

namespace Falak\Terminal\Domain\Data;

use Falak\Terminal\Domain\Models\TerminalSession;

/**
 * Where a terminal session runs (docs/plans/CONSOLE.md "Opening a console"): a host shell as a unix user, or a
 * `docker exec` into a container selected by name or labels, or a `runsc exec` into a sandbox.
 */
final readonly class ConsoleTarget
{
    /**
     * @param  array<string, string>  $env
     * @param  ?array{name?: string, labels?: array<string, string>, user?: string, workdir?: string}  $container  terminal.open's `container`
     * @param  ?string  $containerLabel  docker:<name> | compose:<service> | sandbox:<name>, kept on the session for display
     * @param  ?array{id: string}  $sandbox  terminal.open's `sandbox`
     */
    public function __construct(
        public string $type,
        public string $serverId,
        public string $serverName,
        public ?string $id = null,
        public ?string $name = null,
        public ?string $unixUser = null,
        public ?string $cwd = null,
        public array $env = [],
        public ?array $container = null,
        public ?string $containerLabel = null,
        public ?array $sandbox = null,
    ) {}

    public static function server(string $serverId, string $serverName, string $unixUser): self
    {
        return new self(TerminalSession::TARGET_SERVER, $serverId, $serverName, unixUser: $unixUser);
    }

    /** Not a host shell: a container or a sandbox (no unix user, shell or cwd). */
    public function inContainer(): bool
    {
        return $this->container !== null || $this->sandbox !== null;
    }
}
