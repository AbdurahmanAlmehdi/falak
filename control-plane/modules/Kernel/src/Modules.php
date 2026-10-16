<?php

namespace Falak\Kernel;

use Illuminate\Support\ServiceProvider;

/**
 * Registry of control-plane modules, in dependency order.
 */
final class Modules
{
    /** @var list<string> */
    public const ALL = [
        'Identity',
        'Providers',
        'Fleet',
        'Servers',
        'Limits',
        'SourceControl',
        'Sites',
        'Edge',
        'Builds',
        'Deployments',
        'Processes',
        'Databases',
        'Projects',
        'Volumes',
        'Secrets',
        'Templates',
        'Functions',
        'Network',
        'Recipes',
        'Telemetry',
        'Insights',
        'Alerting',
        'Uptime',
        'Security',
        'Previews',
        'Sandboxes',
        'Terminal',
        'Recovery',
        'Config',
    ];

    /** Namespaces that are private to a module. */
    public const PRIVATE_LAYERS = ['Domain', 'Application', 'Infrastructure', 'Http'];

    /**
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        return array_map(fn (string $m) => "Falak\\{$m}\\{$m}ServiceProvider", self::ALL);
    }
}
