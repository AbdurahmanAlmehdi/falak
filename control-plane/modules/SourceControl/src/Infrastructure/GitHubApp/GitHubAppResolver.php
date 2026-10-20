<?php

namespace Falak\SourceControl\Infrastructure\GitHubApp;

use Falak\SourceControl\Domain\Models\Connection;
use Falak\SourceControl\Domain\Models\GitHubApp;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which GitHub App to use. Precedence: the operator's `GITHUB_APP_*` env app (instance-wide) overrides the
 * organization's manifest-registered apps (one per GitHub owner) for new installations; existing connections keep
 * the app they were installed with.
 */
class GitHubAppResolver
{
    public function env(): ?AppCredentials
    {
        $id = (string) config('source_control.github.app.id');
        $key = (string) config('source_control.github.app.private_key');

        if ($id === '' || $key === '') {
            return null;
        }

        return new AppCredentials(
            key: AppCredentials::ENV,
            appId: $id,
            slug: (string) config('source_control.github.app.slug'),
            privateKey: str_replace('\n', "\n", $key),
            webhookSecret: ((string) config('source_control.github.app.webhook_secret')) ?: null,
            name: ((string) config('source_control.github.app.slug')) ?: null,
        );
    }

    /** The app new installations of this organization go through (env first). */
    public function forOrganization(string $organizationId): ?AppCredentials
    {
        return $this->env() ?? $this->registered($organizationId);
    }

    /** The organization's oldest manifest-registered app, ignoring the env override (the default for links without `?app=`). */
    public function registered(string $organizationId): ?AppCredentials
    {
        $app = $this->apps($organizationId)->first();

        return $app ? self::fromModel($app) : null;
    }

    /** @return Collection<int, GitHubApp> the organization's registered apps, oldest first */
    public function apps(string $organizationId): Collection
    {
        return GitHubApp::query()->where('organization_id', $organizationId)->orderBy('created_at')->orderBy('id')->get();
    }

    public function find(string $key): ?AppCredentials
    {
        if ($key === AppCredentials::ENV) {
            return $this->env();
        }

        $app = GitHubApp::query()->find($key);

        return $app ? self::fromModel($app) : null;
    }

    /** Connections created before app registration existed used the env app. */
    public function forConnection(Connection $connection): ?AppCredentials
    {
        return $this->find($connection->github_app_id ?: AppCredentials::ENV);
    }

    public static function fromModel(GitHubApp $app): AppCredentials
    {
        return new AppCredentials(
            key: $app->id,
            appId: $app->app_id,
            slug: $app->slug,
            privateKey: $app->private_key,
            webhookSecret: $app->webhook_secret,
            name: $app->name,
            ownerLogin: $app->owner_login,
            ownerType: $app->owner_type,
            htmlUrl: $app->html_url,
            organizationId: $app->organization_id,
        );
    }
}
