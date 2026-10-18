<?php

namespace Falak\Providers\Contracts;

enum ProviderType: string
{
    case Hetzner = 'hetzner';
    case DigitalOcean = 'digitalocean';
    case Vultr = 'vultr';
    case Linode = 'linode';
    case Aws = 'aws';
    case Contabo = 'contabo';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Hetzner => 'Hetzner Cloud',
            self::DigitalOcean => 'DigitalOcean',
            self::Vultr => 'Vultr',
            self::Linode => 'Akamai / Linode',
            self::Aws => 'AWS Lightsail',
            self::Contabo => 'Contabo',
            self::Custom => 'Custom (bring your own server)',
        };
    }

    /** Whether servers are created through the provider's API (i.e. a credential is required). */
    public function hasApi(): bool
    {
        return $this !== self::Custom;
    }
}
