<?php

namespace Falak\Functions\Application\Actions;

use Falak\Config\Contracts\ManagedResources;
use Falak\Deployments\Contracts\DeploymentTrigger;
use Falak\Functions\Application\AgentSupport;
use Falak\Functions\Application\Code;
use Falak\Functions\Application\FunctionStore;
use Falak\Functions\Application\StaleVersion;
use Falak\Functions\Domain\Models\CloudFunction;
use Falak\Functions\Domain\Models\FunctionDraft;
use Falak\Functions\Domain\Models\FunctionVersion;
use Falak\Identity\Contracts\AuditLog;
use Falak\Sites\Contracts\Data\SiteData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The editor's Deploy: save the code as a new version (unless it equals the newest one) and deploy it. The editor
 * sends the version it started from; a newer one deployed in between is a conflict (StaleVersion) unless $force.
 */
final class DeployCode
{
    public function __construct(
        private readonly FunctionStore $functions,
        private readonly DeploymentTrigger $deployments,
        private readonly AuditLog $audit,
        private readonly AgentSupport $agents,
        private readonly ManagedResources $managed,
    ) {}

    /**
     * @return array{version: FunctionVersion, created: bool, deployment_id: ?string, warnings: list<string>}
     *
     * @throws StaleVersion|ValidationException
     */
    public function __invoke(SiteData $site, CloudFunction $function, mixed $files, ?string $message, ?string $baseVersionId, ?string $userId, ?string $userName, bool $deploy = true, bool $force = false): array
    {
        // [decision] A function of a falak.yaml runs the repository's code: the editor doesn't replace it.
        $this->managed->managed('site', $site->id)->guardWhole('files', '/code');

        $files = Code::files($files, $function->entrypoint);
        $hash = Code::hash($files, $function->entrypoint);

        [$version, $created] = DB::transaction(function () use ($function, $files, $hash, $message, $baseVersionId, $userId, $userName, $force) {
            CloudFunction::query()->whereKey($function->id)->lockForUpdate()->first();
            $head = $function->head();

            if ($head !== null && $head->id !== $baseVersionId && ! $force) {
                throw new StaleVersion($head);
            }

            if ($head !== null && $head->hash === $hash) {
                return [$head, false];
            }

            return [$this->functions->addVersion($function, $files, $message, $userId, $userName, $baseVersionId), true];
        });

        if ($userId !== null) {
            FunctionDraft::query()->where('function_id', $function->id)->where('user_id', $userId)->delete();
        }

        if ($created) {
            $this->audit->record('function.version.created', 'site', $site->id, ['version' => $version->number, 'hash' => $version->hash], $site->organizationId);
        }

        $warnings = [];
        $deploymentId = null;

        $blocker = $deploy ? $this->agents->blocker($site, $files) : null;

        if ($blocker !== null) {
            $warnings[] = "Version {$version->number} was saved, but could not be deployed: {$blocker}";
        } elseif ($deploy) {
            try {
                $deploymentId = $this->deployments->deploy($site->id, $userId, $version->hash, self::title($version), $version->author_name);
            } catch (ValidationException $e) {
                $warnings[] = "Version {$version->number} was saved, but could not be deployed: ".collect($e->errors())->flatten()->first();
            }
        }

        return ['version' => $version, 'created' => $created, 'deployment_id' => $deploymentId, 'warnings' => $warnings];
    }

    public static function title(FunctionVersion $version): string
    {
        return "v{$version->number}".($version->message ? ": {$version->message}" : '');
    }
}
