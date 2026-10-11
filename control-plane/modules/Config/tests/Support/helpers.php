<?php

use Falak\Config\Domain\Models\ConfigSource;
use Falak\Identity\Contracts\Role;
use Falak\Projects\Application\Actions\LinkService;
use Falak\Projects\Contracts\ServiceKind;
use Falak\Projects\Domain\Models\Environment;
use Falak\SourceControl\Contracts\Data\CommitData;
use Falak\SourceControl\Events\PushReceived;

require_once __DIR__.'/../../../Deployments/tests/Support/helpers.php';
require_once __DIR__.'/../../../Projects/tests/Support/helpers.php';

final class ConfigWorld
{
    public function __construct(
        public DeployWorld $deploy,
        public Environment $environment,
        public ConfigSource $source,
    ) {}

    /** Put falak.yaml in the fake repository (every ref, or one ref). */
    public function file(string $yaml, ?string $ref = null): void
    {
        if ($ref === null) {
            $this->deploy->sourceControl->files['falak.yaml'] = $yaml;
        } else {
            $this->deploy->sourceControl->filesByRef[$ref]['falak.yaml'] = $yaml;
        }
    }

    /**
     * A verified push of $sha to $branch.
     *
     * @param  ?list<string>  $paths  the files the push changed (null: unknown)
     */
    public function push(string $sha, string $branch = 'main', ?array $paths = null): void
    {
        PushReceived::dispatch($this->deploy->organization->id, $this->source->source_connection_id, 'github', 'acme/shop', $branch,
            new CommitData($sha, "Commit {$sha}", 'Ada', 'ada@example.com'), 'ada', str_repeat('0', 39).'1', $paths);
    }

    /** Finish every build and agent command (deployments run to the end). */
    public function settle(): void
    {
        for ($i = 0; $i < 5; $i++) {
            foreach ($this->deploy->builds->builds as $id => $build) {
                if (! $build['status']->isTerminal()) {
                    $this->deploy->builds->succeed((string) $id);
                }
            }

            deploy_run_all($this->deploy->agents);
        }
    }
}

/**
 * A deployable site ("Shop", service `web`) in the default project's production environment, on web-1, with an
 * enabled config source on acme/shop.
 *
 * @param  array<string, mixed>  $site
 */
function config_world(array $site = [], int $servers = 1, bool $experimental = true, Role $role = Role::Owner, bool $actingAs = true): ConfigWorld
{
    $deploy = deploy_world($servers, $site, $role, $actingAs);
    $environment = projects_default_env($deploy->organization);
    app(LinkService::class)($environment, ServiceKind::Site, $deploy->site->id, 'web');

    $source = ConfigSource::query()->create([
        'organization_id' => $deploy->organization->id,
        'project_id' => $environment->project_id,
        'source_connection_id' => array_key_first($deploy->sourceControl->connections),
        'repository' => 'acme/shop',
        'path' => 'falak.yaml',
        'enabled' => true,
        'experimental' => $experimental,
    ]);

    return new ConfigWorld($deploy, $environment, $source);
}

function config_sha(string $char): string
{
    return str_repeat($char, 40);
}
