<?php

namespace Falak\SourceControl\Application\Actions;

use Falak\Identity\Contracts\AuditLog;
use Falak\SourceControl\Contracts\Exceptions\SourceControlException;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Falak\SourceControl\Infrastructure\GitHubApp\AppManifest;

/**
 * Finish the manifest flow: exchange GitHub's one-time code for the new app's credentials and store them
 * (encrypted) for the organization — one app per GitHub owner.
 */
final class RegisterGitHubApp
{
    public function __construct(
        private readonly AppManifest $manifest,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  string  $appKey  the id chosen when the manifest was built (the app's webhook URL contains it)
     */
    public function __invoke(string $organizationId, ?string $userId, string $appKey, string $code): GitHubApp
    {
        $converted = $this->manifest->convert($code);

        // One app per GitHub owner. A personal app's owner is only known now, after the conversion.
        $owner = (string) $converted['owner_login'];
        if ($owner !== '' && GitHubApp::query()->where('organization_id', $organizationId)->whereRaw('lower(owner_login) = ?', [strtolower($owner)])->exists()) {
            throw new SourceControlException("This organization already has a GitHub App owned by {$owner}.");
        }

        $app = new GitHubApp([
            'organization_id' => $organizationId,
            'app_id' => $converted['id'],
            'slug' => $converted['slug'],
            'name' => $converted['name'],
            'owner_login' => $converted['owner_login'],
            'owner_type' => $converted['owner_type'],
            'html_url' => $converted['html_url'],
            'client_id' => $converted['client_id'],
            'created_by' => $userId,
        ]);
        $app->id = $appKey;
        $app->client_secret = $converted['client_secret'];
        $app->webhook_secret = $converted['webhook_secret'];
        $app->private_key = $converted['pem'];
        $app->save();

        $this->audit->record('source_control.github_app_created', 'source_control_github_app', $app->id, [
            'app_id' => $app->app_id,
            'slug' => $app->slug,
            'owner' => $app->owner_login,
        ], $organizationId);

        return $app;
    }
}
