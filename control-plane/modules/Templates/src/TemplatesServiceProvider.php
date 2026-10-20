<?php

namespace Falak\Templates;

use Falak\Identity\Contracts\PermissionRegistry;
use Falak\Identity\Contracts\Role;
use Falak\Kernel\Support\ModuleServiceProvider;
use Falak\Sites\Contracts\ComposeInspector;
use Falak\Templates\Application\Catalog\Catalog;
use Falak\Templates\Application\Catalog\CatalogLocation;
use Falak\Templates\Application\Catalog\TemplateParser;
use Falak\Templates\Application\Compose\ComposeAnalyzer;
use Falak\Templates\Application\Compose\SiteCompose;
use Falak\Templates\Application\Console\RenderTemplateCommand;
use Falak\Templates\Application\Console\SyncTemplatesCommand;
use Falak\Templates\Application\Import\HostResolver;
use Falak\Templates\Application\Import\RemoteFetcher;
use Falak\Templates\Infrastructure\DnsHostResolver;
use Falak\Templates\Infrastructure\FilesystemCatalog;
use Falak\Templates\Infrastructure\GuardedHttpFetcher;
use Falak\Templates\Infrastructure\InspectorComposeAnalyzer;
use Falak\Templates\Infrastructure\SiteDataCompose;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as Http;

/**
 * One-click templates backed by Docker Compose (docs/COMPOSE_TEMPLATES.md §2–§4): the curated catalog, custom
 * organization templates, the input engine and "deploy from template".
 */
class TemplatesServiceProvider extends ModuleServiceProvider
{
    public const VIEW = 'templates.view';

    public const MANAGE = 'templates.manage';

    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        HostResolver::class => DnsHostResolver::class,
        SiteCompose::class => SiteDataCompose::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom($this->modulePath().'/config/templates.php', 'templates');

        $this->app->singleton(Catalog::class, fn (Application $app) => new FilesystemCatalog(
            $app->make(Cache::class),
            $app->make(TemplateParser::class),
            fn () => $app->make(CatalogLocation::class)->current()['path'],
            (int) config('templates.cache_ttl', 3600),
        ));

        // Structure + policy checks come from the compose runtime (Sites\Contracts\ComposeInspector).
        $this->app->bind(ComposeAnalyzer::class, fn (Application $app) => new InspectorComposeAnalyzer($app->make(ComposeInspector::class)));

        $this->app->bind(RemoteFetcher::class, fn (Application $app) => new GuardedHttpFetcher(
            $app->make(Http::class),
            $app->make(HostResolver::class),
            (int) config('templates.max_bytes', 262144),
            (int) config('templates.fetch.timeout', 10),
            (int) config('templates.fetch.max_redirects', 3),
        ));
    }

    protected function bootModule(): void
    {
        $registry = $this->app->make(PermissionRegistry::class);
        $registry->register(self::VIEW, [Role::Admin, Role::Developer, Role::Viewer], 'Browse the template catalog and the organization\'s templates', 'templates');
        $registry->register(self::MANAGE, [Role::Admin, Role::Developer], 'Import, edit and delete the organization\'s templates', 'templates');

        if ($this->app->runningInConsole()) {
            $this->commands([RenderTemplateCommand::class, SyncTemplatesCommand::class]);
        }

        // New and fixed templates reach the panel without a Falak release (falak-sh/templates, FALAK_TEMPLATES_URL).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('templates:sync')->hourly()->name('templates:sync')->withoutOverlapping();
        });
    }
}
