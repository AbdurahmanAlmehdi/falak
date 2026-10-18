<?php

namespace Falak\Servers\Domain\MachineCheck;

use Falak\Servers\Domain\Stack\Stack;

/**
 * What provisioning wants on the machine: the server's stack plus the host settings the plan applies.
 */
final readonly class Wanted
{
    /**
     * @param  list<string>  $phpVersions
     * @param  list<string>  $basePackages
     */
    public function __construct(
        public Stack $stack,
        public bool $servesHttp,
        public bool $customServer,
        public string $hostname,
        public int $sshPort = 22,
        public int $swapMb = 0,
        public array $phpVersions = [],
        public ?string $nodeVersion = null,
        public array $basePackages = [],
        /** The control plane's own machine: its edge owns 80/443, and Docker, SSH, swap and the hostname are its. */
        public bool $controlPlaneHost = false,
        /** The agent's `local_edge` fact: falak-ctl local-server set the agent edge up on 8080/8443. */
        public bool $localEdge = false,
    ) {}
}
