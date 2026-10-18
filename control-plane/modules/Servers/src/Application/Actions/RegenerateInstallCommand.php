<?php

namespace Falak\Servers\Application\Actions;

use Falak\Fleet\Contracts\Data\InstallToken;
use Falak\Fleet\Contracts\Enrollment;
use Falak\Servers\Domain\Models\Server;

/**
 * Issues a fresh one-time install command (e.g. to reinstall the agent or after the previous one expired).
 */
final class RegenerateInstallCommand
{
    public function __construct(private readonly Enrollment $enrollment) {}

    public function __invoke(Server $server): InstallToken
    {
        $install = $this->enrollment->issueInstallToken($server->organization_id, $server->id, 60 * 24 * 7);
        $server->forceFill(['install_command' => $install->command])->save();

        return $install;
    }
}
