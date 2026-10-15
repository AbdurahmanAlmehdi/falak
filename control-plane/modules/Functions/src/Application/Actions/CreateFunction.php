<?php

namespace Falak\Functions\Application\Actions;

use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Application\Starters;
use Falak\Projects\Contracts\Data\EnvironmentData;
use Falak\Sites\Contracts\Data\SiteData;
use Falak\Sites\Contracts\Data\SitePlacement;
use Falak\Sites\Contracts\SiteDomains;
use Falak\Sites\Contracts\SiteFactory;
use Falak\Sites\Contracts\SiteRuntime;
use Illuminate\Validation\ValidationException;

/**
 * Canvas → Create → Function: a function site on one server with the chosen starter as version 1, deployed right away
 * when $deploy. Without, it is a draft: no deployment, so the gateway never gets the function and its schedules stay
 * off the server (Processes only runs jobs of sites with a live release) until the first Deploy.
 */
final class CreateFunction
{
    public function __construct(
        private readonly SiteFactory $sites,
        private readonly FunctionStore $functions,
        private readonly DeploymentTrigger $deployments,
        private readonly SiteDomains $domains,
        private readonly SaveSchedule $schedules,
    ) {}

    /**
     * @return array{site: SiteData, deployment_id: ?string, warnings: list<string>}
     *
     * @throws ValidationException
     */
    public function __invoke(EnvironmentData $environment, ?string $userId, ?string $userName, string $name, string $serverId, string $starter, mixed $domain, ?int $x, ?int $y, bool $deploy, string $runtime = 'bun'): array
    {
        if (! is_array(config("functions.runtimes.{$runtime}"))) {
            throw ValidationException::withMessages(['runtime' => 'Unknown runtime.']);
        }

        Starters::content($starter, (string) config("functions.runtimes.{$runtime}.family"));

        $created = $this->sites->create($environment->organizationId, $userId, array_filter([
            'name' => $name,
            'framework' => 'docker',
            'runtime' => SiteRuntime::Function->value,
            'server_ids' => [$serverId],
            'domain' => $domain,
            // The variables the starter reads, ready to fill in (secrets it needs are generated).
            'variables' => Starters::variables($starter) ?: null,
        ], fn ($v) => $v !== null), new SitePlacement($environment->projectId, $environment->id, $x, $y));

        $site = $created->site;
        $function = $this->functions->ensure($site, $starter, $userId, $userName, $runtime);

        if (($schedule = Starters::ALL[$starter]['schedule'] ?? null) !== null) {
            ($this->schedules)($site, $function, null, $schedule);
        }
        $warnings = $created->warnings;

        // No choice: the organization's default (test domain when there is one, else a generated name), so the
        // function is reachable right away.
        if ($domain === null) {
            try {
                $name = $this->domains->resolveChoice($site->organizationId, null, $site->slug, [$serverId], 'domain', $site->id);

                if ($name !== null) {
                    $this->domains->attach($site->id, $name);
                }
            } catch (ValidationException $e) {
                $warnings[] = 'The function has no domain yet: '.collect($e->errors())->flatten()->first();
            }
        }
        $deploymentId = null;

        if ($deploy && ($head = $function->head()) !== null) {
            try {
                $deploymentId = $this->deployments->deploy($site->id, $userId, $head->hash, DeployCode::title($head), $userName);
            } catch (ValidationException $e) {
                $warnings[] = 'The function was created, but its first deploy did not start: '.collect($e->errors())->flatten()->first();
            }
        }

        return ['site' => $site, 'deployment_id' => $deploymentId, 'warnings' => $warnings];
    }
}
