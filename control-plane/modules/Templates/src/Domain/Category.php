<?php

namespace Falak\Templates\Domain;

enum Category: string
{
    case Automation = 'automation';
    case Analytics = 'analytics';
    case Cms = 'cms';
    case Databases = 'databases';
    case DevTools = 'dev-tools';
    case Monitoring = 'monitoring';
    case Storage = 'storage';
    case Communication = 'communication';
    case Ai = 'ai';
    case Business = 'business';

    public function label(): string
    {
        return match ($this) {
            self::Automation => 'Automation',
            self::Analytics => 'Analytics',
            self::Cms => 'CMS',
            self::Databases => 'Databases',
            self::DevTools => 'Dev tools',
            self::Monitoring => 'Monitoring',
            self::Storage => 'Storage',
            self::Communication => 'Communication',
            self::Ai => 'AI',
            self::Business => 'Business',
        };
    }
}
